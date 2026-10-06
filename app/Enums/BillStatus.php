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
     * Classes Tailwind para o badge de status (tokens paid/due/late do tema, já adaptados ao dark mode).
     */
    public function badgeClasses(): string
    {
        return match ($this) {
            self::Pendente => 'bg-due/10 text-due ring-1 ring-due/20',
            self::Pago => 'bg-paid/10 text-paid ring-1 ring-paid/20',
            self::Vencido => 'bg-late/10 text-late ring-1 ring-late/20',
            self::Renegociado => 'bg-renegotiated/10 text-renegotiated ring-1 ring-renegotiated/20',
        };
    }
}
