<?php

declare(strict_types=1);

namespace App\Services\Ai\Cognitive\PredictiveFailure;

use App\Services\Ai\Kernel\Evidence\AtlasEvidenceLedger;
use App\Services\Ai\Kernel\Evidence\LedgerEventType;
use Illuminate\Support\Str;
use Ramsey\Uuid\Uuid;
use Throwable;

/**
 * L6-11 — the wiring that makes atlas:predict calibration compute on REAL data.
 *
 * The cognitive predictive-failure pipeline (estimate -> insert -> resolve -> metrics)
 * was fully built and CLI-proven, but in production total_insertions=0 because the only
 * producer was the manual `atlas:predict failure` CLI. Nothing recorded a prediction on
 * a real loop/dev event and reconciled its outcome, so brier_score/avg_calibration_error
 * stayed null forever.
 *
 * This bridge closes that gap. On a real loop GRIND of a durable task it:
 *   1. forms a real prediction (predicted failure probability for THIS task, from the
 *      same FailureProbabilityEstimator the cognitive flow uses) BEFORE the outcome, and
 *   2. reconciles the observed outcome (winner => success, no_winner/failed => failure)
 *      into the SAME predictive_failure_insertions table the metrics service reads.
 *
 * Governance (deliberately distinct from the cognitive LEARNING surface):
 *   - This is loop TELEMETRY, not a learning-curriculum insertion. It is NOT routed
 *     through the learning-surface calibration-band gate (which forces the 0.70-0.85
 *     "sweet" band) — a loop task's predicted failure probability is whatever the model
 *     says, recorded honestly. It is tagged source_type=loop_grind_outcome, domain
 *     programming, so it never pollutes the learning history.
 *   - It is flag-gated, DEFAULT OFF. Turning it on is the operator's deliberate decision
 *     to let the live loop feed the predictive calibration surface.
 *   - It is fail-open: any error recording telemetry is swallowed and ledger-noted; it
 *     NEVER crashes a grind. It writes telemetry only — no code, no merge, no proposal
 *     mutation — so the never-merge / governed-merge invariants are untouched.
 */
final class AtlasLoopPredictiveOutcomeBridge
{
    public const SCHEMA_VERSION = 'atlas.cognitive.predictive_failure.loop_outcome_bridge.v1';

    public const SOURCE_TYPE = 'loop_grind_outcome';

    public function __construct(
        private readonly FailureProbabilityEstimator $estimator,
        private readonly CalibrationBandClassifier $bands,
        private readonly PredictiveFailureRepository $repository,
        private readonly AtlasEvidenceLedger $ledger,
    ) {}

    /**
     * Record a prediction on a real loop grind event and reconcile its observed outcome.
     *
     * @param  array<string,mixed>  $grindResult  the grinder result (status/has_winner/...)
     * @param  array<string,mixed>  $context      objective/target_path/signals/domain hints
     * @return array<string,mixed>
     */
    public function recordGrind(array $grindResult, array $context = []): array
    {
        if (! $this->enabled()) {
            return $this->skipped('loop_predictive_bridge_disabled');
        }

        try {
            if (! $this->repository->tableReady()) {
                return $this->skipped('predictive_failure_storage_unavailable');
            }

            $status = trim((string) ($grindResult['status'] ?? ''));
            // Only terminal outcomes are reconcilable telemetry. Backpressure means the
            // task never ran (no observed outcome) — recording it would be noise.
            $outcome = $this->outcomeForStatus($status, (bool) ($grindResult['has_winner'] ?? false));
            if ($outcome === null) {
                return $this->skipped('loop_grind_outcome_not_reconcilable', ['grind_status' => $status]);
            }

            $domain = $this->resolveDomain($context);
            $signals = $this->resolveSignals($grindResult, $context);
            $estimate = $this->estimator->estimate(['signals_used' => $signals]);
            $probability = (float) $estimate['predicted_failure_probability'];
            $band = $this->bands->classify($probability)['band'];
            $signatureKey = $this->signatureKey($outcome, $context, $grindResult);

            $insertion = $this->repository->recordLoopOutcome([
                'envelope_id' => (string) Str::uuid(),
                'target_knowledge_node_id' => $this->targetNodeId($context),
                'domain' => $domain,
                'signals_used' => $signals,
                'predicted_failure_probability' => $probability,
                'calibration_band' => $band,
                'predicted_failure_signature_key' => $signatureKey,
                'problem_payload' => [
                    'schema_version' => self::SCHEMA_VERSION,
                    'kind' => 'loop_grind_outcome',
                    'objective' => (string) ($context['objective'] ?? ''),
                    'target_path' => (string) ($context['target_path'] ?? ''),
                    'grind_status' => $status,
                    'proposals' => (int) ($grindResult['proposals'] ?? 0),
                    'scenarios_explored' => (int) ($grindResult['scenarios_explored'] ?? 0),
                ],
                'source_type' => self::SOURCE_TYPE,
                'outcome' => $outcome,
                'actual_failure_signature' => $signatureKey !== null ? ['signature_key' => $signatureKey] : null,
            ]);

            if (($insertion['status'] ?? null) === 'table_missing') {
                return $this->skipped('predictive_failure_storage_unavailable');
            }

            $this->recordLedger($insertion, $outcome, $estimate, $context, $grindResult);

            return [
                'schema_version' => self::SCHEMA_VERSION,
                'status' => 'recorded',
                'insertion_id' => $insertion['id'] ?? null,
                'domain' => $domain,
                'outcome' => $outcome,
                'predicted_failure_probability' => $probability,
                'calibration_band' => $band,
                'prediction_calibration_error' => $insertion['prediction_calibration_error'] ?? null,
                'source_type' => self::SOURCE_TYPE,
            ];
        } catch (Throwable $e) {
            // Fail-open: telemetry must never crash a grind. Note the failure honestly.
            $this->safeLedger('loop_predictive_bridge_error', [
                'reason' => mb_substr($e->getMessage(), 0, 200),
            ]);

            return $this->skipped('loop_predictive_bridge_error', ['detail' => mb_substr($e->getMessage(), 0, 200)]);
        }
    }

    private function enabled(): bool
    {
        return (bool) config('atlas.loop.predictive_outcome_bridge.enabled', false);
    }

    /**
     * winner => the task succeeded (low failure outcome); no_winner/failed => failure.
     * backpressure/skipped/unknown => not a reconcilable observed outcome.
     */
    private function outcomeForStatus(string $status, bool $hasWinner): ?string
    {
        return match ($status) {
            'winner' => 'success',
            'no_winner' => 'partial',
            'failed' => 'failure',
            default => $hasWinner ? 'success' : null,
        };
    }

    private function resolveDomain(array $context): string
    {
        $domain = trim((string) ($context['domain'] ?? config('atlas.loop.predictive_outcome_bridge.domain', 'programming')));

        return $domain !== '' ? $domain : 'programming';
    }

    /**
     * @param  array<string,mixed>  $grindResult
     * @param  array<string,mixed>  $context
     * @return array<string,mixed>
     */
    private function resolveSignals(array $grindResult, array $context): array
    {
        $signals = is_array($context['signals'] ?? null) ? $context['signals'] : [];

        // Derive honest default signals from the grind shape when the caller gives none.
        // More scenarios explored without a winner => higher observed difficulty prior.
        $scenarios = max(0, (int) ($grindResult['scenarios_explored'] ?? 0));
        $signals['dreyfus_stage'] = (int) ($signals['dreyfus_stage'] ?? 2);
        $signals['kg_gap_score'] = (float) ($signals['kg_gap_score'] ?? min(1.0, 0.4 + $scenarios * 0.02));
        $signals['decay_score'] = (float) ($signals['decay_score'] ?? 0.5);
        $signals['failure_history_signal'] = (float) ($signals['failure_history_signal'] ?? 0.3);
        $signals['loop_target_path'] = (string) ($context['target_path'] ?? '');

        return $signals;
    }

    /**
     * @param  array<string,mixed>  $context
     * @param  array<string,mixed>  $grindResult
     */
    private function signatureKey(string $outcome, array $context, array $grindResult): ?string
    {
        if ($outcome === 'success') {
            return null;
        }

        $reason = trim((string) ($grindResult['reason'] ?? ''));
        $target = trim((string) ($context['target_path'] ?? ''));
        $category = $grindResult['status'] === 'failed' ? 'loop.grind_failed' : 'loop.no_winner';

        $tail = $reason !== '' ? Str::slug(mb_substr($reason, 0, 40)) : ($target !== '' ? Str::slug(mb_substr($target, 0, 40)) : 'unknown');

        return $category.'.'.($tail !== '' ? $tail : 'unknown');
    }

    private function targetNodeId(array $context): string
    {
        $seed = trim((string) ($context['target_path'] ?? '')).'|'.trim((string) ($context['objective'] ?? ''));
        if ($seed === '|') {
            // No stable seed — fall back to a fresh uuid so the column stays valid.
            return (string) Str::uuid();
        }

        // Deterministic per (target_path, objective): the same loop target maps to the
        // same predictive node across grinds, so calibration accrues per target.
        return Uuid::uuid5(Uuid::NAMESPACE_URL, 'atlas-loop-predictive:'.$seed)->toString();
    }

    /**
     * @param  array<string,mixed>  $insertion
     * @param  array<string,mixed>  $estimate
     * @param  array<string,mixed>  $context
     * @param  array<string,mixed>  $grindResult
     */
    private function recordLedger(array $insertion, string $outcome, array $estimate, array $context, array $grindResult): void
    {
        $insertEvent = LedgerEventType::PredictiveFailureInserted;
        $outcomeEvent = match ($outcome) {
            'success' => LedgerEventType::PredictiveFailureOutcomeSuccess,
            'failure', 'partial' => LedgerEventType::PredictiveFailureOutcomeFailure,
            default => LedgerEventType::PredictiveFailureOutcomeAbandoned,
        };

        $base = [
            'schema_version' => self::SCHEMA_VERSION,
            'insertion_id' => $insertion['id'] ?? null,
            'domain' => $insertion['domain'] ?? null,
            'source_type' => self::SOURCE_TYPE,
            'calibration_band' => $insertion['calibration_band'] ?? null,
            'predicted_failure_probability' => $estimate['predicted_failure_probability'] ?? null,
            'outcome' => $outcome,
            'governance' => $this->governanceContract(),
        ];
        $meta = [
            'tenant_id' => 'default',
            'operator_id' => 'atlas_loop_predictive_bridge',
            'envelope_id' => (string) ($insertion['envelope_id'] ?? 'loop_predictive:unknown'),
            'correlation_id' => 'loop_predictive:'.($insertion['id'] ?? 'unknown'),
            'emitter_stage' => 'atlas.cognitive.predictive_failure.loop_bridge',
            'emitter_version' => self::SCHEMA_VERSION,
        ];

        $this->ledger->record($insertEvent, $base, $meta);
        $this->ledger->record($outcomeEvent, array_merge($base, [
            'prediction_calibration_error' => $insertion['prediction_calibration_error'] ?? null,
        ]), $meta);
    }

    /**
     * @param  array<string,mixed>  $extra
     */
    private function safeLedger(string $reason, array $extra = []): void
    {
        try {
            $this->ledger->record(LedgerEventType::PredictiveFailureInsertionSkipped, array_merge([
                'schema_version' => self::SCHEMA_VERSION,
                'source_type' => self::SOURCE_TYPE,
                'reason' => $reason,
                'governance' => $this->governanceContract(),
            ], $extra), [
                'tenant_id' => 'default',
                'operator_id' => 'atlas_loop_predictive_bridge',
                'envelope_id' => 'loop_predictive:skip',
                'correlation_id' => 'loop_predictive:skip',
                'emitter_stage' => 'atlas.cognitive.predictive_failure.loop_bridge',
                'emitter_version' => self::SCHEMA_VERSION,
            ]);
        } catch (Throwable) {
            // Truly fail-open: even ledger failure cannot crash a grind.
        }
    }

    /**
     * @param  array<string,mixed>  $extra
     * @return array<string,mixed>
     */
    private function skipped(string $reason, array $extra = []): array
    {
        return array_merge([
            'schema_version' => self::SCHEMA_VERSION,
            'status' => 'skipped',
            'reason' => $reason,
            'source_type' => self::SOURCE_TYPE,
        ], $extra);
    }

    /**
     * @return array<string,mixed>
     */
    public static function governanceContract(): array
    {
        return [
            'schema_version' => 'atlas.cognitive.predictive_failure.loop_bridge.governance.v1',
            'surface' => 'loop_grind_telemetry',
            'flag_gated' => true,
            'default_off' => true,
            'fail_open' => true,
            'writes_code' => false,
            'mutates_proposals' => false,
            'merges_to_main' => false,
            'distinct_from_learning_surface' => true,
            'not_routed_through_learning_calibration_band_gate' => true,
            'records_real_prediction_and_observed_outcome' => true,
        ];
    }
}
