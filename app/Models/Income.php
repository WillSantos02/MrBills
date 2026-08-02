<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Income extends Model
{
    protected $fillable = [
        'user_id',
        'income_category_id',
        'description',
        'value',
        'date',
        'is_recurrent',
        'total_installments',
        'current_installments',
        'recurrence_group_id',
    ];

    protected $casts = [
        'date' => 'date',
        'is_recurrent' => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(IncomeCategory::class, 'income_category_id');
    }

    /**
     * Todos os lançamentos do mesmo grupo de recorrência.
     */
    public function siblings(): HasMany
    {
        return $this->hasMany(self::class, 'recurrence_group_id', 'recurrence_group_id');
    }

    /**
     * Descrição formatada para exibição, com sufixo de parcela ("Salário - 1/12")
     * quando o lançamento é recorrente. A descrição crua fica em $description.
     */
    public function getDisplayDescriptionAttribute(): string
    {
        if (! $this->is_recurrent) {
            return $this->description;
        }

        return "{$this->description} - {$this->current_installments}/{$this->total_installments}";
    }

    /**
     * Pré-gera todos os lançamentos de uma entrada recorrente de uma só vez,
     * no mesmo padrão de Bill::createRecurrent().
     */
    public static function createRecurrent(array $data): self
    {
        $totalInstallments = max(1, (int) ($data['total_installments'] ?? 1));
        $groupId = (string) Str::uuid();
        $baseDate = Carbon::parse($data['date']);

        $firstIncome = null;

        for ($installment = 1; $installment <= $totalInstallments; $installment++) {
            $installmentDate = $baseDate->copy()->addMonthsNoOverflow($installment - 1);

            $income = self::create(array_merge($data, [
                'date' => $installmentDate->toDateString(),
                'is_recurrent' => true,
                'total_installments' => $totalInstallments,
                'current_installments' => $installment,
                'recurrence_group_id' => $groupId,
            ]));

            if ($installment === 1) {
                $firstIncome = $income;
            }
        }

        return $firstIncome;
    }
}
