<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Concerns;

/**
 * Ordenacao canonica dos payloads de receipt do Loop (ksort SORT_STRING, aceita mixed,
 * preserva listas) — era clonada em 6 ledgers/recorders do AutonomousEvolution.
 * ATENCAO (veto registrado na limpeza 05/07): a semantica SORT_STRING e DIFERENTE do
 * RecursivelyKsortsArrays canonico do SelfConstruction (flags default) e alimenta HASHES
 * de receipt — NUNCA convergir as duas familias; extracao aqui e byte-identica dentro
 * da familia Loop, hash preservado por construcao.
 */
trait SortsReceiptPayloadsCanonically
{
    private function sortRecursive(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(fn (mixed $item): mixed => $this->sortRecursive($item), $value);
        }

        ksort($value, SORT_STRING);
        $sorted = [];
        foreach ($value as $key => $item) {
            $sorted[$key] = $this->sortRecursive($item);
        }

        return $sorted;
    }
}
