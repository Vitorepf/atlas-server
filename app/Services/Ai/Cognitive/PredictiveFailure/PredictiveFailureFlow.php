<?php

namespace App\Services\Ai\Cognitive\PredictiveFailure;

use App\Services\Ai\Kernel\Evidence\AtlasEvidenceLedger;
use App\Services\Ai\Kernel\Evidence\LedgerEventType;
use App\Services\Ai\Kernel\Gates\PredictiveFailureCalibrationBandGate;
use App\Services\Ai\Kernel\Gates\PredictiveFailureSafetyGate;
use Illuminate\Support\Str;

class PredictiveFailureFlow
{
    public const SCHEMA_VERSION = 'atlas.cognitive.predictive_failure.flow.v1';

    public function __construct(
        private readonly PredictiveFailureSelector $selector,
        private readonly FailureProbabilityEstimator $estimator,
        private readonly CalibrationBandClassifier $bands,
        private readonly PredictiveFailureProblemGenerator $problems,
        private readonly PredictiveFailureRepository $repository,
        private readonly PredictiveFailureCalibrationBandGate $calibrationGate,
        private readonly PredictiveFailureSafetyGate $safetyGate,
        private readonly PredictiveFailureCalibrationMetricsService $metrics,
        private readonly AtlasEvidenceLedger $ledger,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function insert(string $nodeOrTopic, string $domain = 'learning', array $load = []): array
    {
        $nodeOrTopic = trim($nodeOrTopic);
        if ($nodeOrTopic === '') {
            return $this->payload('invalid_input', [
                'reason' => 'predictive_failure_subject_required',
            ]);
        }

        if (! $this->repository->tableReady()) {
            $draft = [
                'schema_version' => PredictiveFailureRepository::SCHEMA_VERSION,
                'envelope_id' => (string) Str::uuid(),
                'target_knowledge_node_id' => null,
                'domain' => $domain,
                'calibration_band' => null,
            ];

            $this->record(LedgerEventType::PredictiveFailureInsertionSkipped, $draft, [
                'reason' => 'predictive_failure_storage_unavailable',
            ]);

            return $this->payload('blocked', [
                'reason' => 'predictive_failure_storage_unavailable',
                'next_action' => 'run_migrations_before_predictive_failure',
            ]);
        }

        $selection = $this->selector->select($nodeOrTopic, $domain);
        $estimate = $this->estimator->estimate($selection);
        $band = $this->bands->classify((float) $estimate['predicted_failure_probability']);
        $problem = $this->problems->generate($selection, $estimate);
        $draft = [
            'schema_version' => PredictiveFailureRepository::SCHEMA_VERSION,
            'envelope_id' => (string) Str::uuid(),
            'target_knowledge_node_id' => $selection['target_knowledge_node_id'],
            'domain' => $domain,
            'signals_used' => $estimate['signals_used'],
            'predicted_failure_probability' => $estimate['predicted_failure_probability'],
            'calibration_band' => $band['band'],
            'predicted_failure_signature_key' => $selection['predicted_failure_signature_key'] ?? null,
            'problem_payload' => $problem,
            'source_type' => $selection['source_type'],
        ];

        $calibration = $this->calibrationGate->evaluate($draft);
        $safety = $this->safetyGate->evaluate($draft, $load);
        if (($calibration['status'] ?? null) !== 'passed' || ($safety['status'] ?? null) !== 'passed') {
            $this->record(LedgerEventType::PredictiveFailureInsertionSkipped, $draft, [
                'calibration_gate' => $calibration,
                'safety_gate' => $safety,
            ]);

            return $this->payload('blocked', [
                'selection' => $selection,
                'estimate' => $estimate,
                'problem' => $problem,
                'calibration_gate' => $calibration,
                'safety_gate' => $safety,
            ]);
        }

        $insertion = $this->repository->create($draft);
        if (($insertion['status'] ?? null) === 'table_missing') {
            $this->record(LedgerEventType::PredictiveFailureInsertionSkipped, $draft, [
                'reason' => 'predictive_failure_storage_unavailable',
            ]);

            return $this->payload('blocked', [
                'reason' => 'predictive_failure_storage_unavailable',
                'next_action' => 'run_migrations_before_predictive_failure',
            ]);
        }

        $this->record(LedgerEventType::PredictiveFailureInserted, $insertion, [
            'selection' => $selection,
            'calibration_gate' => $calibration,
            'safety_gate' => $safety,
        ]);

        return $this->payload('inserted', [
            'insertion' => $insertion,
            'selection' => $selection,
            'calibration_gate' => $calibration,
            'safety_gate' => $safety,
            'next_action' => 'resolve',
        ]);
    }

    /**
     * @param  array<string,mixed>|null  $signature
     * @return array<string,mixed>
     */
    public function resolve(int $id, string $outcome, ?array $signature = null): array
    {
        $allowed = ['success', 'partial', 'failure', 'abandoned', 'skipped'];
        if (! in_array($outcome, $allowed, true)) {
            return $this->payload('invalid_input', ['reason' => 'predictive_failure_invalid_outcome', 'allowed' => $allowed]);
        }

        $updated = $this->repository->recordOutcome($id, $outcome, $signature);
        if (($updated['status'] ?? null) === 'missing') {
            return $this->payload('missing', ['reason' => 'predictive_failure_insertion_missing']);
        }

        $event = match ($outcome) {
            'success' => LedgerEventType::PredictiveFailureOutcomeSuccess,
            'failure', 'partial' => LedgerEventType::PredictiveFailureOutcomeFailure,
            default => LedgerEventType::PredictiveFailureOutcomeAbandoned,
        };
        $this->record($event, $updated, ['outcome' => $outcome]);
        $this->record(LedgerEventType::PredictiveFailurePriorUpdated, $updated, [
            'prediction_calibration_error' => $updated['prediction_calibration_error'] ?? null,
        ]);

        return $this->payload('resolved', ['insertion' => $updated]);
    }

    /**
     * @return array<string,mixed>
     */
    public function history(?string $domain = null, int $days = 30): array
    {
        return $this->payload('ok', [
            'insertions' => $this->repository->history($domain, $days),
            'window_days' => max(1, $days),
        ]);
    }

    /**
     * @return array<string,mixed>
     */
    public function metrics(?string $domain = null, int $days = 60): array
    {
        return $this->payload('ok', ['metrics' => $this->metrics->compute($domain, $days)]);
    }

    /**
     * @param  array<string,mixed>  $record
     * @param  array<string,mixed>  $extra
     */
    private function record(LedgerEventType $type, array $record, array $extra = []): void
    {
        $this->ledger->record($type, array_merge([
            'schema_version' => self::SCHEMA_VERSION,
            'insertion_id' => $record['id'] ?? null,
            'target_knowledge_node_id' => $record['target_knowledge_node_id'] ?? null,
            'domain' => $record['domain'] ?? null,
            'calibration_band' => $record['calibration_band'] ?? null,
            'governance' => self::governanceContract(),
        ], $extra), [
            'tenant_id' => 'default',
            'operator_id' => 'atlas_predictive_failure_cli',
            'envelope_id' => (string) ($record['envelope_id'] ?? 'predictive_failure:unknown'),
            'correlation_id' => 'predictive_failure:'.($record['id'] ?? ($record['target_knowledge_node_id'] ?? 'unknown')),
            'emitter_stage' => 'atlas.cognitive.predictive_failure',
            'emitter_version' => self::SCHEMA_VERSION,
        ]);
    }

    /**
     * @param  array<string,mixed>  $extra
     * @return array<string,mixed>
     */
    private function payload(string $status, array $extra = []): array
    {
        return array_merge([
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $status,
            'flow' => 'learning.predictive_failure_insertion',
            'governance' => $this->governanceContract(),
        ], $extra);
    }

    /**
     * @return array<string,mixed>
     */
    public static function governanceContract(): array
    {
        return [
            'schema_version' => 'atlas.cognitive.predictive_failure.governance.v1',
            'operator_opt_in_required' => true,
            'specific_target_required' => true,
            'empty_subject_allowed' => false,
            'auto_schedule_allowed' => false,
            'passive_insertion_allowed' => false,
            'random_frustration_allowed' => false,
            'daily_plan_auto_insert_allowed' => false,
            'requires_calibration_band_gate' => true,
            'requires_safety_gate' => true,
            'requires_outcome_tracking' => true,
            'requires_rivals_learning_validation_before_default' => true,
            'allowed_surfaces_now' => ['cli_explicit'],
            'future_surfaces_require_ap_review' => ['app', 'mobile', 'voice', 'daily_plan'],
        ];
    }
}
