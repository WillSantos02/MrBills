<?php

use App\Enums\CardColor;
use App\Models\CreditCard;
use Illuminate\Validation\Rule;
use Livewire\Component;

new class extends Component
{
    public string $bank = '';
    public string $last_four_digits = '';
    public string $color = 'roxo';
    public string $credit_limit = '';
    public string $closing_day = '';
    public string $due_day = '';

    public function with(): array
    {
        return [
            'colors' => CardColor::cases(),
        ];
    }

    public function save(): void
    {
        $this->validate([
            'bank' => 'required|string|max:100',
            'last_four_digits' => 'required|digits:4',
            'color' => ['required', Rule::enum(CardColor::class)],
            'credit_limit' => 'nullable|numeric|min:0.01|max:99999999',
            'closing_day' => 'required|integer|between:1,31',
            'due_day' => 'required|integer|between:1,31|different:closing_day',
        ], [
            'due_day.different' => 'O vencimento deve ser em um dia diferente do fechamento.',
        ]);

        CreditCard::create([
            'user_id' => auth()->id(),
            'bank' => $this->bank,
            'last_four_digits' => $this->last_four_digits,
            'color' => $this->color,
            'credit_limit' => $this->credit_limit !== '' ? $this->credit_limit : null,
            'closing_day' => (int) $this->closing_day,
            'due_day' => (int) $this->due_day,
        ]);

        $this->reset();

        $this->dispatch('credit-card-created');
    }
};
?>

<div class="p-6 bg-white border border-gray-200 rounded-lg shadow-sm dark:bg-zinc-900 dark:border-zinc-700">
    <h3 class="text-lg font-medium text-gray-900 dark:text-gray-100 mb-4">Novo Cartão</h3>

    <form wire:submit="save" class="space-y-4">
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
            <flux:input wire:model="bank" label="Banco" placeholder="Ex: Nubank, Itaú..." />
            <flux:input wire:model="last_four_digits" label="4 últimos dígitos" inputmode="numeric" maxlength="4" placeholder="1234" />
            <flux:input wire:model="credit_limit" label="Limite (opcional)" type="number" step="0.01" min="0" placeholder="0,00" />
        </div>

        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
            <flux:input wire:model="closing_day" label="Dia de fechamento da fatura" type="number" min="1" max="31" placeholder="Ex: 10" />
            <flux:input wire:model="due_day" label="Dia de vencimento da fatura" type="number" min="1" max="31" placeholder="Ex: 17" />
            @include('partials.card-color-picker', ['model' => 'color', 'selected' => $color, 'colors' => $colors])
        </div>

        <p class="text-xs text-gray-500">
            Compras feitas até o dia do fechamento entram na fatura que vence logo em seguida; depois do fechamento,
            vão para a fatura do mês seguinte.
        </p>

        <div>
            <flux:button type="submit" variant="primary">Salvar Cartão</flux:button>
        </div>
    </form>
</div>
