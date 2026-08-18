<?php

use App\Models\Income;
use App\Models\IncomeCategory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rule;
use Livewire\Attributes\On;
use Livewire\Component;

new class extends Component
{
    // Filtros
    public string $periodType = 'mes_atual'; // geral | mes_atual | mes_especifico | periodo
    public string $specificMonth = '';
    public string $periodStart = '';
    public string $periodEnd = '';
    public string $categoryFilter = '';

    // Edição
    public ?int $editingIncomeId = null;
    public string $edit_description = '';
    public string $edit_value = '';
    public string $edit_date = '';
    public ?int $edit_income_category_id = null;

    // Exclusão
    public ?int $deletingIncomeId = null;
    public bool $deletingIsRecurrent = false;

    public function mount(): void
    {
        $this->specificMonth = now()->format('Y-m');
        $this->periodStart = now()->startOfMonth()->toDateString();
        $this->periodEnd = now()->endOfMonth()->toDateString();
    }

    public function updatedPeriodType(): void
    {
        if ($this->periodType === 'mes_especifico' && $this->specificMonth === '') {
            $this->specificMonth = now()->format('Y-m');
        }

        if ($this->periodType === 'periodo' && ($this->periodStart === '' || $this->periodEnd === '')) {
            $this->periodStart = now()->startOfMonth()->toDateString();
            $this->periodEnd = now()->endOfMonth()->toDateString();
        }
    }

    #[On('income-created')]
    public function refresh(): void
    {
        //
    }

    protected function applyPeriodFilter(Builder $query): void
    {
        match ($this->periodType) {
            'mes_atual' => $query
                ->whereYear('date', now()->year)
                ->whereMonth('date', now()->month),

            'mes_especifico' => $this->specificMonth !== ''
                ? tap($query, function (Builder $q) {
                    $date = \Carbon\Carbon::createFromFormat('Y-m', $this->specificMonth);
                    $q->whereYear('date', $date->year)
                        ->whereMonth('date', $date->month);
                })
                : null,

            'periodo' => ($this->periodStart !== '' && $this->periodEnd !== '')
                ? $query->whereBetween('date', [$this->periodStart, $this->periodEnd])
                : null,

            default => null, // geral: sem filtro de data
        };
    }

    public function with(): array
    {
        $familyUserIds = auth()->user()->familyGroupUserIds();

        $query = Income::with('category')->whereIn('user_id', $familyUserIds);

        $this->applyPeriodFilter($query);

        if ($this->categoryFilter !== '') {
            $query->where('income_category_id', $this->categoryFilter);
        }

        return [
            'incomes' => $query->orderBy('date')->get(),
            'incomeCategories' => IncomeCategory::whereIn('user_id', $familyUserIds)
                ->orderBy('name')
                ->get(),
        ];
    }

    public function editIncome(int $incomeId): void
    {
        $income = Income::whereIn('user_id', auth()->user()->familyGroupUserIds())->findOrFail($incomeId);

        $this->editingIncomeId = $income->id;
        $this->edit_description = $income->description;
        $this->edit_value = (string) $income->value;
        $this->edit_date = $income->date->toDateString();
        $this->edit_income_category_id = $income->income_category_id;
    }

    public function cancelEdit(): void
    {
        $this->reset([
            'editingIncomeId',
            'edit_description',
            'edit_value',
            'edit_date',
            'edit_income_category_id',
        ]);
    }

    public function updateIncome(): void
    {
        $this->validate([
            'edit_description' => 'required|string|max:255',
            'edit_value' => 'required|numeric|min:0.01',
            'edit_date' => 'required|date',
            'edit_income_category_id' => [
                'nullable',
                Rule::exists('income_categories', 'id')->where(fn ($q) => $q->whereIn('user_id', auth()->user()->familyGroupUserIds())),
            ],
        ]);

        $income = Income::whereIn('user_id', auth()->user()->familyGroupUserIds())->findOrFail($this->editingIncomeId);

        $income->update([
            'description' => $this->edit_description,
            'value' => $this->edit_value,
            'date' => $this->edit_date,
            'income_category_id' => $this->edit_income_category_id,
        ]);

        $this->cancelEdit();
    }

    public function askDelete(int $incomeId): void
    {
        $income = Income::whereIn('user_id', auth()->user()->familyGroupUserIds())->findOrFail($incomeId);

        $this->deletingIncomeId = $income->id;
        $this->deletingIsRecurrent = filled($income->recurrence_group_id);
    }

    public function cancelDelete(): void
    {
        $this->reset(['deletingIncomeId', 'deletingIsRecurrent']);
    }

    public function deleteOnlyThis(): void
    {
        Income::whereIn('user_id', auth()->user()->familyGroupUserIds())
            ->where('id', $this->deletingIncomeId)
            ->delete();

        $this->cancelDelete();
    }

    public function deleteThisAndFuture(): void
    {
        $familyUserIds = auth()->user()->familyGroupUserIds();

        $income = Income::whereIn('user_id', $familyUserIds)->findOrFail($this->deletingIncomeId);

        Income::whereIn('user_id', $familyUserIds)
            ->where('recurrence_group_id', $income->recurrence_group_id)
            ->where('current_installments', '>=', $income->current_installments)
            ->delete();

        $this->cancelDelete();
    }
};
?>

<div class="p-6 bg-white border border-gray-200 rounded-lg shadow-sm dark:bg-zinc-900 dark:border-zinc-700">
    <div class="flex justify-between items-center mb-4">
        <h3 class="text-lg font-medium text-gray-900 dark:text-gray-100">Minhas Entradas</h3>
    </div>

    {{-- Filtros --}}
    <div class="mb-4 p-4 bg-gray-50 dark:bg-gray-900 rounded-lg grid grid-cols-1 md:grid-cols-4 gap-4">
        <flux:select wire:model.live="periodType" label="Período">
            <flux:select.option value="geral">Geral</flux:select.option>
            <flux:select.option value="mes_atual">Mês Atual</flux:select.option>
            <flux:select.option value="mes_especifico">Mês Específico</flux:select.option>
            <flux:select.option value="periodo">Período</flux:select.option>
        </flux:select>

        @if ($periodType === 'mes_especifico')
            <flux:input type="month" wire:model.live="specificMonth" label="Mês/Ano" />
        @endif

        @if ($periodType === 'periodo')
            <flux:input type="date" wire:model.live="periodStart" label="Data Inicial" />
            <flux:input type="date" wire:model.live="periodEnd" label="Data Final" />
        @endif

        <flux:select wire:model.live="categoryFilter" label="Categoria">
            <flux:select.option value="">Todas</flux:select.option>
            @foreach ($incomeCategories as $category)
                <flux:select.option value="{{ $category->id }}">{{ $category->name }}</flux:select.option>
            @endforeach
        </flux:select>
    </div>

    <div class="overflow-x-auto">
        <table class="w-full text-sm text-left text-gray-500 dark:text-gray-400">
            <thead class="text-xs text-gray-700 uppercase bg-gray-50 dark:bg-gray-700 dark:text-gray-400">
            <tr>
                <th class="px-6 py-3">Descrição</th>
                <th class="px-6 py-3">Categoria</th>
                <th class="px-6 py-3">Valor</th>
                <th class="px-6 py-3">Data</th>
                <th class="px-6 py-3">Recorrente?</th>
                <th class="px-6 py-3">Ações</th>
            </tr>
            </thead>
            <tbody>
            @forelse($incomes as $income)
                <tr class="bg-white border-b dark:bg-gray-800 dark:border-gray-700">
                    <td class="px-6 py-4 font-medium text-gray-900 dark:text-white">{{ $income->display_description }}</td>
                    <td class="px-6 py-4">{{ $income->category?->name ?? '—' }}</td>
                    <td class="px-6 py-4">R$ {{ number_format($income->value, 2, ',', '.') }}</td>
                    <td class="px-6 py-4">{{ $income->date->format('d/m/Y') }}</td>
                    <td class="px-6 py-4">{{ $income->is_recurrent ? 'Sim' : 'Não' }}</td>
                    <td class="px-6 py-4">
                        <div class="flex gap-3">
                            <button type="button" wire:click="editIncome({{ $income->id }})" class="text-blue-600 hover:underline dark:text-blue-400">
                                Editar
                            </button>
                            <button type="button" wire:click="askDelete({{ $income->id }})" class="text-red-600 hover:underline dark:text-red-400">
                                Excluir
                            </button>
                        </div>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="6" class="px-6 py-4 text-center text-gray-500">
                        Nenhuma entrada cadastrada ainda.
                    </td>
                </tr>
            @endforelse
            </tbody>
        </table>
    </div>

    {{-- Modal de Edição --}}
    @if ($editingIncomeId)
        <div class="fixed inset-0 bg-gray-900/50 flex items-center justify-center z-50" wire:click.self="cancelEdit">
            <div class="bg-white dark:bg-gray-800 rounded-lg shadow-lg p-6 w-full max-w-lg">
                <h3 class="text-lg font-medium text-gray-900 dark:text-gray-100 mb-4">Editar Entrada</h3>

                <form wire:submit="updateIncome" class="space-y-4">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <flux:input wire:model="edit_description" label="Descrição" />
                        <flux:input wire:model="edit_value" label="Valor" type="number" step="0.01" />
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <flux:input wire:model="edit_date" label="Data" type="date" />

                        <flux:select wire:model="edit_income_category_id" label="Categoria">
                            <flux:select.option value="">Sem categoria</flux:select.option>
                            @foreach ($incomeCategories as $category)
                                <flux:select.option value="{{ $category->id }}">{{ $category->name }}</flux:select.option>
                            @endforeach
                        </flux:select>
                    </div>

                    <div class="flex justify-end gap-2 pt-2">
                        <flux:button type="button" variant="ghost" wire:click="cancelEdit">Cancelar</flux:button>
                        <flux:button type="submit" variant="primary">Salvar Alterações</flux:button>
                    </div>
                </form>
            </div>
        </div>
    @endif

    {{-- Modal de Exclusão --}}
    @if ($deletingIncomeId)
        <div class="fixed inset-0 bg-gray-900/50 flex items-center justify-center z-50" wire:click.self="cancelDelete">
            <div class="bg-white dark:bg-gray-800 rounded-lg shadow-lg p-6 w-full max-w-md">
                <h3 class="text-lg font-medium text-gray-900 dark:text-gray-100 mb-2">Excluir Entrada</h3>

                @if ($deletingIsRecurrent)
                    <p class="text-sm text-gray-500 dark:text-gray-400 mb-4">
                        Essa entrada faz parte de uma recorrência. Você quer excluir apenas este lançamento,
                        ou este e todos os futuros?
                    </p>
                    <div class="flex flex-col gap-2">
                        <flux:button variant="danger" wire:click="deleteOnlyThis">Excluir somente este lançamento</flux:button>
                        <flux:button variant="danger" wire:click="deleteThisAndFuture">Excluir este e os futuros</flux:button>
                        <flux:button variant="ghost" wire:click="cancelDelete">Cancelar</flux:button>
                    </div>
                @else
                    <p class="text-sm text-gray-500 dark:text-gray-400 mb-4">
                        Tem certeza que deseja excluir esta entrada? Essa ação não pode ser desfeita.
                    </p>
                    <div class="flex justify-end gap-2">
                        <flux:button variant="ghost" wire:click="cancelDelete">Cancelar</flux:button>
                        <flux:button variant="danger" wire:click="deleteOnlyThis">Excluir</flux:button>
                    </div>
                @endif
            </div>
        </div>
    @endif
</div>
