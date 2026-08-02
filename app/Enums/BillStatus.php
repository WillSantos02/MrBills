<?php

namespace App\Enums;

enum BillStatus: int
{
    case Pendente = 1;
    case Pago = 2;
    case Vencido = 3;
    case Renegociado = 4;

    public function label(): string
    {
        return match ($this) {
            self::Pendente => 'Pendente',
            self::Pago => 'Pago',
            self::Vencido => 'Vencido',
            self::Renegociado => 'Renegociado',
        };
    }

    /**
     * Classes Tailwind para o badge de status (já com suporte a dark mode).
     */
    public function badgeClasses(): string
    {
        return match ($this) {
            self::Pendente => 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900 dark:text-yellow-200',
            self::Pago => 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200',
            self::Vencido => 'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-200',
            self::Renegociado => 'bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-200',
        };
    }
}
