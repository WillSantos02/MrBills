<?php

namespace App\Models\Concerns;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Molde de lançamento fixo (mensal, sem fim) — compartilhado por FixedBill (Despesas) e FixedIncome (Receitas).
 *
 * Diferente de createRecurrent (que gera todas as parcelas de uma vez), não há fim: as ocorrências são geradas
 * numa janela móvel de HORIZON_MONTHS meses à frente, na criação e depois diariamente pelo comando
 * recurrences:generate-fixed. O molde guarda last_generated_occurrence, então excluir uma ocorrência avulsa não
 * faz o gerador recriá-la; apagar o molde encerra a série.
 *
 * @property CarbonImmutable $start_date
 * @property int $last_generated_occurrence
 */
trait GeneratesFixedOccurrences
{
    /** Mês atual + os 11 seguintes ficam sempre gerados. */
    public const HORIZON_MONTHS = 12;

    /**
     * Cria a ocorrência de número $occurrence (1 = primeira) com a data nominal $date.
     */
    abstract protected function createOccurrence(int $occurrence, CarbonImmutable $date, bool $isFirst): void;

    public static function horizon(): CarbonInterface
    {
        return today()->addMonthsNoOverflow(self::HORIZON_MONTHS - 1)->endOfMonth();
    }

    /**
     * Data nominal da n-ésima ocorrência — sempre ancorada em start_date (dia 31 → 28/fev → 31/mar), não na
     * ocorrência anterior, senão o dia "encolheria" para sempre após fevereiro.
     */
    public function dueDateFor(int $occurrence): CarbonImmutable
    {
        return CarbonImmutable::parse($this->start_date)->addMonthsNoOverflow($occurrence - 1);
    }

    /**
     * Gera as ocorrências que faltam até o horizonte. Sempre gera ao menos a primeira (mesmo que comece além
     * da janela). Retorna quantas foram criadas.
     */
    public function generateOccurrences(?CarbonInterface $until = null): int
    {
        $until ??= self::horizon();
        $created = 0;

        while (true) {
            $next = $this->last_generated_occurrence + 1;
            $date = $this->dueDateFor($next);

            if ($next > 1 && $date->greaterThan($until)) {
                break;
            }

            $this->createOccurrence($next, $date, $next === 1);

            $this->last_generated_occurrence = $next;
            $created++;
        }

        $this->save();

        return $created;
    }
}
