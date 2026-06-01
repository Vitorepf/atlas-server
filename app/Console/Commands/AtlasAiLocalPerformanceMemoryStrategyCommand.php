<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Aaeos\Generated\AtlasAiLocalPerformanceMemoryStrategyService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Atlas AI Local Performance Memory Strategy CLI.
 *
 *   php artisan atlas:aaeos:ai-local-performance-memory-strategy
 *     [--total-ram=48]   // measured/assumed total RAM in GB for the reservation plan
 *     [--pressure=2]     // memory-pressure steps 0..4 for the degradation ladder
 *     [--json]
 *
 * Read-only, deterministic. With no flags it runs the strategy self-check at the
 * documented 48GB baseline: the memory pyramid (a legal filtered step and a
 * rejected raw L3->L0 jump), the degradation ladder, the RAM reservation plan,
 * the eight Context Pack quality gates all green and the local-model boundary.
 * It NEVER touches the database.
 *
 * @see docs/engineering-knowledge-base/atlas-ai-local-performance-memory-strategy.md
 */
class AtlasAiLocalPerformanceMemoryStrategyCommand extends Command
{
    protected $signature = 'atlas:aaeos:ai-local-performance-memory-strategy
        {--total-ram= : total RAM in GB for the reservation plan (default 48)}
        {--pressure= : memory-pressure steps 0..4 for the degradation ladder (default 2)}
        {--json : machine-readable JSON output (default true)}';

    protected $description = 'Atlas local performance memory strategy · pyramid promotion, degradation ladder, 48GB reservation, 8 quality gates and the local-model boundary.';

    public function handle(AtlasAiLocalPerformanceMemoryStrategyService $service): int
    {
        try {
            $totalRamOption = $this->strOption('total-ram');
            $totalRam = $totalRamOption !== null
                ? (int) $totalRamOption
                : AtlasAiLocalPerformanceMemoryStrategyService::DEFAULT_TOTAL_RAM_GB;

            $result = $service->report($totalRam);

            $pressureOption = $this->strOption('pressure');
            if ($pressureOption !== null) {
                $result['degradation'] = $service->degradationPlan((int) $pressureOption);
            }

            $this->line((string) json_encode(
                ['ok' => true, 'result' => $result],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
            ));

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->line((string) json_encode([
                'ok' => false,
                'error' => 'local_performance_memory_strategy_failed',
                'message' => $e->getMessage(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::FAILURE;
        }
    }

    private function strOption(string $option): ?string
    {
        $raw = $this->option($option);

        return is_string($raw) && trim($raw) !== '' ? trim($raw) : null;
    }
}
