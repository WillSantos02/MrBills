<?php

namespace App\Models;

use Database\Factories\IncomeCategoryFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class IncomeCategory extends Model
{
    /** @use HasFactory<IncomeCategoryFactory> */
    use HasFactory;

    protected $fillable = [
        'user_id',
        'name',
    ];

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<Income, $this>
     */
    public function incomes(): HasMany
    {
        return $this->hasMany(Income::class);
    }

    /**
     * Mesmo padrão de Category::scopeWithTotals — evita N+1 ao listar.
     * Disponibiliza $incomeCategory->total_geral e $incomeCategory->total_mes_atual.
     *
     * @param  Builder<IncomeCategory>  $query
     * @return Builder<IncomeCategory>
     */
    public function scopeWithTotals(Builder $query): Builder
    {
        return $query
            ->withSum('incomes as total_geral', 'value')
            ->withSum(['incomes as total_mes_atual' => function (Builder $query) {
                $query->whereYear('date', now()->year)
                    ->whereMonth('date', now()->month);
            }], 'value');
    }
}
