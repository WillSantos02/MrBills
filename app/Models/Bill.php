<?php

namespace App\Models;

use App\Enums\BillStatus;
use Carbon\Carbon;
use Database\Factories\BillFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

class Bill extends Model
{
    /** @use HasFactory<BillFactory> */
    use HasFactory;

    protected $fillable = [
        'user_id',
        'description',
        'value',
        'due_date',
        'actual_due_date',
        'is_recurrent',
        'total_installments',
        'current_installments',
        'recurrence_group_id',
        'status',
        'category_id',
        'credit_card_id',
        'last_due_soon_notified_at',
    ];

    protected $casts = [
        'due_date' => 'date',
        'actual_due_date' => 'date',
        'last_due_soon_notified_at' => 'date',
        'is_recurrent' => 'boolean',
        'status' => BillStatus::class,
    ];

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Category, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /**
     * Cartão de crédito, quando esta conta é uma fatura.
     *
     * @return BelongsTo<CreditCard, $this>
     */
    public function creditCard(): BelongsTo
    {
        return $this->belongsTo(CreditCard::class);
    }

    /**
     * Compras de cartão que compõem esta fatura.
     *
     * @return HasMany<CreditCardPurchase, $this>
     */
    public function purchases(): HasMany
    {
        return $this->hasMany(CreditCardPurchase::class);
    }

    /**
     * Lançamento de saldo desta fatura numa fatura seguinte (quando ela venceu sem ser paga).
     *
     * @return HasOne<CreditCardPurchase, $this>
     */
    public function carriedPurchase(): HasOne
    {
        return $this->hasOne(CreditCardPurchase::class, 'carried_from_bill_id');
    }

    public function isInvoice(): bool
    {
        return $this->credit_card_id !== null;
    }

    /**
     * Valor da fatura = soma das compras. Fatura sem nenhuma compra deixa de existir.
     */
    public function syncInvoiceTotal(): void
    {
        if (! $this->purchases()->exists()) {
            $this->delete();

            return;
        }

        $this->update(['value' => $this->purchases()->sum('value')]);
    }

    public function getEffectiveStatusAttribute(): BillStatus
    {
        if ($this->status === BillStatus::Pendente && $this->actual_due_date->isPast()) {
            return BillStatus::Vencido;
        }

        return $this->status;
    }

    public function getDisplayDescriptionAttribute(): string
    {
        if (! $this->is_recurrent) {
            return $this->description;
        }

        return "{$this->description} - {$this->current_installments}/{$this->total_installments}";
    }

    /**
     * @return HasMany<self, $this>
     */
    public function siblings(): HasMany
    {
        return $this->hasMany(self::class, 'recurrence_group_id', 'recurrence_group_id');
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function createRecurrent(array $data): self
    {
        $totalInstallments = max(1, (int) ($data['total_installments'] ?? 1));
        $groupId = (string) Str::uuid();
        $baseDueDate = Carbon::parse($data['due_date']);

        $firstBill = null;

        for ($installment = 1; $installment <= $totalInstallments; $installment++) {
            $installmentDueDate = $baseDueDate->copy()->addMonthsNoOverflow($installment - 1);

            $bill = self::create(array_merge($data, [
                'due_date' => $installmentDueDate->toDateString(),
                'is_recurrent' => true,
                'total_installments' => $totalInstallments,
                'current_installments' => $installment,
                'recurrence_group_id' => $groupId,
            ]));

            if ($installment === 1) {
                $firstBill = $bill;
            }
        }

        return $firstBill;
    }

    protected static function boot()
    {
        parent::boot();

        static::saving(function (Bill $bill) {
            $actualDate = Carbon::parse($bill->due_date);

            if ($actualDate->isWeekend()) {
                $actualDate->next(Carbon::MONDAY);
            }

            $bill->actual_due_date = $actualDate->toImmutable();
        });

        // Fatura renegociada (saldo já transportado pela CreditCard::carryOverOverdueInvoice) que volta a ser
        // Pago/Pendente: o lançamento de saldo na fatura seguinte, se ainda pendente, sai — senão o valor
        // seria cobrado duas vezes.
        static::updated(function (Bill $bill) {
            if (! $bill->isInvoice()
                || ! $bill->wasChanged('status')
                || $bill->getOriginal('status') !== BillStatus::Renegociado) {
                return;
            }

            CreditCardPurchase::where('carried_from_bill_id', $bill->id)
                ->whereHas('bill', fn ($q) => $q->where('status', BillStatus::Pendente->value))
                ->get()
                ->each(fn (CreditCardPurchase $purchase) => $purchase->delete());
        });
    }
}
