<?php

declare(strict_types=1);

namespace App\Services\Ai\Finance\StrategyLoop\Strategy;

use App\Services\Ai\Finance\StrategyLoop\Bar;

interface StrategyRunner
{
    /**
     * @param  list<Bar>  $bars
     * @param  array<string,mixed>  $params
     */
    public function run(array $bars, array $params): StrategyResult;
}
