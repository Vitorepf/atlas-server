<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Aaeos\Generated\AtlasImplementationPriorityEngineService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Atlas Self-Construction Implementation Priority Engine decider CLI.
 *
 *   php artisan atlas:aaeos:implementation-priority-engine [--json]
 *
 * Read-only and deterministic. Scores two illustrative self-construction
 * candidates against the documented Priority Formula and ranks them. With safe
 * defaults the high-leverage compounding-foundation candidate (inside the
 * Current Strategic Bias, all selection gates met) outranks the visually
 * impressive provider-wrapper candidate (which trips Deprioritize triggers and
 * is forbidden P0).
 *
 * @see docs/engineering-knowledge-base/self-construction/implementation-priority-engine.md
 */
class AtlasImplementationPriorityEngineCommand extends Command
{
    protected $signature = 'atlas:aaeos:implementation-priority-engine {--json : machine-readable JSON output (default true)}';

    protected $description = 'Atlas Self-Construction implementation priority engine: signed Priority Formula scoring with P0..P3 banding, selection gate and deprioritize triggers.';

    public function handle(AtlasImplementationPriorityEngineService $service): int
    {
        try {
            // Safe defaults: a compounding foundation (governed memory work,
            // inside the strategic bias, all gates met) versus a flashy
            // provider-wrapper feature (low leverage, missing gates/research).
            $foundation = [
                'id' => 'governed-memory-retrieval',
                'terms' => [
                    'strategic_leverage' => 18,
                    'dependency_unlocks' => 16,
                    'quality_improvement' => 12,
                    'autonomy_enablement' => 10,
                    'user_value' => 6,
                    'evidence_confidence' => 8,
                    'risk' => 4,
                    'implementation_size' => 6,
                    'uncertainty' => 3,
                    'maintenance_burden' => 2,
                ],
                'in_strategic_bias' => true,
                'selection' => [
                    'unlocks_multiple_downstream' => true,
                    'reduces_future_error' => true,
                    'improves_core' => true,
                    'makes_autonomy_safer' => true,
                    'enough_source_truth' => true,
                    'small_reversible_slice' => true,
                    'gates_available' => true,
                ],
                'unlocks' => ['context-retrieval-quality', 'sdd-runtime'],
                'blocked_by' => [],
                'smallest_safe_slice' => 'add one governed memory write path behind a gate',
            ];

            $flashy = [
                'id' => 'animated-provider-wrapper-panel',
                'terms' => [
                    'strategic_leverage' => 3,
                    'dependency_unlocks' => 1,
                    'quality_improvement' => 1,
                    'autonomy_enablement' => 0,
                    'user_value' => 6,
                    'evidence_confidence' => 1,
                    'risk' => 5,
                    'implementation_size' => 7,
                    'uncertainty' => 6,
                    'maintenance_burden' => 5,
                ],
                'in_strategic_bias' => false,
                'selection' => [
                    'small_reversible_slice' => true,
                    'gates_available' => false,
                ],
                'deprioritize' => [
                    'visually_impressive_low_leverage' => true,
                    'provider_wrapper_driven' => true,
                    'missing_tests_gates' => true,
                ],
                'unlocks' => [],
                'blocked_by' => ['no-gates'],
                'smallest_safe_slice' => '',
            ];

            $ranking = $service->rank([$foundation, $flashy]);

            $this->line((string) json_encode(
                [
                    'ok' => true,
                    'schema_version' => AtlasImplementationPriorityEngineService::SCHEMA_VERSION,
                    'ranking' => $ranking,
                    'p0_foundations' => $service->p0Foundations(),
                    'strategic_bias' => $service->strategicBias(),
                ],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
            ));

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->line((string) json_encode([
                'ok' => false,
                'error' => 'implementation_priority_engine_failed',
                'message' => $e->getMessage(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::FAILURE;
        }
    }
}
