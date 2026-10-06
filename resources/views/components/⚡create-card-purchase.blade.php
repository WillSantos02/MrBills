<?php

use App\Enums\BillStatus;
use App\Models\Bill;
use App\Models\Category;
use App\Models\CreditCard;
use App\Models\CreditCardPurchase;
use Carbon\CarbonImmutable;
use Illuminate\Validation\Rule;
use Livewire\Attributes\On;
use Livewire\Component;

new class extends Component
{
    public ?int $credit_card_id = null;
    public string $description = '';
    public string $value = '';
    public string $purchase_date = '';
    public ?int $category_id = null;
    public string $notes = '';
    public bool $is_installment = false;
    public int $total_installments = 2;

    public function mount(): void
    {
        $this->purchase_date = now()->toDateString();
    }

    #[On('credit-card-created')]
    public function refresh(): void
    {
        // Livewire re-renderiza automaticamente o componente ao disparar o listener.
    }

    public function with(): array
    {
        $familyUserIds = auth()->user()->familyGroupUserIds();

        $cards = CreditCard::whereIn('user_id', $familyUserIds)->orderBy('bank')->get();

        // Prévia da(s) fatura(s) em que a compra vai cair, recalculada a cada mudança de cartão/data/parcelas.
        $invoiceDue = null;
        $lastInvoiceDue = null;
        $card = $cards->firstWhere('id', $this->credit_card_id);

        if ($card !== null && $this->purchase_date !== '' && strtotime($this->purchase_date) !== false) {
            $date = CarbonImmutable::parse($this->purchase_date);
            $invoiceDue = $card->invoiceDueDateFor($date);

            if ($this->is_installment && $this->total_installments >= 2) {
                $lastInvoiceDue = $card->invoiceDueDateFor($date, min($this->total_installments, 48) - 1);
            }
        }

        return [
            'cards' => $cards,
            'categories' => Category::whereIn('user_id', $familyUserIds)->orderBy('name')->get(),
            'invoiceDue' => $invoiceDue,
            'lastInvoiceDue' => $lastInvoiceDue,
            'availableLimit' => $card?->availableLimit(),
        ];
    }

    public function save(): void
    {
        $familyUserIds = auth()->user()->familyGroupUserIds();

        $this->validate([
            'credit_card_id' => [
                'required',
                Rule::exists('credit_cards', 'id')->where(fn ($q) => $q->whereIn('user_id', $familyUserIds)),
            ],
            'description' => 'required|string|max:255',
            'value' => 'required|numeric|min:0.01',
            'purchase_date' => 'required|date',
            'category_id' => [
                'nullable',
                Rule::exists('categories', 'id')->where(fn ($q) => $q->whereIn('user_id', $familyUserIds)),
            ],
            'notes' => 'nullable|string|max:1000',
            'total_installments' => $this->is_installment ? 'required|integer|min:2|max:48' : 'nullable',
        ], [
            'credit_card_id.required' => 'Selecione um cartão.',
        ]);

        $card = CreditCard::findOrFail($this->credit_card_id);
        $date = CarbonImmutable::parse($this->purchase_date);
        $installments = $this->is_installment ? $this->total_installments : 1;

        // Fatura já quitada não recebe compra nova — o valor pago ficaria divergente do total.
        for ($offset = 0; $offset < $installments; $offset++) {
            $dueDate = $card->invoiceDueDateFor($date, $offset);

            $closedInvoice = Bill::where('credit_card_id', $card->id)
                ->whereDate('due_date', $dueDate->toDateString())
                ->where('status', '!=', BillStatus::Pendente->value)
                ->exists();

            if ($closedInvoice) {
                $this->addError('purchase_date', "A fatura com vencimento em {$dueDate->format('d/m/Y')} já foi paga.");

                return;
            }
        }

        // Compra parcelada ocupa o valor total do limite de uma vez (como no banco).
        $available = $card->availableLimit();

        if ($available !== null && (float) $this->value > round($available, 2)) {
            $this->addError('value', 'Limite insuficiente. Disponível: R$ '.number_format(max(0, $available), 2, ',', '.').'.');

            return;
        }

        $data = [
            'user_id' => auth()->id(),
            'credit_card_id' => $card->id,
            'description' => $this->description,
            'value' => $this->value,
            'purchase_date' => $this->purchase_date,
            'category_id' => $this->category_id,
            'notes' => $this->notes !== '' ? $this->notes : null,
        ];

        if ($installments > 1) {
            CreditCardPurchase::createInstallments($data, $installments);
        } else {
            CreditCardPurchase::create($data);
        }

        $this->reset(['description', 'value', 'category_id', 'notes', 'is_installment']);
        $this->total_installments = 2;

        $this->dispatch('card-purchase-created');
    }
};
?>

<div class="glass-panel animate-rise rounded-3xl p-6">
    <h3 class="text-lg font-bold text-foreground mb-4">Nova Compra no Cartão</h3>

    @if ($cards->isEmpty())
        <p class="text-sm text-gray-500 dark:text-gray-400">Cadastre um cartão para começar a registrar compras.</p>
    @else
        <form wire:submit="save" class="space-y-4">
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <flux:select wire:model.live="credit_card_id" label="Cartão">
                    <flux:select.option value="">Selecione...</flux:select.option>
                    @foreach ($cards as $card)
                        <flux:select.option value="{{ $card->id }}">{{ $card->bank }} •••• {{ $card->last_four_digits }}</flux:select.option>
                    @endforeach
                </flux:select>

                <flux:input wire:model="description" label="Descrição" placeholder="Ex: Mercado, Farmácia..." />
                <flux:input wire:model="value" :label="$is_installment ? 'Valor total da compra' : 'Valor'" type="number" step="0.01" placeholder="0,00" />
            </div>

            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <flux:input wire:model.live="purchase_date" label="Data da compra" type="date" />

                <flux:select wire:model="category_id" label="Categoria (opcional)">
                    <flux:select.option value="">Sem categoria</flux:select.option>
                    @foreach ($categories as $category)
                        <flux:select.option value="{{ $category->id }}">{{ $category->name }}</flux:select.option>
                    @endforeach
                </flux:select>

                <flux:input wire:model="notes" label="Observação (opcional)" />
            </div>

            <div class="grid grid-cols-1 md:grid-cols-3 gap-4 items-end">
                <flux:checkbox wire:model.live="is_installment" label="Compra parcelada?" />

                @if ($is_installment)
                    <flux:input wire:model.live.blur="total_installments" label="Número de parcelas" type="number" min="2" max="48" />
                @endif
            </div>

            @if ($invoiceDue)
                <p class="text-xs text-gray-500">
                    @if ($lastInvoiceDue)
                        1ª parcela na fatura com vencimento em
                        <span class="font-medium text-gray-700 dark:text-gray-300">{{ $invoiceDue->format('d/m/Y') }}</span>,
                        última em <span class="font-medium text-gray-700 dark:text-gray-300">{{ $lastInvoiceDue->format('d/m/Y') }}</span>.
                        O valor total ocupa o limite de uma vez.
                    @else
                        Esta compra entrará na fatura com vencimento em
                        <span class="font-medium text-gray-700 dark:text-gray-300">{{ $invoiceDue->format('d/m/Y') }}</span>.
                    @endif
                    @if ($availableLimit !== null)
                        Limite disponível: <span class="font-medium text-gray-700 dark:text-gray-300">R$ {{ number_format(max(0, $availableLimit), 2, ',', '.') }}</span>.
                    @endif
                </p>
            @endif

            <div>
                <flux:button type="submit" variant="primary">Salvar Compra</flux:button>
            </div>
        </form>
    @endif
</div>
