<?php

namespace App\Models;

use App\Enums\BillStatus;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Bill extends Model
{
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
    ];

    protected $casts = [
        'due_date' => 'date',
        'actual_due_date' => 'date',
        'is_recurrent' => 'boolean',
        'status' => BillStatus::class,
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function getEffectiveStatusAttribute(): BillStatus
    {
        if ($this->status === BillStatus::Pendente
            && $this->actual_due_date
            && $this->actual_due_date->isPast()) {
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

    public function siblings(): HasMany
    {
        return $this->hasMany(self::class, 'recurrence_group_id', 'recurrence_group_id');
    }

    public static function createRecurrent(array $data): self
    {
        $totalInstallments = max(1, (int) ($data['total_installments'] ?? 1));
        $groupId = (string) \Illuminate\Support\Str::uuid();
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
            if ($bill->due_date) {
                $actualDate = Carbon::parse($bill->due_date);

                if ($actualDate->isWeekend()) {
                    $actualDate->next(Carbon::MONDAY);
                }

                $bill->actual_due_date = $actualDate->toDateString();
            }
        });
    }
}
