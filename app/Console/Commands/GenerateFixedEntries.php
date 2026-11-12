<?php

namespace App\Console\Commands;

use App\Models\FixedBill;
use App\Models\FixedIncome;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class GenerateFixedEntries extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'recurrences:generate-fixed';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Keep fixed (open-ended monthly) bills and incomes generated up to the rolling horizon';

    public function handle(): int
    {
        $bills = $this->generate(FixedBill::class);
        $incomes = $this->generate(FixedIncome::class);

        $this->info("Generated {$bills} fixed bill and {$incomes} fixed income occurrence(s).");

        return self::SUCCESS;
    }

    /**
     * @param  class-string<FixedBill|FixedIncome>  $model
     */
    private function generate(string $model): int
    {
        $horizon = $model::horizon();
        $count = 0;

        $model::query()->orderBy('id')->pluck('id')->each(function (int $id) use ($model, $horizon, &$count) {
            DB::transaction(function () use ($model, $id, $horizon, &$count) {
                // Travado: rodar em paralelo (job atrasado + execução manual) não pode gerar o mesmo mês duas vezes.
                /** @var FixedBill|FixedIncome|null $fixed */
                $fixed = $model::whereKey($id)->lockForUpdate()->first();

                if ($fixed instanceof Model) {
                    $count += $fixed->generateOccurrences($horizon);
                }
            });
        });

        return $count;
    }
}
