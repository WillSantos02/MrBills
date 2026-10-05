<?php

use App\Enums\BillStatus;
use App\Enums\CardColor;
use App\Models\Bill;
use App\Models\CreditCard;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Livewire\Attributes\On;
use Livewire\Component;

new class extends Component
{
    // Edição
    public ?int $editingCardId = null;
    public string $edit_bank = '';
    public string $edit_last_four_digits = '';
    public string $edit_color = '';
    public string $edit_credit_limit = '';
    public string $edit_closing_day = '';
    public string $edit_due_day = '';

    // Exclusão
    public ?int $deletingCardId = null;
    public int $deletingPendingInvoices = 0;

    #[On('credit-card-created')]
    #[On('card-purchase-created')]
    public function refresh(): void
    {
        // Livewire re-renderiza automaticamente o componente ao disparar o listener.
    }

    public function with(): array
    {
        $cards = CreditCard::with('user')
            ->whereIn('user_id', auth()->user()->familyGroupUserIds())
            ->orderBy('bank')
            ->get();

        return [
            'cards' => $cards->map(fn (CreditCard $card) => ['card' => $card] + $card->displaySummary()),
            'colors' => CardColor::cases(),
        ];
    }

    protected function findCard(?int $cardId): CreditCard
    {
        return CreditCard::whereIn('user_id', auth()->user()->familyGroupUserIds())->findOrFail($cardId);
    }

    public function editCard(int $cardId): void
    {
        $card = $this->findCard($cardId);

        $this->editingCardId = $card->id;
        $this->edit_bank = $card->bank;
        $this->edit_last_four_digits = $card->last_four_digits;
        $this->edit_color = $card->color->value;
        $this->edit_credit_limit = (string) $card->credit_limit;
        $this->edit_closing_day = (string) $card->closing_day;
        $this->edit_due_day = (string) $card->due_day;
    }

    public function cancelEdit(): void
    {
        $this->reset([
            'editingCardId',
            'edit_bank',
            'edit_last_four_digits',
            'edit_color',
            'edit_credit_limit',
            'edit_closing_day',
            'edit_due_day',
        ]);
        $this->resetValidation();
    }

    public function updateCard(): void
    {
        $this->validate([
            'edit_bank' => 'required|string|max:100',
            'edit_last_four_digits' => 'required|digits:4',
            'edit_color' => ['required', Rule::enum(CardColor::class)],
            'edit_credit_limit' => 'nullable|numeric|min:0.01|max:99999999',
            'edit_closing_day' => 'required|integer|between:1,31',
            'edit_due_day' => 'required|integer|between:1,31|different:edit_closing_day',
        ], [
            'edit_due_day.different' => 'O vencimento deve ser em um dia diferente do fechamento.',
        ]);

        $card = $this->findCard($this->editingCardId);

        DB::transaction(function () use ($card) {
            $card->update([
                'bank' => $this->edit_bank,
                'last_four_digits' => $this->edit_last_four_digits,
                'color' => $this->edit_color,
                'credit_limit' => $this->edit_credit_limit !== '' ? $this->edit_credit_limit : null,
                'closing_day' => (int) $this->edit_closing_day,
                'due_day' => (int) $this->edit_due_day,
            ]);

            // Novo ciclo/nome: compras de faturas pendentes são redistribuídas e as descrições atualizadas.
            $card->resyncPendingInvoices();
        });

        $this->cancelEdit();
    }

    public function askDelete(int $cardId): void
    {
        $card = $this->findCard($cardId);

        $this->deletingCardId = $card->id;
        $this->deletingPendingInvoices = $card->invoices()->where('status', BillStatus::Pendente->value)->count();
    }

    public function cancelDelete(): void
    {
        $this->reset(['deletingCardId', 'deletingPendingInvoices']);
    }

    public function deleteCard(): void
    {
        $card = $this->findCard($this->deletingCardId);

        DB::transaction(function () use ($card) {
            // Faturas pendentes deixam de fazer sentido sem o cartão; as pagas ficam em Despesas como histórico
            // (credit_card_id vira null via nullOnDelete).
            Bill::where('credit_card_id', $card->id)
                ->where('status', BillStatus::Pendente->value)
                ->delete();

            $card->delete();
        });

        $this->cancelDelete();
    }
};
?>

<div class="p-6 bg-white border border-gray-200 rounded-lg shadow-sm dark:bg-zinc-900 dark:border-zinc-700">
    <h3 class="text-lg font-medium text-gray-900 dark:text-gray-100 mb-4">Meus Cartões</h3>

    <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-3 gap-6">
        @forelse ($cards as $item)
            <div wire:key="card-{{ $item['card']->id }}" class="space-y-2">
                <x-credit-card :card="$item['card']" :total="$item['total']" :closing="$item['closing']" :due="$item['due']"
                               :used="$item['used']" :overdue="$item['overdue']" />

                <div class="flex items-center justify-between text-sm">
                    <span class="text-gray-500 dark:text-gray-400">
                        {{ $item['card']->user?->name ?? '—' }}
                        @if ($item['card']->user_id === auth()->id())
                            <span class="text-xs text-gray-400">(você)</span>
                        @endif
                    </span>
                    <div class="flex gap-3">
                        <button type="button" wire:click="editCard({{ $item['card']->id }})" class="text-blue-600 hover:underline dark:text-blue-400">
                            Editar
                        </button>
                        <button type="button" wire:click="askDelete({{ $item['card']->id }})" class="text-red-600 hover:underline dark:text-red-400">
                            Excluir
                        </button>
                    </div>
                </div>
            </div>
        @empty
            <p class="text-sm text-gray-500 dark:text-gray-400">Nenhum cartão cadastrado ainda.</p>
        @endforelse
    </div>

    {{-- Modal de Edição --}}
    @if ($editingCardId)
        <div class="fixed inset-0 bg-gray-900/50 flex items-center justify-center z-50" wire:click.self="cancelEdit">
            <div class="bg-white dark:bg-gray-800 rounded-lg shadow-lg p-6 w-full max-w-lg">
                <h3 class="text-lg font-medium text-gray-900 dark:text-gray-100 mb-4">Editar Cartão</h3>

                <form wire:submit="updateCard" class="space-y-4">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <flux:input wire:model="edit_bank" label="Banco" />
                        <flux:input wire:model="edit_credit_limit" label="Limite (opcional)" type="number" step="0.01" min="0" />
                    </div>

                    @include('partials.card-color-picker', ['model' => 'edit_color', 'selected' => $edit_color, 'colors' => $colors])

                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                        <flux:input wire:model="edit_last_four_digits" label="Final" inputmode="numeric" maxlength="4" />
                        <flux:input wire:model="edit_closing_day" label="Fechamento" type="number" min="1" max="31" />
                        <flux:input wire:model="edit_due_day" label="Vencimento" type="number" min="1" max="31" />
                    </div>

                    <p class="text-xs text-gray-500">
                        Alterar fechamento/vencimento redistribui as compras das faturas ainda pendentes. Faturas pagas não mudam.
                    </p>

                    <div class="flex justify-end gap-2 pt-2">
                        <flux:button type="button" variant="ghost" wire:click="cancelEdit">Cancelar</flux:button>
                        <flux:button type="submit" variant="primary">Salvar Alterações</flux:button>
                    </div>
                </form>
            </div>
        </div>
    @endif

    {{-- Modal de Exclusão --}}
    @if ($deletingCardId)
        <div class="fixed inset-0 bg-gray-900/50 flex items-center justify-center z-50" wire:click.self="cancelDelete">
            <div class="bg-white dark:bg-gray-800 rounded-lg shadow-lg p-6 w-full max-w-md">
                <h3 class="text-lg font-medium text-gray-900 dark:text-gray-100 mb-2">Excluir Cartão</h3>
                <p class="text-sm text-gray-500 dark:text-gray-400 mb-4">
                    Todas as compras deste cartão serão excluídas.
                    @if ($deletingPendingInvoices > 0)
                        {{ $deletingPendingInvoices === 1 ? 'A fatura pendente também será removida' : "As {$deletingPendingInvoices} faturas pendentes também serão removidas" }}
                        de Despesas.
                    @endif
                    Faturas já pagas continuam em Despesas como histórico. Essa ação não pode ser desfeita.
                </p>
                <div class="flex justify-end gap-2">
                    <flux:button variant="ghost" wire:click="cancelDelete">Cancelar</flux:button>
                    <flux:button variant="danger" wire:click="deleteCard">Excluir</flux:button>
                </div>
            </div>
        </div>
    @endif
</div>
