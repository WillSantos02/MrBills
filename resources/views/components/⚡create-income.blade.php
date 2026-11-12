<?php

use App\Models\FixedIncome;
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
    /** unica | recorrente (n meses) | fixa (sem fim) */
    public string $kind = 'unica';
    public int $total_installments = 2;

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
            'kind' => ['required', Rule::in(['unica', 'recorrente', 'fixa'])],
        ];

        if ($this->kind === 'recorrente') {
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

        match ($this->kind) {
            'recorrente' => Income::createRecurrent(array_merge($data, [
                'total_installments' => $this->total_installments,
            ])),
            'fixa' => FixedIncome::start($data),
            default => Income::create(array_merge($data, [
                'is_recurrent' => false,
                'total_installments' => 1,
                'current_installments' => 1,
            ])),
        };

        $this->reset(['description', 'value', 'date', 'income_category_id', 'kind', 'total_installments']);

        $this->dispatch('income-created');
    }
};
?>

<div class="glass-panel animate-rise rounded-3xl p-6">
    <h3 class="text-lg font-bold text-foreground mb-4">Nova Entrada</h3>

    <form wire:submit="save" class="space-y-4">
        <div class="md:w-1/2">
            <flux:radio.group wire:model.live="kind" label="Tipo de entrada" variant="segmented">
                <flux:radio value="unica" label="Única" icon="document-text" />
                <flux:radio value="recorrente" label="Recorrente" icon="queue-list" />
                <flux:radio value="fixa" label="Fixa" icon="arrow-path" />
            </flux:radio.group>
        </div>

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

        @if ($kind === 'recorrente')
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
        @elseif ($kind === 'fixa')
            <p class="text-xs text-muted-foreground">
                Entrada que se repete todo mês, sem data para acabar (salário, aluguel recebido...). Os próximos
                {{ \App\Models\FixedIncome::HORIZON_MONTHS }} meses ficam sempre lançados; para encerrar, exclua um
                lançamento escolhendo "este e os futuros".
            </p>
        @endif

        <div>
            <flux:button type="submit" variant="primary">Salvar Entrada</flux:button>
        </div>
    </form>
</div>
