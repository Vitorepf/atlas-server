<?php

declare(strict_types=1);

namespace App\Services\Ai\Cognition\Watchdog;

use App\Models\AiRagFeedbackEvent;
use App\Models\AiRunOutcome;
use App\Models\AtlasAemorExecutionEpisode;
use App\Models\AtlasLedgerEvent;
use App\Models\AtlasLongHorizonCompactionReceipt;
use App\Models\AtlasMemoryEntryUsage;
use App\Services\Ai\AtlasDecide\AtlasDecideLiveOutcomeFeedbackService;
use App\Services\Ai\AtlasMemoryQualityService;
use App\Services\Ai\Cognition\AtlasCognitionEvidenceResolver;
use App\Services\Ai\Cognition\AtlasCognitionScoreCardService;
use App\Services\Ai\Compounding\AtlasLearningRecallUseLiftService;
use App\Services\Ai\Governance\GovernanceFloorRegistry;
use App\Services\Ai\Governance\ProviderGovernanceCoverageLedger;
use App\Services\Ai\Kernel\Evidence\AtlasEvidenceLedger;
use App\Services\Ai\Kernel\Evidence\LedgerEventType;
use App\Services\Ai\LongHorizon\LongHorizonCrossWeekRecallLiftGateService;
use App\Services\Ai\Reality\AtlasRealityGraphStatusService;
use App\Services\Ai\Support\AppendOnlyJsonlStore;
use App\Services\Ai\Support\AiValueNormalizer;
use App\Services\Ai\Support\DatabaseTableAvailability;
use Carbon\CarbonImmutable;
use Throwable;

final class AtlasAcosWatchdogHealthService
{
    public const FIELD_AI_RUN_OUTCOME_MAX_AGE_HOURS = 'ai_run_outcome_max_age_hours';
    public const FIELD_BY_EXECUTOR = 'by_executor';
    public const MEMORY_QUALITY_SCHEMA = 'atlas.memory.quality_check.v1';

    public const CONTEXT_FEEDBACK_SCHEMA = 'atlas.context.feedback_health.v1';

    public const COMPACTION_SOAK_SCHEMA = 'atlas.compaction.soak_watch.v1';

    public const ENGINEERING_READINESS_SCHEMA = 'atlas.engineering.enforce_readiness.v1';


    public const FIELD_STATUS = 'status';

    public const FIELD_BLOCKING = 'blocking';

    public const FIELD_SCHEMA_VERSION = 'schema_version';

    public const FIELD_GENERATED_AT = 'generated_at';

    public const FIELD_THRESHOLDS = 'thresholds';

    public const FIELD_CODE = 'code';

    public const FIELD_TOTAL = 'total';

    public const FIELD_READY_TO_ENFORCE = 'ready_to_enforce';

    public const FIELD_CHECKS = 'checks';

    public const FIELD_GREEN = 'green';

    public const FIELD_RED = 'red';

    public const FIELD_YELLOW = 'yellow';

    public const AURG_COVERAGE_SCHEMA = 'atlas.aurg.coverage_gate.v1';

    public const RAG_DIMENSION_SCHEMA = 'atlas.rag.dimension_watchdog.v1';

    public const PIPELINE_SCORECARD_STABILITY_SCHEMA = 'atlas.pipeline.scorecard_stability_watch.v1';

    public const OPE_LIFT_CYCLE_CLOSURE_SCHEMA = 'atlas.ope.lift_cycle_closure_watch.v1';

    public const OPE_SCORECARD_RECEIPTS_DIAGNOSIS_SCHEMA = 'atlas.ope.scorecard_receipts_diagnosis_watch.v1';

    public const ONDA4_EMITTER_VERSION = 'atlas.acos.watchdog.onda4.v1';

    public const STATUS_OK = 'ok';

    public const STATUS_ALERT = 'alert';

    public const STATUS_HEALTHY = 'healthy';

    public const STATUS_READY = 'ready';

    public const STATUS_NOT_READY = 'not_ready';

    public const STATUS_UNAVAILABLE = 'unavailable';

    public const FIELD_PASS = 'pass';

    public const FIELD_MEASURED = 'measured';

    public const FIELD_CERTIFIED = 'certified';
    public const FIELD_RAW = 'raw';
    public const FIELD_ID = 'id';
    public const FIELD_TOTAL_EVENT_COUNT = 'total_event_count';
    public const FIELD_WINDOW = 'window';
    public const FIELD_FALSE_POSITIVE_TOTAL = 'false_positive_total';
    public const FIELD_FALSE_POSITIVE_RATE = 'false_positive_rate';
    public const FIELD_FALSE_POSITIVE_RATE_THRESHOLD = 'false_positive_rate_threshold';
    public const FIELD_MAX_EVENTS = 'max_events';
    public const FIELD_OK = 'ok';
    public const FIELD_FAIL = 'fail';
    public const FIELD_WARN = 'warn';
    public const FIELD_REASON = 'reason';
    public const FIELD_VALUE = 'value';
    public const FIELD_THRESHOLD = 'threshold';
    public const FIELD_MEASURED_SHARE = 'measured_share';
    public const FIELD_DELIVERED_REFS = 'delivered_refs';
    public const FIELD_BLOCKERS = 'blockers';
    public const FIELD_WINDOW_DAYS = 'window_days';
    public const FIELD_CASE_COUNTS = 'case_counts';
    public const FIELD_ACRONYM = 'acronym';
    public const FIELD_SERVICE_CLASS = 'service_class';
    public const FIELD_BYPASS_RATE = 'bypass_rate';
    public const FIELD_DAYS_IN_BLOCK = 'days_in_block';
    public const FIELD_WINDOWED_CONCENTRATION_RATIO = 'windowed_concentration_ratio';
    public const FIELD_SNAPSHOT_AGE_HOURS = 'snapshot_age_hours';
    public const FIELD_MAX_AGE_HOURS = 'max_age_hours';
    public const FIELD_RECALL_USAGE_TOTAL = 'recall_usage_total';
    public const FIELD_SCORE = 'score';
    public const FIELD_ALERT_DETAIL = 'alert_detail';
    public const FIELD_CHECK_ID = 'check_id';
    public const FIELD_MESSAGE = 'message';
    public const FIELD_LAST_AT = 'last_at';
    public const FIELD_WITH_RECALLED_MEMORY = 'with_recalled_memory';
    public const FIELD_WITHOUT_RECALLED_MEMORY = 'without_recalled_memory';
    public const FIELD_LIFT_STATUS = 'lift_status';
    public const FIELD_COVERAGE = 'coverage';
    public const FIELD_LAST_INGEST_AT = 'last_ingest_at';
    public const FIELD_MEMORY_CROSS_LAYER_COVERAGE_RATIO = 'memory_cross_layer_coverage_ratio';
    public const FIELD_AGE_HOURS = 'age_hours';
    public const FIELD_AVAILABLE = 'available';
    public const FIELD_BLOCKER = 'blocker';
    public const FIELD_AUTONOMOS = 'autonomos';
    public const FIELD_AURG_CROSS_LAYER_COVERAGE_RATIO = 'aurg_cross_layer_coverage_ratio';
    public const FIELD_AEMOR_SOURCE_MAX_AGE_HOURS = 'aemor_source_max_age_hours';
    public const FIELD_BLOCKER_SERIES = 'blocker_series';
    public const FIELD_COMPACTION_COUNT = 'compaction_count';
    public const FIELD_DAYS = 'days';
    public const FIELD_DELIVERED_REFS_SHARE = 'delivered_refs_share';
    public const FIELD_EDGES_BY_SOURCE = 'edges_by_source';
    public const FIELD_FIRST_SEEN_AT = 'first_seen_at';
    public const FIELD_FP_DEFINITION = 'fp_definition';
    public const FIELD_HOURS = 'hours';
    public const FIELD_PARTIAL_COUNT = 'partial_count';
    public const FIELD_PIPELINE_STATUS = 'pipeline_status';
    public const FIELD_RECALL_AT_5 = 'recall_at_5';
    public const FIELD_RETRIEVAL_EVAL = 'retrieval_eval';
    public const FIELD_SCORECARD_HASH = 'scorecard_hash';
    public const FIELD_SOURCE = 'source';
    public const FIELD_STORE = 'store';
    public const FIELD_SUBSYSTEMS = 'subsystems';
    public const FIELD_WRITER_SHARES = 'writer_shares';
    public const FIELD_ADML_COST_OUTCOME = 'adml_cost_outcome';
    public const FIELD_ADML_PROVEN_ROUTE_VOLUME_BELOW_FLOOR = 'adml_proven_route_volume_below_floor';
    public const FIELD_ADML_PROVEN_ROUTES = 'adml_proven_routes';
    public const FIELD_AI_RAG_FEEDBACK_EVENTS_TABLE_MISSING = 'ai_rag_feedback_events_table_missing';
    public const FIELD_TREND_STATUS = 'trend_status';
    public const FIELD_TOLERANCE_POINTS = 'tolerance_points';

    public const STATUS_UNKNOWN = 'unknown';

    public const MEMORY_SCORE_REGRESSION_TOLERANCE = 5;

    public const MEMORY_SNAPSHOT_MAX_AGE_HOURS = 48;

    public const MEMORY_CONCENTRATION_FLOOR = 0.35;

    public const LEARNING_NEGATIVE_MAX_AGE_HOURS = 168;

    public const LEARNING_AEMOR_SOURCE_MAX_AGE_HOURS = 48;

    public const LEARNING_AI_RUN_OUTCOME_MAX_AGE_HOURS = 72;

    public const RAG_COVERAGE_FLOOR = 0.5;

    public const RAG_RETRIEVAL_EVAL_FLOOR = 95;

    public const RAG_RECALL_AT_5_FLOOR = 0.85;

    public const RAG_PRE_FILTER_CONCENTRATION_MASK_FLOOR = 0.5;

    public const FEEDBACK_WINDOW_HOURS = 168;

    public const FEEDBACK_TOTAL_EVENT_FLOOR = 10;

    public const FEEDBACK_MEASURED_COUNT_FLOOR = 3;

    public const FEEDBACK_SYNTHETIC_SHARE_MAX = 0.10;

    public const COMPACTION_WINDOW_DAYS = 14;

    public const COMPACTION_MIN_RECEIPTS = 50;

    public const COMPACTION_MIN_RETENTION_SCORE = 0.95;

    public const LIFT_STALLED_DAYS = 7;

    public const PIPELINE_PARTIAL_STALE_DAYS = 7;

    public const ENG_WINDOW_DAYS = 7;

    public const ENG_MIN_REAL_EXECUTIONS_PER_EXECUTOR = 1;

    public const ENG_MIN_FORGE_PROMOTED_CYCLES = 20;

    public const ENG_MIN_ADML_PROVEN_ROUTES = 3;

    public const RECALL_CONCENTRATION_DEMOTION_ENABLED_CONFIG_KEY = 'atlas.semantic_memory.recall_concentration_demotion_enabled';

    public const DEFAULT_RECALL_CONCENTRATION_DEMOTION_ENABLED = true;

    public const ADML_COST_OUTCOME_ENABLED_CONFIG_KEY = 'atlas.patamar4.adml_cost_outcome.enabled';

    public const DEFAULT_ADML_COST_OUTCOME_ENABLED = false;
    public const FIELD_CURRENT_DELTA_FROM_LATEST = 'current_delta_from_latest';
    public const FIELD_LATEST_DELTA_FROM_PREVIOUS = 'latest_delta_from_previous';
    public const FIELD_BY_WRITER = 'by_writer';
    public const FIELD_COMMANDS = 'commands';
    public const FIELD_COMPACTION_RECEIPTS_TABLE_MISSING = 'compaction_receipts_table_missing';
    public const FIELD_CONDITION = 'condition';
    public const FIELD_CONTEXT_RETENTION_SCORE_COUNT = 'context_retention_score_count';
    public const FIELD_CONTEXT_RETENTION_SCORE_MIN = 'context_retention_score_min';
    public const FIELD_CORRELATION_ID = 'correlation_id';
    public const FIELD_CRITICAL_MUST_KEEP_CUTS = 'critical_must_keep_cuts';
    public const FIELD_CRITICAL_MUST_KEEP_SHADOW_CUTS = 'critical_must_keep_shadow_cuts';
    public const FIELD_CROSS_WEEK_RECALL_LIFT_GATE = 'cross_week_recall_lift_gate';
    public const FIELD_DELIVERED_REFS_COUNT = 'delivered_refs_count';
    public const FIELD_DEMOTION_ENABLED = 'demotion_enabled';
    public const FIELD_DEV = 'dev';
    public const FIELD_DIAGNOSES = 'diagnoses';
    public const FIELD_DIAGNOSIS = 'diagnosis';
    public const FIELD_EMITTER_STAGE = 'emitter_stage';
    public const FIELD_EMITTER_VERSION = 'emitter_version';
    public const FIELD_ENVELOPE_ID = 'envelope_id';
    public const FIELD_EVIDENCE_PROVENANCE = 'evidence_provenance';
    public const FIELD_FLIPS = 'flips';
    public const FIELD_FORGE = 'forge';
    public const FIELD_FORGE_GATE_ENFORCE = 'forge_gate_enforce';
    public const FIELD_FORGE_PROMOTED_CYCLE_VOLUME_BELOW_FLOOR = 'forge_promoted_cycle_volume_below_floor';
    public const FIELD_FORGE_PROMOTED_CYCLES = 'forge_promoted_cycles';
    public const FIELD_FORGE_SOVEREIGN_VERDICT_JSONL = 'forge_sovereign_verdict_jsonl';
    public const FIELD_FRESHNESS = 'freshness';
    public const FIELD_FRESHNESS_COMPONENT = 'freshness_component';
    public const FIELD_GOVERNANCE_ENFORCE = 'governance_enforce';
    public const FIELD_IMPROPER_FLOOR_DISCARDS = 'improper_floor_discards';
    public const FIELD_ISSUES = 'issues';
    public const FIELD_KEEP_KIND = 'keep_kind';
    public const FIELD_KIND = 'kind';
    public const FIELD_LATEST_SNAPSHOT_AT = 'latest_snapshot_at';
    public const FIELD_LIVE_OUTCOMES_JSONL = 'live_outcomes_jsonl';
    public const FIELD_MEASURED_COUNT = 'measured_count';
    public const FIELD_MEASURED_COUNT_FLOOR = 'measured_count_floor';
    public const FIELD_MEASUREMENT_READY = 'measurement_ready';
    public const FIELD_MIN_COMPACTIONS = 'min_compactions';
    public const FIELD_MIN_CONTEXT_RETENTION_SCORE = 'min_context_retention_score';
    public const FIELD_MIN_EVIDENCE = 'min_evidence';
    public const FIELD_NEGATIVE_FEEDBACK_MAX_AGE_HOURS = 'negative_feedback_max_age_hours';
    public const FIELD_OPERATOR_ID = 'operator_id';
    public const FIELD_ORIGIN = 'origin';
    public const FIELD_PARTIAL_ACRONYMS = 'partial_acronyms';
    public const FIELD_PARTIAL_STALE_DAYS = 'partial_stale_days';
    public const FIELD_PIPELINE_SCORE_OUT_OF_10 = 'pipeline_score_out_of_10';
    public const FIELD_PRE_FILTER_CONCENTRATION_MASK_FLOOR = 'pre_filter_concentration_mask_floor';
    public const FIELD_PRE_FILTER_CONCENTRATION_RATIO = 'pre_filter_concentration_ratio';
    public const FIELD_PROMOTED = 'promoted';
    public const FIELD_PROMOTED_HARNESS_CAPTURED_CYCLES = 'promoted_harness_captured_cycles';
    public const FIELD_PROVEN_REAL = 'proven_real';
    public const FIELD_PROVIDER_GOVERNANCE_COVERAGE_LEDGER = 'provider_governance_coverage_ledger';
    public const FIELD_READY_ROUTES = 'ready_routes';
    public const FIELD_REAL_EXECUTIONS_PER_EXECUTOR = 'real_executions_per_executor';
    public const FIELD_REQUIRED = 'required';
    public const FIELD_ROLE = 'role';
    public const FIELD_ROLLBACK_ENV = 'rollback_env';
    public const FIELD_ROLLBACK_TRIGGER = 'rollback_trigger';
    public const FIELD_ROUTES = 'routes';
    public const FIELD_SCOPE_ID = 'scope_id';
    public const FIELD_SCOPE_TYPE = 'scope_type';
    public const FIELD_SEVERITY = 'severity';
    public const FIELD_SOURCES = 'sources';
    public const FIELD_STALE_PARTIAL_COUNT = 'stale_partial_count';
    public const FIELD_SYNTHETIC = 'synthetic';
    public const FIELD_SYNTHETIC_SHARE = 'synthetic_share';
    public const FIELD_SYNTHETIC_SHARE_MAX = 'synthetic_share_max';
    public const FIELD_TASK_CATEGORY = 'task_category';
    public const FIELD_TENANT_ID = 'tenant_id';
    public const FIELD_CREATED_AT = 'created_at';
    public const FIELD_CRITICAL = 'critical';
    public const FIELD_HARNESS_CAPTURED = 'harness_captured';
    public const FIELD_RECORDED_AT = 'recorded_at';
    public const FIELD_SURFACE = 'surface';
    public const FIELD_TESTS_RUN = 'tests_run';
    public const FIELD_TOTAL_EVENT_COUNT_FLOOR = 'total_event_count_floor';
    public const FIELD_TRANSCRIPT_INFERRED = 'transcript_inferred';
    public const FIELD_TRANSCRIPT_INFERRED_SHARE = 'transcript_inferred_share';
    public const FIELD_AI_RAG_FEEDBACK_EVENTS = 'ai_rag_feedback_events';
    public const FIELD_ACOS_WATCHDOG = 'acos_watchdog';
    public const FIELD_ATLAS_AEMOR_EXECUTION_EPISODES = 'atlas_aemor_execution_episodes';
    public const FIELD_ATLAS_LEDGER_EVENTS = 'atlas_ledger_events';
    public const FIELD_ATLAS_LONG_HORIZON_COMPACTION_RECEIPTS = 'atlas_long_horizon_compaction_receipts';
    public const FIELD_ATLAS_MEMORY_ENTRY_USAGES = 'atlas_memory_entry_usages';

    /**
     * @param  array<string,mixed>  $filters
     * @return array<string,mixed>
     */
    public function memoryQualityCheck(array $filters = []): array
    {
        $scorecard = app(AtlasMemoryQualityService::class)->scorecard($filters);
        $latestSnapshotAt = $this->parseDate(data_get($scorecard, 'latest_snapshot.snapshot_at'));
        $snapshotAgeHours = $latestSnapshotAt ? round($latestSnapshotAt->diffInMinutes(CarbonImmutable::now('UTC')) / 60, 2) : null;
        $freshness = (int) (AiValueNormalizer::finiteFloatOrNull(data_get($scorecard, 'components.freshness', 0)) ?? 0);
        $concentration = AiValueNormalizer::finiteFloatOrNull(data_get($scorecard, 'ratios.recall_concentration_ratio', 0.0)) ?? 0.0;
        $recallUsageTotal = (int) (AiValueNormalizer::finiteFloatOrNull(data_get($scorecard, 'counts.retrieval_eval.recall_usage_total', 0)) ?? 0);
        $demotionEnabled = (AiValueNormalizer::boolOrNull(config(self::RECALL_CONCENTRATION_DEMOTION_ENABLED_CONFIG_KEY, self::DEFAULT_RECALL_CONCENTRATION_DEMOTION_ENABLED)) ?? self::DEFAULT_RECALL_CONCENTRATION_DEMOTION_ENABLED);
        $trendStatus = AiValueNormalizer::trimmedStringOrNull(data_get($scorecard, 'trend.status')) ?? self::STATUS_UNKNOWN;
        $currentDelta = data_get($scorecard, 'trend.current_delta_from_latest');
        $latestDelta = data_get($scorecard, 'trend.latest_delta_from_previous');
        $scoreRegressed = in_array($trendStatus, ['regressed', 'watch_regressed'], true)
            || (is_numeric($currentDelta) && (int) $currentDelta < -self::MEMORY_SCORE_REGRESSION_TOLERANCE)
            || (is_numeric($latestDelta) && (int) $latestDelta < -self::MEMORY_SCORE_REGRESSION_TOLERANCE);

        $checks = [
            $this->checkRow('score_regression', ! $scoreRegressed, [
                self::FIELD_TREND_STATUS => $trendStatus,
                self::FIELD_TOLERANCE_POINTS => self::MEMORY_SCORE_REGRESSION_TOLERANCE,
                self::FIELD_CURRENT_DELTA_FROM_LATEST => $currentDelta,
                self::FIELD_LATEST_DELTA_FROM_PREVIOUS => $latestDelta,
            ], 'memory_quality_score_regressed'),
            $this->checkRow('freshness_full', $freshness >= 100, [
                self::FIELD_FRESHNESS_COMPONENT => $freshness,
                self::FIELD_REQUIRED => 100,
            ], 'memory_freshness_below_full'),
            $this->checkRow('windowed_concentration_guarded', $concentration <= self::MEMORY_CONCENTRATION_FLOOR || $demotionEnabled || $recallUsageTotal === 0, [
                self::FIELD_WINDOWED_CONCENTRATION_RATIO => $concentration,
                self::FIELD_THRESHOLD => self::MEMORY_CONCENTRATION_FLOOR,
                self::FIELD_DEMOTION_ENABLED => $demotionEnabled,
                self::FIELD_RECALL_USAGE_TOTAL => $recallUsageTotal,
            ], 'recall_concentration_high_without_demotion'),
            $this->checkRow('snapshot_fresh', $snapshotAgeHours !== null && $snapshotAgeHours <= self::MEMORY_SNAPSHOT_MAX_AGE_HOURS, [
                self::FIELD_SNAPSHOT_AGE_HOURS => $snapshotAgeHours,
                self::FIELD_MAX_AGE_HOURS => self::MEMORY_SNAPSHOT_MAX_AGE_HOURS,
                self::FIELD_LATEST_SNAPSHOT_AT => $latestSnapshotAt?->toIso8601String(),
            ], 'memory_quality_snapshot_stale'),
        ];
        $failed = array_values(array_filter($checks, static fn (array $check): bool => ! (AiValueNormalizer::boolOrNull($check[self::FIELD_PASS] ?? null) ?? false)));

        return [
            self::FIELD_SCHEMA_VERSION => self::MEMORY_QUALITY_SCHEMA,
            self::FIELD_STATUS => $failed === [] ? self::STATUS_OK : self::STATUS_ALERT,
            self::STATUS_ALERT => $failed !== [],
            self::FIELD_CHECKS => $checks,
            self::FIELD_RAW => [
                self::FIELD_SCORE => (int) (AiValueNormalizer::finiteFloatOrNull($scorecard[self::FIELD_SCORE] ?? null) ?? 0),
                self::FIELD_STATUS => (AiValueNormalizer::trimmedStringOrNull($scorecard[self::FIELD_STATUS] ?? null) ?? self::STATUS_UNKNOWN),
                self::FIELD_FRESHNESS => $freshness,
                self::FIELD_WINDOWED_CONCENTRATION_RATIO => $concentration,
                self::FIELD_SNAPSHOT_AGE_HOURS => $snapshotAgeHours,
                self::FIELD_RECALL_USAGE_TOTAL => $recallUsageTotal,
            ],
            self::FIELD_ALERT_DETAIL => $failed === [] ? null : [
                self::FIELD_CHECK_ID => AiValueNormalizer::trimmedScalarStringOrNull($failed[0][self::FIELD_ID] ?? null) ?? '',
                self::FIELD_CODE => AiValueNormalizer::trimmedScalarStringOrNull($failed[0][self::FIELD_CODE] ?? null) ?? '',
                self::FIELD_MESSAGE => 'Memory quality check failed.',
            ],
            self::FIELD_GENERATED_AT => now()->toIso8601String(),
        ];
    }

    /** @return array<string,mixed> */
    public function learningCadenceReport(): array
    {
        $now = CarbonImmutable::now('UTC');
        $lastNegative = $this->latestMemoryFeedbackAt(AtlasMemoryEntryUsage::negativeFeedbackActions());
        $lastOutcome = $this->latestModelAt(AiRunOutcome::class, 'created_at', 'ai_run_outcomes');
        $lastDeliveredRefs = $this->latestRagDeliveredRefsAt();
        $aemor = [];
        foreach (['dev', 'forge', 'task'] as $source) {
            $aemor[$source] = $this->latestAemorSourceAt($source);
        }

        $lift = app(AtlasLearningRecallUseLiftService::class)->report();
        $checks = [
            $this->ageCheck('last_negative_feedback', $lastNegative, self::LEARNING_NEGATIVE_MAX_AGE_HOURS, $now),
            $this->ageCheck('last_ai_run_outcome', $lastOutcome, self::LEARNING_AI_RUN_OUTCOME_MAX_AGE_HOURS, $now),
            $this->checkRow('last_delivered_refs_event', $lastDeliveredRefs !== null, [
                self::FIELD_LAST_AT => $lastDeliveredRefs?->toIso8601String(),
            ], 'no_recent_delivered_refs_event'),
        ];
        foreach ($aemor as $source => $at) {
            $checks[] = $this->ageCheck('last_aemor_episode_'.$source, $at, self::LEARNING_AEMOR_SOURCE_MAX_AGE_HOURS, $now);
        }
        $checks[] = $this->checkRow('lift_case_count', (int) (AiValueNormalizer::finiteFloatOrNull(data_get($lift, 'measurement.with_recalled_memory.case_count', 0)) ?? 0) > 0
            && (int) (AiValueNormalizer::finiteFloatOrNull(data_get($lift, 'measurement.without_recalled_memory.case_count', 0)) ?? 0) > 0, [
                self::FIELD_WITH_RECALLED_MEMORY => data_get($lift, 'measurement.with_recalled_memory.case_count', 0),
                self::FIELD_WITHOUT_RECALLED_MEMORY => data_get($lift, 'measurement.without_recalled_memory.case_count', 0),
                self::FIELD_MEASUREMENT_READY => data_get($lift, 'measurement.measurement_ready', false),
            ], 'learning_lift_cases_missing');

        return $this->reportFromChecks('atlas.learning.cadence_watchdog.v1', $checks, [
            self::FIELD_THRESHOLDS => [
                self::FIELD_NEGATIVE_FEEDBACK_MAX_AGE_HOURS => self::LEARNING_NEGATIVE_MAX_AGE_HOURS,
                self::FIELD_AEMOR_SOURCE_MAX_AGE_HOURS => self::LEARNING_AEMOR_SOURCE_MAX_AGE_HOURS,
                self::FIELD_AI_RUN_OUTCOME_MAX_AGE_HOURS => self::LEARNING_AI_RUN_OUTCOME_MAX_AGE_HOURS,
            ],
            self::FIELD_LIFT_STATUS => (AiValueNormalizer::trimmedStringOrNull($lift[self::FIELD_STATUS] ?? null) ?? self::STATUS_UNKNOWN),
        ], 'learning_cadence_stalled');
    }

    /** @return array<string,mixed> */
    public function aurgCoverageReport(): array
    {
        $status = app(AtlasRealityGraphStatusService::class)->status();
        $coverage = AiValueNormalizer::arrayOrEmpty($status[self::FIELD_COVERAGE] ?? null);
        $store = AiValueNormalizer::arrayOrEmpty($status[self::FIELD_STORE] ?? null);
        $edgesBySource = AiValueNormalizer::arrayOrEmpty($store[self::FIELD_EDGES_BY_SOURCE] ?? null);
        $ratio = AiValueNormalizer::finiteFloatOrNull($coverage[self::FIELD_MEMORY_CROSS_LAYER_COVERAGE_RATIO] ?? null) ?? 0.0;
        $blocking = [];
        if (! (AiValueNormalizer::boolOrNull($coverage[self::FIELD_AVAILABLE] ?? null) ?? false)) {
            $blocking[] = (AiValueNormalizer::trimmedStringOrNull($coverage[self::FIELD_REASON] ?? null) ?? 'aurg_store_unavailable');
        }
        if ($ratio < self::RAG_COVERAGE_FLOOR) {
            $blocking[] = 'memory_cross_layer_coverage_below_floor';
        }
        foreach (['linker_memory_code', 'linker_memory_domain', 'linker_evidence'] as $linker) {
            if ((int) (AiValueNormalizer::finiteFloatOrNull($edgesBySource[$linker] ?? null) ?? 0) === 0) {
                $blocking[] = 'cross_layer_linker_zero:'.$linker;
            }
        }

        return [
            self::FIELD_SCHEMA_VERSION => self::AURG_COVERAGE_SCHEMA,
            self::FIELD_STATUS => $blocking === [] ? self::STATUS_OK : self::STATUS_ALERT,
            self::STATUS_ALERT => $blocking !== [],
            self::FIELD_COVERAGE => $coverage,
            self::FIELD_STORE => [
                self::FIELD_EDGES_BY_SOURCE => $edgesBySource,
                self::FIELD_LAST_INGEST_AT => $store[self::FIELD_LAST_INGEST_AT] ?? null,
            ],
            self::FIELD_THRESHOLDS => [self::FIELD_MEMORY_CROSS_LAYER_COVERAGE_RATIO => self::RAG_COVERAGE_FLOOR],
            self::FIELD_BLOCKING => array_values(array_unique($blocking)),
            self::FIELD_GENERATED_AT => now()->toIso8601String(),
        ];
    }

    /** @return array<string,mixed> */
    public function ragDimensionReport(): array
    {
        $quality = app(AtlasMemoryQualityService::class)->scorecard([]);
        $aurg = $this->aurgCoverageReport();
        $retrievalEval = (int) (AiValueNormalizer::finiteFloatOrNull(data_get($quality, 'components.retrieval_eval', 0)) ?? 0);
        // Prefer the RAG-05 frozen golden surface; fall back to the older corpus metrics path.
        $recallAt5 = AiValueNormalizer::finiteFloatOrNull(
            data_get($quality, 'latest_snapshot.metadata.memory_recall_golden.recall_at_5')
        );
        if ($recallAt5 === null) {
            $recallAt5 = AiValueNormalizer::finiteFloatOrNull(
                data_get($quality, 'latest_snapshot.metadata.memory_recall_corpus.metrics.recall_at_5')
            );
        }
        $improperFloorDiscards = (int) (
            AiValueNormalizer::finiteFloatOrNull(
                data_get($quality, 'latest_snapshot.metadata.memory_recall_golden.improper_floor_discards')
            )
            ?? AiValueNormalizer::finiteFloatOrNull(
                data_get($quality, 'latest_snapshot.metadata.memory_recall_corpus.metrics.improper_floor_discards', 0)
            )
            ?? 0
        );
        $coverageRatio = AiValueNormalizer::finiteFloatOrNull(data_get($aurg, 'coverage.memory_cross_layer_coverage_ratio', 0.0)) ?? 0.0;
        $preFilterConcentration = AiValueNormalizer::finiteFloatOrNull(data_get(
            $quality,
            'ratios.pre_filter_recall_concentration_ratio',
            data_get($quality, 'ratios.recall_concentration_ratio', 0.0),
        )) ?? 0.0;
        $issues = [];
        if ($retrievalEval < self::RAG_RETRIEVAL_EVAL_FLOOR) {
            $issues[] = 'retrieval_eval_below_floor';
        }
        if ($recallAt5 === null || $recallAt5 < self::RAG_RECALL_AT_5_FLOOR) {
            $issues[] = 'recall_at_5_below_floor_or_unmeasured';
        }
        if ($improperFloorDiscards > 0) {
            $issues[] = 'improper_floor_discards_present';
        }
        if ($coverageRatio < self::RAG_COVERAGE_FLOOR) {
            $issues[] = 'aurg_cross_layer_coverage_below_floor';
        }
        if ($preFilterConcentration >= self::RAG_PRE_FILTER_CONCENTRATION_MASK_FLOOR
            && $retrievalEval >= self::RAG_RETRIEVAL_EVAL_FLOOR) {
            $issues[] = 'concentration_masked_by_delivery_filter';
        }

        return [
            self::FIELD_SCHEMA_VERSION => self::RAG_DIMENSION_SCHEMA,
            self::FIELD_STATUS => $issues === [] ? self::STATUS_OK : self::STATUS_ALERT,
            self::STATUS_ALERT => $issues !== [],
            self::FIELD_ISSUES => array_values(array_unique($issues)),
            self::FIELD_RAW => [
                self::FIELD_RETRIEVAL_EVAL => $retrievalEval,
                self::FIELD_RECALL_AT_5 => $recallAt5,
                self::FIELD_IMPROPER_FLOOR_DISCARDS => $improperFloorDiscards,
                self::FIELD_AURG_CROSS_LAYER_COVERAGE_RATIO => $coverageRatio,
                self::FIELD_PRE_FILTER_CONCENTRATION_RATIO => $preFilterConcentration,
            ],
            self::FIELD_THRESHOLDS => [
                self::FIELD_RETRIEVAL_EVAL => self::RAG_RETRIEVAL_EVAL_FLOOR,
                self::FIELD_RECALL_AT_5 => self::RAG_RECALL_AT_5_FLOOR,
                self::FIELD_AURG_CROSS_LAYER_COVERAGE_RATIO => self::RAG_COVERAGE_FLOOR,
                self::FIELD_PRE_FILTER_CONCENTRATION_MASK_FLOOR => self::RAG_PRE_FILTER_CONCENTRATION_MASK_FLOOR,
            ],
            self::FIELD_GENERATED_AT => now()->toIso8601String(),
        ];
    }

    /** @return array<string,mixed> */
    public function contextFeedbackHealthReport(): array
    {
        $since = CarbonImmutable::now('UTC')->subHours(self::FEEDBACK_WINDOW_HOURS);
        if (! DatabaseTableAvailability::has(self::FIELD_AI_RAG_FEEDBACK_EVENTS)) {
            return [
                self::FIELD_SCHEMA_VERSION => self::CONTEXT_FEEDBACK_SCHEMA,
                self::FIELD_STATUS => self::STATUS_UNAVAILABLE,
                self::STATUS_ALERT => true,
                self::FIELD_BLOCKING => [self::FIELD_AI_RAG_FEEDBACK_EVENTS_TABLE_MISSING],
                self::FIELD_TOTAL_EVENT_COUNT => 0,
                self::FIELD_MEASURED_SHARE => 0.0,
                self::FIELD_WRITER_SHARES => [],
                self::FIELD_WINDOW => [self::FIELD_HOURS => self::FEEDBACK_WINDOW_HOURS, self::FIELD_TOTAL_EVENT_COUNT => 0],
                self::FIELD_GENERATED_AT => now()->toIso8601String(),
            ];
        }

        $events = AiRagFeedbackEvent::query()->where('created_at', '>=', $since)->get();
        $total = $events->count();
        $measured = 0;
        $delivered = 0;
        $synthetic = 0;
        $transcript = 0;
        $byWriter = [];
        foreach ($events as $event) {
            $writer = AiValueNormalizer::trimmedScalarStringOrNull($event->flow_id ?: null) ?? self::STATUS_UNKNOWN;
            $byWriter[$writer] ??= [self::FIELD_TOTAL => 0, self::FIELD_MEASURED => 0, self::FIELD_DELIVERED_REFS => 0];
            $byWriter[$writer][self::FIELD_TOTAL]++;
            $isMeasured = (int) $event->post_execution_utility > 0 || (int) $event->context_sufficiency > 0 || (int) $event->used_sources > 0;
            $hasDelivered = (int) $event->included_sources > 0 || (AiValueNormalizer::trimmedStringOrNull($event->retrieval_receipt_id) ?? '') !== '';
            $payload = AiValueNormalizer::arrayOrEmpty($event->payload);
            $isSynthetic = ($payload[self::FIELD_SYNTHETIC] ?? false) === true || str_contains(AiValueNormalizer::lowerTrimmedString($payload[self::FIELD_SOURCE] ?? ''), 'synthetic');
            $isTranscript = str_contains(AiValueNormalizer::lowerTrimmedString($payload[self::FIELD_SOURCE] ?? $payload[self::FIELD_ORIGIN] ?? ''), self::FIELD_TRANSCRIPT_INFERRED);
            $measured += $isMeasured ? 1 : 0;
            $delivered += $hasDelivered ? 1 : 0;
            $synthetic += $isSynthetic ? 1 : 0;
            $transcript += $isTranscript ? 1 : 0;
            $byWriter[$writer][self::FIELD_MEASURED] += $isMeasured ? 1 : 0;
            $byWriter[$writer][self::FIELD_DELIVERED_REFS] += $hasDelivered ? 1 : 0;
        }
        foreach ($byWriter as $writer => $row) {
            $byWriter[$writer][self::FIELD_MEASURED_SHARE] = $row[self::FIELD_TOTAL] > 0 ? round($row[self::FIELD_MEASURED] / $row[self::FIELD_TOTAL], 4) : 0.0;
            $byWriter[$writer][self::FIELD_DELIVERED_REFS_SHARE] = $row[self::FIELD_TOTAL] > 0 ? round($row[self::FIELD_DELIVERED_REFS] / $row[self::FIELD_TOTAL], 4) : 0.0;
        }
        $syntheticShare = $total > 0 ? round($synthetic / $total, 4) : 0.0;
        $blocking = [];
        if ($total < self::FEEDBACK_TOTAL_EVENT_FLOOR) {
            $blocking[] = 'total_event_count_below_floor';
        }
        if ($measured < self::FEEDBACK_MEASURED_COUNT_FLOOR) {
            $blocking[] = 'measured_count_below_floor';
        }
        if ($syntheticShare > self::FEEDBACK_SYNTHETIC_SHARE_MAX) {
            $blocking[] = 'synthetic_share_above_floor';
        }

        return [
            self::FIELD_SCHEMA_VERSION => self::CONTEXT_FEEDBACK_SCHEMA,
            self::FIELD_STATUS => $blocking === [] ? self::STATUS_HEALTHY : self::STATUS_ALERT,
            self::STATUS_ALERT => $blocking !== [],
            self::FIELD_BLOCKING => $blocking,
            self::FIELD_WINDOW => [
                self::FIELD_HOURS => self::FEEDBACK_WINDOW_HOURS,
                self::FIELD_TOTAL_EVENT_COUNT => $total,
                self::FIELD_MEASURED_COUNT => $measured,
                self::FIELD_DELIVERED_REFS_COUNT => $delivered,
                'utility_real_share' => $total > 0 ? round($measured / $total, 4) : 0.0,
                self::FIELD_DELIVERED_REFS_SHARE => $total > 0 ? round($delivered / $total, 4) : 0.0,
                self::FIELD_SYNTHETIC_SHARE => $syntheticShare,
                self::FIELD_TRANSCRIPT_INFERRED_SHARE => $total > 0 ? round($transcript / $total, 4) : 0.0,
            ],
            self::FIELD_TOTAL_EVENT_COUNT => $total,
            self::FIELD_MEASURED_SHARE => $total > 0 ? round($measured / $total, 4) : 0.0,
            self::FIELD_WRITER_SHARES => $byWriter,
            self::FIELD_BY_WRITER => $byWriter,
            self::FIELD_THRESHOLDS => [
                self::FIELD_TOTAL_EVENT_COUNT_FLOOR => self::FEEDBACK_TOTAL_EVENT_FLOOR,
                self::FIELD_MEASURED_COUNT_FLOOR => self::FEEDBACK_MEASURED_COUNT_FLOOR,
                self::FIELD_SYNTHETIC_SHARE_MAX => self::FEEDBACK_SYNTHETIC_SHARE_MAX,
            ],
            self::FIELD_GENERATED_AT => now()->toIso8601String(),
        ];
    }

    /** @return array<string,mixed> */
    public function compactionSoakWatchReport(): array
    {
        $since = CarbonImmutable::now('UTC')->subDays(self::COMPACTION_WINDOW_DAYS);
        if (! DatabaseTableAvailability::has(self::FIELD_ATLAS_LONG_HORIZON_COMPACTION_RECEIPTS)) {
            return [
                self::FIELD_SCHEMA_VERSION => self::COMPACTION_SOAK_SCHEMA,
                self::FIELD_STATUS => self::STATUS_UNAVAILABLE,
                self::FIELD_READY_TO_ENFORCE => false,
                self::FIELD_BLOCKING => [self::FIELD_COMPACTION_RECEIPTS_TABLE_MISSING],
                self::FIELD_WINDOW => [self::FIELD_DAYS => self::COMPACTION_WINDOW_DAYS, self::FIELD_COMPACTION_COUNT => 0],
                self::FIELD_GENERATED_AT => now()->toIso8601String(),
            ];
        }
        $receipts = AtlasLongHorizonCompactionReceipt::query()->where('created_at', '>=', $since)->get();
        $criticalCuts = 0;
        $retentionScores = [];
        foreach ($receipts as $receipt) {
            $retention = AiValueNormalizer::finiteFloatOrNull($receipt->context_retention_score);
            if ($retention !== null) {
                $retentionScores[] = $retention;
            }
            foreach (AiValueNormalizer::arrayOrEmpty($receipt->unresolved_loss ?? null) as $loss) {
                if (! is_array($loss)) {
                    continue;
                }
                $kind = AiValueNormalizer::lowerTrimmedString($loss[self::FIELD_KIND] ?? $loss[self::FIELD_SEVERITY] ?? $loss[self::FIELD_KEEP_KIND] ?? '');
                if (str_contains($kind, self::FIELD_CRITICAL)) {
                    $criticalCuts++;
                }
            }
        }
        $crossWeek = app(LongHorizonCrossWeekRecallLiftGateService::class)->evaluate();
        $minRetention = $retentionScores === [] ? null : min($retentionScores);
        $blocking = [];
        if ($receipts->count() < self::COMPACTION_MIN_RECEIPTS) {
            $blocking[] = 'compaction_volume_below_floor';
        }
        if ($criticalCuts > 0) {
            $blocking[] = 'critical_must_keep_shadow_cut';
        }
        if ($minRetention === null || $minRetention < self::COMPACTION_MIN_RETENTION_SCORE) {
            $blocking[] = 'context_retention_score_below_floor';
        }
        if (($crossWeek[self::FIELD_CERTIFIED] ?? false) !== true) {
            $blocking[] = 'cross_week_recall_lift_not_certified';
        }

        return [
            self::FIELD_SCHEMA_VERSION => self::COMPACTION_SOAK_SCHEMA,
            self::FIELD_STATUS => $blocking === [] ? self::STATUS_READY : self::STATUS_NOT_READY,
            self::FIELD_READY_TO_ENFORCE => $blocking === [],
            self::FIELD_BLOCKING => array_values(array_unique($blocking)),
            self::FIELD_WINDOW => [
                self::FIELD_DAYS => self::COMPACTION_WINDOW_DAYS,
                self::FIELD_COMPACTION_COUNT => $receipts->count(),
                self::FIELD_CRITICAL_MUST_KEEP_SHADOW_CUTS => $criticalCuts,
                self::FIELD_CONTEXT_RETENTION_SCORE_MIN => $minRetention,
                self::FIELD_CONTEXT_RETENTION_SCORE_COUNT => count($retentionScores),
            ],
            self::FIELD_CROSS_WEEK_RECALL_LIFT_GATE => [
                self::FIELD_STATUS => $crossWeek[self::FIELD_STATUS] ?? self::STATUS_UNKNOWN,
                self::FIELD_CERTIFIED => (AiValueNormalizer::boolOrNull($crossWeek[self::FIELD_CERTIFIED] ?? null) ?? false),
                self::FIELD_BLOCKERS => AiValueNormalizer::arrayOrEmpty($crossWeek[self::FIELD_BLOCKERS] ?? null),
            ],
            self::FIELD_ROLLBACK_TRIGGER => [
                self::FIELD_ID => 'cpt_09_compaction_enforce',
                self::FIELD_CONDITION => '>=1 critical must_keep cut after enforcement flip',
                self::FIELD_ROLLBACK_ENV => 'ATLAS_TOKEN_ECONOMY_ENFORCEMENT_MODE=observe',
            ],
            self::FIELD_THRESHOLDS => [
                self::FIELD_WINDOW_DAYS => self::COMPACTION_WINDOW_DAYS,
                self::FIELD_MIN_COMPACTIONS => self::COMPACTION_MIN_RECEIPTS,
                self::FIELD_CRITICAL_MUST_KEEP_CUTS => 0,
                self::FIELD_MIN_CONTEXT_RETENTION_SCORE => self::COMPACTION_MIN_RETENTION_SCORE,
            ],
            self::FIELD_GENERATED_AT => now()->toIso8601String(),
        ];
    }

    /** @return array<string,mixed> */
    public function pipelineStabilityReport(): array
    {
        $scorecard = app(AtlasCognitionScoreCardService::class)->build();
        $pipeline = AiValueNormalizer::finiteFloatOrNull(data_get($scorecard, 'score.dimensions.pipeline.score_out_of_10', 0.0)) ?? 0.0;
        $partials = array_values(array_filter(AiValueNormalizer::arrayOrEmpty($scorecard[self::FIELD_SUBSYSTEMS] ?? null), static fn (array $row): bool => ($row[self::FIELD_PIPELINE_STATUS] ?? null) === AtlasCognitionScoreCardService::STATUS_PARTIAL));
        $blocking = [];
        if ($pipeline < 10.0) {
            $blocking[] = 'pipeline_score_below_perfect';
        }
        if ($partials !== []) {
            $blocking[] = 'pipeline_partials_present';
        }
        $payload = [
            self::FIELD_SCHEMA_VERSION => self::PIPELINE_SCORECARD_STABILITY_SCHEMA,
            self::FIELD_STATUS => $blocking === [] ? self::STATUS_OK : self::STATUS_ALERT,
            self::FIELD_BLOCKING => $blocking,
            self::FIELD_SCORECARD_HASH => $scorecard[self::FIELD_SCORECARD_HASH] ?? null,
            self::FIELD_PIPELINE_SCORE_OUT_OF_10 => $pipeline,
            self::FIELD_PARTIAL_COUNT => count($partials),
            self::FIELD_PARTIAL_ACRONYMS => array_values(array_map(static fn (array $row): string => (AiValueNormalizer::trimmedStringOrNull($row[self::FIELD_ACRONYM] ?? null) ?? ''), array_slice($partials, 0, 10))),
            self::FIELD_GENERATED_AT => now()->toIso8601String(),
        ];
        $this->recordLedger($blocking === [] ? LedgerEventType::OperationCompleted : LedgerEventType::OperationBlocked, $payload, 'pip-08.scorecard_stability');

        return $payload;
    }

    /** @return array<string,mixed> */
    public function liftCycleClosureReport(): array
    {
        $report = app(AtlasLearningRecallUseLiftService::class)->report();
        $blockers = array_values(AiValueNormalizer::arrayOrEmpty(data_get($report, 'measurement.blockers', [])));
        $status = (AiValueNormalizer::trimmedStringOrNull($report[self::FIELD_STATUS] ?? null) ?? self::STATUS_UNKNOWN);
        $series = $this->blockerSeries('ope-08.lift_cycle_closure', $blockers);
        $stalled = array_values(array_filter($series, static fn (array $row): bool => (int) (AiValueNormalizer::finiteFloatOrNull($row[self::FIELD_DAYS_IN_BLOCK] ?? null) ?? 0) > self::LIFT_STALLED_DAYS));
        $blocking = $blockers;
        if ($stalled !== []) {
            $blocking[] = 'lift_blocker_stalled';
        }
        $payload = [
            self::FIELD_SCHEMA_VERSION => self::OPE_LIFT_CYCLE_CLOSURE_SCHEMA,
            self::FIELD_STATUS => $blocking === [] && (AiValueNormalizer::boolOrNull(data_get($report, 'measurement.measurement_ready', false)) ?? false) ? self::STATUS_OK : self::STATUS_ALERT,
            self::FIELD_LIFT_STATUS => $status,
            self::FIELD_BLOCKING => array_values(array_unique($blocking)),
            self::FIELD_CASE_COUNTS => [
                self::FIELD_WITH_RECALLED_MEMORY => (int) (AiValueNormalizer::finiteFloatOrNull(data_get($report, 'measurement.with_recalled_memory.case_count', 0)) ?? 0),
                self::FIELD_WITHOUT_RECALLED_MEMORY => (int) (AiValueNormalizer::finiteFloatOrNull(data_get($report, 'measurement.without_recalled_memory.case_count', 0)) ?? 0),
            ],
            self::FIELD_BLOCKER_SERIES => $series,
            self::FIELD_GENERATED_AT => now()->toIso8601String(),
        ];
        $this->recordLedger($blocking === [] ? LedgerEventType::OperationCompleted : LedgerEventType::OperationBlocked, [
            self::FIELD_BLOCKERS => $blockers,
            self::FIELD_STATUS => $status,
            self::FIELD_CASE_COUNTS => $payload[self::FIELD_CASE_COUNTS],
            self::FIELD_BLOCKER_SERIES => $series,
        ], 'ope-08.lift_cycle_closure');

        return $payload;
    }

    /** @return array<string,mixed> */
    public function scorecardReceiptsDiagnosisReport(): array
    {
        $scorecard = app(AtlasCognitionScoreCardService::class)->build();
        $resolver = app(AtlasCognitionEvidenceResolver::class);
        $partials = [];
        foreach (AiValueNormalizer::arrayOrEmpty($scorecard[self::FIELD_SUBSYSTEMS] ?? null) as $row) {
            if (($row[self::FIELD_PIPELINE_STATUS] ?? null) !== AtlasCognitionScoreCardService::STATUS_PARTIAL) {
                continue;
            }
            $diagnosis = $resolver->resolvePipelineDiagnosis((AiValueNormalizer::trimmedStringOrNull($row[self::FIELD_SERVICE_CLASS] ?? null) ?? ''));
            $partials[] = [
                self::FIELD_ACRONYM => (AiValueNormalizer::trimmedStringOrNull($row[self::FIELD_ACRONYM] ?? null) ?? ''),
                self::FIELD_SERVICE_CLASS => (AiValueNormalizer::trimmedStringOrNull($row[self::FIELD_SERVICE_CLASS] ?? null) ?? ''),
                self::FIELD_DIAGNOSIS => $diagnosis,
            ];
        }
        $stale = array_values(array_filter($partials, static function (array $row): bool {
            $age = AiValueNormalizer::finiteFloatOrNull(data_get($row, 'diagnosis.latest_receipt_age_days'));

            return $age !== null && $age > self::PIPELINE_PARTIAL_STALE_DAYS;
        }));
        $blocking = [];
        if ($partials !== []) {
            $blocking[] = 'pipeline_partials_present';
        }
        if ($stale !== []) {
            $blocking[] = 'pipeline_partial_stale_after_mint_window';
        }

        return [
            self::FIELD_SCHEMA_VERSION => self::OPE_SCORECARD_RECEIPTS_DIAGNOSIS_SCHEMA,
            self::FIELD_STATUS => $blocking === [] ? self::STATUS_OK : self::STATUS_ALERT,
            self::FIELD_BLOCKING => $blocking,
            self::FIELD_PARTIAL_COUNT => count($partials),
            self::FIELD_STALE_PARTIAL_COUNT => count($stale),
            self::FIELD_DIAGNOSES => $partials,
            self::FIELD_THRESHOLDS => [self::FIELD_PARTIAL_STALE_DAYS => self::PIPELINE_PARTIAL_STALE_DAYS],
            self::FIELD_GENERATED_AT => now()->toIso8601String(),
        ];
    }

    /** @return array<string,mixed> */
    public function engineeringEnforceReadinessReport(): array
    {
        $coverage = app(ProviderGovernanceCoverageLedger::class);
        $live = app(AtlasDecideLiveOutcomeFeedbackService::class);
        $coverageRows = AppendOnlyJsonlStore::read($coverage->logPath());
        $windowCoverageRows = $this->rowsInWindow($coverageRows, self::ENG_WINDOW_DAYS, 'context.recorded_at');
        $summary = $coverage->summary();
        $liveRows = $this->rowsInWindow($live->listOutcomes(), self::ENG_WINDOW_DAYS);
        $governanceByExecutor = $this->countExecutors($windowCoverageRows);
        $forgePromoted = $this->forgePromotedCycles();
        $admlRoutes = $this->admlReadyRoutes($liveRows);
        $bypassRate = AiValueNormalizer::finiteFloatOrNull($summary[self::FIELD_BYPASS_RATE] ?? null) ?? 1.0;
        $flips = [
            self::FIELD_GOVERNANCE_ENFORCE => [
                self::STATUS_READY => count(array_filter($governanceByExecutor, static fn (int $count): bool => $count >= self::ENG_MIN_REAL_EXECUTIONS_PER_EXECUTOR)) >= 3
                    && $bypassRate === 0.0
                    && (int) (AiValueNormalizer::finiteFloatOrNull($summary[self::FIELD_FALSE_POSITIVE_TOTAL] ?? null) ?? 0) === 0,
                self::FIELD_BLOCKING => array_values(array_filter([
                    count(array_filter($governanceByExecutor, static fn (int $count): bool => $count >= self::ENG_MIN_REAL_EXECUTIONS_PER_EXECUTOR)) >= 3 ? null : 'governance_soak_volume_below_floor',
                    $bypassRate === 0.0 ? null : 'governance_bypass_rate_nonzero',
                    (int) (AiValueNormalizer::finiteFloatOrNull($summary[self::FIELD_FALSE_POSITIVE_TOTAL] ?? null) ?? 0) === 0 ? null : 'governance_false_positive_nonzero',
                ])),
                self::FIELD_RAW => [
                    self::FIELD_WINDOW_DAYS => self::ENG_WINDOW_DAYS,
                    self::FIELD_BY_EXECUTOR => $governanceByExecutor,
                    self::FIELD_BYPASS_RATE => AiValueNormalizer::finiteFloatOrNull($summary[self::FIELD_BYPASS_RATE] ?? null) ?? 0.0,
                    self::FIELD_FALSE_POSITIVE_TOTAL => (int) (AiValueNormalizer::finiteFloatOrNull($summary[self::FIELD_FALSE_POSITIVE_TOTAL] ?? null) ?? 0),
                    self::FIELD_FP_DEFINITION => (AiValueNormalizer::trimmedStringOrNull($summary[self::FIELD_FP_DEFINITION] ?? null) ?? ''),
                ],
            ],
            self::FIELD_FORGE_GATE_ENFORCE => [
                self::STATUS_READY => $forgePromoted >= self::ENG_MIN_FORGE_PROMOTED_CYCLES,
                self::FIELD_BLOCKING => $forgePromoted >= self::ENG_MIN_FORGE_PROMOTED_CYCLES ? [] : [self::FIELD_FORGE_PROMOTED_CYCLE_VOLUME_BELOW_FLOOR],
                self::FIELD_RAW => [self::FIELD_PROMOTED_HARNESS_CAPTURED_CYCLES => $forgePromoted],
            ],
            self::FIELD_ADML_COST_OUTCOME => [
                self::STATUS_READY => count($admlRoutes) >= self::ENG_MIN_ADML_PROVEN_ROUTES,
                self::FIELD_BLOCKING => count($admlRoutes) >= self::ENG_MIN_ADML_PROVEN_ROUTES ? [] : [self::FIELD_ADML_PROVEN_ROUTE_VOLUME_BELOW_FLOOR],
                self::FIELD_RAW => [self::FIELD_READY_ROUTES => count($admlRoutes), self::FIELD_ROUTES => $admlRoutes],
            ],
        ];
        $blocking = [];
        foreach ($flips as $flip) {
            $blocking = array_merge($blocking, AiValueNormalizer::arrayOrEmpty($flip[self::FIELD_BLOCKING] ?? null));
        }

        return [
            self::FIELD_SCHEMA_VERSION => self::ENGINEERING_READINESS_SCHEMA,
            self::FIELD_STATUS => $blocking === [] ? self::STATUS_READY : self::STATUS_NOT_READY,
            self::FIELD_READY_TO_ENFORCE => $blocking === [],
            self::FIELD_BLOCKING => array_values(array_unique($blocking)),
            self::FIELD_FLIPS => $flips,
            self::FIELD_SOURCES => [
                self::FIELD_PROVIDER_GOVERNANCE_COVERAGE_LEDGER => $this->relativePath($coverage->logPath()),
                self::FIELD_LIVE_OUTCOMES_JSONL => $this->relativePath($live->logPath()),
                self::FIELD_FORGE_SOVEREIGN_VERDICT_JSONL => $this->relativePath($this->forgeSovereignVerdictPath()),
            ],
            self::FIELD_THRESHOLDS => [
                self::FIELD_WINDOW_DAYS => self::ENG_WINDOW_DAYS,
                self::FIELD_REAL_EXECUTIONS_PER_EXECUTOR => self::ENG_MIN_REAL_EXECUTIONS_PER_EXECUTOR,
                self::FIELD_FORGE_PROMOTED_CYCLES => self::ENG_MIN_FORGE_PROMOTED_CYCLES,
                self::FIELD_ADML_PROVEN_ROUTES => self::ENG_MIN_ADML_PROVEN_ROUTES,
            ],
            self::FIELD_GENERATED_AT => now()->toIso8601String(),
        ];
    }

    /** @param array<string,mixed> $report */
    public function toCheckResult(array $report, string $alertCode, string $message): AtlasWatchdogCheckResult
    {
        $status = (AiValueNormalizer::trimmedStringOrNull($report[self::FIELD_STATUS] ?? null) ?? '');
        $alert = (AiValueNormalizer::boolOrNull($report[self::STATUS_ALERT] ?? null) ?? false)
            || in_array($status, [self::STATUS_ALERT, self::STATUS_NOT_READY, self::STATUS_UNAVAILABLE], true)
            || (isset($report[self::FIELD_READY_TO_ENFORCE]) && $report[self::FIELD_READY_TO_ENFORCE] === false);

        if (! $alert) {
            return AtlasWatchdogCheckResult::ok($report);
        }

        return AtlasWatchdogCheckResult::alert(
            evidence: $report,
            alert: [
                self::FIELD_CODE => $alertCode,
                self::FIELD_MESSAGE => $message,
            ],
        );
    }

    /**
     * @param  list<array<string,mixed>>  $checks
     * @param  array<string,mixed>  $extra
     * @return array<string,mixed>
     */
    private function reportFromChecks(string $schema, array $checks, array $extra, string $alertCode): array
    {
        $failed = array_values(array_filter($checks, static fn (array $check): bool => ! (AiValueNormalizer::boolOrNull($check[self::FIELD_PASS] ?? null) ?? false)));

        return [
            self::FIELD_SCHEMA_VERSION => $schema,
            self::FIELD_STATUS => $failed === [] ? self::STATUS_OK : self::STATUS_ALERT,
            self::STATUS_ALERT => $failed !== [],
            self::FIELD_CHECKS => $checks,
            self::FIELD_BLOCKING => array_values(array_map(static fn (array $check): string => AiValueNormalizer::trimmedScalarStringOrNull($check[self::FIELD_CODE] ?? null) ?? '', $failed)),
            self::FIELD_ALERT_DETAIL => $failed === [] ? null : [
                self::FIELD_CODE => $alertCode,
                self::FIELD_CHECK_ID => AiValueNormalizer::trimmedScalarStringOrNull($failed[0][self::FIELD_ID] ?? null) ?? '',
            ],
            ...$extra,
            self::FIELD_GENERATED_AT => now()->toIso8601String(),
        ];
    }

    /** @return array<string,mixed> */
    private function checkRow(string $id, bool $pass, array $raw = [], string $code = ''): array
    {
        return [
            self::FIELD_ID => $id,
            self::FIELD_PASS => $pass,
            self::FIELD_CODE => $code !== '' ? $code : ($pass ? self::STATUS_OK : $id.'_failed'),
            self::FIELD_RAW => $raw,
        ];
    }

    private function ageCheck(string $id, ?CarbonImmutable $at, int $maxAgeHours, CarbonImmutable $now): array
    {
        $age = $at ? round($at->diffInMinutes($now) / 60, 2) : null;

        return $this->checkRow($id, $age !== null && $age <= $maxAgeHours, [
            self::FIELD_LAST_AT => $at?->toIso8601String(),
            self::FIELD_AGE_HOURS => $age,
            self::FIELD_MAX_AGE_HOURS => $maxAgeHours,
        ], $id.'_stale_or_missing');
    }

    private function parseDate(mixed $value): ?CarbonImmutable
    {
        if ($value instanceof \DateTimeInterface) {
            return CarbonImmutable::instance($value);
        }
        if (AiValueNormalizer::trimmedStringOrNull($value) === null) {
            return null;
        }
        try {
            return CarbonImmutable::parse($value)->utc();
        } catch (Throwable) {
            return null;
        }
    }

    /** @param list<string> $actions */
    private function latestMemoryFeedbackAt(array $actions): ?CarbonImmutable
    {
        if (! DatabaseTableAvailability::has(self::FIELD_ATLAS_MEMORY_ENTRY_USAGES)) {
            return null;
        }
        $raw = AtlasMemoryEntryUsage::query()
            ->whereIn('feedback_action', $actions)
            ->max('feedback_recorded_at');

        return $this->parseDate($raw);
    }

    /** @param class-string $model */
    private function latestModelAt(string $model, string $column, string $table): ?CarbonImmutable
    {
        if (! DatabaseTableAvailability::has($table)) {
            return null;
        }

        return $this->parseDate($model::query()->max($column));
    }

    private function latestRagDeliveredRefsAt(): ?CarbonImmutable
    {
        if (! DatabaseTableAvailability::has(self::FIELD_AI_RAG_FEEDBACK_EVENTS)) {
            return null;
        }
        $row = AiRagFeedbackEvent::query()
            ->where(function ($query): void {
                $query->where('included_sources', '>', 0)
                    ->orWhereNotNull('retrieval_receipt_id');
            })
            ->latest('created_at')
            ->first();

        return $this->parseDate($row?->created_at);
    }

    private function latestAemorSourceAt(string $source): ?CarbonImmutable
    {
        if (! DatabaseTableAvailability::has(self::FIELD_ATLAS_AEMOR_EXECUTION_EPISODES)) {
            return null;
        }
        $needle = '%'.$source.'%';
        $raw = AtlasAemorExecutionEpisode::query()
            ->where(function ($query) use ($needle): void {
                $query->where('flow_id', 'like', $needle)
                    ->orWhere('surface_id', 'like', $needle)
                    ->orWhere('scope_type', 'like', $needle);
            })
            ->max('created_at');

        return $this->parseDate($raw);
    }

    /**
     * @param  list<string>  $blockers
     * @return list<array<string,mixed>>
     */
    private function blockerSeries(string $scopeId, array $blockers): array
    {
        if (! DatabaseTableAvailability::has(self::FIELD_ATLAS_LEDGER_EVENTS)) {
            return array_map(static fn (string $blocker): array => [
                self::FIELD_BLOCKER => $blocker,
                self::FIELD_DAYS_IN_BLOCK => 0,
                self::FIELD_FIRST_SEEN_AT => null,
            ], $blockers);
        }
        $hasScopeColumns = DatabaseTableAvailability::hasColumn('atlas_ledger_events', 'scope_type')
            && DatabaseTableAvailability::hasColumn('atlas_ledger_events', 'scope_id');
        $series = [];
        foreach ($blockers as $blocker) {
            $query = AtlasLedgerEvent::query()
                ->where('event_type', LedgerEventType::OperationBlocked->value)
                ->whereJsonContains('payload->blockers', $blocker)
                ->orderBy('occurred_at');
            if ($hasScopeColumns) {
                $query->where('scope_type', 'acos_watchdog')->where('scope_id', $scopeId);
            } else {
                // Repair migrations may recreate the ledger without scope columns;
                // correlation_id still scopes watchdog blocker history honestly.
                $query->where('correlation_id', 'acos:watchdog:'.$scopeId);
            }
            $first = $query->first();
            $firstAt = $this->parseDate($first?->occurred_at) ?? CarbonImmutable::now('UTC');
            $series[] = [
                self::FIELD_BLOCKER => $blocker,
                self::FIELD_FIRST_SEEN_AT => $first?->occurred_at?->toIso8601String(),
                self::FIELD_DAYS_IN_BLOCK => (int) floor($firstAt->diffInHours(CarbonImmutable::now('UTC')) / 24),
            ];
        }

        return $series;
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function recordLedger(LedgerEventType $type, array $payload, string $scopeId): void
    {
        try {
            app(AtlasEvidenceLedger::class)->record($type, $payload, [
                self::FIELD_TENANT_ID => 'default',
                self::FIELD_OPERATOR_ID => 'system',
                self::FIELD_ENVELOPE_ID => 'acos:watchdog:'.$scopeId.':'.now()->format('YmdHis'),
                self::FIELD_CORRELATION_ID => 'acos:watchdog:'.$scopeId,
                self::FIELD_SCOPE_TYPE => self::FIELD_ACOS_WATCHDOG,
                self::FIELD_SCOPE_ID => $scopeId,
                self::FIELD_EMITTER_STAGE => 'atlas.acos.watchdog',
                self::FIELD_EMITTER_VERSION => self::ONDA4_EMITTER_VERSION,
            ]);
        } catch (Throwable) {
            // The health report remains honest even if the append-only evidence sink is absent.
        }
    }

    /**
     * @param  list<array<string,mixed>>  $rows
     * @return list<array<string,mixed>>
     */
    private function rowsInWindow(array $rows, int $days, string $recordedAtPath = 'recorded_at'): array
    {
        $since = CarbonImmutable::now('UTC')->subDays($days);

        return array_values(array_filter($rows, function (array $row) use ($since, $recordedAtPath): bool {
            $at = $this->parseDate(data_get($row, $recordedAtPath) ?? data_get($row, self::FIELD_RECORDED_AT) ?? data_get($row, self::FIELD_CREATED_AT));

            return $at !== null && $at->greaterThanOrEqualTo($since);
        }));
    }

    /** @param list<array<string,mixed>> $rows @return array<string,int> */
    private function countExecutors(array $rows): array
    {
        $counts = [self::FIELD_DEV => 0, self::FIELD_FORGE => 0, self::FIELD_AUTONOMOS => 0];
        foreach ($rows as $row) {
            $actor = AiValueNormalizer::lowerTrimmedString(data_get($row, 'context.executor', data_get($row, 'context.actor', data_get($row, self::FIELD_SURFACE, ''))));
            foreach (array_keys($counts) as $executor) {
                if (str_contains($actor, $executor)) {
                    $counts[$executor]++;
                }
            }
        }

        return $counts;
    }

    private function forgePromotedCycles(): int
    {
        $count = 0;
        foreach (AppendOnlyJsonlStore::read($this->forgeSovereignVerdictPath()) as $row) {
            if (($row[self::FIELD_PROMOTED] ?? false) === true
                && ($row[self::FIELD_EVIDENCE_PROVENANCE] ?? null) === self::FIELD_HARNESS_CAPTURED
                && (int) (AiValueNormalizer::finiteFloatOrNull($row[self::FIELD_TESTS_RUN] ?? null) ?? 0) > 0
                && count(AiValueNormalizer::arrayOrEmpty($row[self::FIELD_COMMANDS] ?? null)) > 0) {
                $count++;
            }
        }

        return $count;
    }

    /** @param list<array<string,mixed>> $rows @return array<string,int> */
    private function admlReadyRoutes(array $rows): array
    {
        $minEvidence = (int) app(GovernanceFloorRegistry::class)->atlasDecideCostOutcomeConfig(
            (AiValueNormalizer::boolOrNull(config(self::ADML_COST_OUTCOME_ENABLED_CONFIG_KEY, self::DEFAULT_ADML_COST_OUTCOME_ENABLED)) ?? self::DEFAULT_ADML_COST_OUTCOME_ENABLED),
        )[self::FIELD_MIN_EVIDENCE];
        $routes = [];
        foreach ($rows as $row) {
            if (($row[self::FIELD_PROVEN_REAL] ?? false) !== true) {
                continue;
            }
            $route = (AiValueNormalizer::trimmedStringOrNull($row[self::FIELD_TASK_CATEGORY] ?? null) ?? '').'|'.(AiValueNormalizer::trimmedStringOrNull($row[self::FIELD_ROLE] ?? null) ?? '');
            if ($route !== '|') {
                $routes[$route] = ($routes[$route] ?? 0) + 1;
            }
        }

        return array_filter($routes, static fn (int $count): bool => $count >= $minEvidence);
    }

    private function forgeSovereignVerdictPath(): string
    {
        return storage_path('app/atlas/engineering-kernel/forge-sovereign-verdicts.jsonl');
    }

    private function relativePath(string $path): string
    {
        $path = str_replace('\\', '/', $path);
        $base = str_replace('\\', '/', base_path());
        if (str_starts_with($path, $base.'/')) {
            return substr($path, strlen($base) + 1);
        }

        return $path;
    }
}
