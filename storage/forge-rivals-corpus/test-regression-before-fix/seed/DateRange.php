<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Intervalo fechado [start, end] entre dois timestamps Unix.
 *
 * BUG (seed): `contains()` usa `<=` no limite superior mas inclui também
 * o instante imediatamente após o end por causa de um cálculo derivado
 * que adiciona um segundo. O comportamento esperado é fechado em `end`
 * exato: o que cai depois de `end` é fora.
 */
final class DateRange
{
    public function __construct(
        private readonly int $start,
        private readonly int $end,
    ) {}

    public function contains(int $timestamp): bool
    {
        if ($timestamp < $this->start) {
            return false;
        }
        // BUG: estende o end em +1 silenciosamente, incluindo um instante extra.
        return $timestamp <= ($this->end + 1);
    }
}
