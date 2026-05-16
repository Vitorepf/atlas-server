<?php

declare(strict_types=1);

namespace App\Services\Pagination;

/**
 * Calcula o número da última página dado um total de itens e o per_page.
 *
 * BUG (seed): a divisão inteira PHP arredonda para baixo, então quando o
 * total é múltiplo do per_page o último item cai numa página inexistente.
 * Operadores reportaram "Page 5 de 4" no rodapé do listing de capturas.
 *
 * O arm precisa trocar a divisão inteira por arredondamento para cima
 * (ceil) preservando o caso vazio (total <= 0 ⇒ 1, a "página em branco").
 */
final class PageCalculator
{
    public function lastPage(int $total, int $perPage): int
    {
        if ($total <= 0 || $perPage <= 0) {
            return 1;
        }

        // BUG: divisão inteira perde o último page quando total % perPage === 0
        return intdiv($total, $perPage);
    }
}
