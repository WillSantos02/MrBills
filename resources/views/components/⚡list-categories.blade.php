<?php

use App\Models\Category;
use Illuminate\Validation\Rule;
use Livewire\Attributes\On;
use Livewire\Component;

new class extends Component
{
    // Edição
    public ?int $editingCategoryId = null;
    public string $edit_name = '';

    // Exclusão
    public ?int $deletingCategoryId = null;
    public string $deletingCategoryName = '';
    public int $deletingBillsCount = 0;

    #[On('category-created')]
    public function refresh(): void
    {
        // Livewire re-renderiza automaticamente o componente ao disparar o listener.
    }

    public function with(): array
    {
        return [
            'categories' => Category::where('user_id', auth()->id())
                ->withTotals()
                ->latest()
                ->get(),
        ];
    }

    public function editCategory(int $categoryId): void
    {
        $category = Category::where('user_id', auth()->id())->findOrFail($categoryId);

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
                Rule::unique('categories', 'name')
                    ->where('user_id', auth()->id())
                    ->ignore($this->editingCategoryId),
            ],
        ], [
            'edit_name.unique' => 'Você já possui uma categoria com esse nome.',
        ]);

        Category::where('user_id', auth()->id())
            ->findOrFail($this->editingCategoryId)
            ->update(['name' => $this->edit_name]);

        $this->cancelEditCategory();
    }

    public function askDeleteCategory(int $categoryId): void
    {
        $category = Category::where('user_id', auth()->id())
            ->withCount('bills')
            ->findOrFail($categoryId);

        $this->deletingCategoryId = $category->id;
        $this->deletingCategoryName = $category->name;
        $this->deletingBillsCount = $category->bills_count;
    }

    public function cancelDeleteCategory(): void
    {
        $this->reset(['deletingCategoryId', 'deletingCategoryName', 'deletingBillsCount']);
    }

    public function confirmDeleteCategory(): void
    {
        // As contas vinculadas ficam sem categoria automaticamente (nullOnDelete na migration).
        Category::where('user_id', auth()->id())
            ->where('id', $this->deletingCategoryId)
            ->delete();

        $this->cancelDeleteCategory();
    }
};
?>

<div class="p-6 bg-white border border-gray-200 rounded-lg shadow-sm dark:bg-zinc-900 dark:border-zinc-700">
    <div class="flex justify-between items-center mb-4">
        <h3 class="text-lg font-medium text-gray-900 dark:text-gray-100">Categorias de Despesa (Contas)</h3>
    </div>

    <div class="overflow-x-auto">
        <table class="w-full text-sm text-left text-gray-500 dark:text-gray-400">
            <thead class="text-xs text-gray-700 uppercase bg-gray-50 dark:bg-gray-700 dark:text-gray-400">
            <tr>
                <th class="px-6 py-3">Categoria</th>
                <th class="px-6 py-3">Total Geral</th>
                <th class="px-6 py-3">Total do Mês Atual</th>
                <th class="px-6 py-3">Ações</th>
            </tr>
            </thead>
            <tbody>
            @forelse($categories as $category)
                <tr class="bg-white border-b dark:bg-gray-800 dark:border-gray-700">
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
                        Nenhuma categoria cadastrada ainda.
                    </td>
                </tr>
            @endforelse
            </tbody>
        </table>
    </div>

    {{-- Modal de Edição --}}
    @if ($editingCategoryId)
        <div class="fixed inset-0 bg-gray-900/50 flex items-center justify-center z-50" wire:click.self="cancelEditCategory">
            <div class="bg-white dark:bg-gray-800 rounded-lg shadow-lg p-6 w-full max-w-md">
                <h3 class="text-lg font-medium text-gray-900 dark:text-gray-100 mb-4">Editar Categoria</h3>

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

    {{-- Modal de Exclusão --}}
    @if ($deletingCategoryId)
        <div class="fixed inset-0 bg-gray-900/50 flex items-center justify-center z-50" wire:click.self="cancelDeleteCategory">
            <div class="bg-white dark:bg-gray-800 rounded-lg shadow-lg p-6 w-full max-w-md">
                <h3 class="text-lg font-medium text-gray-900 dark:text-gray-100 mb-2">Excluir Categoria</h3>

                <p class="text-sm text-gray-500 dark:text-gray-400 mb-4">
                    Tem certeza que deseja excluir "{{ $deletingCategoryName }}"?
                    @if ($deletingBillsCount > 0)
                        <strong>{{ $deletingBillsCount }}</strong> {{ $deletingBillsCount === 1 ? 'conta ficará' : 'contas ficarão' }} sem categoria após essa exclusão.
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
