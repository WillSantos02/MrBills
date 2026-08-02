<?php

use App\Enums\BillStatus;
use App\Models\Bill;
use App\Models\Category;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\On;
use Livewire\Component;

new class extends Component
{
    // Filtros
    public string $periodType = 'mes_atual'; // geral | mes_atual | mes_especifico | periodo
    public string $specificMonth = '';
    public string $periodStart = '';
    public string $periodEnd = '';
    public string $statusFilter = '';
    public string $categoryFilter = '';

    // Edição
    public ?int $editingBillId = null;
    public string $edit_description = '';
    public string $edit_value = '';
    public string $edit_due_date = '';
    public ?int $edit_category_id = null;
    public int $edit_status = 1;

    // Exclusão
    public ?int $deletingBillId = null;
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

    #[On('bill-created')]
    public function refresh(): void
    {
        // Livewire re-renderiza automaticamente o componente ao disparar o listener.
    }

    protected function applyPeriodFilter(Builder $query): void
    {
        match ($this->periodType) {
            'mes_atual' => $query
                ->whereYear('actual_due_date', now()->year)
                ->whereMonth('actual_due_date', now()->month),

            'mes_especifico' => $this->specificMonth !== ''
                ? tap($query, function (Builder $q) {
                    $date = \Carbon\Carbon::createFromFormat('Y-m', $this->specificMonth);
                    $q->whereYear('actual_due_date', $date->year)
                        ->whereMonth('actual_due_date', $date->month);
                })
                : null,

            'periodo' => ($this->periodStart !== '' && $this->periodEnd !== '')
                ? $query->whereBetween('actual_due_date', [$this->periodStart, $this->periodEnd])
                : null,

            default => null, // geral: sem filtro de data
        };
    }

    protected function applyStatusFilter(Builder $query): void
    {
        if ($this->statusFilter === '') {
            return;
        }

        $status = BillStatus::from((int) $this->statusFilter);

        // "Vencido" não é persistido — é Pendente com vencimento no passado (ver effective_status no Model).
        if ($status === BillStatus::Vencido) {
            $query->where('status', BillStatus::Pendente->value)
                ->where('actual_due_date', '<', now()->toDateString());
        } elseif ($status === BillStatus::Pendente) {
            $query->where('status', BillStatus::Pendente->value)
                ->where('actual_due_date', '>=', now()->toDateString());
        } else {
            $query->where('status', $status->value);
        }
    }

    public function with(): array
    {
        $query = Bill::with('category')->where('user_id', auth()->id());

        $this->applyPeriodFilter($query);
        $this->applyStatusFilter($query);

        if ($this->categoryFilter !== '') {
            $query->where('category_id', $this->categoryFilter);
        }

        return [
            'bills' => $query->orderBy('actual_due_date')->get(),
            'categories' => Category::where('user_id', auth()->id())
                ->orderBy('name')
                ->get(),
            'statuses' => BillStatus::cases(),
        ];
    }

    public function editBill(int $billId): void
    {
        $bill = Bill::where('user_id', auth()->id())->findOrFail($billId);

        $this->editingBillId = $bill->id;
        $this->edit_description = $bill->description;
        $this->edit_value = (string) $bill->value;
        $this->edit_due_date = $bill->due_date->toDateString();
        $this->edit_category_id = $bill->category_id;
        $this->edit_status = $bill->status->value;
    }

    public function cancelEdit(): void
    {
        $this->reset([
            'editingBillId',
            'edit_description',
            'edit_value',
            'edit_due_date',
            'edit_category_id',
            'edit_status',
        ]);
    }

    public function updateBill(): void
    {
        $this->validate([
            'edit_description' => 'required|string|max:255',
            'edit_value' => 'required|numeric|min:0.01',
            'edit_due_date' => 'required|date',
            'edit_category_id' => 'nullable|exists:categories,id',
            'edit_status' => 'required|integer',
        ]);

        $bill = Bill::where('user_id', auth()->id())->findOrFail($this->editingBillId);

        $bill->update([
            'description' => $this->edit_description,
            'value' => $this->edit_value,
            'due_date' => $this->edit_due_date,
            'category_id' => $this->edit_category_id,
            'status' => $this->edit_status,
        ]);

        $this->cancelEdit();
    }

    public function askDelete(int $billId): void
    {
        $bill = Bill::where('user_id', auth()->id())->findOrFail($billId);

        $this->deletingBillId = $bill->id;
        $this->deletingIsRecurrent = filled($bill->recurrence_group_id);
    }

    public function cancelDelete(): void
    {
        $this->reset(['deletingBillId', 'deletingIsRecurrent']);
    }

    public function deleteOnlyThis(): void
    {
        Bill::where('user_id', auth()->id())
            ->where('id', $this->deletingBillId)
            ->delete();

        $this->cancelDelete();
    }

    public function deleteThisAndFuture(): void
    {
        $bill = Bill::where('user_id', auth()->id())->findOrFail($this->deletingBillId);

        Bill::where('user_id', auth()->id())
            ->where('recurrence_group_id', $bill->recurrence_group_id)
            ->where('current_installments', '>=', $bill->current_installments)
            ->delete();

        $this->cancelDelete();
    }
};
?>

<div class="p-6 bg-white border border-gray-200 rounded-lg shadow-sm dark:bg-zinc-900 dark:border-zinc-700">
    <div class="flex justify-between items-center mb-4">
        <h3 class="text-lg font-medium text-gray-900 dark:text-gray-100">Minhas Contas</h3>
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

        <flux:select wire:model.live="statusFilter" label="Status">
            <flux:select.option value="">Todos</flux:select.option>
            @foreach ($statuses as $status)
                <flux:select.option value="{{ $status->value }}">{{ $status->label() }}</flux:select.option>
            @endforeach
        </flux:select>

        <flux:select wire:model.live="categoryFilter" label="Categoria">
            <flux:select.option value="">Todas</flux:select.option>
            @foreach ($categories as $category)
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
                <th class="px-6 py-3">Venc. Original</th>
                <th class="px-6 py-3">Venc. Real (Útil)</th>
                <th class="px-6 py-3">Recorrente?</th>
                <th class="px-6 py-3">Status</th>
                <th class="px-6 py-3">Ações</th>
            </tr>
            </thead>
            <tbody>
            @forelse($bills as $bill)
                <tr class="bg-white border-b dark:bg-gray-800 dark:border-gray-700">
                    <td class="px-6 py-4 font-medium text-gray-900 dark:text-white">{{ $bill->display_description }}</td>
                    <td class="px-6 py-4">{{ $bill->category?->name ?? '—' }}</td>
                    <td class="px-6 py-4">R$ {{ number_format($bill->value, 2, ',', '.') }}</td>
                    <td class="px-6 py-4">{{ $bill->due_date->format('d/m/Y') }}</td>
                    <td class="px-6 py-4">{{ $bill->actual_due_date->format('d/m/Y') }}</td>
                    <td class="px-6 py-4">{{ $bill->is_recurrent ? 'Sim' : 'Não' }}</td>
                    <td class="px-6 py-4">
                        <span class="px-2 py-1 text-xs rounded-full {{ $bill->effective_status->badgeClasses() }}">
                            {{ $bill->effective_status->label() }}
                        </span>
                    </td>
                    <td class="px-6 py-4">
                        <div class="flex gap-3">
                            <button type="button" wire:click="editBill({{ $bill->id }})" class="text-blue-600 hover:underline dark:text-blue-400">
                                Editar
                            </button>
                            <button type="button" wire:click="askDelete({{ $bill->id }})" class="text-red-600 hover:underline dark:text-red-400">
                                Excluir
                            </button>
                        </div>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="8" class="px-6 py-4 text-center text-gray-500">
                        Nenhuma conta encontrada para os filtros selecionados.
                    </td>
                </tr>
            @endforelse
            </tbody>
        </table>
    </div>

    {{-- Modal de Edição --}}
    @if ($editingBillId)
        <div class="fixed inset-0 bg-gray-900/50 flex items-center justify-center z-50" wire:click.self="cancelEdit">
            <div class="bg-white dark:bg-gray-800 rounded-lg shadow-lg p-6 w-full max-w-lg">
                <h3 class="text-lg font-medium text-gray-900 dark:text-gray-100 mb-4">Editar Conta</h3>

                <form wire:submit="updateBill" class="space-y-4">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <flux:input wire:model="edit_description" label="Descrição" />
                        <flux:input wire:model="edit_value" label="Valor" type="number" step="0.01" />
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <flux:input wire:model="edit_due_date" label="Data de Vencimento" type="date" />

                        <flux:select wire:model="edit_category_id" label="Categoria">
                            <flux:select.option value="">Sem categoria</flux:select.option>
                            @foreach ($categories as $category)
                                <flux:select.option value="{{ $category->id }}">{{ $category->name }}</flux:select.option>
                            @endforeach
                        </flux:select>
                    </div>

                    <flux:select wire:model="edit_status" label="Status">
                        @foreach ($statuses as $status)
                            <flux:select.option value="{{ $status->value }}">{{ $status->label() }}</flux:select.option>
                        @endforeach
                    </flux:select>

                    <div class="flex justify-end gap-2 pt-2">
                        <flux:button type="button" variant="ghost" wire:click="cancelEdit">Cancelar</flux:button>
                        <flux:button type="submit" variant="primary">Salvar Alterações</flux:button>
                    </div>
                </form>
            </div>
        </div>
    @endif

    {{-- Modal de Exclusão --}}
    @if ($deletingBillId)
        <div class="fixed inset-0 bg-gray-900/50 flex items-center justify-center z-50" wire:click.self="cancelDelete">
            <div class="bg-white dark:bg-gray-800 rounded-lg shadow-lg p-6 w-full max-w-md">
                <h3 class="text-lg font-medium text-gray-900 dark:text-gray-100 mb-2">Excluir Conta</h3>

                @if ($deletingIsRecurrent)
                    <p class="text-sm text-gray-500 dark:text-gray-400 mb-4">
                        Essa conta faz parte de uma recorrência. Você quer excluir apenas esta parcela,
                        ou esta e todas as parcelas futuras?
                    </p>
                    <div class="flex flex-col gap-2">
                        <flux:button variant="danger" wire:click="deleteOnlyThis">Excluir somente esta parcela</flux:button>
                        <flux:button variant="danger" wire:click="deleteThisAndFuture">Excluir esta e as futuras</flux:button>
                        <flux:button variant="ghost" wire:click="cancelDelete">Cancelar</flux:button>
                    </div>
                @else
                    <p class="text-sm text-gray-500 dark:text-gray-400 mb-4">
                        Tem certeza que deseja excluir esta conta? Essa ação não pode ser desfeita.
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
