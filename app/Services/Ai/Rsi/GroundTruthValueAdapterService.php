<?php

declare(strict_types=1);

namespace App\Services\Ai\Rsi;

use App\Services\Ai\Mission\MissionCanonicalHash;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\Rsi\ComponentValueLedgerService;

/**
 * Governed RSI · Part 3 · GroundTruthValueAdapter — the single real-signal
 * boundary.
 *
 * "Value" for a loop component must come from REALITY, not from an internal
 * proxy a self-improvement could game. This adapter is the ONE place that maps
 * the loop's real telemetry into a value scalar; everything downstream consumes
 * its fold, never raw signals. It folds ONLY numbers already produced by the
 * live loop and recorded in append-only ledgers:
 *
 *   - the target component's value-per-token from the append-only
 *     {@see ComponentValueLedgerService} (proven outcome delta per real token,
 *     measured-or-reverted — never invented);
 *   - the real-world OPERATOR-ACCEPTANCE signal via {@see OperatorAcceptanceSignalPort}
 *     (an explicit human confirmation recorded in the append-only evidence
 *     store; live telemetry is out of scope by design).
 *
 * Honesty is structural and matches the M keystone:
 *   - The adapter NEVER asks a provider to score a component.
 *   - A missing signal yields a NULL value (signal_available=false), NEVER a
 *     fabricated or zero-imputed number.
 *   - It NEVER merges, reverts, canonizes or auto-applies; it reads and folds.
 *
 * It exposes the post-merge ground-truth value-per-token a self-improvement to a
 * component must have RAISED for the meta measured-or-reverted authority
 * ({@see RsiOutcomeMaterializerService}) to consolidate rather than revert.
 */
final class GroundTruthValueAdapterService
{
    public const SCHEMA = 'atlas.rsi.ground_truth_value.v1';

    public const SIGNAL_COMPONENT_VALUE_PER_TOKEN = 'component_value_per_token';

    public function __construct(
        private readonly ComponentValueLedgerService $componentValueLedger,
        private readonly OperatorAcceptanceSignalPort $operatorAcceptance,
    ) {}

    /**
     * Fold the REAL ground-truth value-per-token for a component AFTER a merge.
     *
     * Reads the append-only ComponentValueLedger (optionally over injected
     * $records for a deterministic test seam — no I/O) and the real-world
     * operator-acceptance signal for the self-improvement merge. The returned
     * `value` is the component's current proven value-per-token, or NULL when no
     * proven signal exists (never fabricated).
     *
     * @param  array<string,mixed>  $context  area_id/focus/component_id/merge_hash;
     *                                        optional `records` injected ledger events
     * @return array<string,mixed> atlas.rsi.ground_truth_value.v1
     */
    public function groundTruthValue(array $context): array
    {
        $areaId = (string) ($context['area_id'] ?? 'agentic_engineering_os');
        $focus = (string) ($context['focus'] ?? 'dev_forge');
        $componentId = (string) ($context['component_id'] ?? '');
        $mergeHash = (string) ($context['merge_hash'] ?? '');
        $records = is_array($context['records'] ?? null) ? $context['records'] : null;

        $stats = $this->componentValueLedger->valuePerTokenByComponent($areaId, $focus, $records);
        $componentStats = $stats[$componentId] ?? null;

        $value = null;
        $provenCycles = 0;
        $totalTokens = 0;
        $signalAvailable = false;
        if (is_array($componentStats) && $componentStats['value_per_token'] !== null && (int) $componentStats['proven_cycles'] > 0) {
            // Real proven value-per-token folded from the append-only ledger.
            $value = (float) $componentStats['value_per_token'];
            $provenCycles = (int) $componentStats['proven_cycles'];
            $totalTokens = (int) $componentStats['total_tokens'];
            $signalAvailable = true;
        }

        // Real-world boundary: explicit operator acceptance of the merged
        // self-improvement. NEVER a provider score; missing => not accepted.
        $acceptance = $this->operatorAcceptance->isAcceptedByOperator($mergeHash, $componentId);

        $payload = [
            'schema_version' => self::SCHEMA,
            'area_id' => $areaId,
            'focus' => $focus,
            'component_id' => $componentId,
            'merge_hash' => $mergeHash,
            'signal_type' => self::SIGNAL_COMPONENT_VALUE_PER_TOKEN,
            'signal_available' => $signalAvailable,
            'value' => $value,
            'proven_cycles' => $provenCycles,
            'total_tokens' => $totalTokens,
            'operator_accepted' => (bool) $acceptance['accepted'],
            'operator_acceptance_reason' => (string) $acceptance['reason'],
            'operator_acceptance_evidence_ref' => $acceptance['evidence_ref'],
            'operator_acceptance_confidence' => $acceptance['confidence'],
            // The adapter folds existing numbers; it never asks a provider.
            'provider_invoked' => false,
        ];

        $payload['ground_truth_hash'] = MissionCanonicalHash::sha256([
            'area_id' => $areaId,
            'focus' => $focus,
            'component_id' => $componentId,
            'merge_hash' => $mergeHash,
            'value' => $value,
            'signal_available' => $signalAvailable,
            'operator_accepted' => (bool) $acceptance['accepted'],
        ]);

        return $payload;
    }
}
