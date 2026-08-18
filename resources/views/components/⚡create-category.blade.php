<?php

use App\Models\Category;
use App\Models\IncomeCategory;
use Illuminate\Validation\Rule;
use Livewire\Component;

new class extends Component
{
    public string $name = '';
    public string $type = 'despesa'; // 'despesa' (Contas) | 'entrada' (Carteira)

    public function save(): void
    {
        $familyUserIds = auth()->user()->familyGroupUserIds();

        if ($this->type === 'entrada') {
            $this->validate([
                'name' => [
                    'required',
                    'string',
                    'max:255',
                    Rule::unique('income_categories', 'name')->where(fn ($query) => $query->whereIn('user_id', $familyUserIds)),
                ],
            ], [
                'name.unique' => 'Sua família já possui uma categoria de entrada com esse nome.',
            ]);

            IncomeCategory::create([
                'user_id' => auth()->id(),
                'name' => $this->name,
            ]);

            $this->dispatch('income-category-created');
        } else {
            $this->validate([
                'name' => [
                    'required',
                    'string',
                    'max:255',
                    Rule::unique('categories', 'name')->where(fn ($query) => $query->whereIn('user_id', $familyUserIds)),
                ],
            ], [
                'name.unique' => 'Sua família já possui uma categoria de despesa com esse nome.',
            ]);

            Category::create([
                'user_id' => auth()->id(),
                'name' => $this->name,
            ]);

            $this->dispatch('category-created');
        }

        $this->reset('name');
    }
};
?>

<div class="p-6 bg-white border border-gray-200 rounded-lg shadow-sm dark:bg-zinc-900 dark:border-zinc-700">
    <h3 class="text-lg font-medium text-gray-900 dark:text-gray-100 mb-4">Nova Categoria</h3>

    <form wire:submit="save" class="grid grid-cols-1 md:grid-cols-3 gap-4 md:items-end">
        <flux:select wire:model="type" label="Tipo">
            <flux:select.option value="despesa">Despesa (Contas)</flux:select.option>
            <flux:select.option value="entrada">Entrada (Carteira)</flux:select.option>
        </flux:select>

        <flux:input wire:model="name" label="Nome da Categoria" placeholder="Ex: Moradia, Salário..." />

        <flux:button type="submit" variant="primary">Salvar</flux:button>
    </form>
</div>
