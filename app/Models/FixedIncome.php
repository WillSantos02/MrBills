<?php

namespace App\Models;

use App\Models\Concerns\GeneratesFixedOccurrences;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Receita fixa: entrada mensal sem número de meses (salário, aluguel recebido...). Cada ocorrência é uma Income
 * comum (is_fixed = true, mesmo recurrence_group_id). Mecânica da janela móvel em GeneratesFixedOccurrences.
 */
class FixedIncome extends Model
{
    use GeneratesFixedOccurrences;

    protected $fillable = [
        'user_id',
        'income_category_id',
        'description',
        'value',
        'start_date',
        'recurrence_group_id',
        'last_generated_occurrence',
    ];

    protected $casts = [
        'start_date' => 'date',
        'last_generated_occurrence' => 'integer',
    ];

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<IncomeCategory, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(IncomeCategory::class, 'income_category_id');
    }

    /**
     * @return HasMany<Income, $this>
     */
    public function incomes(): HasMany
    {
        return $this->hasMany(Income::class, 'recurrence_group_id', 'recurrence_group_id');
    }

    /**
     * @param  array{user_id: int, income_category_id: ?int, description: string, value: string|float, date: string}  $data
     */
    public static function start(array $data): self
    {
        return DB::transaction(function () use ($data) {
            $fixed = self::create([
                'user_id' => $data['user_id'],
                'income_category_id' => $data['income_category_id'],
                'description' => $data['description'],
                'value' => $data['value'],
                'start_date' => $data['date'],
                'recurrence_group_id' => (string) Str::uuid(),
            ]);

            $fixed->generateOccurrences();

            return $fixed;
        });
    }

    protected function createOccurrence(int $occurrence, CarbonImmutable $date, bool $isFirst): void
    {
        Income::create([
            'user_id' => $this->user_id,
            'income_category_id' => $this->income_category_id,
            'description' => $this->description,
            'value' => $this->value,
            'date' => $date->toDateString(),
            'is_recurrent' => true,
            'is_fixed' => true,
            'total_installments' => 0,
            'current_installments' => $occurrence,
            'recurrence_group_id' => $this->recurrence_group_id,
        ]);
    }
}
