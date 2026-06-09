<?php

namespace App\Services\Ai\Telemetry\Engine\Stats;

use App\Services\Ai\RuntimeBoundary\StatsEngineRuntimeClient;

/**
 * Wilson score confidence interval for proportions.
 *
 *   center = (p̂ + z²/(2n)) / (1 + z²/n)
 *   margin = z·√(p̂(n-p̂)/n + z²/(4n²)) / (1 + z²/n)
 *
 * Wilson is correct at boundaries (p=0 or p=1) where Wald approximation fails.
 * Min n = 5 (below this even Wilson is meaningless).
 *
 * The arithmetic is NOT done in PHP: per the runtime_language_boundary canon
 * (and "nunca faça em PHP o que deveria ser Python") it is computed in numpy by
 * runtimes/python/stats_engine and verified through StatsEngineRuntimeClient. If
 * the Python runtime is absent the client throws — there is NO PHP fallback math
 * (that would be the boundary violation we removed).
 */
class WilsonCalculator
{
    public const MIN_N = 5;
    public const Z_95 = 1.96;

    public function __construct(
        private readonly StatsEngineRuntimeClient $runtime = new StatsEngineRuntimeClient,
    ) {}

    /**
     * @return array{lower:?float, upper:?float, center:?float, n:int, k:int, method:string}
     */
    public function ci(int $k, int $n, float $z = self::Z_95): array
    {
        /** @var array{lower:?float, upper:?float, center:?float, n:int, k:int, method:string} */
        return $this->runtime->wilson($k, $n, $z);
    }
}
