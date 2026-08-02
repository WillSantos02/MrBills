<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Category extends Model
{
    protected $fillable = [
        'user_id',
        'name',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function bills(): HasMany
    {
        return $this->hasMany(Bill::class);
    }

    public function scopeWithTotals(Builder $query): Builder
    {
        return $query
            ->withSum('bills as total_geral', 'value')
            ->withSum(['bills as total_mes_atual' => function (Builder $query) {
                $query->whereYear('due_date', now()->year)
                    ->whereMonth('due_date', now()->month);
            }], 'value');
    }
}
