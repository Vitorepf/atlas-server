<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainAmplifierPromotionGate;
use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainAmplifierTelemetryAggregator;
use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainCompressionCadencePlanningRunner;
use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainModelEscalationEconomyPolicy;
use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainValueGateBacktestReplay;
use Illuminate\Console\Command;
use App\Console\Concerns\EmitsCanonicalJson;

/**
 * Read-only operator entry point for model-amplifier economics: aggregates telemetry
 * ({@see AtlasExternalBrainAmplifierTelemetryAggregator}), decides escalation vs. scaffold
 * vs. defer ({@see AtlasExternalBrainModelEscalationEconomyPolicy}), gates shadow→live
 * promotion ({@see AtlasExternalBrainAmplifierPromotionGate}), and replays historical
 * outcomes through the value gate ({@see AtlasExternalBrainValueGateBacktestReplay}) —
 * before the amplifier is trusted to expand its autonomy footprint.
 *
 * Never mutates files, calls providers, or runs git — read-only reporting only.
 *
 * Input: a single JSON file (--input=PATH) with keys:
 *   { telemetry:{...}, escalation:{...}, promotion:{...}, replay:{...} }
 * Missing/absent sections default to empty and produce a conservative report.
 */
final class AtlasExternalBrainAmplifierEconomicsCommand extends Command
{
    use EmitsCanonicalJson;

    /** @var string */
    protected $signature = 'atlas:external-brain:amplifier-economics
        {--input= : Path to a JSON file with telemetry, escalation, promotion and replay sections}';

    /** @var string */
    protected $description = 'Read-only model-amplifier economics report: telemetry health, escalation policy, shadow-to-live promotion gate, and value-gate backtest replay.';

    public function handle(
        AtlasExternalBrainAmplifierTelemetryAggregator $telemetryAggregator,
        AtlasExternalBrainModelEscalationEconomyPolicy $escalationPolicy,
        AtlasExternalBrainAmplifierPromotionGate $promotionGate,
        AtlasExternalBrainValueGateBacktestReplay $backtestReplay,
        AtlasExternalBrainCompressionCadencePlanningRunner $compressionRunner,
    ): int {
        $inputPath = trim((string) $this->option('input'));
        if ($inputPath === '' || ! is_file($inputPath)) {
            $this->error('--input=<path> required and must exist');

            return self::FAILURE;
        }

        $decoded = json_decode((string) file_get_contents($inputPath), true);
        if (! is_array($decoded)) {
            $this->error('invalid input JSON');

            return self::FAILURE;
        }

        $telemetryInput = is_array($decoded['telemetry'] ?? null) ? $decoded['telemetry'] : [];
        $escalationInput = is_array($decoded['escalation'] ?? null) ? $decoded['escalation'] : [];
        $promotionInput = is_array($decoded['promotion'] ?? null) ? $decoded['promotion'] : [];
        $replayInput = is_array($decoded['replay'] ?? null) ? $decoded['replay'] : [];
        $compressionInput = is_array($decoded['compression'] ?? null) ? $decoded['compression'] : [];

        $telemetry = $telemetryAggregator->aggregate($telemetryInput);
        $escalation = $escalationPolicy->decide($escalationInput);
        $promotion = $promotionGate->evaluate($promotionInput);
        $replay = $backtestReplay->replay($replayInput);
        $compressionPlan = $compressionRunner->plan($compressionInput);

        $rollbackCandidate = $telemetry['status'] === AtlasExternalBrainAmplifierTelemetryAggregator::STATUS_ROLLBACK_CANDIDATE;
        $safeToExpandAutonomy = ! $rollbackCandidate
            && (bool) $promotion['promote']
            && $escalation['decision'] !== AtlasExternalBrainModelEscalationEconomyPolicy::DECISION_DEFER_FOR_MORE_EVIDENCE;

        $payload = [
            'status' => 'ok',
            'telemetry' => $telemetry,
            'escalation_decision' => $escalation,
            'promotion_gate' => $promotion,
            'backtest_replay' => $replay,
            'compression_plan' => $compressionPlan,
            'rollback_candidate' => $rollbackCandidate,
            'safe_to_expand_autonomy_footprint' => $safeToExpandAutonomy,
        ];

        $this->line($this->encode($payload));

        return self::SUCCESS;
    }
}
