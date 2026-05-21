<?php

declare(strict_types=1);

namespace App\Services\Ai\Context;

use App\Services\Ai\Mission\MissionCanonicalHash;
use Illuminate\Support\Carbon;

final class AtlasContextObservabilityPlaneService
{
    public const SCHEMA_VERSION = 'atlas.aucri.context_observability_plane.v1';

    public const SNAPSHOT_SCHEMA = 'atlas.aucri.context_observability_snapshot.v1';

    public const TRACE_SCHEMA = 'atlas.aucri.context_trace.v1';

    public const SOURCE_HEALTH_SCHEMA = 'atlas.aucri.context_source_health.v1';

    public const BLOCKER_SCHEMA = 'atlas.aucri.context_blocker.v1';

    public function __construct(
        private readonly AtlasRetrievalCostLatencyGovernorService $costLatencyGovernor,
        private readonly AtlasRetrievalEvaluationBenchmarkArenaService $arena,
        private readonly AtlasRetrievalFeedbackLoopService $feedbackLoop,
    ) {}

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function snapshot(array $input = []): array
    {
        $risk = $this->risk((string) ($input['risk_level'] ?? $input['risk'] ?? 'low'));
        $flowId = trim((string) ($input['flow_id'] ?? $this->flowId($input)));
        $arena = $this->arena->evaluate(['risk_level' => $risk]);
        $budget = $this->costLatencyGovernor->govern($input + ['risk_level' => $risk, 'flow_id' => $flowId]);
        $feedback = $this->feedbackLoop->capture([
            'objective' => 'acop:'.MissionCanonicalHash::sha256((string) ($input['query'] ?? $input['objective'] ?? $flowId)),
            'domain' => (string) ($input['domain'] ?? 'atlas'),
            'task_type' => (string) ($input['task_type'] ?? 'direct'),
            'risk_level' => $risk,
            'outcome_status' => (string) ($input['outcome_status'] ?? 'passed'),
            'record' => false,
        ]);
        $sourceHealth = $this->sourceHealth($arena, $feedback);
        $traces = $this->traces($flowId, $arena, $budget, $feedback);
        $blockers = $this->blockers($arena, $budget, $feedback);

        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $this->status($blockers, $budget, $arena),
            'generated_at' => Carbon::now()->toIso8601String(),
            'snapshot' => [
                'schema_version' => self::SNAPSHOT_SCHEMA,
                'window_hours' => max(1, min(720, (int) ($input['hours'] ?? 24))),
                'flow_id' => $flowId,
                'risk_level' => $risk,
                'trace_count' => count($traces),
                'source_count' => count($sourceHealth),
                'blocker_count' => count($blockers),
            ],
            'traces' => $traces,
            'source_health' => $sourceHealth,
            'blockers' => $blockers,
            'metrics' => [
                'arena_required_source_recall' => (float) data_get($arena, 'summary.metrics.required_source_recall', 0.0),
                'arena_groundedness' => (float) data_get($arena, 'summary.metrics.groundedness', 0.0),
                'arena_context_roi' => (float) data_get($arena, 'summary.metrics.context_roi', 0.0),
                'budget_latency_ms' => (int) data_get($budget, 'receipt.observed_latency_ms', 0),
                'budget_cost_units' => (int) data_get($budget, 'receipt.estimated_cost_units', 0),
                'feedback_roi' => (float) data_get($feedback, 'context_roi.roi_score', 0.0),
                'misses' => array_sum(array_column($sourceHealth, 'missed')),
                'ruido' => array_sum(array_column($sourceHealth, 'noise')),
            ],
            'runtime_refs' => [
                'arena_hash' => (string) ($arena['arena_hash'] ?? ''),
                'budget_hash' => (string) ($budget['governor_hash'] ?? ''),
                'budget_receipt_hash' => (string) data_get($budget, 'receipt.receipt_hash', ''),
                'feedback_hash' => (string) ($feedback['retrieval_feedback_hash'] ?? ''),
            ],
            'claims' => [
                'providers_invoked' => false,
                'writes' => false,
                'rivals_run' => false,
                'benchmark_run' => false,
                'raw_text_exposed' => false,
            ],
        ];

        $hashPayload = $payload;
        unset($hashPayload['generated_at']);
        $payload['snapshot_hash'] = MissionCanonicalHash::sha256($hashPayload);

        return $payload;
    }

    /**
     * @param  array<string,mixed>  $arena
     * @param  array<string,mixed>  $feedback
     * @return array<int,array<string,mixed>>
     */
    private function sourceHealth(array $arena, array $feedback): array
    {
        $sources = [];
        foreach ((array) ($arena['results'] ?? []) as $result) {
            foreach ((array) ($result['required_source_coverage'] ?? []) as $sourceType => $covered) {
                $sourceType = (string) $sourceType;
                $sources[$sourceType] ??= ['included' => 0, 'used' => 0, 'missed' => 0, 'noise' => 0];
                $sources[$sourceType]['included']++;
                $sources[$sourceType][(bool) $covered ? 'used' : 'missed']++;
            }
        }

        foreach ((array) data_get($feedback, 'missed_ref_candidates', []) as $miss) {
            $sourceType = (string) ($miss['source_type'] ?? 'unknown');
            $sources[$sourceType] ??= ['included' => 0, 'used' => 0, 'missed' => 0, 'noise' => 0];
            $sources[$sourceType]['missed']++;
        }

        foreach ((array) data_get($feedback, 'noise_ref_candidates', []) as $noise) {
            $sourceType = (string) ($noise['source_type'] ?? 'unknown');
            $sources[$sourceType] ??= ['included' => 0, 'used' => 0, 'missed' => 0, 'noise' => 0];
            $sources[$sourceType]['noise']++;
        }

        ksort($sources);

        return array_values(array_map(
            static fn (string $sourceType, array $stats): array => [
                'schema_version' => self::SOURCE_HEALTH_SCHEMA,
                'source_type' => $sourceType,
                'source_ref_hash' => MissionCanonicalHash::sha256('source:'.$sourceType),
                'included' => (int) $stats['included'],
                'used' => (int) $stats['used'],
                'missed' => (int) $stats['missed'],
                'noise' => (int) $stats['noise'],
                'health' => ((int) $stats['missed'] === 0 && (int) $stats['noise'] === 0) ? 'healthy' : 'watch',
            ],
            array_keys($sources),
            array_values($sources),
        ));
    }

    /**
     * @param  array<string,mixed>  $arena
     * @param  array<string,mixed>  $budget
     * @param  array<string,mixed>  $feedback
     * @return array<int,array<string,mixed>>
     */
    private function traces(string $flowId, array $arena, array $budget, array $feedback): array
    {
        return [
            $this->trace($flowId, 'areba', (string) ($arena['status'] ?? 'unknown'), (string) ($arena['arena_hash'] ?? '')),
            $this->trace($flowId, 'arclg', (string) ($budget['status'] ?? 'unknown'), (string) data_get($budget, 'receipt.receipt_hash', '')),
            $this->trace($flowId, 'arfl', (string) ($feedback['status'] ?? 'unknown'), (string) ($feedback['retrieval_feedback_hash'] ?? '')),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function trace(string $flowId, string $stage, string $status, string $receiptHash): array
    {
        return [
            'schema_version' => self::TRACE_SCHEMA,
            'trace_id' => MissionCanonicalHash::sha256([$flowId, $stage, $receiptHash]),
            'flow_id' => $flowId,
            'retrieval_stage' => $stage,
            'status' => $status,
            'receipt_hash' => $receiptHash,
        ];
    }

    /**
     * @param  array<string,mixed>  $arena
     * @param  array<string,mixed>  $budget
     * @param  array<string,mixed>  $feedback
     * @return array<int,array<string,mixed>>
     */
    private function blockers(array $arena, array $budget, array $feedback): array
    {
        $blockers = [];
        foreach ((array) data_get($arena, 'regression_report.regressions', []) as $regression) {
            $blockers[] = $this->blocker('areba_regression', (string) ($regression['reason'] ?? 'regression'), (string) ($regression['severity'] ?? 'critical'));
        }

        foreach ((array) data_get($budget, 'receipt.blocked_reasons', []) as $reason) {
            $blockers[] = $this->blocker('arclg_budget', (string) $reason, 'critical');
        }

        foreach ((array) data_get($feedback, 'learning_candidate.reasons', []) as $reason) {
            $blockers[] = $this->blocker('arfl_learning_candidate', (string) $reason, 'warn');
        }

        return array_values($blockers);
    }

    /**
     * @return array<string,mixed>
     */
    private function blocker(string $kind, string $reason, string $severity): array
    {
        return [
            'schema_version' => self::BLOCKER_SCHEMA,
            'kind' => $kind,
            'reason' => $reason,
            'severity' => $severity,
            'blocker_hash' => MissionCanonicalHash::sha256([$kind, $reason, $severity]),
        ];
    }

    /**
     * @param  array<int,array<string,mixed>>  $blockers
     * @param  array<string,mixed>  $budget
     * @param  array<string,mixed>  $arena
     */
    private function status(array $blockers, array $budget, array $arena): string
    {
        if ((string) ($budget['status'] ?? 'blocked') === 'blocked' || (string) ($arena['status'] ?? 'blocked') === 'blocked') {
            return 'blocked';
        }

        if ($blockers !== [] || (string) ($budget['status'] ?? 'ready') === 'degraded') {
            return 'watch';
        }

        return 'healthy';
    }

    /**
     * @param  array<string,mixed>  $input
     */
    private function flowId(array $input): string
    {
        return trim((string) ($input['domain'] ?? 'atlas')).'.'.trim((string) ($input['task_type'] ?? 'direct'));
    }

    private function risk(string $risk): string
    {
        return in_array($risk, ['low', 'medium', 'high', 'irreversible'], true) ? $risk : 'low';
    }
}
