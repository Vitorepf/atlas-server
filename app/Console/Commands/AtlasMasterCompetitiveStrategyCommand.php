<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Aaeos\Generated\AtlasMasterCompetitiveStrategyService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Operator entry point for the Master Architecture Competitive Strategy decider.
 *
 * Demonstrates the doc's four contracts on safe defaults: the Position
 * classifier (governed upper layer admissible, model-vs-model rejected), the
 * five-step Absorption Loop for a sample capability, the eight-advantage moat
 * enumeration, the Stop-The-Line decision (absorb vs reject-with-evidence) and
 * the direct-usage coverage signal.
 *
 * @see docs/engineering-knowledge-base/master-architecture/competitive-strategy.md
 */
final class AtlasMasterCompetitiveStrategyCommand extends Command
{
    protected $signature = 'atlas:aaeos:master-competitive-strategy {--json : Machine-readable JSON output}';

    protected $description = 'Decide the Atlas Master Competitive Strategy: posture classification, the five-step absorption loop, the eight-advantage moat, and the Stop-The-Line absorb/reject rule.';

    public function handle(AtlasMasterCompetitiveStrategyService $service): int
    {
        try {
            $result = [
                'position_admissible' => $service->classifyPosition(
                    AtlasMasterCompetitiveStrategyService::POSTURE_GOVERNED_UPPER_LAYER,
                ),
                'position_rejected' => $service->classifyPosition(
                    AtlasMasterCompetitiveStrategyService::POSTURE_MODEL_VS_MODEL,
                ),
                'absorption_loop' => $service->resolveAbsorptionLoop('provider_design_mode', true, true),
                'absorption_loop_no_compare_no_evidence' => $service->resolveAbsorptionLoop('provider_voice_mode', false, false),
                'moat' => $service->moat(),
                'stop_the_line_absorb' => $service->evaluateStopTheLine(true, 'absorb'),
                'stop_the_line_reject_with_evidence' => $service->evaluateStopTheLine(true, 'reject', true),
                'stop_the_line_reject_without_evidence' => $service->evaluateStopTheLine(true, 'reject', false),
                'stop_the_line_not_tripped' => $service->evaluateStopTheLine(false, 'absorb'),
                'direct_usage_gap' => $service->assessDirectProviderUsage(true, 'finance_domain_skill'),
            ];
        } catch (Throwable $e) {
            $this->line((string) json_encode([
                'ok' => false,
                'error' => 'exception',
                'message' => $e->getMessage(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::FAILURE;
        }

        $this->line((string) json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }
}
