<?php

use App\Models\Income;
use App\Models\IncomeCategory;
use Illuminate\Validation\Rule;
use Livewire\Component;

new class extends Component
{
    public string $description = '';
    public string $value = '';
    public string $date = '';
    public ?int $income_category_id = null;
    public bool $is_recurrent = false;
    public int $total_installments = 1;

    public function with(): array
    {
        return [
            'incomeCategories' => IncomeCategory::whereIn('user_id', auth()->user()->familyGroupUserIds())
                ->orderBy('name')
                ->get(),
        ];
    }

    public function save(): void
    {
        $rules = [
            'description' => 'required|string|max:255',
            'value' => 'required|numeric|min:0.01',
            'date' => 'required|date',
            'income_category_id' => [
                'nullable',
                Rule::exists('income_categories', 'id')->where(fn ($q) => $q->whereIn('user_id', auth()->user()->familyGroupUserIds())),
            ],
        ];

        if ($this->is_recurrent) {
            $rules['total_installments'] = 'required|integer|min:2|max:360';
        }

        $this->validate($rules);

        $data = [
            'user_id' => auth()->id(),
            'description' => $this->description,
            'value' => $this->value,
            'date' => $this->date,
            'income_category_id' => $this->income_category_id,
        ];

        if ($this->is_recurrent) {
            Income::createRecurrent(array_merge($data, [
                'total_installments' => $this->total_installments,
            ]));
        } else {
            Income::create(array_merge($data, [
                'is_recurrent' => false,
                'total_installments' => 1,
                'current_installments' => 1,
            ]));
        }

        $this->reset(['description', 'value', 'date', 'income_category_id', 'is_recurrent']);
        $this->total_installments = 1;

        $this->dispatch('income-created');
    }
};
?>

<div class="p-6 bg-white border border-gray-200 rounded-lg shadow-sm dark:bg-zinc-900 dark:border-zinc-700">
    <h3 class="text-lg font-medium text-gray-900 dark:text-gray-100 mb-4">Nova Entrada</h3>

    <form wire:submit="save" class="space-y-4">
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <flux:input wire:model="description" label="Descrição" placeholder="Ex: Salário, Freelance..." />
            <flux:input wire:model="value" label="Valor" type="number" step="0.01" placeholder="0,00" />
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <flux:input wire:model="date" label="Data" type="date" />

            <flux:select wire:model="income_category_id" label="Categoria">
                <flux:select.option value="">Sem categoria</flux:select.option>
                @foreach ($incomeCategories as $category)
                    <flux:select.option value="{{ $category->id }}">{{ $category->name }}</flux:select.option>
                @endforeach
            </flux:select>
        </div>

        <div>
            <flux:checkbox wire:model.live="is_recurrent" label="Entrada recorrente (ex: salário mensal)?" />
        </div>

        @if ($is_recurrent)
            <div class="md:w-1/2">
                <flux:input
                    wire:model="total_installments"
                    label="Número de Meses"
                    type="number"
                    min="2"
                    max="360"
                />
                <p class="text-xs text-gray-500 mt-1">
                    Todos os lançamentos serão criados agora, com repetição mensal a partir da data informada.
                </p>
            </div>
        @endif

        <div>
            <flux:button type="submit" variant="primary">Salvar Entrada</flux:button>
        </div>
    </form>
</div>
