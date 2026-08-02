<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class IncomeCategory extends Model
{
    protected $fillable = [
        'user_id',
        'name',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function incomes(): HasMany
    {
        return $this->hasMany(Income::class);
    }

    /**
     * Mesmo padrão de Category::scopeWithTotals — evita N+1 ao listar.
     * Disponibiliza $incomeCategory->total_geral e $incomeCategory->total_mes_atual.
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
