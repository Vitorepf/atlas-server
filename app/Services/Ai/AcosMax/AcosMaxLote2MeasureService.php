<?php

declare(strict_types=1);

namespace App\Services\Ai\AcosMax;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class AcosMaxLote2MeasureService
{
    public const MAXL06_MEASURE_ID = 'atlas.evidence.delta_attribution.v1';

    public const MULTN1704_MEASURE_ID = 'atlas.originator.predicted_impact_calibration.v1';

    public const MULTX01_MEASURE_ID = 'acos.flywheel.loops.v1';

    public const MULTX06_MEASURE_ID = 'acos.learning_latency.v1';

    public const MULTX09_MEASURE_ID = 'acos.windows_orchestrator.v1';

    public const MULTJ01_MEASURE_ID = 'atlas.ai.lesson_half_life.v2';

    public const MULTJ02_MEASURE_ID = 'atlas.ai.lesson_semantic_dedup.v1';

    public const MULTJ03_MEASURE_ID = 'atlas.ai.counterfactual_lift.v2';

    public const TETO02_MEASURE_ID = 'mission_e2e.v1';

    /** @return array<string,mixed> */
    public static function freezePayload(string $slice): array
    {
        $slice = strtoupper($slice);
        $payloads = self::freezePayloads();

        return $payloads[$slice] ?? [
            'kind' => 'measure_freeze',
            'measure_id' => strtolower($slice).'.unknown',
            'formula_version' => strtolower($slice).'.unknown',
            'formula' => 'Unknown LOTE 2 measure freeze.',
            'thresholds' => [],
            'denominator_min' => 1,
            'ttl_days' => 30,
            'author_engine_id' => 'cursor-acos-max-lote2',
            'judge_engine_id' => 'codex-independent-lote2-judge',
        ];
    }

    /** @return array<string,mixed> */
    public function maxl06DeltaAttribution(): array
    {
        return $this->emptyReport('MAXL-06', 'pending_window', 'missing_lineage_ledger_dependencies', [
            'measure_id' => self::MAXL06_MEASURE_ID,
            'basis' => 'unavailable',
            'allowed_basis' => ['lineage_ledger', 'git_log'],
            'counterfactual_basis' => 'none',
            'correlation_label_required' => 'correlational_attribution',
            'attributed_delta' => [],
            'dependencies' => ['ASI-11', 'MAXL-04'],
        ]);
    }

    /** @return array<string,mixed> */
    public function multn1704PredictedImpact(): array
    {
        $originations = $this->countTableIfPresent('atlas_loop_origination_outcomes');

        return $this->emptyReport('MULTN17-04', 'insufficient_signal', 'pending_real_originator_outcome_window', [
            'measure_id' => self::MULTN1704_MEASURE_ID,
            'denominator_min' => 20,
            'denominator' => [
                'originations' => $originations,
                'resolved_outcomes' => 0,
            ],
            'bands' => [],
            'unresolved' => $originations,
        ]);
    }

    /** @return array<string,mixed> */
    public function multx01FlywheelLoops(): array
    {
        return $this->emptyReport('MULTX-01', 'insufficient_signal', 'no_complete_proven_real_loop_window', [
            'measure_id' => self::MULTX01_MEASURE_ID,
            'denominator_min' => 1,
            'loops_complete' => 0,
            'loops_partial' => [],
            'n_total' => 0,
            'time_per_loop' => [
                'p50_seconds' => null,
                'p95_seconds' => null,
            ],
            'valid_loop_definition' => [
                'requires_proven_real_outcome' => true,
                'legacy_unjoined_rows' => 'legacy_unjoined',
            ],
        ]);
    }

    /** @return array<string,mixed> */
    public function multx06LearningLatency(): array
    {
        return $this->emptyReport('MULTX-06', 'insufficient_signal', 'no_promoted_lesson_delivery_chain', [
            'measure_id' => self::MULTX06_MEASURE_ID,
            'denominator_min' => 8,
            'n' => 0,
            'by_lesson_class' => [],
            'never_delivered' => 0,
            'latency_seconds' => [
                'p50' => null,
                'p95' => null,
            ],
        ]);
    }

    /** @return array<string,mixed> */
    public function multj01LessonHalfLife(): array
    {
        return $this->emptyReport('MULTJ-01', 'insufficient_signal', 'no_measured_lesson_usage_buckets', [
            'measure_id' => self::MULTJ01_MEASURE_ID,
            'denominator_min' => 8,
            'bucket_width_weeks' => 2,
            'memory_types' => [],
            'buckets' => [],
        ]);
    }

    /** @return array<string,mixed> */
    public function multj02DedupCalibration(): array
    {
        return $this->emptyReport('MULTJ-02', 'pending_window', 'calibration_freeze_only_before_enforce', [
            'measure_id' => self::MULTJ02_MEASURE_ID,
            'mode' => 'observe',
            'would_merge_count' => 0,
            'actual_merge_count' => 0,
            'threshold' => data_get(self::freezePayload('MULTJ-02'), 'thresholds.cosine_merge_threshold'),
            'reversible_receipt_required' => true,
        ]);
    }

    /** @return array<string,mixed> */
    public function multj03CounterfactualLift(): array
    {
        return $this->emptyReport('MULTJ-03', 'insufficient_signal', 'no_paired_peek_evaluations', [
            'measure_id' => self::MULTJ03_MEASURE_ID,
            'denominator_min' => 8,
            'sample_rate' => data_get(self::freezePayload('MULTJ-03'), 'thresholds.sample_rate'),
            'n_pairs' => 0,
            'paired_delta' => null,
            'memory_types' => [],
            'record_usage_for_peek' => false,
        ]);
    }

    /** @return array<string,mixed> */
    public function teto02MissionE2e(?int $days = null): array
    {
        if (! Schema::hasTable('atlas_mission_deliveries')) {
            return $this->emptyReport('TETO-02', 'insufficient_signal', 'mission_delivery_table_missing', [
                'measure_id' => self::TETO02_MEASURE_ID,
                'denominator_min' => 20,
                'window_days' => $days,
                'denominator' => ['operator_requests' => 0],
            ]);
        }

        $query = DB::table('atlas_mission_deliveries');
        if ($days !== null && $days > 0) {
            $query->where('created_at', '>=', now()->subDays($days));
        }
        $rows = $query->get();
        $total = $rows->count();
        $completed = $rows->filter(static fn ($row): bool => in_array(strtolower((string) ($row->status ?? '')), [
            'completed',
            'delivered',
            'succeeded',
            'success',
        ], true))->count();

        return [
            'schema_version' => 'atlas.acos.lote2.measure_report.v1',
            'slice' => 'TETO-02',
            'status' => $total >= 20 ? 'ok' : 'insufficient_signal',
            'reason' => $total >= 20 ? null : 'operator_request_window_below_floor',
            'measure_id' => self::TETO02_MEASURE_ID,
            'formula_version' => 'mission_e2e_rate.v1',
            'generated_at' => now()->toIso8601String(),
            'freeze' => self::freezePayload('TETO-02'),
            'denominator_min' => 20,
            'window_days' => $days,
            'metrics' => [
                'operator_requests' => $total,
                'completed_e2e' => $completed,
                'mission_e2e_rate' => $total > 0 ? round($completed / $total, 4) : null,
                'asks_per_request' => null,
                'request_to_delivery_p50_seconds' => null,
                'request_to_delivery_p95_seconds' => null,
            ],
            'abandoned_count_as_not_completed' => true,
        ];
    }

    /** @return array<string,array<string,mixed>> */
    public static function freezePayloads(): array
    {
        return [
            'MAXL-06' => self::payload(self::MAXL06_MEASURE_ID, 'maxl06.delta_attribution.v1', 'Report-only attribution joins daily measure deltas to lineage decision_ids/commits; when lineage is absent, basis must be labeled and causal language must use correlational_attribution.', 1, 30, 'cursor-acos-max-maxl06', 'codex-independent-maxl06-judge', ['allowed_basis' => ['lineage_ledger', 'git_log'], 'counterfactual_basis' => 'none']),
            'MULTN17-04' => self::payload(self::MULTN1704_MEASURE_ID, 'multn17.predicted_impact_calibration.v1', 'Derived predicted_impact band versus realized proven_real outcome curve for origination; report-only until at least 20 real originations resolve.', 20, 30, 'cursor-acos-max-multn17-04', 'codex-independent-multn17-04-judge', ['denominator_min_originations' => 20, 'max_abs_declared_realized_deviation' => 1]),
            'MULTX-01' => self::payload(self::MULTX01_MEASURE_ID, 'multx.flywheel_loop_definition.v1', 'A valid loop chains task, decision receipt, delivered context, execution outcome, lesson, and subsequent measured recall; proven_real outcome is mandatory.', 1, 30, 'cursor-acos-max-multx01', 'codex-independent-multx01-judge', ['requires_proven_real_outcome' => true]),
            'MULTX-06' => self::payload(self::MULTX06_MEASURE_ID, 'multx.learning_latency.v1', 'Measure p50/p95 latency from outcome-created lesson to first delivered context and first measured citation; never_delivered remains in denominator.', 8, 30, 'cursor-acos-max-multx06', 'codex-independent-multx06-judge', ['denominator_min_promoted_lessons' => 8]),
            'MULTX-09' => self::payload(self::MULTX09_MEASURE_ID, 'multx.windows_orchestrator.v1', 'Read-only PromotionProtocol window DAG: started windows publish days_remaining and critical path; not-started windows never receive fabricated ETA; associated series silence beyond the watchdog floor emits dead_window.', 1, 30, 'cursor-acos-max-multx09', 'codex-independent-multx09-judge', ['dead_window_silent_days' => 3, 'not_started_eta_allowed' => false, 'read_only' => true]),
            'MULTJ-01' => self::payload(self::MULTJ01_MEASURE_ID, 'multj.lesson_half_life.v2', 'Bucket lesson lift by age since promotion using two-week buckets; buckets below n=8 publish insufficient instead of null.', 8, 30, 'cursor-acos-max-multj01', 'codex-independent-multj01-judge', ['bucket_width_weeks' => 2, 'denominator_min_per_bucket' => 8]),
            'MULTJ-02' => self::payload(self::MULTJ02_MEASURE_ID, 'multj.semantic_dedup_freeze.v1', 'Semantic lesson dedup threshold freeze for observe-mode would-merge receipts; enforcement requires later calibrated promotion.', 1, 30, 'cursor-acos-max-multj02', 'codex-independent-multj02-judge', ['cosine_merge_threshold' => 0.88, 'observe_mode_actual_merges' => 0]),
            'MULTJ-03' => self::payload(self::MULTJ03_MEASURE_ID, 'multj.counterfactual_lift.v2', 'Paired peek evaluation of the same task with and without injected lesson; n_pairs below 8 publishes insufficient_signal and peek must not record usage.', 8, 30, 'cursor-acos-max-multj03', 'codex-independent-multj03-judge', ['sample_rate' => 0.05, 'denominator_min_pairs' => 8, 'record_usage_for_peek' => false]),
            'TETO-02' => self::payload(self::TETO02_MEASURE_ID, 'mission_e2e_rate.v1', 'Operator natural-language request to completed result rate, asks per request, and request-to-delivery latency; abandoned missions stay in the denominator.', 20, 30, 'cursor-acos-max-teto02', 'codex-independent-teto02-judge', ['target_mission_e2e_rate' => 0.70, 'denominator_min_operator_requests' => 20]),
        ];
    }

    /** @return array<string,mixed> */
    private static function payload(string $measureId, string $formulaVersion, string $formula, int $denominatorMin, int $ttlDays, string $author, string $judge, array $thresholds): array
    {
        return [
            'kind' => 'measure_freeze',
            'measure_id' => $measureId,
            'formula_version' => $formulaVersion,
            'formula' => $formula,
            'thresholds' => $thresholds,
            'denominator_min' => $denominatorMin,
            'ttl_days' => $ttlDays,
            'author_engine_id' => $author,
            'judge_engine_id' => $judge,
            'dual_read_required' => false,
        ];
    }

    /** @return array<string,mixed> */
    private function emptyReport(string $slice, string $status, string $reason, array $extra): array
    {
        return array_merge([
            'schema_version' => 'atlas.acos.lote2.measure_report.v1',
            'slice' => $slice,
            'status' => $status,
            'reason' => $reason,
            'formula_version' => (string) data_get(self::freezePayload($slice), 'formula_version'),
            'generated_at' => now()->toIso8601String(),
            'freeze' => self::freezePayload($slice),
        ], $extra);
    }

    private function countTableIfPresent(string $table): int
    {
        if (! Schema::hasTable($table)) {
            return 0;
        }

        return (int) DB::table($table)->count();
    }
}
