<?php

declare(strict_types=1);

namespace App\Services\Ai\Finance\SpotExec;

use App\Services\Ai\Finance\StrategyLoop\Bar;

/**
 * Fonte de mercado do runtime spot (paper E live): barras FECHADAS + mid atual.
 * Interface fina para o runtime ser testável com fakes determinísticos.
 */
interface MarketSource
{
    /**
     * Apenas velas já FECHADAS (a vela em formação é invisível), ascendentes.
     *
     * @return list<Bar>
     */
    public function closedBars(string $symbol, string $interval, int $limit): array;

    /** Mid atual do book (null em falha de rede — o chamador decide o fallback). */
    public function mid(string $symbol): ?float;
}
