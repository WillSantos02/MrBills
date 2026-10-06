<?php

use App\Models\IncomeCategory;
use Illuminate\Validation\Rule;
use Livewire\Attributes\On;
use Livewire\Component;

new class extends Component
{
    public ?int $editingCategoryId = null;
    public string $edit_name = '';

    public ?int $deletingCategoryId = null;
    public string $deletingCategoryName = '';
    public int $deletingIncomesCount = 0;

    #[On('income-category-created')]
    public function refresh(): void
    {
        //
    }

    public function with(): array
    {
        return [
            'incomeCategories' => IncomeCategory::whereIn('user_id', auth()->user()->familyGroupUserIds())
                ->withTotals()
                ->latest()
                ->get(),
        ];
    }

    public function editCategory(int $categoryId): void
    {
        $category = IncomeCategory::whereIn('user_id', auth()->user()->familyGroupUserIds())->findOrFail($categoryId);

        $this->editingCategoryId = $category->id;
        $this->edit_name = $category->name;
    }

    public function cancelEditCategory(): void
    {
        $this->reset(['editingCategoryId', 'edit_name']);
    }

    public function updateCategory(): void
    {
        $this->validate([
            'edit_name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('income_categories', 'name')
                    ->where(fn ($query) => $query->whereIn('user_id', auth()->user()->familyGroupUserIds()))
                    ->ignore($this->editingCategoryId),
            ],
        ], [
            'edit_name.unique' => 'Sua família já possui uma categoria de entrada com esse nome.',
        ]);

        IncomeCategory::whereIn('user_id', auth()->user()->familyGroupUserIds())
            ->findOrFail($this->editingCategoryId)
            ->update(['name' => $this->edit_name]);

        $this->cancelEditCategory();
    }

    public function askDeleteCategory(int $categoryId): void
    {
        $category = IncomeCategory::whereIn('user_id', auth()->user()->familyGroupUserIds())
            ->withCount('incomes')
            ->findOrFail($categoryId);

        $this->deletingCategoryId = $category->id;
        $this->deletingCategoryName = $category->name;
        $this->deletingIncomesCount = $category->incomes_count;
    }

    public function cancelDeleteCategory(): void
    {
        $this->reset(['deletingCategoryId', 'deletingCategoryName', 'deletingIncomesCount']);
    }

    public function confirmDeleteCategory(): void
    {
        IncomeCategory::whereIn('user_id', auth()->user()->familyGroupUserIds())
            ->where('id', $this->deletingCategoryId)
            ->delete();

        $this->cancelDeleteCategory();
    }
};
?>

<div class="glass-panel animate-rise rounded-3xl p-6">
    <div class="flex justify-between items-center mb-4">
        <h3 class="text-lg font-bold text-foreground">Categorias de Entrada (Carteira)</h3>
    </div>

    <div class="overflow-x-auto">
        <table class="w-full text-sm text-left text-gray-500 dark:text-gray-400">
            <thead class="font-mono text-[11px] uppercase tracking-[0.14em] text-muted-foreground bg-line/40">
            <tr>
                <th class="px-6 py-3">Categoria</th>
                <th class="px-6 py-3">Total Geral</th>
                <th class="px-6 py-3">Total do Mês Atual</th>
                <th class="px-6 py-3">Ações</th>
            </tr>
            </thead>
            <tbody>
            @forelse($incomeCategories as $category)
                <tr class="border-b border-line/70 transition-colors hover:bg-line/30">
                    <td class="px-6 py-4 font-medium text-gray-900 dark:text-white">{{ $category->name }}</td>
                    <td class="px-6 py-4">R$ {{ number_format($category->total_geral ?? 0, 2, ',', '.') }}</td>
                    <td class="px-6 py-4">R$ {{ number_format($category->total_mes_atual ?? 0, 2, ',', '.') }}</td>
                    <td class="px-6 py-4">
                        <div class="flex gap-3">
                            <button type="button" wire:click="editCategory({{ $category->id }})" class="text-blue-600 hover:underline dark:text-blue-400">
                                Editar
                            </button>
                            <button type="button" wire:click="askDeleteCategory({{ $category->id }})" class="text-red-600 hover:underline dark:text-red-400">
                                Excluir
                            </button>
                        </div>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="4" class="px-6 py-4 text-center text-gray-500">
                        Nenhuma categoria de entrada cadastrada ainda.
                    </td>
                </tr>
            @endforelse
            </tbody>
        </table>
    </div>

    @if ($editingCategoryId)
        <div class="fixed inset-0 bg-foreground/35 backdrop-blur-sm p-4 flex items-center justify-center z-50" wire:click.self="cancelEditCategory">
            <div class="modal-panel rounded-3xl p-6 w-full max-w-md">
                <h3 class="text-lg font-bold text-foreground mb-4">Editar Categoria</h3>

                <form wire:submit="updateCategory" class="space-y-4">
                    <flux:input wire:model="edit_name" label="Nome da Categoria" />

                    <div class="flex justify-end gap-2 pt-2">
                        <flux:button type="button" variant="ghost" wire:click="cancelEditCategory">Cancelar</flux:button>
                        <flux:button type="submit" variant="primary">Salvar</flux:button>
                    </div>
                </form>
            </div>
        </div>
    @endif

    @if ($deletingCategoryId)
        <div class="fixed inset-0 bg-foreground/35 backdrop-blur-sm p-4 flex items-center justify-center z-50" wire:click.self="cancelDeleteCategory">
            <div class="modal-panel rounded-3xl p-6 w-full max-w-md">
                <h3 class="text-lg font-bold text-foreground mb-2">Excluir Categoria</h3>

                <p class="text-sm text-gray-500 dark:text-gray-400 mb-4">
                    Tem certeza que deseja excluir "{{ $deletingCategoryName }}"?
                    @if ($deletingIncomesCount > 0)
                        <strong>{{ $deletingIncomesCount }}</strong> {{ $deletingIncomesCount === 1 ? 'lançamento ficará' : 'lançamentos ficarão' }} sem categoria após essa exclusão.
                    @endif
                </p>

                <div class="flex justify-end gap-2">
                    <flux:button variant="ghost" wire:click="cancelDeleteCategory">Cancelar</flux:button>
                    <flux:button variant="danger" wire:click="confirmDeleteCategory">Excluir</flux:button>
                </div>
            </div>
        </div>
    @endif
</div>
