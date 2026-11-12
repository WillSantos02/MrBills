<?php

namespace App\Models;

use App\Enums\BillStatus;
use App\Models\Concerns\GeneratesFixedOccurrences;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Conta fixa: despesa mensal sem número de parcelas (energia, água, aluguel...). Cada ocorrência é uma Bill
 * comum (is_fixed = true, mesmo recurrence_group_id), então aparece em Despesas, no dashboard e nas
 * notificações sem tratamento especial. Mecânica da janela móvel em GeneratesFixedOccurrences.
 */
class FixedBill extends Model
{
    use GeneratesFixedOccurrences;

    /** Situação da 1ª ocorrência na criação (ex.: o mês corrente já foi pago); só vale dentro de start(). */
    private BillStatus $firstStatus = BillStatus::Pendente;

    protected $fillable = [
        'user_id',
        'category_id',
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
     * @return BelongsTo<Category, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /**
     * @return HasMany<Bill, $this>
     */
    public function bills(): HasMany
    {
        return $this->hasMany(Bill::class, 'recurrence_group_id', 'recurrence_group_id');
    }

    /**
     * Cria a conta fixa e já gera as ocorrências da janela. A primeira recebe $firstStatus; as demais nascem
     * Pendente.
     *
     * @param  array{user_id: int, category_id: ?int, description: string, value: string|float, due_date: string}  $data
     */
    public static function start(array $data, BillStatus $firstStatus = BillStatus::Pendente): self
    {
        return DB::transaction(function () use ($data, $firstStatus) {
            $fixed = self::create([
                'user_id' => $data['user_id'],
                'category_id' => $data['category_id'],
                'description' => $data['description'],
                'value' => $data['value'],
                'start_date' => $data['due_date'],
                'recurrence_group_id' => (string) Str::uuid(),
            ]);

            $fixed->firstStatus = $firstStatus;
            $fixed->generateOccurrences();

            return $fixed;
        });
    }

    protected function createOccurrence(int $occurrence, CarbonImmutable $date, bool $isFirst): void
    {
        Bill::create([
            'user_id' => $this->user_id,
            'category_id' => $this->category_id,
            'description' => $this->description,
            'value' => $this->value,
            'due_date' => $date->toDateString(),
            'status' => ($isFirst ? $this->firstStatus : BillStatus::Pendente)->value,
            'is_recurrent' => true,
            'is_fixed' => true,
            'total_installments' => 0,
            'current_installments' => $occurrence,
            'recurrence_group_id' => $this->recurrence_group_id,
        ]);
    }
}
