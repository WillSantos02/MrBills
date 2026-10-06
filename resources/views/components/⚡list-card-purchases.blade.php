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
    // Filtros
    public string $cardFilter = '';
    public string $invoiceFilter = '';

    // Edição
    public ?int $editingPurchaseId = null;
    public string $edit_description = '';
    public string $edit_value = '';
    public string $edit_purchase_date = '';
    public ?int $edit_category_id = null;
    public string $edit_notes = '';

    // Exclusão
    public ?int $deletingPurchaseId = null;
    public bool $deletingIsInstallment = false;

    #[On('credit-card-created')]
    #[On('card-purchase-created')]
    public function refresh(): void
    {
        // Livewire re-renderiza automaticamente o componente ao disparar o listener.
    }

    public function updatedCardFilter(): void
    {
        $this->invoiceFilter = '';
    }

    public function with(): array
    {
        $familyUserIds = auth()->user()->familyGroupUserIds();

        $cards = CreditCard::whereIn('user_id', $familyUserIds)->orderBy('bank')->get();
        $selectedCard = $cards->firstWhere('id', (int) $this->cardFilter);

        $invoices = $selectedCard !== null
            ? $selectedCard->invoices()->orderByDesc('due_date')->get()
            : collect();

        $selectedInvoice = $invoices->firstWhere('id', (int) $this->invoiceFilter);

        $query = CreditCardPurchase::with(['creditCard', 'bill', 'category', 'user'])
            ->whereIn('credit_card_id', $cards->pluck('id'));

        if ($selectedCard !== null) {
            $query->where('credit_card_id', $selectedCard->id);
        }

        if ($selectedInvoice !== null) {
            $query->where('bill_id', $selectedInvoice->id);
        }

        return [
            'cards' => $cards,
            'invoices' => $invoices,
            'selectedCard' => $selectedCard,
            'selectedInvoice' => $selectedInvoice,
            'purchases' => $query->orderByDesc('purchase_date')->orderByDesc('id')->get(),
            'categories' => Category::whereIn('user_id', $familyUserIds)->orderBy('name')->get(),
        ];
    }

    /**
     * Compras da família cujas faturas ainda estão pendentes — faturas pagas ficam congeladas como histórico,
     * e saldos transportados de faturas vencidas são lançamentos do sistema (não editáveis).
     *
     * @return \Illuminate\Database\Eloquent\Builder<CreditCardPurchase>
     */
    protected function editablePurchases(): \Illuminate\Database\Eloquent\Builder
    {
        return CreditCardPurchase::whereHas('creditCard', fn ($q) => $q->whereIn('user_id', auth()->user()->familyGroupUserIds()))
            ->whereHas('bill', fn ($q) => $q->where('status', BillStatus::Pendente->value))
            ->whereNull('carried_from_bill_id');
    }

    protected function findEditablePurchase(?int $purchaseId): CreditCardPurchase
    {
        return $this->editablePurchases()->findOrFail($purchaseId);
    }

    public function editPurchase(int $purchaseId): void
    {
        $purchase = $this->findEditablePurchase($purchaseId);

        $this->editingPurchaseId = $purchase->id;
        $this->edit_description = $purchase->description;
        $this->edit_value = (string) $purchase->value;
        $this->edit_purchase_date = $purchase->purchase_date->toDateString();
        $this->edit_category_id = $purchase->category_id;
        $this->edit_notes = $purchase->notes ?? '';
    }

    public function cancelEdit(): void
    {
        $this->reset([
            'editingPurchaseId',
            'edit_description',
            'edit_value',
            'edit_purchase_date',
            'edit_category_id',
            'edit_notes',
        ]);
        $this->resetValidation();
    }

    public function updatePurchase(): void
    {
        $this->validate([
            'edit_description' => 'required|string|max:255',
            'edit_value' => 'required|numeric|min:0.01',
            'edit_purchase_date' => 'required|date',
            'edit_category_id' => [
                'nullable',
                Rule::exists('categories', 'id')->where(fn ($q) => $q->whereIn('user_id', auth()->user()->familyGroupUserIds())),
            ],
            'edit_notes' => 'nullable|string|max:1000',
        ]);

        $purchase = $this->findEditablePurchase($this->editingPurchaseId);
        $dueDate = $purchase->creditCard->invoiceDueDateFor(
            CarbonImmutable::parse($this->edit_purchase_date),
            $purchase->current_installments - 1,
        );

        $targetInvoicePaid = Bill::where('credit_card_id', $purchase->credit_card_id)
            ->whereDate('due_date', $dueDate->toDateString())
            ->where('status', '!=', BillStatus::Pendente->value)
            ->exists();

        if ($targetInvoicePaid) {
            $this->addError('edit_purchase_date', "A fatura com vencimento em {$dueDate->format('d/m/Y')} já foi paga.");

            return;
        }

        $available = $purchase->creditCard->availableLimit();

        if ($available !== null && (float) $this->edit_value - (float) $purchase->value > round($available, 2)) {
            $this->addError('edit_value', 'Limite insuficiente. Disponível: R$ '.number_format(max(0, $available), 2, ',', '.').'.');

            return;
        }

        $purchase->update([
            'description' => $this->edit_description,
            'value' => $this->edit_value,
            'purchase_date' => $this->edit_purchase_date,
            'category_id' => $this->edit_category_id,
            'notes' => $this->edit_notes !== '' ? $this->edit_notes : null,
        ]);

        $this->cancelEdit();
        $this->dispatch('card-purchase-created');
    }

    public function askDelete(int $purchaseId): void
    {
        $purchase = $this->findEditablePurchase($purchaseId);

        $this->deletingPurchaseId = $purchase->id;
        $this->deletingIsInstallment = $purchase->isInstallment();
    }

    public function cancelDelete(): void
    {
        $this->reset(['deletingPurchaseId', 'deletingIsInstallment']);
    }

    public function deletePurchase(): void
    {
        $this->findEditablePurchase($this->deletingPurchaseId)->delete();

        $this->cancelDelete();
        $this->dispatch('card-purchase-created');
    }

    /**
     * Parcela atual + as seguintes do mesmo parcelamento. Parcelas em faturas já pagas nunca são excluídas.
     */
    public function deleteThisAndFuture(): void
    {
        $purchase = $this->findEditablePurchase($this->deletingPurchaseId);

        $this->editablePurchases()
            ->where('installment_group_id', $purchase->installment_group_id)
            ->where('current_installments', '>=', $purchase->current_installments)
            ->get()
            ->each(fn (CreditCardPurchase $installment) => $installment->delete());

        $this->cancelDelete();
        $this->dispatch('card-purchase-created');
    }
};
?>

<div class="glass-panel animate-rise rounded-3xl p-6">
    <h3 class="text-lg font-bold text-foreground mb-4">Compras e Faturas</h3>

    {{-- Filtros --}}
    <div class="mb-4 p-4 bg-line/35 ring-1 ring-line/60 rounded-2xl grid grid-cols-1 md:grid-cols-3 gap-4">
        <flux:select wire:model.live="cardFilter" label="Cartão">
            <flux:select.option value="">Todos</flux:select.option>
            @foreach ($cards as $card)
                <flux:select.option value="{{ $card->id }}">{{ $card->bank }} •••• {{ $card->last_four_digits }}</flux:select.option>
            @endforeach
        </flux:select>

        @if ($selectedCard)
            <flux:select wire:model.live="invoiceFilter" label="Fatura">
                <flux:select.option value="">Todas</flux:select.option>
                @foreach ($invoices as $invoice)
                    <flux:select.option value="{{ $invoice->id }}">
                        Vence {{ $invoice->due_date->format('d/m/Y') }} — {{ $invoice->effective_status->label() }}
                    </flux:select.option>
                @endforeach
            </flux:select>
        @endif
    </div>

    @if ($selectedInvoice)
        <div class="mb-4 grid grid-cols-2 md:grid-cols-4 gap-4 rounded-lg border border-gray-200 p-4 text-sm dark:border-zinc-700">
            <div>
                <p class="text-gray-500 dark:text-gray-400">Total da fatura</p>
                <p class="text-lg font-semibold text-gray-900 dark:text-white">R$ {{ number_format($selectedInvoice->value, 2, ',', '.') }}</p>
            </div>
            <div>
                <p class="text-gray-500 dark:text-gray-400">Fechamento</p>
                <p class="font-medium text-gray-900 dark:text-white">{{ $selectedCard->closingDateForInvoiceDue($selectedInvoice->due_date)->format('d/m/Y') }}</p>
            </div>
            <div>
                <p class="text-gray-500 dark:text-gray-400">Vencimento (útil)</p>
                <p class="font-medium text-gray-900 dark:text-white">{{ $selectedInvoice->actual_due_date->format('d/m/Y') }}</p>
            </div>
            <div>
                <p class="text-gray-500 dark:text-gray-400">Status</p>
                <span class="px-2 py-1 text-xs rounded-full {{ $selectedInvoice->effective_status->badgeClasses() }}">
                    {{ $selectedInvoice->effective_status->label() }}
                </span>
            </div>
        </div>
    @endif

    <div class="overflow-x-auto">
        <table class="w-full text-sm text-left text-gray-500 dark:text-gray-400">
            <thead class="font-mono text-[11px] uppercase tracking-[0.14em] text-muted-foreground bg-line/40">
            <tr>
                <th class="px-6 py-3">Data</th>
                <th class="px-6 py-3">Descrição</th>
                <th class="px-6 py-3">Cartão</th>
                <th class="px-6 py-3">Categoria</th>
                <th class="px-6 py-3">Valor</th>
                <th class="px-6 py-3">Fatura</th>
                <th class="px-6 py-3">Quem lançou</th>
                <th class="px-6 py-3">Ações</th>
            </tr>
            </thead>
            <tbody>
            @forelse ($purchases as $purchase)
                <tr wire:key="purchase-{{ $purchase->id }}" class="border-b border-line/70 transition-colors hover:bg-line/30">
                    <td class="px-6 py-4 whitespace-nowrap">{{ $purchase->purchase_date->format('d/m/Y') }}</td>
                    <td class="px-6 py-4 font-medium text-gray-900 dark:text-white">
                        {{ $purchase->display_description }}
                        @if ($purchase->isCarryOver())
                            <span class="ml-1 px-2 py-0.5 text-xs rounded-full bg-amber-100 text-amber-800 dark:bg-amber-900 dark:text-amber-200">Saldo transportado</span>
                        @endif
                        @if ($purchase->notes)
                            <p class="text-xs font-normal text-gray-400">{{ $purchase->notes }}</p>
                        @endif
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap">{{ $purchase->creditCard->bank }} •••• {{ $purchase->creditCard->last_four_digits }}</td>
                    <td class="px-6 py-4">{{ $purchase->category?->name ?? '—' }}</td>
                    <td class="px-6 py-4 whitespace-nowrap">R$ {{ number_format($purchase->value, 2, ',', '.') }}</td>
                    <td class="px-6 py-4 whitespace-nowrap">
                        {{ $purchase->bill->due_date->format('d/m/Y') }}
                        <span class="ml-1 px-2 py-0.5 text-xs rounded-full {{ $purchase->bill->effective_status->badgeClasses() }}">
                            {{ $purchase->bill->effective_status->label() }}
                        </span>
                    </td>
                    <td class="px-6 py-4">
                        {{ $purchase->user?->name ?? '—' }}
                        @if ($purchase->user_id === auth()->id())
                            <span class="text-xs text-gray-400">(você)</span>
                        @endif
                    </td>
                    <td class="px-6 py-4">
                        @if ($purchase->isCarryOver())
                            <span class="text-xs text-gray-400">Lançamento automático</span>
                        @elseif ($purchase->bill->status === \App\Enums\BillStatus::Pendente)
                            <div class="flex gap-3">
                                <button type="button" wire:click="editPurchase({{ $purchase->id }})" class="text-blue-600 hover:underline dark:text-blue-400">
                                    Editar
                                </button>
                                <button type="button" wire:click="askDelete({{ $purchase->id }})" class="text-red-600 hover:underline dark:text-red-400">
                                    Excluir
                                </button>
                            </div>
                        @else
                            <span class="text-xs text-gray-400">Fatura fechada</span>
                        @endif
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="8" class="px-6 py-4 text-center text-gray-500">
                        Nenhuma compra encontrada.
                    </td>
                </tr>
            @endforelse
            </tbody>
        </table>
    </div>

    {{-- Modal de Edição --}}
    @if ($editingPurchaseId)
        <div class="fixed inset-0 bg-foreground/35 backdrop-blur-sm p-4 flex items-center justify-center z-50" wire:click.self="cancelEdit">
            <div class="modal-panel rounded-3xl p-6 w-full max-w-lg">
                <h3 class="text-lg font-bold text-foreground mb-4">Editar Compra</h3>

                <form wire:submit="updatePurchase" class="space-y-4">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <flux:input wire:model="edit_description" label="Descrição" />
                        <flux:input wire:model="edit_value" label="Valor" type="number" step="0.01" />
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <flux:input wire:model="edit_purchase_date" label="Data da compra" type="date" />

                        <flux:select wire:model="edit_category_id" label="Categoria">
                            <flux:select.option value="">Sem categoria</flux:select.option>
                            @foreach ($categories as $category)
                                <flux:select.option value="{{ $category->id }}">{{ $category->name }}</flux:select.option>
                            @endforeach
                        </flux:select>
                    </div>

                    <flux:input wire:model="edit_notes" label="Observação" />

                    <div class="flex justify-end gap-2 pt-2">
                        <flux:button type="button" variant="ghost" wire:click="cancelEdit">Cancelar</flux:button>
                        <flux:button type="submit" variant="primary">Salvar Alterações</flux:button>
                    </div>
                </form>
            </div>
        </div>
    @endif

    {{-- Modal de Exclusão --}}
    @if ($deletingPurchaseId)
        <div class="fixed inset-0 bg-foreground/35 backdrop-blur-sm p-4 flex items-center justify-center z-50" wire:click.self="cancelDelete">
            <div class="modal-panel rounded-3xl p-6 w-full max-w-md">
                <h3 class="text-lg font-bold text-foreground mb-2">Excluir Compra</h3>
                @if ($deletingIsInstallment)
                    <p class="text-sm text-gray-500 dark:text-gray-400 mb-4">
                        Essa compra é parcelada. Você quer excluir apenas esta parcela, ou esta e todas as parcelas
                        futuras? Parcelas em faturas já pagas não são afetadas.
                    </p>
                    <div class="flex flex-col gap-2">
                        <flux:button variant="danger" wire:click="deletePurchase">Excluir somente esta parcela</flux:button>
                        <flux:button variant="danger" wire:click="deleteThisAndFuture">Excluir esta e as futuras</flux:button>
                        <flux:button variant="ghost" wire:click="cancelDelete">Cancelar</flux:button>
                    </div>
                @else
                    <p class="text-sm text-gray-500 dark:text-gray-400 mb-4">
                        O valor será descontado da fatura. Essa ação não pode ser desfeita.
                    </p>
                    <div class="flex justify-end gap-2">
                        <flux:button variant="ghost" wire:click="cancelDelete">Cancelar</flux:button>
                        <flux:button variant="danger" wire:click="deletePurchase">Excluir</flux:button>
                    </div>
                @endif
            </div>
        </div>
    @endif
</div>
