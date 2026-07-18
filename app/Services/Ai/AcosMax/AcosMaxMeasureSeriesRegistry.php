<?php

declare(strict_types=1);

namespace App\Services\Ai\AcosMax;

use App\Console\Commands\AtlasAcosFreezeCommand;
use App\Console\Commands\AtlasAcosMSeriesCommand;
use App\Services\Ai\AtlasDecide\AtlasDecideCostOutcomeRouter;
use App\Services\Ai\AtlasDecide\AtlasDecideLiveOutcomeFeedbackService;
use App\Services\Ai\AtlasDecide\AtlasDecideReplayDivergenceService;
use App\Services\Ai\AtlasDecide\AtlasDecideRouteRegretService;
use App\Services\Ai\Autonomy\AtlasOperatorReviewDebtMeter;
use App\Services\Ai\Autonomy\OperatorApprovalHistoryMeter;
use App\Services\Ai\Cognition\AtlasImmuneClassifierHybridFreeze;
use App\Services\Ai\Cognition\AtlasImmuneSignatureFreeze;
use App\Services\Ai\Cognition\ImmuneCalibrationService;
use App\Services\Ai\Cognition\ImmuneSignatureStore;
use App\Services\Ai\Cognition\ImmuneVerdictLedger;
use App\Services\Ai\Compounding\AtlasLearningRecallUseLiftService;
use App\Services\Ai\Compounding\AtlasLessonQualityService;
use App\Services\Ai\Governance\Recursion\MetaLoopBreakerService;
use App\Services\Ai\Memory\AtlasMemoryTemporalQualityService;
use App\Services\Ai\OpenBrain\AtlasAobgLatencyLedger;
use App\Services\Ai\Reality\AtlasAurgPprShadowDualReadLedger;
use App\Services\Ai\Support\AiValueNormalizer;

final class AcosMaxMeasureSeriesRegistry
{

    public const FIELD_SLICE = 'slice';

    public const FIELD_SERIES = 'series';

    public const FIELD_PATH = 'path';

    public const FIELD_TABLE = 'table';

    public const FIELD_TTL_DAYS = 'ttl_days';

    public const FIELD_TIMESTAMP_FIELD = 'timestamp_field';

    public const FIELD_SOURCE_TYPE = 'source_type';

    public const FIELD_TTL_SOURCE = 'ttl_source';
    public const FIELD_SCOPE_ID = 'scope_id';
    public const FIELD_SCOPE_TYPE = 'scope_type';
    public const FIELD_WHERE = 'where';
    public const FIELD_GENERATED_AT = 'generated_at';
    public const FIELD_RECORDED_AT = 'recorded_at';
    public const FIELD_ATLAS_LEDGER_EVENTS = 'atlas_ledger_events';
    public const FIELD_OCCURRED_AT = 'occurred_at';
    public const FIELD_ACOS_WATCHDOG = 'acos_watchdog';
    public const FIELD_UNIFIED = 'unified';
    public const FIELD_ATTESTED_AT = 'attested_at';
    public const FIELD_CAPTURES = 'captures';
    public const FIELD_CREATED_AT = 'created_at';
    public const FIELD_DECIDED_AT = 'decided_at';
    public const FIELD_FIRST_SEEN = 'first_seen';
    public const FIELD_GROUND_TRUTH_RECEIPT = 'ground_truth_receipt';
    public const FIELD_ONE_TIME_CLEANUP_RECEIPT = 'one_time_cleanup_receipt';
    public const FIELD_TS = 'ts';
    public const FIELD_MAXA_06 = 'MAXA-06';
    public const FIELD_MAXL_06 = 'MAXL-06';
    public const FIELD_MULTJ_01 = 'MULTJ-01';
    public const FIELD_MULTJ_02 = 'MULTJ-02';
    public const FIELD_MULTJ_03 = 'MULTJ-03';
    public const FIELD_MULTJ_04 = 'MULTJ-04';
    public const FIELD_MULTJ_06 = 'MULTJ-06';
    public const FIELD_MULTN17_04 = 'MULTN17-04';
    public const FIELD_MULTX_01 = 'MULTX-01';
    public const FIELD_MULTX_06 = 'MULTX-06';
    public const FIELD_MULTX_09 = 'MULTX-09';
    public const FIELD_TETO_02 = 'TETO-02';
    public const FIELD_ASI_05 = 'ASI-05';
    public const FIELD_ELEV_02 = 'ELEV-02';
    public const FIELD_ELEV_12 = 'ELEV-12';
    public const FIELD_ELEV_20S = 'ELEV-20s';
    public const FIELD_ELEV_25 = 'ELEV-25';
    public const FIELD_ELEV_27 = 'ELEV-27';
    public const FIELD_ESP_00 = 'ESP-00';
    public const FIELD_ESP_03 = 'ESP-03';
    public const FIELD_ESP_05 = 'ESP-05';
    public const FIELD_ESP_06 = 'ESP-06';
    public const FIELD_ESP_09 = 'ESP-09';
    public const FIELD_MAXA_04 = 'MAXA-04';
    public const FIELD_MAXD_04 = 'MAXD-04';
    public const FIELD_MAXG_01 = 'MAXG-01';
    public const FIELD_MAXH_01 = 'MAXH-01';
    public const FIELD_MAXI_02 = 'MAXI-02';
    public const FIELD_MAXI_03 = 'MAXI-03';
    public const FIELD_MAXI_04 = 'MAXI-04';
    public const FIELD_MAXI_05 = 'MAXI-05';
    public const FIELD_MAXJ_01 = 'MAXJ-01';
    public const FIELD_MAXJ_05 = 'MAXJ-05';
    public const FIELD_MAXK_01 = 'MAXK-01';
    public const FIELD_MAXL_02 = 'MAXL-02';
    public const FIELD_MAXL_07 = 'MAXL-07';
    public const FIELD_MAXL_08 = 'MAXL-08';
    public const FIELD_MAXM_01 = 'MAXM-01';
    public const FIELD_MULTK_01 = 'MULTK-01';
    public const FIELD_MULTK_02 = 'MULTK-02';
    public const FIELD_MULTK_03 = 'MULTK-03';
    public const FIELD_MULTN15_02 = 'MULTN15-02';
    public const FIELD_MULTX_02 = 'MULTX-02';
    public const FIELD_RAGX_07 = 'RAGX-07';
    public const FIELD_REC_06 = 'REC-06';
    public const FIELD_TETO_01 = 'TETO-01';
    public const FIELD_ACOS_ASI05_LEDGER_CLEANUP_V1 = 'acos.asi05.ledger_cleanup.v1';
    public const FIELD_ACOS_DEAD_SERIES_WATCHDOG_V1 = 'acos.dead_series_watchdog.v1';
    public const FIELD_ACOS_ESP00_GROUND_TRUTH_V1 = 'acos.esp00.ground_truth.v1';
    public const FIELD_AOBG_LATENCY_LEDGER_V1 = 'aobg.latency_ledger.v1';
    public const FIELD_ATLAS_CAPTURE_COGNITIVE_IMMUNE_AUDIT_V2 = 'atlas.capture.cognitive_immune_audit.v2';
    public const FIELD_ATLAS_EVIDENCE_LEDGER_HASH_CHAIN_V1 = 'atlas.evidence_ledger.hash_chain.v1';
    public const FIELD_ATLAS_PROVIDER_LEAK_CORPUS_V1 = 'atlas.provider_leak_corpus.v1';
    public const FIELD_ATLAS_RESOURCE_BUDGET_V1 = 'atlas.resource_budget.v1';
    public const FIELD_ATLAS_TEST_ATTESTATION_V1 = 'atlas.test_attestation.v1';
    public const FIELD_ELEV_20S_FREEZE_EQUIVALENT = 'elev-20s-freeze-equivalent';
    public const FIELD_ELEV_27_RESOURCE_BUDGET = 'elev-27-resource-budget';
    public const FIELD_ESP_03_TEST_ATTESTATION_SEAL = 'esp-03-test-attestation-seal';
    public const FIELD_MAXA_04_JINA_V3_DUAL_READ_WINDOW = 'maxa-04-jina-v3-dual-read-window';
    public const FIELD_MAXD_04_PPR_SHADOW_DUAL_READ_WINDOW = 'maxd-04-ppr-shadow-dual-read-window';
    public const FIELD_MAXI_02_SHADOW_AUDIT_V2 = 'maxi-02-shadow-audit-v2';
    public const FIELD_MAXL_02_FREEZE_EQUIVALENT = 'maxl-02-freeze-equivalent';
    public const FIELD_MAXM_01_FROZEN_CORPUS_BASELINE = 'maxm-01-frozen-corpus-baseline';
    public const FIELD_RAGX_07_RECORDS_ONLY_AB_REGISTRATION = 'ragx-07-records-only-ab-registration';
    public const FIELD_REC_06_META_LOOP_BREAKER_READER = 'rec-06-meta-loop-breaker-reader';
    public const FIELD_FREEZE_ACOS_FLYWHEEL_LOOPS_V1 = 'freeze:acos.flywheel.loops.v1';
    public const FIELD_FREEZE_ACOS_LEARNING_LATENCY_V1 = 'freeze:acos.learning_latency.v1';
    public const FIELD_FREEZE_ACOS_OPERATOR_REVIEW_DEBT_V1 = 'freeze:acos.operator_review_debt.v1';
    public const FIELD_FREEZE_ACOS_VERIFIED_SHARE_V1 = 'freeze:acos.verified_share.v1';
    public const FIELD_FREEZE_ACOS_WINDOWS_ORCHESTRATOR_V1 = 'freeze:acos.windows_orchestrator.v1';
    public const FIELD_FREEZE_AOBG_LATENCY_LEDGER_V1 = 'freeze:aobg.latency_ledger.v1';
    public const FIELD_FREEZE_ASI_METRIC_M_V1 = 'freeze:asi.metric.m.v1';
    public const FIELD_FREEZE_ATLAS_AI_ABSTRACTION_LADDER_V1 = 'freeze:atlas.ai.abstraction_ladder.v1';
    public const FIELD_FREEZE_ATLAS_AI_COUNTERFACTUAL_LIFT_V2 = 'freeze:atlas.ai.counterfactual_lift.v2';
    public const FIELD_FREEZE_ATLAS_AI_LESSON_HALF_LIFE_V2 = 'freeze:atlas.ai.lesson_half_life.v2';
    public const FIELD_FREEZE_ATLAS_AI_LESSON_QUALITY_V2 = 'freeze:atlas.ai.lesson_quality.v2';
    public const FIELD_FREEZE_ATLAS_AI_LESSON_SEMANTIC_DEDUP_V1 = 'freeze:atlas.ai.lesson_semantic_dedup.v1';
    public const FIELD_FREEZE_ATLAS_AI_LESSON_TYPE_YIELD_V2 = 'freeze:atlas.ai.lesson_type_yield.v2';
    public const FIELD_FREEZE_ATLAS_AI_PROCEDURAL_SKILL_PROMOTER_V1 = 'freeze:atlas.ai.procedural_skill_promoter.v1';
    public const FIELD_FREEZE_ATLAS_CODE_SYMBOL_EMBEDDING_COVERAGE_V1 = 'freeze:atlas.code_symbol_embedding_coverage.v1';
    public const FIELD_FREEZE_ATLAS_CONTEXT_EXECUTION_COOCCURRENCE_V1 = 'freeze:atlas.context.execution_cooccurrence.v1';
    public const FIELD_FREEZE_ATLAS_CONTEXT_GOLDEN_COUNTERFACTUAL_V1 = 'freeze:atlas.context.golden_counterfactual.v1';
    public const FIELD_FREEZE_ATLAS_DECIDE_CASCADE_COST_ROUTER_V1 = 'freeze:atlas.decide.cascade_cost_router.v1';
    public const FIELD_FREEZE_ATLAS_DECIDE_COST_OUTCOME_UNCERTAINTY_V1 = 'freeze:atlas.decide.cost_outcome_uncertainty.v1';
    public const FIELD_FREEZE_ATLAS_DECIDE_REPLAY_DIVERGENCE_V1 = 'freeze:atlas.decide.replay_divergence.v1';
    public const FIELD_FREEZE_ATLAS_DECIDE_ROUTE_REGRET_V2 = 'freeze:atlas.decide.route_regret.v2';
    public const FIELD_FREEZE_ATLAS_DECIDE_ZERO_WEIGHT_OUTCOMES_V1 = 'freeze:atlas.decide.zero_weight_outcomes.v1';
    public const FIELD_FREEZE_ATLAS_ESP_06_OUTCOME_ENVELOPE_V1 = 'freeze:atlas.esp_06.outcome_envelope.v1';
    public const FIELD_FREEZE_ATLAS_ESP_09_CHALLENGER_ADVISORY_V1 = 'freeze:atlas.esp_09.challenger_advisory.v1';
    public const FIELD_FREEZE_ATLAS_EVIDENCE_DELTA_ATTRIBUTION_V1 = 'freeze:atlas.evidence.delta_attribution.v1';
    public const FIELD_FREEZE_ATLAS_IMMUNE_CALIBRATION_V1 = 'freeze:atlas.immune.calibration.v1';
    public const FIELD_FREEZE_ATLAS_IMMUNE_CLASSIFIER_HYBRID_V1 = 'freeze:atlas.immune.classifier_hybrid.v1';
    public const FIELD_FREEZE_ATLAS_IMMUNE_SIGNATURE_STORE_V1 = 'freeze:atlas.immune.signature_store.v1';
    public const FIELD_FREEZE_ATLAS_KB_EMBEDDING_COVERAGE_V1 = 'freeze:atlas.kb_embedding_coverage.v1';
    public const FIELD_FREEZE_ATLAS_M_FUNNEL_V1 = 'freeze:atlas.m.funnel.v1';
    public const FIELD_FREEZE_ATLAS_MEMORY_TEMPORAL_TRUTH_V2 = 'freeze:atlas.memory.temporal_truth.v2';
    public const FIELD_FREEZE_ATLAS_N_CAPTURE_DRILL_V1 = 'freeze:atlas.n_capture_drill.v1';
    public const FIELD_FREEZE_MISSION_E2E_V1 = 'freeze:mission_e2e.v1';
    public const FIELD_FREEZE_OPERATOR_APPROVAL_HISTORY_V1 = 'freeze:operator.approval_history.v1';
    public const FIELD_APP_ATLAS_EVIDENCE_ACOS_MAX_ASI_05_LEDGER_CLEANUP_JSONL = 'app/atlas/evidence/acos-max-asi-05-ledger-cleanup.jsonl';
    public const FIELD_APP_ATLAS_EVIDENCE_ACOS_MAX_ESP_00_GROUND_TRUTH_JSONL = 'app/atlas/evidence/acos-max-esp-00-ground-truth.jsonl';
    public const FIELD_APP_ATLAS_EVIDENCE_ACOS_MAX_MAXI_04_CLASSIFIER_HYBRID_JSONL = 'app/atlas/evidence/acos-max-maxi-04-classifier-hybrid.jsonl';
    public const FIELD_APP_ATLAS_EVIDENCE_ACOS_MAX_MAXM01_PROVIDER_LEAK_CORPUS_JSONL = 'app/atlas/evidence/acos-max-maxm01-provider-leak-corpus.jsonl';
    public const FIELD_APP_ATLAS_EVIDENCE_RAGX_AB_REGISTRATIONS_JSONL = 'app/atlas/evidence/ragx-ab-registrations.jsonl';
    public const FIELD_ATLAS_ATLAS_DECIDE_LIVE_OUTCOMES_JSONL = 'atlas/atlas_decide/live_outcomes.jsonl';
    public const FIELD_FREEZE_ATLAS_ORIGINATOR_PREDICTED_IMPACT_CALIBRATION_V1 = 'freeze:atlas.originator.predicted_impact_calibration.v1';
    public const FIELD_ATLAS_ACOS_DELTA_ATTRIBUTION___JSON = 'atlas:acos:delta-attribution --json';
    public const FIELD_ATLAS_ACOS_REC06_BREAKERS___JSON = 'atlas:acos:rec06-breakers --json';
    public const FIELD_ATLAS_AI_ABSTRACTION_LADDER___JSON = 'atlas:ai:abstraction-ladder --json';
    public const FIELD_ATLAS_AI_COUNTERFACTUAL_LIFT___JSON = 'atlas:ai:counterfactual-lift --json';
    public const FIELD_ATLAS_AI_LESSON_DEDUP_CALIBRATION___JSON = 'atlas:ai:lesson-dedup-calibration --json';
    public const FIELD_ATLAS_AI_LESSON_HALF_LIFE___JSON = 'atlas:ai:lesson-half-life --json';
    public const FIELD_ATLAS_AI_LESSON_QUALITY___JSON = 'atlas:ai:lesson-quality --json';
    public const FIELD_ATLAS_AI_LESSON_TYPE_YIELD___JSON = 'atlas:ai:lesson-type-yield --json';
    public const FIELD_ATLAS_AI_PROCEDURAL_SKILL_PROMOTER___JSON = 'atlas:ai:procedural-skill-promoter --json';
    public const FIELD_ATLAS_ATLAS_DECIDE_LIVE_FEEDBACK___REGRET___JSON = 'atlas:atlas-decide:live-feedback --regret --json';
    public const FIELD_ATLAS_BRAIN_PREDICTED_IMPACT___JSON = 'atlas:brain:predicted-impact --json';
    public const FIELD_ATLAS_CONTEXT_EXECUTION_COOCCURRENCE___JSON = 'atlas:context:execution-cooccurrence --json';
    public const FIELD_ATLAS_CONTEXT_GOLDEN_COUNTERFACTUAL___JSON = 'atlas:context:golden-counterfactual --json';
    public const FIELD_ATLAS_DECIDE_REPLAY_DIVERGENCE___JSON = 'atlas:decide:replay-divergence --json';
    public const INT_180 = 180;
    public const INT_365 = 365;
    public const INT_60 = 60;
    public const INT_30 = 30;
    public const INT_90 = 90;

    public const SOURCE_TYPE_JSONL = 'jsonl';

    public const SOURCE_TYPE_JSONL_DIR = 'jsonl_dir';

    public const SOURCE_TYPE_TABLE = 'table';

    public const SOURCE_TYPE_COMMAND = 'command';

    public const SOURCE_TYPE_COMPUTED_READER_FIELD = 'computed_reader_field';

    /** @var list<array<string,mixed>> */
    private array $entries;

    /**
     * @param  list<array<string,mixed>>|null  $entries
     */
    public function __construct(?array $entries = null)
    {
        $this->entries = array_values($entries ?? self::defaultEntries());
    }

    /**
     * @return list<array{
     *     slice:string,
     *     series:string,
     *     path?:string,
     *     table?:string,
     *     ttl_days:int,
     *     timestamp_field?:string,
     *     source_type?:string,
     *     ttl_source?:string
     * }>
     */
    public function entries(): array
    {
        return array_values(array_map(function (array $entry): array {
            $entry[self::FIELD_SLICE] = AiValueNormalizer::trimmedStringOrNull($entry[self::FIELD_SLICE] ?? null) ?? '';
            $entry[self::FIELD_SERIES] = AiValueNormalizer::trimmedStringOrNull($entry[self::FIELD_SERIES] ?? null) ?? '';
            $entry[self::FIELD_TTL_DAYS] = max(1, (int) (AiValueNormalizer::finiteFloatOrNull($entry[self::FIELD_TTL_DAYS] ?? null) ?? 1));

            return $entry;
        }, $this->entries));
    }

    /** @return list<string> */
    public function seriesIds(): array
    {
        return $this->uniqueColumn(self::FIELD_SERIES);
    }

    /** @return list<string> */
    public function sliceIds(): array
    {
        return $this->uniqueColumn(self::FIELD_SLICE);
    }

    /**
     * @return list<array<string,mixed>>
     */
    public static function defaultEntries(): array
    {
        return [
            [
                self::FIELD_SLICE => self::FIELD_MAXG_01,
                self::FIELD_SERIES => self::FIELD_AOBG_LATENCY_LEDGER_V1,
                self::FIELD_PATH => storage_path(AtlasAobgLatencyLedger::DEFAULT_RELATIVE_DIR),
                self::FIELD_SOURCE_TYPE => self::SOURCE_TYPE_JSONL_DIR,
                self::FIELD_TIMESTAMP_FIELD => self::FIELD_TS,
                self::FIELD_TTL_DAYS => (int) AtlasAcosFreezeCommand::defaultFreezePayload()[self::FIELD_TTL_DAYS],
                self::FIELD_TTL_SOURCE => self::FIELD_FREEZE_AOBG_LATENCY_LEDGER_V1,
            ],
            [
                self::FIELD_SLICE => self::FIELD_ELEV_02,
                self::FIELD_SERIES => AtlasAcosMSeriesCommand::MEASURE_ID,
                self::FIELD_PATH => storage_path(AtlasAcosMSeriesCommand::DEFAULT_SERIES_RELATIVE_PATH),
                self::FIELD_SOURCE_TYPE => self::SOURCE_TYPE_JSONL,
                self::FIELD_TIMESTAMP_FIELD => self::FIELD_RECORDED_AT,
                self::FIELD_TTL_DAYS => (int) AtlasAcosFreezeCommand::asiMetricMFreezePayload()[self::FIELD_TTL_DAYS],
                self::FIELD_TTL_SOURCE => self::FIELD_FREEZE_ASI_METRIC_M_V1,
            ],
            [
                self::FIELD_SLICE => self::FIELD_ELEV_12,
                self::FIELD_SERIES => AcosMaxVerifiedShareService::MEASURE_ID,
                self::FIELD_PATH => storage_path(self::FIELD_ATLAS_ATLAS_DECIDE_LIVE_OUTCOMES_JSONL),
                self::FIELD_SOURCE_TYPE => self::SOURCE_TYPE_JSONL,
                self::FIELD_TIMESTAMP_FIELD => self::FIELD_RECORDED_AT,
                self::FIELD_TTL_DAYS => (int) AcosMaxVerifiedShareService::freezePayload()[self::FIELD_TTL_DAYS],
                self::FIELD_TTL_SOURCE => self::FIELD_FREEZE_ACOS_VERIFIED_SHARE_V1,
            ],
            [
                self::FIELD_SLICE => self::FIELD_ASI_05,
                self::FIELD_SERIES => self::FIELD_ACOS_ASI05_LEDGER_CLEANUP_V1,
                self::FIELD_PATH => storage_path(self::FIELD_APP_ATLAS_EVIDENCE_ACOS_MAX_ASI_05_LEDGER_CLEANUP_JSONL),
                self::FIELD_SOURCE_TYPE => self::SOURCE_TYPE_JSONL,
                self::FIELD_TIMESTAMP_FIELD => self::FIELD_RECORDED_AT,
                self::FIELD_TTL_DAYS => self::INT_365,
                self::FIELD_TTL_SOURCE => self::FIELD_ONE_TIME_CLEANUP_RECEIPT,
            ],
            [
                self::FIELD_SLICE => self::FIELD_ESP_00,
                self::FIELD_SERIES => self::FIELD_ACOS_ESP00_GROUND_TRUTH_V1,
                self::FIELD_PATH => storage_path(self::FIELD_APP_ATLAS_EVIDENCE_ACOS_MAX_ESP_00_GROUND_TRUTH_JSONL),
                self::FIELD_SOURCE_TYPE => self::SOURCE_TYPE_JSONL,
                self::FIELD_TIMESTAMP_FIELD => self::FIELD_RECORDED_AT,
                self::FIELD_TTL_DAYS => self::INT_365,
                self::FIELD_TTL_SOURCE => self::FIELD_GROUND_TRUTH_RECEIPT,
            ],
            [
                self::FIELD_SLICE => self::FIELD_MAXL_02,
                self::FIELD_SERIES => self::FIELD_ATLAS_EVIDENCE_LEDGER_HASH_CHAIN_V1,
                self::FIELD_TABLE => self::FIELD_ATLAS_LEDGER_EVENTS,
                self::FIELD_SOURCE_TYPE => self::SOURCE_TYPE_TABLE,
                self::FIELD_TIMESTAMP_FIELD => self::FIELD_OCCURRED_AT,
                self::FIELD_TTL_DAYS => self::INT_30,
                self::FIELD_TTL_SOURCE => self::FIELD_MAXL_02_FREEZE_EQUIVALENT,
            ],
            [
                self::FIELD_SLICE => self::FIELD_MAXH_01,
                self::FIELD_SERIES => AtlasMemoryTemporalQualityService::MEASURE_ID,
                self::FIELD_PATH => 'atlas:memory:temporal-quality --json',
                self::FIELD_SOURCE_TYPE => self::SOURCE_TYPE_COMMAND,
                self::FIELD_TIMESTAMP_FIELD => self::FIELD_GENERATED_AT,
                self::FIELD_TTL_DAYS => (int) AtlasMemoryTemporalQualityService::freezePayload()[self::FIELD_TTL_DAYS],
                self::FIELD_TTL_SOURCE => self::FIELD_FREEZE_ATLAS_MEMORY_TEMPORAL_TRUTH_V2,
            ],
            [
                self::FIELD_SLICE => self::FIELD_MAXI_02,
                self::FIELD_SERIES => self::FIELD_ATLAS_CAPTURE_COGNITIVE_IMMUNE_AUDIT_V2,
                self::FIELD_TABLE => self::FIELD_CAPTURES,
                self::FIELD_SOURCE_TYPE => self::SOURCE_TYPE_TABLE,
                self::FIELD_TIMESTAMP_FIELD => self::FIELD_CREATED_AT,
                self::FIELD_TTL_DAYS => ImmuneCalibrationService::TTL_DAYS,
                self::FIELD_TTL_SOURCE => self::FIELD_MAXI_02_SHADOW_AUDIT_V2,
            ],
            [
                self::FIELD_SLICE => self::FIELD_MAXI_03,
                self::FIELD_SERIES => ImmuneCalibrationService::MEASURE_ID,
                self::FIELD_TABLE => ImmuneVerdictLedger::TABLE,
                self::FIELD_SOURCE_TYPE => self::SOURCE_TYPE_TABLE,
                self::FIELD_TIMESTAMP_FIELD => self::FIELD_DECIDED_AT,
                self::FIELD_TTL_DAYS => ImmuneCalibrationService::TTL_DAYS,
                self::FIELD_TTL_SOURCE => self::FIELD_FREEZE_ATLAS_IMMUNE_CALIBRATION_V1,
            ],
            [
                self::FIELD_SLICE => self::FIELD_ELEV_20S,
                self::FIELD_SERIES => self::FIELD_ACOS_DEAD_SERIES_WATCHDOG_V1,
                self::FIELD_TABLE => self::FIELD_ATLAS_LEDGER_EVENTS,
                self::FIELD_SOURCE_TYPE => self::SOURCE_TYPE_TABLE,
                self::FIELD_TIMESTAMP_FIELD => self::FIELD_OCCURRED_AT,
                self::FIELD_WHERE => [
                    self::FIELD_SCOPE_TYPE => self::FIELD_ACOS_WATCHDOG,
                    self::FIELD_SCOPE_ID => self::FIELD_UNIFIED,
                ],
                self::FIELD_TTL_DAYS => self::INT_30,
                self::FIELD_TTL_SOURCE => self::FIELD_ELEV_20S_FREEZE_EQUIVALENT,
            ],
            [
                self::FIELD_SLICE => self::FIELD_ELEV_25,
                self::FIELD_SERIES => AtlasOperatorReviewDebtMeter::MEASURE_ID,
                self::FIELD_TABLE => self::FIELD_ATLAS_LEDGER_EVENTS,
                self::FIELD_SOURCE_TYPE => self::SOURCE_TYPE_TABLE,
                self::FIELD_TIMESTAMP_FIELD => self::FIELD_OCCURRED_AT,
                self::FIELD_WHERE => [
                    self::FIELD_SCOPE_TYPE => self::FIELD_ACOS_WATCHDOG,
                    self::FIELD_SCOPE_ID => self::FIELD_UNIFIED,
                ],
                self::FIELD_TTL_DAYS => AtlasOperatorReviewDebtMeter::TTL_DAYS,
                self::FIELD_TTL_SOURCE => self::FIELD_FREEZE_ACOS_OPERATOR_REVIEW_DEBT_V1,
            ],
            [
                self::FIELD_SLICE => self::FIELD_MAXJ_01,
                self::FIELD_SERIES => AtlasLessonQualityService::MEASURE_ID,
                self::FIELD_PATH => self::FIELD_ATLAS_AI_LESSON_QUALITY___JSON,
                self::FIELD_SOURCE_TYPE => self::SOURCE_TYPE_COMMAND,
                self::FIELD_TIMESTAMP_FIELD => self::FIELD_GENERATED_AT,
                self::FIELD_TTL_DAYS => (int) AtlasAcosFreezeCommand::lessonQualityFreezePayload()[self::FIELD_TTL_DAYS],
                self::FIELD_TTL_SOURCE => self::FIELD_FREEZE_ATLAS_AI_LESSON_QUALITY_V2,
            ],
            [
                self::FIELD_SLICE => self::FIELD_MAXJ_05,
                self::FIELD_SERIES => AtlasLearningRecallUseLiftService::LESSON_TYPE_YIELD_MEASURE_ID,
                self::FIELD_PATH => self::FIELD_ATLAS_AI_LESSON_TYPE_YIELD___JSON,
                self::FIELD_SOURCE_TYPE => self::SOURCE_TYPE_COMMAND,
                self::FIELD_TIMESTAMP_FIELD => self::FIELD_GENERATED_AT,
                self::FIELD_TTL_DAYS => (int) AtlasAcosFreezeCommand::lessonTypeYieldFreezePayload()[self::FIELD_TTL_DAYS],
                self::FIELD_TTL_SOURCE => self::FIELD_FREEZE_ATLAS_AI_LESSON_TYPE_YIELD_V2,
            ],
            [
                self::FIELD_SLICE => self::FIELD_MAXK_01,
                self::FIELD_SERIES => AtlasDecideRouteRegretService::MEASURE_ID,
                self::FIELD_PATH => self::FIELD_ATLAS_ATLAS_DECIDE_LIVE_FEEDBACK___REGRET___JSON,
                self::FIELD_SOURCE_TYPE => self::SOURCE_TYPE_COMMAND,
                self::FIELD_TIMESTAMP_FIELD => self::FIELD_GENERATED_AT,
                self::FIELD_TTL_DAYS => AtlasDecideRouteRegretService::TTL_DAYS,
                self::FIELD_TTL_SOURCE => self::FIELD_FREEZE_ATLAS_DECIDE_ROUTE_REGRET_V2,
            ],
            [
                self::FIELD_SLICE => self::FIELD_MULTK_01,
                self::FIELD_SERIES => AtlasDecideCostOutcomeRouter::MULTK01_MEASURE_ID,
                self::FIELD_PATH => 'AtlasDecideCostOutcomeRouter::costOutcomeCandidates',
                self::FIELD_SOURCE_TYPE => self::SOURCE_TYPE_COMPUTED_READER_FIELD,
                self::FIELD_TIMESTAMP_FIELD => self::FIELD_GENERATED_AT,
                self::FIELD_TTL_DAYS => (int) AtlasDecideCostOutcomeRouter::multk01FreezePayload()[self::FIELD_TTL_DAYS],
                self::FIELD_TTL_SOURCE => self::FIELD_FREEZE_ATLAS_DECIDE_COST_OUTCOME_UNCERTAINTY_V1,
            ],
            [
                self::FIELD_SLICE => self::FIELD_MULTK_02,
                self::FIELD_SERIES => AtlasDecideCostOutcomeRouter::MULTK02_MEASURE_ID,
                self::FIELD_PATH => 'AtlasDecideCostOutcomeRouter::costOutcomeRoute.cascade',
                self::FIELD_SOURCE_TYPE => self::SOURCE_TYPE_COMPUTED_READER_FIELD,
                self::FIELD_TIMESTAMP_FIELD => self::FIELD_GENERATED_AT,
                self::FIELD_TTL_DAYS => self::INT_30,
                self::FIELD_TTL_SOURCE => self::FIELD_FREEZE_ATLAS_DECIDE_CASCADE_COST_ROUTER_V1,
            ],
            [
                self::FIELD_SLICE => self::FIELD_MULTK_03,
                self::FIELD_SERIES => AtlasDecideReplayDivergenceService::MEASURE_ID,
                self::FIELD_PATH => self::FIELD_ATLAS_DECIDE_REPLAY_DIVERGENCE___JSON,
                self::FIELD_SOURCE_TYPE => self::SOURCE_TYPE_COMMAND,
                self::FIELD_TIMESTAMP_FIELD => self::FIELD_GENERATED_AT,
                self::FIELD_TTL_DAYS => self::INT_30,
                self::FIELD_TTL_SOURCE => self::FIELD_FREEZE_ATLAS_DECIDE_REPLAY_DIVERGENCE_V1,
            ],
            [
                self::FIELD_SLICE => self::FIELD_ESP_05,
                self::FIELD_SERIES => AtlasDecideLiveOutcomeFeedbackService::ZERO_WEIGHT_MEASURE_ID,
                self::FIELD_PATH => 'AtlasDecideLiveOutcomeFeedbackService::routeStats.providers.*.zero_weight_success_rate',
                self::FIELD_SOURCE_TYPE => self::SOURCE_TYPE_COMPUTED_READER_FIELD,
                self::FIELD_TIMESTAMP_FIELD => self::FIELD_GENERATED_AT,
                self::FIELD_TTL_DAYS => (int) AtlasDecideLiveOutcomeFeedbackService::zeroWeightFreezePayload()[self::FIELD_TTL_DAYS],
                self::FIELD_TTL_SOURCE => self::FIELD_FREEZE_ATLAS_DECIDE_ZERO_WEIGHT_OUTCOMES_V1,
            ],
            [
                self::FIELD_SLICE => self::FIELD_ESP_06,
                self::FIELD_SERIES => OutcomeEnvelopeBridge::MEASURE_ID,
                self::FIELD_PATH => 'OutcomeEnvelopeBridge::producerConsumerMeta',
                self::FIELD_SOURCE_TYPE => self::SOURCE_TYPE_COMPUTED_READER_FIELD,
                self::FIELD_TIMESTAMP_FIELD => self::FIELD_GENERATED_AT,
                self::FIELD_TTL_DAYS => (int) OutcomeEnvelopeBridge::freezePayload()[self::FIELD_TTL_DAYS],
                self::FIELD_TTL_SOURCE => self::FIELD_FREEZE_ATLAS_ESP_06_OUTCOME_ENVELOPE_V1,
            ],
            [
                self::FIELD_SLICE => self::FIELD_ESP_09,
                self::FIELD_SERIES => Esp09IndependentChallengerService::MEASURE_ID,
                self::FIELD_PATH => 'Esp09IndependentChallengerService::refutationSeries',
                self::FIELD_SOURCE_TYPE => self::SOURCE_TYPE_COMPUTED_READER_FIELD,
                self::FIELD_TIMESTAMP_FIELD => self::FIELD_GENERATED_AT,
                self::FIELD_TTL_DAYS => (int) Esp09IndependentChallengerService::freezePayload()[self::FIELD_TTL_DAYS],
                self::FIELD_TTL_SOURCE => self::FIELD_FREEZE_ATLAS_ESP_09_CHALLENGER_ADVISORY_V1,
            ],
            [
                self::FIELD_SLICE => self::FIELD_MULTN15_02,
                self::FIELD_SERIES => OperatorApprovalHistoryMeter::MEASURE_ID,
                self::FIELD_PATH => 'atlas:operator-approval-history --json',
                self::FIELD_SOURCE_TYPE => self::SOURCE_TYPE_COMMAND,
                self::FIELD_TIMESTAMP_FIELD => self::FIELD_GENERATED_AT,
                self::FIELD_TTL_DAYS => OperatorApprovalHistoryMeter::TTL_DAYS,
                self::FIELD_TTL_SOURCE => self::FIELD_FREEZE_OPERATOR_APPROVAL_HISTORY_V1,
            ],
            [
                self::FIELD_SLICE => self::FIELD_MAXL_06,
                self::FIELD_SERIES => AcosMaxLote2MeasureService::MAXL06_MEASURE_ID,
                self::FIELD_PATH => self::FIELD_ATLAS_ACOS_DELTA_ATTRIBUTION___JSON,
                self::FIELD_SOURCE_TYPE => self::SOURCE_TYPE_COMMAND,
                self::FIELD_TIMESTAMP_FIELD => self::FIELD_GENERATED_AT,
                self::FIELD_TTL_DAYS => (int) AcosMaxLote2MeasureService::freezePayload(self::FIELD_MAXL_06)[self::FIELD_TTL_DAYS],
                self::FIELD_TTL_SOURCE => self::FIELD_FREEZE_ATLAS_EVIDENCE_DELTA_ATTRIBUTION_V1,
            ],
            [
                self::FIELD_SLICE => self::FIELD_MAXL_07,
                self::FIELD_SERIES => GoldenCounterfactualReplayService::MEASURE_ID,
                self::FIELD_PATH => self::FIELD_ATLAS_CONTEXT_GOLDEN_COUNTERFACTUAL___JSON,
                self::FIELD_SOURCE_TYPE => self::SOURCE_TYPE_COMMAND,
                self::FIELD_TIMESTAMP_FIELD => self::FIELD_GENERATED_AT,
                self::FIELD_TTL_DAYS => self::INT_90,
                self::FIELD_TTL_SOURCE => self::FIELD_FREEZE_ATLAS_CONTEXT_GOLDEN_COUNTERFACTUAL_V1,
            ],
            [
                self::FIELD_SLICE => self::FIELD_MAXL_08,
                self::FIELD_SERIES => ExecutionContextCooccurrenceService::MEASURE_ID,
                self::FIELD_PATH => self::FIELD_ATLAS_CONTEXT_EXECUTION_COOCCURRENCE___JSON,
                self::FIELD_SOURCE_TYPE => self::SOURCE_TYPE_COMMAND,
                self::FIELD_TIMESTAMP_FIELD => self::FIELD_GENERATED_AT,
                self::FIELD_TTL_DAYS => self::INT_90,
                self::FIELD_TTL_SOURCE => self::FIELD_FREEZE_ATLAS_CONTEXT_EXECUTION_COOCCURRENCE_V1,
            ],
            [
                self::FIELD_SLICE => self::FIELD_MULTN17_04,
                self::FIELD_SERIES => AcosMaxLote2MeasureService::MULTN1704_MEASURE_ID,
                self::FIELD_PATH => self::FIELD_ATLAS_BRAIN_PREDICTED_IMPACT___JSON,
                self::FIELD_SOURCE_TYPE => self::SOURCE_TYPE_COMMAND,
                self::FIELD_TIMESTAMP_FIELD => self::FIELD_GENERATED_AT,
                self::FIELD_TTL_DAYS => (int) AcosMaxLote2MeasureService::freezePayload(self::FIELD_MULTN17_04)[self::FIELD_TTL_DAYS],
                self::FIELD_TTL_SOURCE => self::FIELD_FREEZE_ATLAS_ORIGINATOR_PREDICTED_IMPACT_CALIBRATION_V1,
            ],
            [
                self::FIELD_SLICE => self::FIELD_MULTX_01,
                self::FIELD_SERIES => AcosMaxLote2MeasureService::MULTX01_MEASURE_ID,
                self::FIELD_PATH => 'atlas:flywheel:loops --json',
                self::FIELD_SOURCE_TYPE => self::SOURCE_TYPE_COMMAND,
                self::FIELD_TIMESTAMP_FIELD => self::FIELD_GENERATED_AT,
                self::FIELD_TTL_DAYS => (int) AcosMaxLote2MeasureService::freezePayload(self::FIELD_MULTX_01)[self::FIELD_TTL_DAYS],
                self::FIELD_TTL_SOURCE => self::FIELD_FREEZE_ACOS_FLYWHEEL_LOOPS_V1,
            ],
            [
                self::FIELD_SLICE => self::FIELD_MULTX_02,
                self::FIELD_SERIES => AtlasFlywheelFunnelService::MEASURE_ID,
                self::FIELD_PATH => 'atlas:flywheel:funnel --json',
                self::FIELD_SOURCE_TYPE => self::SOURCE_TYPE_COMMAND,
                self::FIELD_TIMESTAMP_FIELD => self::FIELD_GENERATED_AT,
                self::FIELD_TTL_DAYS => self::INT_30,
                self::FIELD_TTL_SOURCE => self::FIELD_FREEZE_ATLAS_M_FUNNEL_V1,
            ],
            [
                self::FIELD_SLICE => self::FIELD_MULTX_06,
                self::FIELD_SERIES => AcosMaxLote2MeasureService::MULTX06_MEASURE_ID,
                self::FIELD_PATH => 'atlas:flywheel:learning-latency --json',
                self::FIELD_SOURCE_TYPE => self::SOURCE_TYPE_COMMAND,
                self::FIELD_TIMESTAMP_FIELD => self::FIELD_GENERATED_AT,
                self::FIELD_TTL_DAYS => (int) AcosMaxLote2MeasureService::freezePayload(self::FIELD_MULTX_06)[self::FIELD_TTL_DAYS],
                self::FIELD_TTL_SOURCE => self::FIELD_FREEZE_ACOS_LEARNING_LATENCY_V1,
            ],
            [
                self::FIELD_SLICE => self::FIELD_MULTX_09,
                self::FIELD_SERIES => AcosMaxLote2MeasureService::MULTX09_MEASURE_ID,
                self::FIELD_PATH => 'atlas:windows --json',
                self::FIELD_SOURCE_TYPE => self::SOURCE_TYPE_COMMAND,
                self::FIELD_TIMESTAMP_FIELD => self::FIELD_GENERATED_AT,
                self::FIELD_TTL_DAYS => (int) AcosMaxLote2MeasureService::freezePayload(self::FIELD_MULTX_09)[self::FIELD_TTL_DAYS],
                self::FIELD_TTL_SOURCE => self::FIELD_FREEZE_ACOS_WINDOWS_ORCHESTRATOR_V1,
            ],
            [
                self::FIELD_SLICE => self::FIELD_MULTJ_01,
                self::FIELD_SERIES => AcosMaxLote2MeasureService::MULTJ01_MEASURE_ID,
                self::FIELD_PATH => self::FIELD_ATLAS_AI_LESSON_HALF_LIFE___JSON,
                self::FIELD_SOURCE_TYPE => self::SOURCE_TYPE_COMMAND,
                self::FIELD_TIMESTAMP_FIELD => self::FIELD_GENERATED_AT,
                self::FIELD_TTL_DAYS => (int) AcosMaxLote2MeasureService::freezePayload(self::FIELD_MULTJ_01)[self::FIELD_TTL_DAYS],
                self::FIELD_TTL_SOURCE => self::FIELD_FREEZE_ATLAS_AI_LESSON_HALF_LIFE_V2,
            ],
            [
                self::FIELD_SLICE => self::FIELD_MULTJ_02,
                self::FIELD_SERIES => AcosMaxLote2MeasureService::MULTJ02_MEASURE_ID,
                self::FIELD_PATH => self::FIELD_ATLAS_AI_LESSON_DEDUP_CALIBRATION___JSON,
                self::FIELD_SOURCE_TYPE => self::SOURCE_TYPE_COMMAND,
                self::FIELD_TIMESTAMP_FIELD => self::FIELD_GENERATED_AT,
                self::FIELD_TTL_DAYS => (int) AcosMaxLote2MeasureService::freezePayload(self::FIELD_MULTJ_02)[self::FIELD_TTL_DAYS],
                self::FIELD_TTL_SOURCE => self::FIELD_FREEZE_ATLAS_AI_LESSON_SEMANTIC_DEDUP_V1,
            ],
            [
                self::FIELD_SLICE => self::FIELD_MULTJ_03,
                self::FIELD_SERIES => AcosMaxLote2MeasureService::MULTJ03_MEASURE_ID,
                self::FIELD_PATH => self::FIELD_ATLAS_AI_COUNTERFACTUAL_LIFT___JSON,
                self::FIELD_SOURCE_TYPE => self::SOURCE_TYPE_COMMAND,
                self::FIELD_TIMESTAMP_FIELD => self::FIELD_GENERATED_AT,
                self::FIELD_TTL_DAYS => (int) AcosMaxLote2MeasureService::freezePayload(self::FIELD_MULTJ_03)[self::FIELD_TTL_DAYS],
                self::FIELD_TTL_SOURCE => self::FIELD_FREEZE_ATLAS_AI_COUNTERFACTUAL_LIFT_V2,
            ],
            [
                self::FIELD_SLICE => self::FIELD_MULTJ_04,
                self::FIELD_SERIES => AcosMaxLote2MeasureService::MULTJ04_MEASURE_ID,
                self::FIELD_PATH => self::FIELD_ATLAS_AI_PROCEDURAL_SKILL_PROMOTER___JSON,
                self::FIELD_SOURCE_TYPE => self::SOURCE_TYPE_COMMAND,
                self::FIELD_TIMESTAMP_FIELD => self::FIELD_GENERATED_AT,
                self::FIELD_TTL_DAYS => (int) AcosMaxLote2MeasureService::freezePayload(self::FIELD_MULTJ_04)[self::FIELD_TTL_DAYS],
                self::FIELD_TTL_SOURCE => self::FIELD_FREEZE_ATLAS_AI_PROCEDURAL_SKILL_PROMOTER_V1,
            ],
            [
                self::FIELD_SLICE => self::FIELD_MULTJ_06,
                self::FIELD_SERIES => AcosMaxLote2MeasureService::MULTJ06_MEASURE_ID,
                self::FIELD_PATH => self::FIELD_ATLAS_AI_ABSTRACTION_LADDER___JSON,
                self::FIELD_SOURCE_TYPE => self::SOURCE_TYPE_COMMAND,
                self::FIELD_TIMESTAMP_FIELD => self::FIELD_GENERATED_AT,
                self::FIELD_TTL_DAYS => (int) AcosMaxLote2MeasureService::freezePayload(self::FIELD_MULTJ_06)[self::FIELD_TTL_DAYS],
                self::FIELD_TTL_SOURCE => self::FIELD_FREEZE_ATLAS_AI_ABSTRACTION_LADDER_V1,
            ],
            [
                self::FIELD_SLICE => self::FIELD_TETO_02,
                self::FIELD_SERIES => AcosMaxLote2MeasureService::TETO02_MEASURE_ID,
                self::FIELD_PATH => 'atlas:mission:e2e --json',
                self::FIELD_SOURCE_TYPE => self::SOURCE_TYPE_COMMAND,
                self::FIELD_TIMESTAMP_FIELD => self::FIELD_GENERATED_AT,
                self::FIELD_TTL_DAYS => (int) AcosMaxLote2MeasureService::freezePayload(self::FIELD_TETO_02)[self::FIELD_TTL_DAYS],
                self::FIELD_TTL_SOURCE => self::FIELD_FREEZE_MISSION_E2E_V1,
            ],
            [
                self::FIELD_SLICE => self::FIELD_ELEV_27,
                self::FIELD_SERIES => self::FIELD_ATLAS_RESOURCE_BUDGET_V1,
                self::FIELD_PATH => 'AtlasResourceBudgetService::report',
                self::FIELD_SOURCE_TYPE => self::SOURCE_TYPE_COMPUTED_READER_FIELD,
                self::FIELD_TIMESTAMP_FIELD => self::FIELD_GENERATED_AT,
                self::FIELD_TTL_DAYS => self::INT_30,
                self::FIELD_TTL_SOURCE => self::FIELD_ELEV_27_RESOURCE_BUDGET,
            ],
            [
                self::FIELD_SLICE => self::FIELD_ESP_03,
                self::FIELD_SERIES => self::FIELD_ATLAS_TEST_ATTESTATION_V1,
                self::FIELD_PATH => 'AtlasTestAttestationService::attest',
                self::FIELD_SOURCE_TYPE => self::SOURCE_TYPE_COMPUTED_READER_FIELD,
                self::FIELD_TIMESTAMP_FIELD => self::FIELD_ATTESTED_AT,
                self::FIELD_TTL_DAYS => self::INT_30,
                self::FIELD_TTL_SOURCE => self::FIELD_ESP_03_TEST_ATTESTATION_SEAL,
            ],
            [
                self::FIELD_SLICE => self::FIELD_MAXM_01,
                self::FIELD_SERIES => self::FIELD_ATLAS_PROVIDER_LEAK_CORPUS_V1,
                self::FIELD_PATH => storage_path(self::FIELD_APP_ATLAS_EVIDENCE_ACOS_MAX_MAXM01_PROVIDER_LEAK_CORPUS_JSONL),
                self::FIELD_SOURCE_TYPE => self::SOURCE_TYPE_JSONL,
                self::FIELD_TIMESTAMP_FIELD => self::FIELD_RECORDED_AT,
                self::FIELD_TTL_DAYS => self::INT_180,
                self::FIELD_TTL_SOURCE => self::FIELD_MAXM_01_FROZEN_CORPUS_BASELINE,
            ],
            [
                self::FIELD_SLICE => self::FIELD_MAXI_04,
                self::FIELD_SERIES => AtlasImmuneClassifierHybridFreeze::MEASURE_ID,
                self::FIELD_PATH => storage_path(self::FIELD_APP_ATLAS_EVIDENCE_ACOS_MAX_MAXI_04_CLASSIFIER_HYBRID_JSONL),
                self::FIELD_SOURCE_TYPE => self::SOURCE_TYPE_JSONL,
                self::FIELD_TIMESTAMP_FIELD => self::FIELD_RECORDED_AT,
                self::FIELD_TTL_DAYS => AtlasImmuneClassifierHybridFreeze::TTL_DAYS,
                self::FIELD_TTL_SOURCE => self::FIELD_FREEZE_ATLAS_IMMUNE_CLASSIFIER_HYBRID_V1,
            ],
            [
                self::FIELD_SLICE => self::FIELD_MAXI_05,
                self::FIELD_SERIES => AtlasImmuneSignatureFreeze::MEASURE_ID,
                self::FIELD_TABLE => ImmuneSignatureStore::TABLE,
                self::FIELD_SOURCE_TYPE => self::SOURCE_TYPE_TABLE,
                self::FIELD_TIMESTAMP_FIELD => self::FIELD_FIRST_SEEN,
                self::FIELD_TTL_DAYS => AtlasImmuneSignatureFreeze::TTL_DAYS,
                self::FIELD_TTL_SOURCE => self::FIELD_FREEZE_ATLAS_IMMUNE_SIGNATURE_STORE_V1,
            ],
            [
                self::FIELD_SLICE => self::FIELD_TETO_01,
                self::FIELD_SERIES => AtlasNCaptureDrillService::MEASURE_ID,
                self::FIELD_PATH => storage_path(AtlasNCaptureDrillService::RELATIVE_LEDGER_PATH),
                self::FIELD_SOURCE_TYPE => self::SOURCE_TYPE_JSONL,
                self::FIELD_TIMESTAMP_FIELD => self::FIELD_RECORDED_AT,
                self::FIELD_TTL_DAYS => self::INT_365,
                self::FIELD_TTL_SOURCE => self::FIELD_FREEZE_ATLAS_N_CAPTURE_DRILL_V1,
            ],
            [
                self::FIELD_SLICE => self::FIELD_MAXA_06,
                self::FIELD_SERIES => AtlasKnowledgeItemEmbeddingCoverageService::MEASURE_ID,
                self::FIELD_PATH => 'AtlasKnowledgeItemEmbeddingCoverageService::report',
                self::FIELD_SOURCE_TYPE => self::SOURCE_TYPE_COMPUTED_READER_FIELD,
                self::FIELD_TIMESTAMP_FIELD => self::FIELD_GENERATED_AT,
                self::FIELD_TTL_DAYS => self::INT_60,
                self::FIELD_TTL_SOURCE => self::FIELD_FREEZE_ATLAS_KB_EMBEDDING_COVERAGE_V1,
            ],
            [
                self::FIELD_SLICE => self::FIELD_MAXA_06,
                self::FIELD_SERIES => AtlasCodeSymbolEmbeddingCoverageService::MEASURE_ID,
                self::FIELD_PATH => 'AtlasCodeSymbolEmbeddingCoverageService::report',
                self::FIELD_SOURCE_TYPE => self::SOURCE_TYPE_COMPUTED_READER_FIELD,
                self::FIELD_TIMESTAMP_FIELD => self::FIELD_GENERATED_AT,
                self::FIELD_TTL_DAYS => self::INT_60,
                self::FIELD_TTL_SOURCE => self::FIELD_FREEZE_ATLAS_CODE_SYMBOL_EMBEDDING_COVERAGE_V1,
            ],
            [
                self::FIELD_SLICE => self::FIELD_MAXD_04,
                self::FIELD_SERIES => AtlasAurgPprShadowDualReadLedger::SCHEMA,
                self::FIELD_PATH => storage_path(AtlasAurgPprShadowDualReadLedger::RELATIVE_PATH),
                self::FIELD_SOURCE_TYPE => self::SOURCE_TYPE_JSONL,
                self::FIELD_TIMESTAMP_FIELD => self::FIELD_RECORDED_AT,
                self::FIELD_TTL_DAYS => self::INT_90,
                self::FIELD_TTL_SOURCE => self::FIELD_MAXD_04_PPR_SHADOW_DUAL_READ_WINDOW,
            ],
            [
                self::FIELD_SLICE => self::FIELD_MAXA_04,
                self::FIELD_SERIES => Maxa04JinaV3DualReadLedger::SCHEMA,
                self::FIELD_PATH => storage_path(Maxa04JinaV3DualReadLedger::RELATIVE_PATH),
                self::FIELD_SOURCE_TYPE => self::SOURCE_TYPE_JSONL,
                self::FIELD_TIMESTAMP_FIELD => self::FIELD_RECORDED_AT,
                self::FIELD_TTL_DAYS => self::INT_90,
                self::FIELD_TTL_SOURCE => self::FIELD_MAXA_04_JINA_V3_DUAL_READ_WINDOW,
            ],
            [
                self::FIELD_SLICE => self::FIELD_RAGX_07,
                self::FIELD_SERIES => RagxChainMechanismService::AB_SCHEMA,
                self::FIELD_PATH => storage_path(self::FIELD_APP_ATLAS_EVIDENCE_RAGX_AB_REGISTRATIONS_JSONL),
                self::FIELD_SOURCE_TYPE => self::SOURCE_TYPE_JSONL,
                self::FIELD_TIMESTAMP_FIELD => self::FIELD_RECORDED_AT,
                self::FIELD_TTL_DAYS => self::INT_90,
                self::FIELD_TTL_SOURCE => self::FIELD_RAGX_07_RECORDS_ONLY_AB_REGISTRATION,
            ],
            [
                self::FIELD_SLICE => self::FIELD_REC_06,
                self::FIELD_SERIES => MetaLoopBreakerService::SCHEMA_VERSION,
                self::FIELD_PATH => self::FIELD_ATLAS_ACOS_REC06_BREAKERS___JSON,
                self::FIELD_SOURCE_TYPE => self::SOURCE_TYPE_COMMAND,
                self::FIELD_TIMESTAMP_FIELD => self::FIELD_GENERATED_AT,
                self::FIELD_TTL_DAYS => self::INT_30,
                self::FIELD_TTL_SOURCE => self::FIELD_REC_06_META_LOOP_BREAKER_READER,
            ],
        ];
    }

    /** @return list<string> */
    private function uniqueColumn(string $column): array
    {
        $values = [];
        foreach ($this->entries() as $entry) {
            $value = AiValueNormalizer::trimmedStringOrNull($entry[$column] ?? null) ?? '';
            if ($value !== '') {
                $values[$value] = true;
            }
        }

        return array_keys($values);
    }
}
