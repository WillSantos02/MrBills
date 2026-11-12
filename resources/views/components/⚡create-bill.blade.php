<?php

use App\Enums\BillStatus;
use App\Models\Bill;
use App\Models\Category;
use App\Models\FixedBill;
use Illuminate\Validation\Rule;
use Livewire\Component;

new class extends Component
{
    public string $description = '';
    public string $value = '';
    public string $due_date = '';
    public ?int $category_id = null;

    /** unica | parcelada | fixa */
    public string $kind = 'unica';
    public int $total_installments = 2;

    /** Situação no momento do cadastro: Pendente (a pagar) ou Pago (gasto pontual, Pix/débito na hora). */
    public int $status = 1;

    public function mount(): void
    {
        $this->status = BillStatus::Pendente->value;
    }

    public function updatedStatus(): void
    {
        // Gasto já pago quase sempre é de hoje — preenche a data se o usuário ainda não escolheu nenhuma.
        if ((int) $this->status === BillStatus::Pago->value && $this->due_date === '') {
            $this->due_date = today()->toDateString();
        }
    }

    public function with(): array
    {
        return [
            'categories' => Category::whereIn('user_id', auth()->user()->familyGroupUserIds())
                ->orderBy('name')
                ->get(),
        ];
    }

    public function save(): void
    {
        $rules = [
            'description' => 'required|string|max:255',
            'value' => 'required|numeric|min:0.01',
            'due_date' => 'required|date',
            'category_id' => [
                'nullable',
                Rule::exists('categories', 'id')->where(fn ($q) => $q->whereIn('user_id', auth()->user()->familyGroupUserIds())),
            ],
            'kind' => ['required', Rule::in(['unica', 'parcelada', 'fixa'])],
            'status' => ['required', 'integer', Rule::in([BillStatus::Pendente->value, BillStatus::Pago->value])],
        ];

        if ($this->kind === 'parcelada') {
            $rules['total_installments'] = 'required|integer|min:2|max:360';
        }

        $this->validate($rules);

        $status = BillStatus::from((int) $this->status);

        $data = [
            'user_id' => auth()->id(),
            'description' => $this->description,
            'value' => $this->value,
            'due_date' => $this->due_date,
            'category_id' => $this->category_id,
        ];

        match ($this->kind) {
            // Só a 1ª ocorrência herda a situação escolhida (ex.: 1ª parcela paga na loja); as próximas nascem a pagar.
            'parcelada' => $this->createInstallments($data, $status),
            'fixa' => FixedBill::start($data, $status),
            default => Bill::create(array_merge($data, [
                'status' => $status->value,
                'is_recurrent' => false,
                'is_spot' => $status === BillStatus::Pago,
                'total_installments' => 1,
                'current_installments' => 1,
            ])),
        };

        $this->reset(['description', 'value', 'due_date', 'category_id', 'kind', 'total_installments']);
        $this->status = BillStatus::Pendente->value;

        $this->dispatch('bill-created');
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function createInstallments(array $data, BillStatus $firstStatus): void
    {
        $first = Bill::createRecurrent(array_merge($data, [
            'status' => BillStatus::Pendente->value,
            'total_installments' => $this->total_installments,
        ]));

        if ($firstStatus !== BillStatus::Pendente) {
            $first->update(['status' => $firstStatus->value]);
        }
    }
};
?>

<div class="glass-panel animate-rise rounded-3xl p-6">
    <h3 class="text-lg font-bold text-foreground mb-4">Nova Conta</h3>

    @php($isPaid = (int) $status === \App\Enums\BillStatus::Pago->value)

    <form wire:submit="save" class="space-y-4">
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <flux:radio.group wire:model.live="kind" label="Tipo de conta" variant="segmented">
                <flux:radio value="unica" label="Única" icon="document-text" />
                <flux:radio value="parcelada" label="Parcelada" icon="queue-list" />
                <flux:radio value="fixa" label="Fixa" icon="arrow-path" />
            </flux:radio.group>

            <flux:radio.group wire:model.live="status" label="Situação" variant="segmented">
                <flux:radio value="{{ \App\Enums\BillStatus::Pendente->value }}" label="A pagar" icon="clock" />
                <flux:radio value="{{ \App\Enums\BillStatus::Pago->value }}" label="Já pago" icon="check-circle" />
            </flux:radio.group>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <flux:input wire:model="description" label="Descrição"
                        :placeholder="$isPaid && $kind === 'unica' ? 'Ex: Padaria, Pix para fulano...' : ($kind === 'fixa' ? 'Ex: Energia, Água, Aluguel...' : 'Ex: Aluguel, Internet...')" />
            <flux:input wire:model="value" label="Valor" type="number" step="0.01" placeholder="0,00" />
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <flux:input wire:model="due_date" type="date"
                        :label="$isPaid && $kind === 'unica' ? 'Data do Gasto' : ($kind === 'unica' ? 'Data de Vencimento' : 'Vencimento da 1ª Conta')" />

            <flux:select wire:model="category_id" label="Categoria">
                <flux:select.option value="">Sem categoria</flux:select.option>
                @foreach ($categories as $category)
                    <flux:select.option value="{{ $category->id }}">{{ $category->name }}</flux:select.option>
                @endforeach
            </flux:select>
        </div>

        @if ($kind === 'parcelada')
            <div class="md:w-1/2">
                <flux:input
                    wire:model="total_installments"
                    label="Número de Parcelas"
                    type="number"
                    min="2"
                    max="360"
                />
                <p class="text-xs text-muted-foreground mt-1">
                    Todas as parcelas serão criadas agora, com vencimento mensal a partir da data informada.
                    @if ($isPaid) Só a 1ª parcela entra como paga. @endif
                </p>
            </div>
        @elseif ($kind === 'fixa')
            <p class="text-xs text-muted-foreground">
                Conta que se repete todo mês, sem data para acabar (energia, água, aluguel...). Os próximos
                {{ \App\Models\FixedBill::HORIZON_MONTHS }} meses ficam sempre lançados; para encerrar, exclua uma
                ocorrência escolhendo "esta e as futuras".
                @if ($isPaid) Só a 1ª entra como paga. @endif
            </p>
        @elseif ($isPaid)
            <p class="text-xs text-muted-foreground">
                Gasto pontual já pago (Pix, débito, dinheiro): entra como Pago na data do gasto, sem ajuste de fim de semana.
            </p>
        @endif

        <div>
            <flux:button type="submit" variant="primary">{{ $isPaid && $kind === 'unica' ? 'Registrar Gasto' : 'Salvar Conta' }}</flux:button>
        </div>
    </form>
</div>
