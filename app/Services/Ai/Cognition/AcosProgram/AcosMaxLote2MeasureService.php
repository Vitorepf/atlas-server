<?php

declare(strict_types=1);

namespace App\Services\Ai\Cognition\AcosProgram;

use App\Support\FirstNonEmptyString;
use App\Services\Ai\Support\AiValueNormalizer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class AcosMaxLote2MeasureService
{
    public const FIELD_COMPLETED_E2E = 'completed_e2e';
    public const FIELD_COMPLETION_CLAIM_ALLOWED = 'completion_claim_allowed';
    public const MAXL06_MEASURE_ID = 'atlas.evidence.delta_attribution.v1';

    public const MULTN1704_MEASURE_ID = 'atlas.originator.predicted_impact_calibration.v1';

    public const MULTX01_MEASURE_ID = 'acos.flywheel.loops.v1';

    public const MULTX06_MEASURE_ID = 'acos.learning_latency.v1';

    public const MULTX09_MEASURE_ID = 'acos.windows_orchestrator.v1';

    public const MULTJ01_MEASURE_ID = 'atlas.ai.lesson_half_life.v2';

    public const MULTJ02_MEASURE_ID = 'atlas.ai.lesson_semantic_dedup.v1';

    public const MULTJ03_MEASURE_ID = 'atlas.ai.counterfactual_lift.v2';

    public const MULTJ04_MEASURE_ID = 'atlas.ai.procedural_skill_promoter.v1';

    public const MULTJ06_MEASURE_ID = 'atlas.ai.abstraction_ladder.v1';

    public const TETO02_MEASURE_ID = 'mission_e2e.v1';

    public const REPORT_SCHEMA = 'atlas.acos.lote2.measure_report.v1';
    public const FIELD_NEVER_DELIVERED = 'never_delivered';
    public const FIELD_NEVER_CITED = 'never_cited';
    public const FIELD_ROWS = 'rows';
    public const FIELD_FREEZE = 'freeze';
    public const FIELD_DELIVERED = 'delivered';
    public const FIELD_CITED = 'cited';
    public const FIELD_BLOCKED_BY = 'blocked_by';
    public const FIELD_SCORE = 'score';

    public const STATUS_OK = 'ok';

    public const STATUS_PENDING_WINDOW = 'pending_window';

    public const STATUS_INSUFFICIENT_SIGNAL = 'insufficient_signal';

    public const STATUS_MEASURED = 'measured';

    public const REASON_MISSING_LINEAGE_LEDGER = 'missing_lineage_ledger_dependencies';

    public const REASON_PENDING_REAL_ORIGINATOR_OUTCOME = 'pending_real_originator_outcome_window';

    public const REASON_LOOP_SOURCE_TABLES_MISSING = 'loop_source_tables_missing';

    public const REASON_LEARNING_LATENCY_SOURCE_TABLES_MISSING = 'learning_latency_source_tables_missing';

    public const REASON_NO_MEASURED_LESSON_USAGE_BUCKETS = 'no_measured_lesson_usage_buckets';

    public const REASON_CALIBRATION_FREEZE_ONLY = 'calibration_freeze_only_before_enforce';

    public const REASON_PAIRED_FEEDBACK_TABLE_MISSING = 'paired_feedback_table_missing';

    public const REASON_MISSION_DELIVERY_TABLE_MISSING = 'mission_delivery_table_missing';

    public const KIND_MEASURE_FREEZE = 'measure_freeze';

    public const MODE_OBSERVE = 'observe';

    public const BASIS_UNAVAILABLE = 'unavailable';

    public const MEMORY_TYPE_UNKNOWN = 'unknown';

    public const FIELD_COMPLETE = 'complete';

    public const FIELD_PARTIAL = 'partial';

    public const FIELD_INCOMPLETE = 'incomplete';

    public const FIELD_PROVEN_REAL = 'proven_real';

    public const FIELD_FIXTURE = 'fixture';

    public const FIELD_IS_FIXTURE = 'is_fixture';

    public const MISSION_STATUS_COMPLETED = 'completed';

    public const MISSION_STATUS_DELIVERED = 'delivered';

    public const MISSION_STATUS_SUCCEEDED = 'succeeded';

    public const MISSION_STATUS_SUCCESS = 'success';

    public const FIELD_MEASURE_ID = 'measure_id';

    public const FIELD_FORMULA_VERSION = 'formula_version';

    public const FIELD_DENOMINATOR_MIN = 'denominator_min';

    public const FIELD_KIND = 'kind';

    public const FIELD_FORMULA = 'formula';

    public const FIELD_SLICE = 'slice';

    public const FIELD_STATUS = 'status';

    public const FIELD_REASON = 'reason';

    public const FIELD_MEMORY_TYPE = 'memory_type';

    public const FIELD_N_PAIRS = 'n_pairs';

    public const FIELD_GENERATED_AT = 'generated_at';

    public const FIELD_SCHEMA_VERSION = 'schema_version';
    public const FIELD_READ_ONLY = 'read_only';
    public const FIELD_LESSON_CLASS = 'lesson_class';
    public const FIELD_CITATION_LATENCIES = 'citation_latencies';
    public const FIELD_RECORD_USAGE_FOR_PEEK = 'record_usage_for_peek';
    public const FIELD_PROVIDER_CALLS_MADE = 'provider_calls_made';
    public const FIELD_MEMORY_WRITTEN = 'memory_written';
    public const FIELD_DELIVERY_P50 = 'delivery_p50';
    public const FIELD_DELIVERY_P95 = 'delivery_p95';
    public const FIELD_CITATION_P50 = 'citation_p50';
    public const FIELD_CITATION_P95 = 'citation_p95';
    public const FIELD_DELIVERY_LATENCIES = 'delivery_latencies';
    public const FIELD_MEMORY_TYPES = 'memory_types';
    public const FIELD_CLAIM_POLICY = 'claim_policy';
    public const FIELD_LATENCY_SECONDS = 'latency_seconds';
    public const FIELD_SAMPLE_RATE = 'sample_rate';
    public const FIELD_PAIRED_DELTA = 'paired_delta';
    public const FIELD_THRESHOLDS = 'thresholds';
    public const FIELD_TTL_DAYS = 'ttl_days';
    public const FIELD_AUTHOR_ENGINE_ID = 'author_engine_id';
    public const FIELD_JUDGE_ENGINE_ID = 'judge_engine_id';
    public const FIELD_ALLOWED_BASIS = 'allowed_basis';
    public const FIELD_COUNTERFACTUAL_BASIS = 'counterfactual_basis';
    public const FIELD_DENOMINATOR = 'denominator';
    public const FIELD_LOOPS_COMPLETE = 'loops_complete';
    public const FIELD_LOOPS = 'loops';
    public const FIELD_LOOPS_PARTIAL = 'loops_partial';
    public const FIELD_N_TOTAL = 'n_total';
    public const FIELD_FIXTURE_REJECTED = 'fixture_rejected';
    public const FIELD_POLICY_VIOLATION_ROWS = 'policy_violation_rows';
    public const FIELD_CONTROL_SCORE_SUM = 'control_score_sum';
    public const FIELD_TREATMENT_SCORE_SUM = 'treatment_score_sum';
    public const FIELD_DELTA_SUM = 'delta_sum';
    public const FIELD_MISSING_TABLES = 'missing_tables';
    public const FIELD_TIME_PER_LOOP = 'time_per_loop';
    public const FIELD_P50_SECONDS = 'p50_seconds';
    public const FIELD_P95_SECONDS = 'p95_seconds';
    public const FIELD_MARCO_ESP_V1 = 'marco_esp_v1';
    public const FIELD_SATISFIED = 'satisfied';
    public const FIELD_VALID_LOOP_DEFINITION = 'valid_loop_definition';
    public const FIELD_REQUIRES_ZERO_FIXTURE = 'requires_zero_fixture';
    public const FIELD_SYNTHETIC_FIXTURE_CLAIM_ALLOWED = 'synthetic_fixture_claim_allowed';
    public const FIELD_REQUIRES_PROVEN_REAL_OUTCOME = 'requires_proven_real_outcome';
    public const FIELD_OUTCOME_ID = 'outcome_id';
    public const FIELD_LOOP_ID = 'loop_id';
    public const FIELD_FIXTURE_FREE = 'fixture_free';
    public const FIELD_BY_LESSON_CLASS = 'by_lesson_class';
    public const FIELD_ADMISSION_DOOR = 'admission_door';
    public const FIELD_ACTUAL_MERGE_COUNT = 'actual_merge_count';
    public const FIELD_ATTRIBUTED_DELTA = 'attributed_delta';
    public const FIELD_BANDS = 'bands';
    public const FIELD_BASIS = 'basis';
    public const FIELD_BUCKETS = 'buckets';
    public const FIELD_BUCKET_WIDTH_WEEKS = 'bucket_width_weeks';
    public const FIELD_CHAIN = 'chain';
    public const FIELD_CONTROL = 'control';
    public const FIELD_INVALID_PAIRS = 'invalid_pairs';
    public const FIELD_LOOP = 'loop';
    public const FIELD_MODE = 'mode';
    public const FIELD_OPERATOR_REQUESTS = 'operator_requests';
    public const FIELD_PEEK_POLICY = 'peek_policy';
    public const FIELD_PEEK_POLICY_VIOLATION = 'peek_policy_violation';
    public const FIELD_POLICY_VALID = 'policy_valid';
    public const FIELD_POSITIVE_LIFT_FABRICATED = 'positive_lift_fabricated';
    public const FIELD_RATE = 'rate';
    public const FIELD_TREATMENT = 'treatment';
    public const FIELD_USAGE_ROWS_RECORDED = 'usage_rows_recorded';
    public const FIELD_WINDOW_DAYS = 'window_days';
    public const FIELD_ABANDONED_COUNT_AS_NOT_COMPLETED = 'abandoned_count_as_not_completed';
    public const FIELD_ARM = 'arm';
    public const FIELD_ASKS_PER_REQUEST = 'asks_per_request';
    public const FIELD_BLOCKED_BY_TOP = 'blocked_by_top';
    public const FIELD_CANDIDATE_ID = 'candidate_id';
    public const FIELD_CITATION_LATENCY_SECONDS = 'citation_latency_seconds';
    public const FIELD_CONTROL_SCORE_MEAN = 'control_score_mean';
    public const FIELD_CORRELATION_LABEL_REQUIRED = 'correlation_label_required';
    public const FIELD_COSINE_MERGE_THRESHOLD = 'cosine_merge_threshold';
    public const FIELD_COUNT = 'count';
    public const FIELD_DEAD_WINDOW_SILENT_DAYS = 'dead_window_silent_days';
    public const FIELD_DECISION_ID = 'decision_id';
    public const FIELD_DEFAULT_OFF = 'default_off';
    public const FIELD_DELIVERY_LATENCY_SECONDS = 'delivery_latency_seconds';
    public const FIELD_DENOMINATOR_MIN_OPERATOR_REQUESTS = 'denominator_min_operator_requests';
    public const FIELD_DENOMINATOR_MIN_ORIGINATIONS = 'denominator_min_originations';
    public const FIELD_DENOMINATOR_MIN_PAIRS = 'denominator_min_pairs';
    public const FIELD_DENOMINATOR_MIN_PER_BUCKET = 'denominator_min_per_bucket';
    public const FIELD_DENOMINATOR_MIN_PROMOTED_LESSONS = 'denominator_min_promoted_lessons';
    public const FIELD_DEPENDENCIES = 'dependencies';
    public const FIELD_DISTINCT_SIGNATURE_K = 'distinct_signature_k';
    public const FIELD_DUAL_READ_REQUIRED = 'dual_read_required';
    public const FIELD_LEARNING_CANDIDATE_ID = 'learning_candidate_id';
    public const FIELD_LEGACY_UNJOINED_ROWS = 'legacy_unjoined_rows';
    public const FIELD_MAX_ABS_DECLARED_REALIZED_DEVIATION = 'max_abs_declared_realized_deviation';
    public const FIELD_METRICS = 'metrics';
    public const FIELD_MISSION_E2E_RATE = 'mission_e2e_rate';
    public const FIELD_NEVER_DELIVERED_IN_DENOMINATOR = 'never_delivered_in_denominator';
    public const FIELD_NO_COMPLETE_PROVEN_REAL_LOOP_WINDOW = 'no_complete_proven_real_loop_window';
    public const FIELD_NOT_STARTED_ETA_ALLOWED = 'not_started_eta_allowed';
    public const FIELD_OBSERVE_MODE_ACTUAL_MERGES = 'observe_mode_actual_merges';
    public const FIELD_ORIGINATIONS = 'originations';
    public const FIELD_PAIR_ID = 'pair_id';
    public const FIELD_PATTERN_FLOOR = 'pattern_floor';
    public const FIELD_PROCEDURAL_CASE_COUNT_FLOOR = 'procedural_case_count_floor';
    public const FIELD_RECORD_USAGE = 'record_usage';
    public const FIELD_REQUEST_TO_DELIVERY_P50_SECONDS = 'request_to_delivery_p50_seconds';
    public const FIELD_REQUEST_TO_DELIVERY_P95_SECONDS = 'request_to_delivery_p95_seconds';
    public const FIELD_REQUIRES_CHAINED_IDS = 'requires_chained_ids';
    public const FIELD_REQUIRES_DECISION_RECEIPT_ID = 'requires_decision_receipt_id';
    public const FIELD_REQUIRES_DELIVERED_CONTEXT_RECEIPT = 'requires_delivered_context_receipt';
    public const FIELD_REQUIRES_LEARNING_CANDIDATE = 'requires_learning_candidate';
    public const FIELD_REQUIRES_LOOPS_COMPLETE_MIN = 'requires_loops_complete_min';
    public const FIELD_REQUIRES_PROVEN_REAL = 'requires_proven_real';
    public const FIELD_REQUIRES_SUBSEQUENT_MEASURED_RECALL = 'requires_subsequent_measured_recall';
    public const FIELD_RESOLVED_OUTCOMES = 'resolved_outcomes';
    public const FIELD_RETRIEVAL_POLICY_CHANGED = 'retrieval_policy_changed';
    public const FIELD_RETRIEVAL_RECEIPT_ID = 'retrieval_receipt_id';
    public const FIELD_REVERSIBLE_RECEIPT_REQUIRED = 'reversible_receipt_required';
    public const FIELD_SUBSEQUENT_RECALL_FEEDBACK_ID = 'subsequent_recall_feedback_id';
    public const FIELD_TARGET_MISSION_E2E_RATE = 'target_mission_e2e_rate';
    public const FIELD_TASK_ID = 'task_id';
    public const FIELD_THRESHOLD = 'threshold';
    public const FIELD_TIME_TO_RECALL_SECONDS = 'time_to_recall_seconds';
    public const FIELD_TREATMENT_SCORE_MEAN = 'treatment_score_mean';
    public const FIELD_UNRESOLVED = 'unresolved';
    public const FIELD_WITH_LESSON = 'with_lesson';
    public const FIELD_WITHOUT_LESSON = 'without_lesson';
    public const FIELD_WOULD_MERGE_COUNT = 'would_merge_count';
    public const FIELD_COUNTERFACTUAL_LIFT_V2 = 'counterfactual_lift_v2';
    public const FIELD_DECISION_RECEIPT_ID = 'decision_receipt_id';
    public const FIELD_FIXTURE_CHAIN = 'fixture_chain';
    public const FIELD_IRRELEVANT = 'irrelevant';
    public const FIELD_PEEK = 'peek';
    public const FIELD_PROMOTED = 'promoted';
    public const FIELD_RECEIPT_ID = 'receipt_id';
    public const FIELD_NONE = 'none';
    public const FIELD_CORRELATIONAL_ATTRIBUTION = 'correlational_attribution';
    public const FIELD_CREATED_AT = 'created_at';
    public const FIELD_LEGACY_UNJOINED = 'legacy_unjoined';
    public const FIELD_AI_RAG_FEEDBACK_EVENTS = 'ai_rag_feedback_events';
    public const FIELD_AI_LEARNING_CANDIDATES = 'ai_learning_candidates';
    public const FIELD_AI_RUN_OUTCOMES = 'ai_run_outcomes';
    public const FIELD_ATLAS_MISSION_DELIVERIES = 'atlas_mission_deliveries';
    public const FIELD_SHA256 = 'sha256';
    public const FIELD_ATLAS_LOOP_ORIGINATION_OUTCOMES = 'atlas_loop_origination_outcomes';
    public const FIELD_WITHOUT = 'without';
    public const FIELD_GIT_LOG = 'git_log';
    public const FIELD_LINEAGE_LEDGER = 'lineage_ledger';
    public const FIELD_DECISION_RECEIPT_MISSING = 'decision_receipt_missing';
    public const FIELD_DELIVERED_CONTEXT_MISSING = 'delivered_context_missing';
    public const FIELD_LEARNING_CANDIDATE_MISSING = 'learning_candidate_missing';
    public const FIELD_OPERATOR_REQUEST_WINDOW_BELOW_FLOOR = 'operator_request_window_below_floor';
    public const FIELD_OUTCOME_NOT_PROVEN_REAL = 'outcome_not_proven_real';
    public const FIELD_PAIRED_PEEK_FLOOR_BELOW_MINIMUM = 'paired_peek_floor_below_minimum';
    public const FIELD_PROMOTED_LESSON_DENOMINATOR_BELOW_MIN = 'promoted_lesson_denominator_below_min';
    public const FIELD_SUBSEQUENT_MEASURED_RECALL_MISSING = 'subsequent_measured_recall_missing';
    public const FIELD_WITH = 'with';
    public const FIELD_MULTJ_03 = 'MULTJ-03';
    public const FIELD_MULTX_06 = 'MULTX-06';
    public const FIELD_MULTX_01 = 'MULTX-01';
    public const FIELD_TETO_02 = 'TETO-02';
    public const FIELD_MULTJ_02 = 'MULTJ-02';
    public const FIELD_MAXL_06 = 'MAXL-06';
    public const FIELD_MULTJ_01 = 'MULTJ-01';
    public const FIELD_MULTN17_04 = 'MULTN17-04';
    public const FIELD_ASI_02 = 'ASI-02';
    public const FIELD_ASI_11 = 'ASI-11';
    public const FIELD_MAXL_04 = 'MAXL-04';
    public const FIELD_MULTJ_04 = 'MULTJ-04';
    public const FIELD_MULTJ_06 = 'MULTJ-06';
    public const FIELD_MULTX_09 = 'MULTX-09';
    public const FIELD_MISSION_E2E_RATE_V1 = 'mission_e2e_rate.v1';
    public const FIELD_CODEX_INDEPENDENT_LOTE2_JUDGE = 'codex-independent-lote2-judge';
    public const FIELD_CODEX_INDEPENDENT_MAXL06_JUDGE = 'codex-independent-maxl06-judge';
    public const FIELD_CODEX_INDEPENDENT_MULTJ01_JUDGE = 'codex-independent-multj01-judge';
    public const FIELD_CODEX_INDEPENDENT_MULTJ02_JUDGE = 'codex-independent-multj02-judge';
    public const FIELD_CODEX_INDEPENDENT_MULTJ03_JUDGE = 'codex-independent-multj03-judge';
    public const FIELD_CODEX_INDEPENDENT_MULTJ04_JUDGE = 'codex-independent-multj04-judge';
    public const FIELD_CODEX_INDEPENDENT_MULTJ06_JUDGE = 'codex-independent-multj06-judge';
    public const FIELD_CODEX_INDEPENDENT_MULTN17_04_JUDGE = 'codex-independent-multn17-04-judge';
    public const FIELD_CODEX_INDEPENDENT_MULTX01_JUDGE = 'codex-independent-multx01-judge';
    public const FIELD_CODEX_INDEPENDENT_MULTX06_JUDGE = 'codex-independent-multx06-judge';
    public const FIELD_CODEX_INDEPENDENT_MULTX09_JUDGE = 'codex-independent-multx09-judge';
    public const FIELD_CODEX_INDEPENDENT_TETO02_JUDGE = 'codex-independent-teto02-judge';
    public const FIELD_CURSOR_ACOS_MAX_LOTE2 = 'cursor-acos-max-lote2';
    public const FIELD_CURSOR_ACOS_MAX_MAXL06 = 'cursor-acos-max-maxl06';
    public const FIELD_CURSOR_ACOS_MAX_MULTJ01 = 'cursor-acos-max-multj01';
    public const FIELD_CURSOR_ACOS_MAX_MULTJ02 = 'cursor-acos-max-multj02';
    public const FIELD_CURSOR_ACOS_MAX_MULTJ03 = 'cursor-acos-max-multj03';
    public const FIELD_CURSOR_ACOS_MAX_MULTJ04 = 'cursor-acos-max-multj04';
    public const FIELD_CURSOR_ACOS_MAX_MULTJ06 = 'cursor-acos-max-multj06';
    public const FIELD_CURSOR_ACOS_MAX_MULTN17_04 = 'cursor-acos-max-multn17-04';
    public const FIELD_CURSOR_ACOS_MAX_MULTX01 = 'cursor-acos-max-multx01';
    public const FIELD_CURSOR_ACOS_MAX_MULTX06 = 'cursor-acos-max-multx06';
    public const FIELD_CURSOR_ACOS_MAX_MULTX09 = 'cursor-acos-max-multx09';
    public const FIELD_CURSOR_ACOS_MAX_TETO02 = 'cursor-acos-max-teto02';
    public const FIELD_LOOP_TIME_TO_RECALL_SECONDS = 'loop.time_to_recall_seconds';
    public const FIELD_MAXL06_DELTA_ATTRIBUTION_V1 = 'maxl06.delta_attribution.v1';
    public const FIELD_MULTJ_ABSTRACTION_LADDER_V1 = 'multj.abstraction_ladder.v1';
    public const FIELD_MULTJ_COUNTERFACTUAL_LIFT_V2 = 'multj.counterfactual_lift.v2';
    public const FIELD_MULTJ_LESSON_HALF_LIFE_V2 = 'multj.lesson_half_life.v2';
    public const FIELD_MULTJ_PROCEDURAL_SKILL_PROMOTER_V1 = 'multj.procedural_skill_promoter.v1';
    public const FIELD_MULTJ_SEMANTIC_DEDUP_FREEZE_V1 = 'multj.semantic_dedup_freeze.v1';
    public const FIELD_MULTN17_PREDICTED_IMPACT_CALIBRATION_V1 = 'multn17.predicted_impact_calibration.v1';
    public const FIELD_MULTX_FLYWHEEL_LOOP_DEFINITION_V1 = 'multx.flywheel_loop_definition.v1';
    public const FIELD_MULTX_LEARNING_LATENCY_V1 = 'multx.learning_latency.v1';
    public const FIELD_MULTX_WINDOWS_ORCHESTRATOR_V1 = 'multx.windows_orchestrator.v1';
    public const FIELD_THRESHOLDS_COSINE_MERGE_THRESHOLD = 'thresholds.cosine_merge_threshold';
    public const FIELD_THRESHOLDS_DENOMINATOR_MIN_PAIRS = 'thresholds.denominator_min_pairs';
    public const FIELD_THRESHOLDS_DENOMINATOR_MIN_PROMOTED_LESSONS = 'thresholds.denominator_min_promoted_lessons';
    public const FIELD_THRESHOLDS_SAMPLE_RATE = 'thresholds.sample_rate';
    public const FIELD_COMPLETION_CLAIM_ALLOWED_WITHOUT_PROVEN_REAL = 'completion_claim_allowed_without_proven_real';
    public const FIELD__UNKNOWN = '.unknown';
    public const FIELD_UNKNOWN_LOTE_2_MEASURE_FREEZE_ = 'Unknown LOTE 2 measure freeze.';
    public const FIELD_A_VALID_LOOP_CHAINS_TASK__DECISION_RECEIPT__DELIVERED_CONTEXT__EXECUTION_OUTCOME__LESSON__AND_SUBSEQUENT_MEASURED_RECALL__PROVEN_REAL_OUTCOME_IS_MANDATORY_ = 'A valid loop chains task, decision receipt, delivered context, execution outcome, lesson, and subsequent measured recall; proven_real outcome is mandatory.';
    public const FIELD_BUCKET_LESSON_LIFT_BY_AGE_SINCE_PROMOTION_USING_TWO_WEEK_BUCKETS__BUCKETS_BELOW_N_8_PUBLISH_INSUFFICIENT_INSTEAD_OF_NULL_ = 'Bucket lesson lift by age since promotion using two-week buckets; buckets below n=8 publish insufficient instead of null.';
    public const FIELD_DERIVED_PREDICTED_IMPACT_BAND_VERSUS_REALIZED_PROVEN_REAL_OUTCOME_CURVE_FOR_ORIGINATION__REPORT_ONLY_UNTIL_AT_LEAST_20_REAL_ORIGINATIONS_RESOLVE_ = 'Derived predicted_impact band versus realized proven_real outcome curve for origination; report-only until at least 20 real originations resolve.';
    public const FIELD_MEASURE_P50_P95_LATENCY_FROM_OUTCOME_CREATED_LESSON_TO_FIRST_DELIVERED_CONTEXT_AND_FIRST_MEASURED_CITATION__NEVER_DELIVERED_REMAINS_IN_DENOMINATOR_ = 'Measure p50/p95 latency from outcome-created lesson to first delivered context and first measured citation; never_delivered remains in denominator.';
    public const FIELD_OPERATOR_NATURAL_LANGUAGE_REQUEST_TO_COMPLETED_RESULT_RATE__ASKS_PER_REQUEST__AND_REQUEST_TO_DELIVERY_LATENCY__ABANDONED_MISSIONS_STAY_IN_THE_DENOMINATOR_ = 'Operator natural-language request to completed result rate, asks per request, and request-to-delivery latency; abandoned missions stay in the denominator.';
    public const FIELD_PAIRED_PEEK_EVALUATION_OF_THE_SAME_TASK_WITH_AND_WITHOUT_INJECTED_LESSON__N_PAIRS_BELOW_8_PUBLISHES_INSUFFICIENT_SIGNAL_AND_PEEK_MUST_NOT_RECORD_USAGE_ = 'Paired peek evaluation of the same task with and without injected lesson; n_pairs below 8 publishes insufficient_signal and peek must not record usage.';
    public const FIELD_SEMANTIC_LESSON_DEDUP_THRESHOLD_FREEZE_FOR_OBSERVE_MODE_WOULD_MERGE_RECEIPTS__ENFORCEMENT_REQUIRES_LATER_CALIBRATED_PROMOTION_ = 'Semantic lesson dedup threshold freeze for observe-mode would-merge receipts; enforcement requires later calibrated promotion.';
    public const INT_3 = 3;
    public const FLOAT_0_0001 = 0.0001;
    public const FLOAT_0_05 = 0.05;
    public const FLOAT_0_70 = 0.70;
    public const FLOAT_0_88 = 0.88;
    public const INT_8 = 8;
    public const FLOAT_0_0 = 0.0;
    public const INT_2 = 2;
    public const INT_20 = 20;
    public const INT_30 = 30;

    /** @return array<string,mixed> */
    public static function freezePayload(string $slice): array
    {
        $slice = AiValueNormalizer::upperTrimmedString($slice);
        $payloads = self::freezePayloads();

        return $payloads[$slice] ?? [
            self::FIELD_KIND => self::KIND_MEASURE_FREEZE,
            self::FIELD_MEASURE_ID => AiValueNormalizer::lowerTrimmedString($slice).self::FIELD__UNKNOWN,
            self::FIELD_FORMULA_VERSION => AiValueNormalizer::lowerTrimmedString($slice).self::FIELD__UNKNOWN,
            self::FIELD_FORMULA => self::FIELD_UNKNOWN_LOTE_2_MEASURE_FREEZE_,
            self::FIELD_THRESHOLDS => [],
            self::FIELD_DENOMINATOR_MIN => 1,
            self::FIELD_TTL_DAYS => self::INT_30,
            self::FIELD_AUTHOR_ENGINE_ID => self::FIELD_CURSOR_ACOS_MAX_LOTE2,
            self::FIELD_JUDGE_ENGINE_ID => self::FIELD_CODEX_INDEPENDENT_LOTE2_JUDGE,
        ];
    }

    /** @return array<string,mixed> */
    public function maxl06DeltaAttribution(): array
    {
        return $this->emptyReport(self::FIELD_MAXL_06, self::STATUS_PENDING_WINDOW, self::REASON_MISSING_LINEAGE_LEDGER, [
            self::FIELD_MEASURE_ID => self::MAXL06_MEASURE_ID,
            self::FIELD_BASIS => self::BASIS_UNAVAILABLE,
            self::FIELD_ALLOWED_BASIS => [self::FIELD_LINEAGE_LEDGER, self::FIELD_GIT_LOG],
            self::FIELD_COUNTERFACTUAL_BASIS => self::FIELD_NONE,
            self::FIELD_CORRELATION_LABEL_REQUIRED => self::FIELD_CORRELATIONAL_ATTRIBUTION,
            self::FIELD_ATTRIBUTED_DELTA => [],
            self::FIELD_DEPENDENCIES => [self::FIELD_ASI_11, self::FIELD_MAXL_04],
        ]);
    }

    /** @return array<string,mixed> */
    public function multn1704PredictedImpact(): array
    {
        $originations = $this->countTableIfPresent(self::FIELD_ATLAS_LOOP_ORIGINATION_OUTCOMES);

        return $this->emptyReport(self::FIELD_MULTN17_04, self::STATUS_INSUFFICIENT_SIGNAL, self::REASON_PENDING_REAL_ORIGINATOR_OUTCOME, [
            self::FIELD_MEASURE_ID => self::MULTN1704_MEASURE_ID,
            self::FIELD_DENOMINATOR_MIN => self::INT_20,
            self::FIELD_DENOMINATOR => [
                self::FIELD_ORIGINATIONS => $originations,
                self::FIELD_RESOLVED_OUTCOMES => 0,
            ],
            self::FIELD_BANDS => [],
            self::FIELD_UNRESOLVED => $originations,
        ]);
    }

    /** @return array<string,mixed> */
    public function multx01FlywheelLoops(): array
    {
        $requiredTables = [self::FIELD_AI_RUN_OUTCOMES, self::FIELD_AI_RAG_FEEDBACK_EVENTS, self::FIELD_AI_LEARNING_CANDIDATES];
        $missingTables = array_values(array_filter($requiredTables, static fn (string $table): bool => ! Schema::hasTable($table)));
        if ($missingTables !== []) {
            return $this->emptyReport(self::FIELD_MULTX_01, self::STATUS_INSUFFICIENT_SIGNAL, self::REASON_LOOP_SOURCE_TABLES_MISSING, [
                self::FIELD_MEASURE_ID => self::MULTX01_MEASURE_ID,
                self::FIELD_DENOMINATOR_MIN => 1,
                self::FIELD_LOOPS_COMPLETE => 0,
                self::FIELD_LOOPS => [],
                self::FIELD_LOOPS_PARTIAL => [],
                self::FIELD_N_TOTAL => 0,
                self::FIELD_FIXTURE_REJECTED => 0,
                self::FIELD_MISSING_TABLES => $missingTables,
                self::FIELD_TIME_PER_LOOP => [
                    self::FIELD_P50_SECONDS => null,
                    self::FIELD_P95_SECONDS => null,
                ],
                self::FIELD_MARCO_ESP_V1 => [
                    self::FIELD_SATISFIED => false,
                    self::FIELD_BLOCKED_BY => [self::REASON_LOOP_SOURCE_TABLES_MISSING],
                ],
                self::FIELD_VALID_LOOP_DEFINITION => $this->multx01ValidLoopDefinition(),
            ]);
        }

        $deliveriesByOutcome = [];
        $recallsByCandidate = [];
        foreach (DB::table(self::FIELD_AI_RAG_FEEDBACK_EVENTS)->orderBy(self::FIELD_CREATED_AT)->get() as $row) {
            $outcomeId = AiValueNormalizer::trimmedStringOrNull($row->run_outcome_id ?? null) ?? '';
            if ($outcomeId !== '') {
                $deliveriesByOutcome[$outcomeId][] = $row;
            }

            $candidateId = AiValueNormalizer::trimmedStringOrNull($row->memory_candidate_id ?? null) ?? '';
            if ($candidateId !== '') {
                $recallsByCandidate[$candidateId][] = $row;
            }
        }

        $candidatesByOutcome = [];
        foreach (DB::table(self::FIELD_AI_LEARNING_CANDIDATES)->orderBy(self::FIELD_CREATED_AT)->get() as $candidate) {
            $outcomeId = AiValueNormalizer::trimmedStringOrNull($candidate->run_outcome_id ?? null) ?? '';
            if ($outcomeId !== '') {
                $candidatesByOutcome[$outcomeId][] = $candidate;
            }
        }

        $loops = [];
        $partial = [];
        $durations = [];
        $fixtureRejected = 0;

        foreach (DB::table(self::FIELD_AI_RUN_OUTCOMES)->orderBy(self::FIELD_CREATED_AT)->get() as $outcome) {
            $assembled = $this->assembleMultx01Loop(
                $outcome,
                $deliveriesByOutcome[AiValueNormalizer::trimmedScalarStringOrNull($outcome->id ?? null) ?? ''] ?? [],
                $candidatesByOutcome[AiValueNormalizer::trimmedScalarStringOrNull($outcome->id ?? null) ?? ''] ?? [],
                $recallsByCandidate,
            );

            if ($assembled[self::FIELD_COMPLETE] === true) {
                $loops[] = $assembled[self::FIELD_LOOP];
                $durations[] = (int) (AiValueNormalizer::finiteFloatOrNull(data_get($assembled, self::FIELD_LOOP_TIME_TO_RECALL_SECONDS)) ?? 0);
            } else {
                $partial[] = $assembled[self::FIELD_PARTIAL];
                if (in_array(self::FIELD_FIXTURE_CHAIN, AiValueNormalizer::arrayOrEmpty($assembled[self::FIELD_PARTIAL][self::FIELD_BLOCKED_BY] ?? null), true)) {
                    $fixtureRejected++;
                }
            }
        }

        $loopsComplete = count($loops);
        $marcoSatisfied = $loopsComplete >= 1;
        $blockedByTop = $this->blockedByTopN($partial, 10);

        return [
            self::FIELD_SCHEMA_VERSION => self::REPORT_SCHEMA,
            self::FIELD_SLICE => self::FIELD_MULTX_01,
            self::FIELD_STATUS => $marcoSatisfied ? self::STATUS_OK : self::STATUS_INSUFFICIENT_SIGNAL,
            self::FIELD_REASON => $marcoSatisfied ? null : self::FIELD_NO_COMPLETE_PROVEN_REAL_LOOP_WINDOW,
            self::FIELD_FORMULA_VERSION => AiValueNormalizer::trimmedStringOrNull(data_get(self::freezePayload(self::FIELD_MULTX_01), self::FIELD_FORMULA_VERSION)) ?? '',
            self::FIELD_GENERATED_AT => now()->toIso8601String(),
            self::FIELD_FREEZE => self::freezePayload(self::FIELD_MULTX_01),
            self::FIELD_MEASURE_ID => self::MULTX01_MEASURE_ID,
            self::FIELD_DENOMINATOR_MIN => 1,
            self::FIELD_LOOPS_COMPLETE => $loopsComplete,
            self::FIELD_LOOPS => $loops,
            self::FIELD_LOOPS_PARTIAL => $partial,
            self::FIELD_BLOCKED_BY_TOP => $blockedByTop,
            self::FIELD_N_TOTAL => $loopsComplete + count($partial),
            self::FIELD_FIXTURE_REJECTED => $fixtureRejected,
            self::FIELD_TIME_PER_LOOP => [
                self::FIELD_P50_SECONDS => $this->percentileInt($durations, 0.50),
                self::FIELD_P95_SECONDS => $this->percentileInt($durations, 0.95),
            ],
            self::FIELD_MARCO_ESP_V1 => [
                self::FIELD_SATISFIED => $marcoSatisfied,
                self::FIELD_BLOCKED_BY => $marcoSatisfied ? [] : [self::FIELD_NO_COMPLETE_PROVEN_REAL_LOOP_WINDOW],
                self::FIELD_REQUIRES_LOOPS_COMPLETE_MIN => 1,
                self::FIELD_REQUIRES_PROVEN_REAL => true,
                self::FIELD_REQUIRES_CHAINED_IDS => true,
                self::FIELD_REQUIRES_ZERO_FIXTURE => true,
            ],
            self::FIELD_VALID_LOOP_DEFINITION => $this->multx01ValidLoopDefinition(),
            self::FIELD_CLAIM_POLICY => [
                self::FIELD_READ_ONLY => true,
                self::FIELD_PROVIDER_CALLS_MADE => false,
                self::FIELD_MEMORY_WRITTEN => false,
                self::FIELD_SYNTHETIC_FIXTURE_CLAIM_ALLOWED => false,
                self::FIELD_COMPLETION_CLAIM_ALLOWED_WITHOUT_PROVEN_REAL => false,
            ],
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $partial
     * @return list<array{reason:string,count:int}>
     */
    private function blockedByTopN(array $partial, int $limit): array
    {
        $counts = [];
        foreach ($partial as $row) {
            foreach (AiValueNormalizer::arrayOrEmpty($row[self::FIELD_BLOCKED_BY] ?? null) as $reason) {
                $key = AiValueNormalizer::trimmedStringOrNull($reason) ?? '';
                if ($key === '') {
                    continue;
                }
                $counts[$key] = ($counts[$key] ?? 0) + 1;
            }
        }
        arsort($counts);
        $top = [];
        foreach (array_slice($counts, 0, max(1, $limit), true) as $reason => $count) {
            $top[] = [self::FIELD_REASON => AiValueNormalizer::trimmedScalarStringOrNull($reason) ?? '', self::FIELD_COUNT => (int) $count];
        }

        return $top;
    }

    /** @return array<string,mixed> */
    private function multx01ValidLoopDefinition(): array
    {
        return [
            self::FIELD_REQUIRES_PROVEN_REAL_OUTCOME => true,
            self::FIELD_REQUIRES_DECISION_RECEIPT_ID => true,
            self::FIELD_REQUIRES_DELIVERED_CONTEXT_RECEIPT => true,
            self::FIELD_REQUIRES_LEARNING_CANDIDATE => true,
            self::FIELD_REQUIRES_SUBSEQUENT_MEASURED_RECALL => true,
            self::FIELD_REQUIRES_ZERO_FIXTURE => true,
            self::FIELD_LEGACY_UNJOINED_ROWS => self::FIELD_LEGACY_UNJOINED,
        ];
    }

    /**
     * @param  list<object>  $deliveries
     * @param  list<object>  $candidates
     * @param  array<string,list<object>>  $recallsByCandidate
     * @return array{complete:bool,loop?:array<string,mixed>,partial?:array<string,mixed>}
     */
    private function assembleMultx01Loop(object $outcome, array $deliveries, array $candidates, array $recallsByCandidate): array
    {
        $outcomePayload = $this->decodeJsonObject($outcome->payload ?? null);
        $delivery = $this->firstContextDelivery($deliveries);
        $candidate = $candidates[0] ?? null;
        $recall = $candidate === null ? null : $this->firstSubsequentRecall(
            $recallsByCandidate[AiValueNormalizer::trimmedScalarStringOrNull($candidate->id ?? null) ?? ''] ?? [],
            AiValueNormalizer::trimmedString($candidate->created_at ?? $outcome->created_at ?? ''),
        );

        $decisionId = $this->firstNonEmpty([
            data_get($outcomePayload, self::FIELD_DECISION_ID),
            data_get($outcomePayload, self::FIELD_DECISION_RECEIPT_ID),
            data_get($outcomePayload, self::FIELD_RECEIPT_ID),
        ]);
        $provenReal = data_get($outcomePayload, self::FIELD_PROVEN_REAL) === true;
        $fixture = $this->isFixtureMarked($outcome, $outcomePayload)
            || ($delivery !== null && $this->isFixtureMarked($delivery, $this->decodeJsonObject($delivery->payload ?? null)))
            || ($candidate !== null && $this->isFixtureMarked($candidate, $this->decodeJsonObject($candidate->payload ?? null)))
            || ($recall !== null && $this->isFixtureMarked($recall, $this->decodeJsonObject($recall->payload ?? null)));

        $blockedBy = [];
        if (! $provenReal) {
            $blockedBy[] = self::FIELD_OUTCOME_NOT_PROVEN_REAL;
        }
        if ($decisionId === '') {
            $blockedBy[] = self::FIELD_DECISION_RECEIPT_MISSING;
        }
        if ($delivery === null || (AiValueNormalizer::trimmedStringOrNull($delivery->retrieval_receipt_id ?? null) ?? '') === '') {
            $blockedBy[] = self::FIELD_DELIVERED_CONTEXT_MISSING;
        }
        if ($candidate === null) {
            $blockedBy[] = self::FIELD_LEARNING_CANDIDATE_MISSING;
        }
        if ($recall === null) {
            $blockedBy[] = self::FIELD_SUBSEQUENT_MEASURED_RECALL_MISSING;
        }
        if ($fixture) {
            $blockedBy[] = self::FIELD_FIXTURE_CHAIN;
        }

        $taskId = $this->firstNonEmpty([
            data_get($outcomePayload, self::FIELD_TASK_ID),
            $outcome->run_id ?? null,
        ]);

        $chain = [
            self::FIELD_TASK_ID => $taskId,
            self::FIELD_OUTCOME_ID => AiValueNormalizer::trimmedScalarStringOrNull($outcome->id ?? null) ?? '',
            self::FIELD_DECISION_ID => $decisionId,
            self::FIELD_RETRIEVAL_RECEIPT_ID => $delivery === null ? null : (AiValueNormalizer::trimmedScalarStringOrNull($delivery->retrieval_receipt_id ?? null) ?? ''),
            self::FIELD_LEARNING_CANDIDATE_ID => $candidate === null ? null : (AiValueNormalizer::trimmedScalarStringOrNull($candidate->id ?? null) ?? ''),
            self::FIELD_SUBSEQUENT_RECALL_FEEDBACK_ID => $recall === null ? null : (AiValueNormalizer::trimmedScalarStringOrNull($recall->id ?? null) ?? ''),
        ];

        if ($blockedBy !== []) {
            return [
                self::FIELD_COMPLETE => false,
                self::FIELD_PARTIAL => [
                    self::FIELD_LOOP_ID => hash(self::FIELD_SHA256, implode('|', array_map(static fn ($value): string => AiValueNormalizer::trimmedScalarStringOrNull($value) ?? '', $chain))),
                    self::FIELD_CHAIN => $chain,
                    self::FIELD_PROVEN_REAL => $provenReal,
                    self::FIELD_FIXTURE_FREE => ! $fixture,
                    self::FIELD_BLOCKED_BY => array_values(array_unique($blockedBy)),
                ],
            ];
        }

        return [
            self::FIELD_COMPLETE => true,
            self::FIELD_LOOP => [
                self::FIELD_LOOP_ID => hash(self::FIELD_SHA256, implode('|', array_map(static fn ($value): string => AiValueNormalizer::trimmedScalarStringOrNull($value) ?? '', $chain))),
                self::FIELD_CHAIN => $chain,
                self::FIELD_PROVEN_REAL => true,
                self::FIELD_FIXTURE_FREE => true,
                self::FIELD_TIME_TO_RECALL_SECONDS => $this->secondsBetween(
                    AiValueNormalizer::trimmedString($outcome->created_at ?? ''),
                    AiValueNormalizer::trimmedString($recall->created_at ?? ''),
                ),
            ],
        ];
    }

    /**
     * @param  list<object>  $deliveries
     */
    private function firstContextDelivery(array $deliveries): ?object
    {
        foreach ($deliveries as $delivery) {
            if ((AiValueNormalizer::trimmedStringOrNull($delivery->retrieval_receipt_id ?? null) ?? '') !== '') {
                return $delivery;
            }
        }

        return null;
    }

    /**
     * @param  list<object>  $recalls
     */
    private function firstSubsequentRecall(array $recalls, string $candidateCreatedAt): ?object
    {
        foreach ($recalls as $recall) {
            if ($candidateCreatedAt === '' || strtotime(AiValueNormalizer::trimmedString($recall->created_at ?? '')) >= strtotime($candidateCreatedAt)) {
                return $recall;
            }
        }

        return null;
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function isFixtureMarked(object $row, array $payload): bool
    {
        if (data_get($payload, self::FIELD_FIXTURE) === true || data_get($payload, self::FIELD_IS_FIXTURE) === true) {
            return true;
        }

        return str_contains(AiValueNormalizer::lowerTrimmedString($row->source ?? ''), self::FIELD_FIXTURE);
    }

    /**
     * @param  list<mixed>  $values
     */
    private function firstNonEmpty(array $values): string
    {
        return FirstNonEmptyString::from($values);
    }

    private function secondsBetween(string $start, string $end): int
    {
        $startTs = strtotime($start);
        $endTs = strtotime($end);
        if ($startTs === false || $endTs === false) {
            return 0;
        }

        return max(0, $endTs - $startTs);
    }

    /**
     * @param  list<int>  $values
     */
    private function percentileInt(array $values, float $percentile): ?int
    {
        if ($values === []) {
            return null;
        }

        sort($values);
        $index = (int) ceil(count($values) * $percentile) - 1;
        $index = max(0, min(count($values) - 1, $index));

        return $values[$index];
    }

    /** @return array<string,mixed> */
    public function multx06LearningLatency(): array
    {
        $requiredTables = [self::FIELD_AI_RUN_OUTCOMES, self::FIELD_AI_RAG_FEEDBACK_EVENTS, self::FIELD_AI_LEARNING_CANDIDATES];
        $missingTables = array_values(array_filter($requiredTables, static fn (string $table): bool => ! Schema::hasTable($table)));
        $denominatorMin = (int) data_get(self::freezePayload(self::FIELD_MULTX_06), self::FIELD_THRESHOLDS_DENOMINATOR_MIN_PROMOTED_LESSONS, 8);

        if ($missingTables !== []) {
            return $this->emptyReport(self::FIELD_MULTX_06, self::STATUS_INSUFFICIENT_SIGNAL, self::REASON_LEARNING_LATENCY_SOURCE_TABLES_MISSING, [
                self::FIELD_MEASURE_ID => self::MULTX06_MEASURE_ID,
                self::FIELD_DENOMINATOR_MIN => $denominatorMin,
                'n' => 0,
                self::FIELD_BY_LESSON_CLASS => [],
                self::FIELD_NEVER_DELIVERED => 0,
                self::FIELD_NEVER_CITED => 0,
                self::FIELD_LATENCY_SECONDS => [
                    self::FIELD_DELIVERY_P50 => null,
                    self::FIELD_DELIVERY_P95 => null,
                    self::FIELD_CITATION_P50 => null,
                    self::FIELD_CITATION_P95 => null,
                ],
                self::FIELD_MISSING_TABLES => $missingTables,
            ]);
        }

        $outcomes = [];
        foreach (DB::table(self::FIELD_AI_RUN_OUTCOMES)->get() as $outcome) {
            $outcomes[AiValueNormalizer::trimmedScalarStringOrNull($outcome->id ?? null) ?? ''] = $outcome;
        }

        $deliveriesByOutcome = [];
        $citationsByCandidate = [];
        foreach (DB::table(self::FIELD_AI_RAG_FEEDBACK_EVENTS)->orderBy(self::FIELD_CREATED_AT)->get() as $row) {
            $outcomeId = AiValueNormalizer::trimmedStringOrNull($row->run_outcome_id ?? null) ?? '';
            if ($outcomeId !== '') {
                $deliveriesByOutcome[$outcomeId][] = $row;
            }
            $candidateId = AiValueNormalizer::trimmedStringOrNull($row->memory_candidate_id ?? null) ?? '';
            if ($candidateId !== '') {
                $citationsByCandidate[$candidateId][] = $row;
            }
        }

        $rows = [];
        $deliveryLatencies = [];
        $citationLatencies = [];
        $neverDelivered = 0;
        $neverCited = 0;
        $byClass = [];

        foreach (DB::table(self::FIELD_AI_LEARNING_CANDIDATES)->orderBy(self::FIELD_CREATED_AT)->get() as $candidate) {
            if (! $this->isPromotedLearningCandidate($candidate)) {
                continue;
            }

            $candidateId = AiValueNormalizer::trimmedScalarStringOrNull($candidate->id ?? null) ?? '';
            $lessonClass = AiValueNormalizer::trimmedStringOrNull($candidate->memory_type ?? null) ?? self::MEMORY_TYPE_UNKNOWN;
            $outcome = $outcomes[AiValueNormalizer::trimmedScalarStringOrNull($candidate->run_outcome_id ?? null) ?? ''] ?? null;
            if ($outcome === null) {
                continue;
            }

            $delivery = $this->firstContextDelivery($deliveriesByOutcome[AiValueNormalizer::trimmedScalarStringOrNull($outcome->id ?? null) ?? ''] ?? []);
            $citation = $this->firstSubsequentRecall($citationsByCandidate[$candidateId] ?? [], AiValueNormalizer::trimmedString($candidate->created_at ?? ''));
            $deliverySeconds = $delivery === null ? null : $this->secondsBetween(AiValueNormalizer::trimmedString($outcome->created_at ?? ''), AiValueNormalizer::trimmedString($delivery->created_at ?? ''));
            $citationSeconds = $citation === null ? null : $this->secondsBetween(AiValueNormalizer::trimmedString($outcome->created_at ?? ''), AiValueNormalizer::trimmedString($citation->created_at ?? ''));

            if ($deliverySeconds === null) {
                $neverDelivered++;
            } else {
                $deliveryLatencies[] = $deliverySeconds;
            }
            if ($citationSeconds === null) {
                $neverCited++;
            } else {
                $citationLatencies[] = $citationSeconds;
            }

            $byClass[$lessonClass] ??= [
                self::FIELD_LESSON_CLASS => $lessonClass,
                'n' => 0,
                self::FIELD_DELIVERED => 0,
                self::FIELD_CITED => 0,
                self::FIELD_NEVER_DELIVERED => 0,
                self::FIELD_NEVER_CITED => 0,
                self::FIELD_DELIVERY_LATENCIES => [],
                self::FIELD_CITATION_LATENCIES => [],
            ];
            $byClass[$lessonClass]['n']++;
            if ($deliverySeconds === null) {
                $byClass[$lessonClass][self::FIELD_NEVER_DELIVERED]++;
            } else {
                $byClass[$lessonClass][self::FIELD_DELIVERED]++;
                $byClass[$lessonClass][self::FIELD_DELIVERY_LATENCIES][] = $deliverySeconds;
            }
            if ($citationSeconds === null) {
                $byClass[$lessonClass][self::FIELD_NEVER_CITED]++;
            } else {
                $byClass[$lessonClass][self::FIELD_CITED]++;
                $byClass[$lessonClass][self::FIELD_CITATION_LATENCIES][] = $citationSeconds;
            }

            $rows[] = [
                self::FIELD_CANDIDATE_ID => $candidateId,
                self::FIELD_OUTCOME_ID => AiValueNormalizer::trimmedScalarStringOrNull($outcome->id ?? null) ?? '',
                self::FIELD_LESSON_CLASS => $lessonClass,
                self::FIELD_DELIVERED => $deliverySeconds !== null,
                self::FIELD_CITED => $citationSeconds !== null,
                self::FIELD_DELIVERY_LATENCY_SECONDS => $deliverySeconds,
                self::FIELD_CITATION_LATENCY_SECONDS => $citationSeconds,
            ];
        }

        $n = count($rows);
        $status = $n >= $denominatorMin ? self::STATUS_OK : self::STATUS_INSUFFICIENT_SIGNAL;

        return [
            self::FIELD_SCHEMA_VERSION => self::REPORT_SCHEMA,
            self::FIELD_SLICE => self::FIELD_MULTX_06,
            self::FIELD_STATUS => $status,
            self::FIELD_REASON => $status === self::STATUS_OK ? null : self::FIELD_PROMOTED_LESSON_DENOMINATOR_BELOW_MIN,
            self::FIELD_FORMULA_VERSION => AiValueNormalizer::trimmedStringOrNull(data_get(self::freezePayload(self::FIELD_MULTX_06), self::FIELD_FORMULA_VERSION)) ?? '',
            self::FIELD_GENERATED_AT => now()->toIso8601String(),
            self::FIELD_FREEZE => self::freezePayload(self::FIELD_MULTX_06),
            self::FIELD_MEASURE_ID => self::MULTX06_MEASURE_ID,
            self::FIELD_DENOMINATOR_MIN => $denominatorMin,
            'n' => $n,
            self::FIELD_BY_LESSON_CLASS => $this->learningLatencyByClass($byClass),
            self::FIELD_NEVER_DELIVERED => $neverDelivered,
            self::FIELD_NEVER_CITED => $neverCited,
            self::FIELD_LATENCY_SECONDS => [
                self::FIELD_DELIVERY_P50 => $this->percentileInt($deliveryLatencies, 0.50),
                self::FIELD_DELIVERY_P95 => $this->percentileInt($deliveryLatencies, 0.95),
                self::FIELD_CITATION_P50 => $this->percentileInt($citationLatencies, 0.50),
                self::FIELD_CITATION_P95 => $this->percentileInt($citationLatencies, 0.95),
            ],
            self::FIELD_ROWS => $rows,
            self::FIELD_CLAIM_POLICY => [
                self::FIELD_READ_ONLY => true,
                self::FIELD_PROVIDER_CALLS_MADE => false,
                self::FIELD_MEMORY_WRITTEN => false,
                self::FIELD_NEVER_DELIVERED_IN_DENOMINATOR => true,
            ],
        ];
    }

    private function isPromotedLearningCandidate(object $candidate): bool
    {
        return (AiValueNormalizer::trimmedScalarStringOrNull($candidate->status ?? null) ?? '') === self::FIELD_PROMOTED
            || (AiValueNormalizer::boolOrNull($candidate->promotion_allowed ?? null) ?? false) === true;
    }

    /**
     * @param  array<string,array<string,mixed>>  $byClass
     * @return list<array<string,mixed>>
     */
    private function learningLatencyByClass(array $byClass): array
    {
        ksort($byClass);

        return array_values(array_map(function (array $row): array {
            return [
                self::FIELD_LESSON_CLASS => AiValueNormalizer::trimmedScalarStringOrNull($row[self::FIELD_LESSON_CLASS] ?? null) ?? '',
                'n' => (int) (AiValueNormalizer::finiteFloatOrNull($row['n'] ?? null) ?? 0),
                self::FIELD_DELIVERED => (int) (AiValueNormalizer::finiteFloatOrNull($row[self::FIELD_DELIVERED] ?? null) ?? 0),
                self::FIELD_CITED => (int) (AiValueNormalizer::finiteFloatOrNull($row[self::FIELD_CITED] ?? null) ?? 0),
                self::FIELD_NEVER_DELIVERED => (int) (AiValueNormalizer::finiteFloatOrNull($row[self::FIELD_NEVER_DELIVERED] ?? null) ?? 0),
                self::FIELD_NEVER_CITED => (int) (AiValueNormalizer::finiteFloatOrNull($row[self::FIELD_NEVER_CITED] ?? null) ?? 0),
                self::FIELD_LATENCY_SECONDS => [
                    self::FIELD_DELIVERY_P50 => $this->percentileInt(AiValueNormalizer::arrayOrEmpty($row[self::FIELD_DELIVERY_LATENCIES] ?? null), 0.50),
                    self::FIELD_DELIVERY_P95 => $this->percentileInt(AiValueNormalizer::arrayOrEmpty($row[self::FIELD_DELIVERY_LATENCIES] ?? null), 0.95),
                    self::FIELD_CITATION_P50 => $this->percentileInt(AiValueNormalizer::arrayOrEmpty($row[self::FIELD_CITATION_LATENCIES] ?? null), 0.50),
                    self::FIELD_CITATION_P95 => $this->percentileInt(AiValueNormalizer::arrayOrEmpty($row[self::FIELD_CITATION_LATENCIES] ?? null), 0.95),
                ],
            ];
        }, $byClass));
    }

    /** @return array<string,mixed> */
    public function multj01LessonHalfLife(): array
    {
        return $this->emptyReport(self::FIELD_MULTJ_01, self::STATUS_INSUFFICIENT_SIGNAL, self::REASON_NO_MEASURED_LESSON_USAGE_BUCKETS, [
            self::FIELD_MEASURE_ID => self::MULTJ01_MEASURE_ID,
            self::FIELD_DENOMINATOR_MIN => self::INT_8,
            self::FIELD_BUCKET_WIDTH_WEEKS => self::INT_2,
            self::FIELD_MEMORY_TYPES => [],
            self::FIELD_BUCKETS => [],
        ]);
    }

    /** @return array<string,mixed> */
    public function multj02DedupCalibration(): array
    {
        return $this->emptyReport(self::FIELD_MULTJ_02, self::STATUS_PENDING_WINDOW, self::REASON_CALIBRATION_FREEZE_ONLY, [
            self::FIELD_MEASURE_ID => self::MULTJ02_MEASURE_ID,
            self::FIELD_MODE => self::MODE_OBSERVE,
            self::FIELD_WOULD_MERGE_COUNT => 0,
            self::FIELD_ACTUAL_MERGE_COUNT => 0,
            self::FIELD_THRESHOLD => data_get(self::freezePayload(self::FIELD_MULTJ_02), self::FIELD_THRESHOLDS_COSINE_MERGE_THRESHOLD),
            self::FIELD_REVERSIBLE_RECEIPT_REQUIRED => true,
        ]);
    }

    /** @return array<string,mixed> */
    public function multj03CounterfactualLift(): array
    {
        $denominatorMin = (int) data_get(self::freezePayload(self::FIELD_MULTJ_03), self::FIELD_THRESHOLDS_DENOMINATOR_MIN_PAIRS, 8);
        $sampleRate = AiValueNormalizer::finiteFloatOrNull(data_get(self::freezePayload(self::FIELD_MULTJ_03), self::FIELD_THRESHOLDS_SAMPLE_RATE, 0.05)) ?? 0.05;

        if (! Schema::hasTable(self::FIELD_AI_RAG_FEEDBACK_EVENTS)) {
            return $this->emptyReport(self::FIELD_MULTJ_03, self::STATUS_INSUFFICIENT_SIGNAL, self::REASON_PAIRED_FEEDBACK_TABLE_MISSING, [
                self::FIELD_MEASURE_ID => self::MULTJ03_MEASURE_ID,
                self::FIELD_DENOMINATOR_MIN => $denominatorMin,
                self::FIELD_SAMPLE_RATE => $sampleRate,
                self::FIELD_RATE => $sampleRate,
                self::FIELD_N_PAIRS => 0,
                self::FIELD_PAIRED_DELTA => null,
                self::FIELD_MEMORY_TYPES => [],
                self::FIELD_PEEK_POLICY => [
                    self::FIELD_RECORD_USAGE_FOR_PEEK => false,
                    self::FIELD_USAGE_ROWS_RECORDED => 0,
                ],
                self::FIELD_INVALID_PAIRS => [
                    self::FIELD_PEEK_POLICY_VIOLATION => 0,
                    self::FIELD_INCOMPLETE => 0,
                    self::FIELD_POSITIVE_LIFT_FABRICATED => 0,
                ],
            ]);
        }

        $pairs = [];
        foreach (DB::table(self::FIELD_AI_RAG_FEEDBACK_EVENTS)->orderBy(self::FIELD_CREATED_AT)->get() as $row) {
            $payload = $this->decodeJsonObject($row->payload ?? null);
            $meta = $this->counterfactualLiftMeta($payload);
            if ($meta === []) {
                continue;
            }

            $pairId = AiValueNormalizer::trimmedStringOrNull($meta[self::FIELD_PAIR_ID] ?? null) ?? '';
            $arm = $this->counterfactualArm((AiValueNormalizer::trimmedStringOrNull($meta[self::FIELD_ARM] ?? null) ?? ''));
            if ($pairId === '' || $arm === '') {
                continue;
            }

            $pairs[$pairId] ??= [
                self::FIELD_MEMORY_TYPE => $this->memoryTypeFromCounterfactualMeta($meta),
                self::FIELD_ROWS => [],
                self::FIELD_POLICY_VIOLATION_ROWS => 0,
            ];
            $pairs[$pairId][self::FIELD_MEMORY_TYPE] = $pairs[$pairId][self::FIELD_MEMORY_TYPE] !== self::MEMORY_TYPE_UNKNOWN
                ? $pairs[$pairId][self::FIELD_MEMORY_TYPE]
                : $this->memoryTypeFromCounterfactualMeta($meta);
            $pairs[$pairId][self::FIELD_ROWS][$arm] = [
                self::FIELD_SCORE => $this->counterfactualScore($row, $meta),
                self::FIELD_POLICY_VALID => AiValueNormalizer::lowerTrimmedString($meta[self::FIELD_MODE] ?? self::FIELD_PEEK) === self::FIELD_PEEK
                    && ($meta[self::FIELD_RECORD_USAGE] ?? false) === false,
            ];
            if (! $pairs[$pairId][self::FIELD_ROWS][$arm][self::FIELD_POLICY_VALID]) {
                $pairs[$pairId][self::FIELD_POLICY_VIOLATION_ROWS]++;
            }
        }

        $groups = [];
        $validDeltas = [];
        $invalidPolicyPairs = 0;
        $invalidPolicyRows = 0;
        $incompletePairs = 0;
        $positiveLiftFabricated = 0;

        foreach ($pairs as $pair) {
            $rows = $pair[self::FIELD_ROWS];
            if (($pair[self::FIELD_POLICY_VIOLATION_ROWS] ?? 0) > 0) {
                $invalidPolicyPairs++;
                $invalidPolicyRows += count($rows);
                continue;
            }
            if (! isset($rows[self::FIELD_CONTROL], $rows[self::FIELD_TREATMENT])) {
                $incompletePairs++;
                continue;
            }

            $memoryType = (AiValueNormalizer::trimmedStringOrNull($pair[self::FIELD_MEMORY_TYPE] ?? null) ?? self::MEMORY_TYPE_UNKNOWN);
            $control = AiValueNormalizer::finiteFloatOrNull($rows[self::FIELD_CONTROL][self::FIELD_SCORE] ?? null) ?? 0.0;
            $treatment = AiValueNormalizer::finiteFloatOrNull($rows[self::FIELD_TREATMENT][self::FIELD_SCORE] ?? null) ?? 0.0;
            $delta = round($treatment - $control, 4);
            $groups[$memoryType] ??= [
                self::FIELD_MEMORY_TYPE => $memoryType,
                self::FIELD_N_PAIRS => 0,
                self::FIELD_CONTROL_SCORE_SUM => self::FLOAT_0_0,
                self::FIELD_TREATMENT_SCORE_SUM => self::FLOAT_0_0,
                self::FIELD_DELTA_SUM => self::FLOAT_0_0,
            ];
            $groups[$memoryType][self::FIELD_N_PAIRS]++;
            $groups[$memoryType][self::FIELD_CONTROL_SCORE_SUM] += $control;
            $groups[$memoryType][self::FIELD_TREATMENT_SCORE_SUM] += $treatment;
            $groups[$memoryType][self::FIELD_DELTA_SUM] += $delta;
            $validDeltas[] = $delta;

            if ($memoryType === self::FIELD_IRRELEVANT && $delta > self::FLOAT_0_0001) {
                $positiveLiftFabricated++;
            }
        }

        $memoryTypes = array_values(array_map(
            fn (array $group): array => $this->finalizeCounterfactualLiftGroup($group, $denominatorMin),
            $groups,
        ));
        usort($memoryTypes, static fn (array $a, array $b): int => $a[self::FIELD_MEMORY_TYPE] <=> $b[self::FIELD_MEMORY_TYPE]);

        $measured = array_values(array_filter($memoryTypes, static fn (array $group): bool => $group[self::FIELD_STATUS] === self::STATUS_MEASURED));
        $measuredPairs = array_sum(array_column($measured, self::FIELD_N_PAIRS));
        $measuredDeltaSum = array_sum(array_map(
            static fn (array $group): float => (AiValueNormalizer::finiteFloatOrNull($group[self::FIELD_PAIRED_DELTA] ?? null) ?? 0.0) * (int) $group[self::FIELD_N_PAIRS],
            $measured,
        ));

        return [
            self::FIELD_SCHEMA_VERSION => self::REPORT_SCHEMA,
            self::FIELD_SLICE => self::FIELD_MULTJ_03,
            self::FIELD_STATUS => $measuredPairs > 0 ? self::STATUS_OK : self::STATUS_INSUFFICIENT_SIGNAL,
            self::FIELD_REASON => $measuredPairs > 0 ? null : self::FIELD_PAIRED_PEEK_FLOOR_BELOW_MINIMUM,
            self::FIELD_FORMULA_VERSION => AiValueNormalizer::trimmedStringOrNull(data_get(self::freezePayload(self::FIELD_MULTJ_03), self::FIELD_FORMULA_VERSION)) ?? '',
            self::FIELD_GENERATED_AT => now()->toIso8601String(),
            self::FIELD_FREEZE => self::freezePayload(self::FIELD_MULTJ_03),
            self::FIELD_MEASURE_ID => self::MULTJ03_MEASURE_ID,
            self::FIELD_DENOMINATOR_MIN => $denominatorMin,
            self::FIELD_SAMPLE_RATE => $sampleRate,
            self::FIELD_RATE => $sampleRate,
            self::FIELD_N_PAIRS => count($validDeltas),
            self::FIELD_PAIRED_DELTA => $measuredPairs > 0 ? round($measuredDeltaSum / $measuredPairs, 4) : null,
            self::FIELD_MEMORY_TYPES => $memoryTypes,
            self::FIELD_PEEK_POLICY => [
                self::FIELD_RECORD_USAGE_FOR_PEEK => false,
                self::FIELD_USAGE_ROWS_RECORDED => $invalidPolicyRows,
            ],
            self::FIELD_INVALID_PAIRS => [
                self::FIELD_PEEK_POLICY_VIOLATION => $invalidPolicyPairs,
                self::FIELD_INCOMPLETE => $incompletePairs,
                self::FIELD_POSITIVE_LIFT_FABRICATED => $positiveLiftFabricated,
            ],
            self::FIELD_CLAIM_POLICY => [
                self::FIELD_READ_ONLY => true,
                self::FIELD_PROVIDER_CALLS_MADE => false,
                self::FIELD_MEMORY_WRITTEN => false,
                self::FIELD_RETRIEVAL_POLICY_CHANGED => false,
                self::FIELD_RECORD_USAGE_FOR_PEEK => false,
                self::FIELD_SYNTHETIC_FIXTURE_CLAIM_ALLOWED => false,
                self::FIELD_COMPLETION_CLAIM_ALLOWED => false,
            ],
        ];
    }

    /** @return array<string,mixed> */
    public function multj04ProceduralSkillPromoter(): array
    {
        return app(AcosMaxProceduralSkillPromoterService::class)->report();
    }

    /**
     * @param  array<string,mixed>  $group
     * @return array<string,mixed>
     */
    private function finalizeCounterfactualLiftGroup(array $group, int $denominatorMin): array
    {
        $n = (int) (AiValueNormalizer::finiteFloatOrNull($group[self::FIELD_N_PAIRS] ?? null) ?? 0);

        return [
            self::FIELD_MEMORY_TYPE => AiValueNormalizer::trimmedScalarStringOrNull($group[self::FIELD_MEMORY_TYPE] ?? null) ?? '',
            self::FIELD_STATUS => $n >= $denominatorMin ? self::STATUS_MEASURED : self::STATUS_INSUFFICIENT_SIGNAL,
            self::FIELD_N_PAIRS => $n,
            self::FIELD_CONTROL_SCORE_MEAN => $n > 0 ? round((AiValueNormalizer::finiteFloatOrNull($group[self::FIELD_CONTROL_SCORE_SUM] ?? null) ?? 0.0) / $n, 4) : null,
            self::FIELD_TREATMENT_SCORE_MEAN => $n > 0 ? round((AiValueNormalizer::finiteFloatOrNull($group[self::FIELD_TREATMENT_SCORE_SUM] ?? null) ?? 0.0) / $n, 4) : null,
            self::FIELD_PAIRED_DELTA => $n > 0 ? round((AiValueNormalizer::finiteFloatOrNull($group[self::FIELD_DELTA_SUM] ?? null) ?? 0.0) / $n, 4) : null,
        ];
    }

    /** @return array<string,mixed> */
    private function decodeJsonObject(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (AiValueNormalizer::trimmedStringOrNull($value) === null) {
            return [];
        }

        $decoded = json_decode($value, true);

        return AiValueNormalizer::arrayOrEmpty($decoded);
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function counterfactualLiftMeta(array $payload): array
    {
        $meta = data_get($payload, self::FIELD_COUNTERFACTUAL_LIFT_V2);

        return AiValueNormalizer::arrayOrEmpty($meta);
    }

    private function counterfactualArm(string $arm): string
    {
        $arm = AiValueNormalizer::lowerTrimmedString($arm);

        return match ($arm) {
            self::FIELD_CONTROL, self::FIELD_WITHOUT, self::FIELD_WITHOUT_LESSON => self::FIELD_CONTROL,
            self::FIELD_TREATMENT, self::FIELD_WITH, self::FIELD_WITH_LESSON => self::FIELD_TREATMENT,
            default => '',
        };
    }

    /**
     * @param  array<string,mixed>  $meta
     */
    private function memoryTypeFromCounterfactualMeta(array $meta): string
    {
        $memoryType = AiValueNormalizer::trimmedStringOrNull($meta[self::FIELD_MEMORY_TYPE] ?? null) ?? '';

        return $memoryType === '' ? self::MEMORY_TYPE_UNKNOWN : $memoryType;
    }

    /**
     * @param  array<string,mixed>  $meta
     */
    private function counterfactualScore(object $row, array $meta): float
    {
        $score = $meta[self::FIELD_SCORE] ?? $row->post_execution_utility ?? $row->context_sufficiency ?? 0;

        return AiValueNormalizer::finiteFloatOrNull($score) ?? 0.0;
    }

    /** @return array<string,mixed> */
    public function teto02MissionE2e(?int $days = null): array
    {
        if (! Schema::hasTable(self::FIELD_ATLAS_MISSION_DELIVERIES)) {
            return $this->emptyReport(self::FIELD_TETO_02, self::STATUS_INSUFFICIENT_SIGNAL, self::REASON_MISSION_DELIVERY_TABLE_MISSING, [
                self::FIELD_MEASURE_ID => self::TETO02_MEASURE_ID,
                self::FIELD_DENOMINATOR_MIN => self::INT_20,
                self::FIELD_WINDOW_DAYS => $days,
                self::FIELD_DENOMINATOR => [self::FIELD_OPERATOR_REQUESTS => 0],
            ]);
        }

        $query = DB::table(self::FIELD_ATLAS_MISSION_DELIVERIES);
        if ($days !== null && $days > 0) {
            $query->where(self::FIELD_CREATED_AT, '>=', now()->subDays($days));
        }
        $rows = $query->get();
        $total = $rows->count();
        $completed = $rows->filter(static fn ($row): bool => in_array(AiValueNormalizer::lowerTrimmedString($row->status ?? ''), [
            self::MISSION_STATUS_COMPLETED,
            self::MISSION_STATUS_DELIVERED,
            self::MISSION_STATUS_SUCCEEDED,
            self::MISSION_STATUS_SUCCESS,
        ], true))->count();

        return [
            self::FIELD_SCHEMA_VERSION => self::REPORT_SCHEMA,
            self::FIELD_SLICE => self::FIELD_TETO_02,
            self::FIELD_STATUS => $total >= self::INT_20 ? self::STATUS_OK : self::STATUS_INSUFFICIENT_SIGNAL,
            self::FIELD_REASON => $total >= self::INT_20 ? null : self::FIELD_OPERATOR_REQUEST_WINDOW_BELOW_FLOOR,
            self::FIELD_MEASURE_ID => self::TETO02_MEASURE_ID,
            self::FIELD_FORMULA_VERSION => self::FIELD_MISSION_E2E_RATE_V1,
            self::FIELD_GENERATED_AT => now()->toIso8601String(),
            self::FIELD_FREEZE => self::freezePayload(self::FIELD_TETO_02),
            self::FIELD_DENOMINATOR_MIN => self::INT_20,
            self::FIELD_WINDOW_DAYS => $days,
            self::FIELD_METRICS => [
                self::FIELD_OPERATOR_REQUESTS => $total,
                self::FIELD_COMPLETED_E2E => $completed,
                self::FIELD_MISSION_E2E_RATE => $total > 0 ? round($completed / $total, 4) : null,
                self::FIELD_ASKS_PER_REQUEST => null,
                self::FIELD_REQUEST_TO_DELIVERY_P50_SECONDS => null,
                self::FIELD_REQUEST_TO_DELIVERY_P95_SECONDS => null,
            ],
            self::FIELD_ABANDONED_COUNT_AS_NOT_COMPLETED => true,
        ];
    }

    /** @return array<string,array<string,mixed>> */
    public static function freezePayloads(): array
    {
        return [
            self::FIELD_MAXL_06 => self::payload(self::MAXL06_MEASURE_ID, self::FIELD_MAXL06_DELTA_ATTRIBUTION_V1, 'Report-only attribution joins daily measure deltas to lineage decision_ids/commits; when lineage is absent, basis must be labeled and causal language must use correlational_attribution.', 1, 30, self::FIELD_CURSOR_ACOS_MAX_MAXL06, self::FIELD_CODEX_INDEPENDENT_MAXL06_JUDGE, [self::FIELD_ALLOWED_BASIS => [self::FIELD_LINEAGE_LEDGER, self::FIELD_GIT_LOG], self::FIELD_COUNTERFACTUAL_BASIS => self::FIELD_NONE]),
            self::FIELD_MULTN17_04 => self::payload(self::MULTN1704_MEASURE_ID, self::FIELD_MULTN17_PREDICTED_IMPACT_CALIBRATION_V1, self::FIELD_DERIVED_PREDICTED_IMPACT_BAND_VERSUS_REALIZED_PROVEN_REAL_OUTCOME_CURVE_FOR_ORIGINATION__REPORT_ONLY_UNTIL_AT_LEAST_20_REAL_ORIGINATIONS_RESOLVE_, 20, 30, self::FIELD_CURSOR_ACOS_MAX_MULTN17_04, self::FIELD_CODEX_INDEPENDENT_MULTN17_04_JUDGE, [self::FIELD_DENOMINATOR_MIN_ORIGINATIONS => self::INT_20, self::FIELD_MAX_ABS_DECLARED_REALIZED_DEVIATION => 1]),
            self::FIELD_MULTX_01 => self::payload(self::MULTX01_MEASURE_ID, self::FIELD_MULTX_FLYWHEEL_LOOP_DEFINITION_V1, self::FIELD_A_VALID_LOOP_CHAINS_TASK__DECISION_RECEIPT__DELIVERED_CONTEXT__EXECUTION_OUTCOME__LESSON__AND_SUBSEQUENT_MEASURED_RECALL__PROVEN_REAL_OUTCOME_IS_MANDATORY_, 1, 30, self::FIELD_CURSOR_ACOS_MAX_MULTX01, self::FIELD_CODEX_INDEPENDENT_MULTX01_JUDGE, [self::FIELD_REQUIRES_PROVEN_REAL_OUTCOME => true]),
            self::FIELD_MULTX_06 => self::payload(self::MULTX06_MEASURE_ID, self::FIELD_MULTX_LEARNING_LATENCY_V1, self::FIELD_MEASURE_P50_P95_LATENCY_FROM_OUTCOME_CREATED_LESSON_TO_FIRST_DELIVERED_CONTEXT_AND_FIRST_MEASURED_CITATION__NEVER_DELIVERED_REMAINS_IN_DENOMINATOR_, 8, 30, self::FIELD_CURSOR_ACOS_MAX_MULTX06, self::FIELD_CODEX_INDEPENDENT_MULTX06_JUDGE, [self::FIELD_DENOMINATOR_MIN_PROMOTED_LESSONS => self::INT_8]),
            self::FIELD_MULTX_09 => self::payload(self::MULTX09_MEASURE_ID, self::FIELD_MULTX_WINDOWS_ORCHESTRATOR_V1, 'Read-only PromotionProtocol window DAG: started windows publish days_remaining and critical path; not-started windows never receive fabricated ETA; associated series silence beyond the watchdog floor emits dead_window.', 1, 30, self::FIELD_CURSOR_ACOS_MAX_MULTX09, self::FIELD_CODEX_INDEPENDENT_MULTX09_JUDGE, [self::FIELD_DEAD_WINDOW_SILENT_DAYS => self::INT_3, self::FIELD_NOT_STARTED_ETA_ALLOWED => false, self::FIELD_READ_ONLY => true]),
            self::FIELD_MULTJ_01 => self::payload(self::MULTJ01_MEASURE_ID, self::FIELD_MULTJ_LESSON_HALF_LIFE_V2, self::FIELD_BUCKET_LESSON_LIFT_BY_AGE_SINCE_PROMOTION_USING_TWO_WEEK_BUCKETS__BUCKETS_BELOW_N_8_PUBLISH_INSUFFICIENT_INSTEAD_OF_NULL_, 8, 30, self::FIELD_CURSOR_ACOS_MAX_MULTJ01, self::FIELD_CODEX_INDEPENDENT_MULTJ01_JUDGE, [self::FIELD_BUCKET_WIDTH_WEEKS => self::INT_2, self::FIELD_DENOMINATOR_MIN_PER_BUCKET => self::INT_8]),
            self::FIELD_MULTJ_02 => self::payload(self::MULTJ02_MEASURE_ID, self::FIELD_MULTJ_SEMANTIC_DEDUP_FREEZE_V1, self::FIELD_SEMANTIC_LESSON_DEDUP_THRESHOLD_FREEZE_FOR_OBSERVE_MODE_WOULD_MERGE_RECEIPTS__ENFORCEMENT_REQUIRES_LATER_CALIBRATED_PROMOTION_, 1, 30, self::FIELD_CURSOR_ACOS_MAX_MULTJ02, self::FIELD_CODEX_INDEPENDENT_MULTJ02_JUDGE, [self::FIELD_COSINE_MERGE_THRESHOLD => self::FLOAT_0_88, self::FIELD_OBSERVE_MODE_ACTUAL_MERGES => 0]),
            self::FIELD_MULTJ_03 => self::payload(self::MULTJ03_MEASURE_ID, self::FIELD_MULTJ_COUNTERFACTUAL_LIFT_V2, self::FIELD_PAIRED_PEEK_EVALUATION_OF_THE_SAME_TASK_WITH_AND_WITHOUT_INJECTED_LESSON__N_PAIRS_BELOW_8_PUBLISHES_INSUFFICIENT_SIGNAL_AND_PEEK_MUST_NOT_RECORD_USAGE_, 8, 30, self::FIELD_CURSOR_ACOS_MAX_MULTJ03, self::FIELD_CODEX_INDEPENDENT_MULTJ03_JUDGE, [self::FIELD_SAMPLE_RATE => self::FLOAT_0_05, self::FIELD_DENOMINATOR_MIN_PAIRS => self::INT_8, self::FIELD_RECORD_USAGE_FOR_PEEK => false]),
            self::FIELD_MULTJ_04 => self::payload(self::MULTJ04_MEASURE_ID, self::FIELD_MULTJ_PROCEDURAL_SKILL_PROMOTER_V1, 'Procedural playbooks can propose skill.v1 candidates only after the real procedural case_count floor; output is default-OFF and ASI-02 holds promotion_allowed=false until gates pass.', AcosMaxProceduralSkillPromoterService::DEFAULT_CASE_COUNT_FLOOR, 30, self::FIELD_CURSOR_ACOS_MAX_MULTJ04, self::FIELD_CODEX_INDEPENDENT_MULTJ04_JUDGE, [self::FIELD_PROCEDURAL_CASE_COUNT_FLOOR => AcosMaxProceduralSkillPromoterService::DEFAULT_CASE_COUNT_FLOOR, self::FIELD_DEFAULT_OFF => true, self::FIELD_ADMISSION_DOOR => self::FIELD_ASI_02]),
            self::FIELD_MULTJ_06 => self::payload(self::MULTJ06_MEASURE_ID, self::FIELD_MULTJ_ABSTRACTION_LADDER_V1, 'Distinct-signature patterns sharing primary_cause aggregate to level-3 principles when distinct_signature_k is met; derived_from refs must resolve or gate rejects.', 3, 30, self::FIELD_CURSOR_ACOS_MAX_MULTJ06, self::FIELD_CODEX_INDEPENDENT_MULTJ06_JUDGE, [self::FIELD_DISTINCT_SIGNATURE_K => self::INT_3, self::FIELD_PATTERN_FLOOR => 1]),
            self::FIELD_TETO_02 => self::payload(self::TETO02_MEASURE_ID, self::FIELD_MISSION_E2E_RATE_V1, self::FIELD_OPERATOR_NATURAL_LANGUAGE_REQUEST_TO_COMPLETED_RESULT_RATE__ASKS_PER_REQUEST__AND_REQUEST_TO_DELIVERY_LATENCY__ABANDONED_MISSIONS_STAY_IN_THE_DENOMINATOR_, 20, 30, self::FIELD_CURSOR_ACOS_MAX_TETO02, self::FIELD_CODEX_INDEPENDENT_TETO02_JUDGE, [self::FIELD_TARGET_MISSION_E2E_RATE => self::FLOAT_0_70, self::FIELD_DENOMINATOR_MIN_OPERATOR_REQUESTS => self::INT_20]),
        ];
    }

    /** @return array<string,mixed> */
    private static function payload(string $measureId, string $formulaVersion, string $formula, int $denominatorMin, int $ttlDays, string $author, string $judge, array $thresholds): array
    {
        return [
            self::FIELD_KIND => self::KIND_MEASURE_FREEZE,
            self::FIELD_MEASURE_ID => $measureId,
            self::FIELD_FORMULA_VERSION => $formulaVersion,
            self::FIELD_FORMULA => $formula,
            self::FIELD_THRESHOLDS => $thresholds,
            self::FIELD_DENOMINATOR_MIN => $denominatorMin,
            self::FIELD_TTL_DAYS => $ttlDays,
            self::FIELD_AUTHOR_ENGINE_ID => $author,
            self::FIELD_JUDGE_ENGINE_ID => $judge,
            self::FIELD_DUAL_READ_REQUIRED => false,
        ];
    }

    /** @return array<string,mixed> */
    private function emptyReport(string $slice, string $status, string $reason, array $extra): array
    {
        return array_merge([
            self::FIELD_SCHEMA_VERSION => self::REPORT_SCHEMA,
            self::FIELD_SLICE => $slice,
            self::FIELD_STATUS => $status,
            self::FIELD_REASON => $reason,
            self::FIELD_FORMULA_VERSION => AiValueNormalizer::trimmedStringOrNull(data_get(self::freezePayload($slice), self::FIELD_FORMULA_VERSION)) ?? '',
            self::FIELD_GENERATED_AT => now()->toIso8601String(),
            self::FIELD_FREEZE => self::freezePayload($slice),
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
