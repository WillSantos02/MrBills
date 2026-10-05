<?php

namespace App\Enums;

enum CardColor: string
{
    case Roxo = 'roxo';
    case Azul = 'azul';
    case Preto = 'preto';
    case Laranja = 'laranja';
    case Verde = 'verde';
    case Vermelho = 'vermelho';

    public function label(): string
    {
        return ucfirst($this->value);
    }
}
