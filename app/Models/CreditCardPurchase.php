<?php

namespace App\Models;

use Database\Factories\CreditCardPurchaseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class CreditCardPurchase extends Model
{
    /** @use HasFactory<CreditCardPurchaseFactory> */
    use HasFactory;

    protected $fillable = [
        'user_id',
        'credit_card_id',
        'bill_id',
        'category_id',
        'description',
        'value',
        'purchase_date',
        'notes',
        'total_installments',
        'current_installments',
        'installment_group_id',
        'carried_from_bill_id',
    ];

    protected $casts = [
        'purchase_date' => 'date',
        'total_installments' => 'integer',
        'current_installments' => 'integer',
    ];

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<CreditCard, $this>
     */
    public function creditCard(): BelongsTo
    {
        return $this->belongsTo(CreditCard::class);
    }

    /**
     * @return BelongsTo<Bill, $this>
     */
    public function bill(): BelongsTo
    {
        return $this->belongsTo(Bill::class);
    }

    /**
     * @return BelongsTo<Category, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /**
     * Fatura vencida cujo saldo este lançamento transporta (só em lançamentos gerados pelo sistema).
     *
     * @return BelongsTo<Bill, $this>
     */
    public function carriedFromBill(): BelongsTo
    {
        return $this->belongsTo(Bill::class, 'carried_from_bill_id');
    }

    /**
     * @return HasMany<self, $this>
     */
    public function siblings(): HasMany
    {
        return $this->hasMany(self::class, 'installment_group_id', 'installment_group_id');
    }

    public function isInstallment(): bool
    {
        return $this->installment_group_id !== null;
    }

    public function isCarryOver(): bool
    {
        return $this->carried_from_bill_id !== null;
    }

    public function getDisplayDescriptionAttribute(): string
    {
        if (! $this->isInstallment()) {
            return $this->description;
        }

        return "{$this->description} - {$this->current_installments}/{$this->total_installments}";
    }

    /**
     * Divide o valor total em centavos: o resto da divisão vai para a 1ª parcela (ex.: 100,00 em 3x =
     * 33,34 + 33,33 + 33,33).
     *
     * @return list<string>
     */
    public static function splitInstallments(float|string $total, int $installments): array
    {
        $totalCents = (int) round((float) $total * 100);
        $baseCents = intdiv($totalCents, $installments);
        $remainder = $totalCents - ($baseCents * $installments);

        $values = [];

        for ($i = 1; $i <= $installments; $i++) {
            $cents = $baseCents + ($i === 1 ? $remainder : 0);
            $values[] = number_format($cents / 100, 2, '.', '');
        }

        return $values;
    }

    /**
     * Compra parcelada: cria todas as parcelas de uma vez (mesmo padrão de Bill::createRecurrent), cada uma
     * caindo numa fatura seguinte. 'value' em $data é o valor total da compra.
     *
     * @param  array<string, mixed>  $data
     */
    public static function createInstallments(array $data, int $installments): self
    {
        $values = self::splitInstallments($data['value'], $installments);
        $groupId = (string) Str::uuid();

        $first = null;

        foreach ($values as $index => $value) {
            $purchase = self::create(array_merge($data, [
                'value' => $value,
                'total_installments' => $installments,
                'current_installments' => $index + 1,
                'installment_group_id' => $groupId,
            ]));

            $first ??= $purchase;
        }

        return $first;
    }

    /**
     * Aponta bill_id para a fatura do ciclo de purchase_date (criando-a se preciso).
     */
    public function assignInvoice(): void
    {
        $card = CreditCard::findOrFail($this->credit_card_id);

        // Parcela N cai N-1 faturas depois da fatura da data da compra.
        $this->bill()->associate($card->invoiceFor($this->purchase_date, max(0, ($this->current_installments ?? 1) - 1)));
    }

    protected static function booted(): void
    {
        static::saving(function (CreditCardPurchase $purchase) {
            // A fatura é sempre derivada da data/cartão — nunca aceita de fora na criação.
            if (! $purchase->exists || $purchase->isDirty(['purchase_date', 'credit_card_id', 'current_installments'])) {
                $purchase->assignInvoice();
            }
        });

        // O valor da fatura é sempre a soma das suas compras — recalculado a cada alteração, inclusive na
        // fatura de origem quando a compra muda de ciclo.
        static::saved(function (CreditCardPurchase $purchase) {
            Bill::find($purchase->bill_id)?->syncInvoiceTotal();

            if ($purchase->wasChanged('bill_id')) {
                Bill::whereKey($purchase->getOriginal('bill_id'))->first()?->syncInvoiceTotal();
            }
        });

        static::deleted(function (CreditCardPurchase $purchase) {
            Bill::find($purchase->bill_id)?->syncInvoiceTotal();
        });
    }
}
