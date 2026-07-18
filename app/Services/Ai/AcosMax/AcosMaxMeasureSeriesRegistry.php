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
                self::FIELD_SLICE => 'MAXG-01',
                self::FIELD_SERIES => 'aobg.latency_ledger.v1',
                self::FIELD_PATH => storage_path(AtlasAobgLatencyLedger::DEFAULT_RELATIVE_DIR),
                self::FIELD_SOURCE_TYPE => self::SOURCE_TYPE_JSONL_DIR,
                self::FIELD_TIMESTAMP_FIELD => 'ts',
                self::FIELD_TTL_DAYS => (int) AtlasAcosFreezeCommand::defaultFreezePayload()[self::FIELD_TTL_DAYS],
                self::FIELD_TTL_SOURCE => 'freeze:aobg.latency_ledger.v1',
            ],
            [
                self::FIELD_SLICE => 'ELEV-02',
                self::FIELD_SERIES => AtlasAcosMSeriesCommand::MEASURE_ID,
                self::FIELD_PATH => storage_path(AtlasAcosMSeriesCommand::DEFAULT_SERIES_RELATIVE_PATH),
                self::FIELD_SOURCE_TYPE => self::SOURCE_TYPE_JSONL,
                self::FIELD_TIMESTAMP_FIELD => self::FIELD_RECORDED_AT,
                self::FIELD_TTL_DAYS => (int) AtlasAcosFreezeCommand::asiMetricMFreezePayload()[self::FIELD_TTL_DAYS],
                self::FIELD_TTL_SOURCE => 'freeze:asi.metric.m.v1',
            ],
            [
                self::FIELD_SLICE => 'ELEV-12',
                self::FIELD_SERIES => AcosMaxVerifiedShareService::MEASURE_ID,
                self::FIELD_PATH => storage_path('atlas/atlas_decide/live_outcomes.jsonl'),
                self::FIELD_SOURCE_TYPE => self::SOURCE_TYPE_JSONL,
                self::FIELD_TIMESTAMP_FIELD => self::FIELD_RECORDED_AT,
                self::FIELD_TTL_DAYS => (int) AcosMaxVerifiedShareService::freezePayload()[self::FIELD_TTL_DAYS],
                self::FIELD_TTL_SOURCE => 'freeze:acos.verified_share.v1',
            ],
            [
                self::FIELD_SLICE => 'ASI-05',
                self::FIELD_SERIES => 'acos.asi05.ledger_cleanup.v1',
                self::FIELD_PATH => storage_path('app/atlas/evidence/acos-max-asi-05-ledger-cleanup.jsonl'),
                self::FIELD_SOURCE_TYPE => self::SOURCE_TYPE_JSONL,
                self::FIELD_TIMESTAMP_FIELD => self::FIELD_RECORDED_AT,
                self::FIELD_TTL_DAYS => 365,
                self::FIELD_TTL_SOURCE => 'one_time_cleanup_receipt',
            ],
            [
                self::FIELD_SLICE => 'ESP-00',
                self::FIELD_SERIES => 'acos.esp00.ground_truth.v1',
                self::FIELD_PATH => storage_path('app/atlas/evidence/acos-max-esp-00-ground-truth.jsonl'),
                self::FIELD_SOURCE_TYPE => self::SOURCE_TYPE_JSONL,
                self::FIELD_TIMESTAMP_FIELD => self::FIELD_RECORDED_AT,
                self::FIELD_TTL_DAYS => 365,
                self::FIELD_TTL_SOURCE => self::FIELD_GROUND_TRUTH_RECEIPT,
            ],
            [
                self::FIELD_SLICE => 'MAXL-02',
                self::FIELD_SERIES => 'atlas.evidence_ledger.hash_chain.v1',
                self::FIELD_TABLE => self::FIELD_ATLAS_LEDGER_EVENTS,
                self::FIELD_SOURCE_TYPE => self::SOURCE_TYPE_TABLE,
                self::FIELD_TIMESTAMP_FIELD => self::FIELD_OCCURRED_AT,
                self::FIELD_TTL_DAYS => 30,
                self::FIELD_TTL_SOURCE => 'maxl-02-freeze-equivalent',
            ],
            [
                self::FIELD_SLICE => 'MAXH-01',
                self::FIELD_SERIES => AtlasMemoryTemporalQualityService::MEASURE_ID,
                self::FIELD_PATH => 'atlas:memory:temporal-quality --json',
                self::FIELD_SOURCE_TYPE => self::SOURCE_TYPE_COMMAND,
                self::FIELD_TIMESTAMP_FIELD => self::FIELD_GENERATED_AT,
                self::FIELD_TTL_DAYS => (int) AtlasMemoryTemporalQualityService::freezePayload()[self::FIELD_TTL_DAYS],
                self::FIELD_TTL_SOURCE => 'freeze:atlas.memory.temporal_truth.v2',
            ],
            [
                self::FIELD_SLICE => 'MAXI-02',
                self::FIELD_SERIES => 'atlas.capture.cognitive_immune_audit.v2',
                self::FIELD_TABLE => self::FIELD_CAPTURES,
                self::FIELD_SOURCE_TYPE => self::SOURCE_TYPE_TABLE,
                self::FIELD_TIMESTAMP_FIELD => self::FIELD_CREATED_AT,
                self::FIELD_TTL_DAYS => ImmuneCalibrationService::TTL_DAYS,
                self::FIELD_TTL_SOURCE => 'maxi-02-shadow-audit-v2',
            ],
            [
                self::FIELD_SLICE => 'MAXI-03',
                self::FIELD_SERIES => ImmuneCalibrationService::MEASURE_ID,
                self::FIELD_TABLE => ImmuneVerdictLedger::TABLE,
                self::FIELD_SOURCE_TYPE => self::SOURCE_TYPE_TABLE,
                self::FIELD_TIMESTAMP_FIELD => self::FIELD_DECIDED_AT,
                self::FIELD_TTL_DAYS => ImmuneCalibrationService::TTL_DAYS,
                self::FIELD_TTL_SOURCE => 'freeze:atlas.immune.calibration.v1',
            ],
            [
                self::FIELD_SLICE => 'ELEV-20s',
                self::FIELD_SERIES => 'acos.dead_series_watchdog.v1',
                self::FIELD_TABLE => self::FIELD_ATLAS_LEDGER_EVENTS,
                self::FIELD_SOURCE_TYPE => self::SOURCE_TYPE_TABLE,
                self::FIELD_TIMESTAMP_FIELD => self::FIELD_OCCURRED_AT,
                self::FIELD_WHERE => [
                    self::FIELD_SCOPE_TYPE => self::FIELD_ACOS_WATCHDOG,
                    self::FIELD_SCOPE_ID => self::FIELD_UNIFIED,
                ],
                self::FIELD_TTL_DAYS => 30,
                self::FIELD_TTL_SOURCE => 'elev-20s-freeze-equivalent',
            ],
            [
                self::FIELD_SLICE => 'ELEV-25',
                self::FIELD_SERIES => AtlasOperatorReviewDebtMeter::MEASURE_ID,
                self::FIELD_TABLE => self::FIELD_ATLAS_LEDGER_EVENTS,
                self::FIELD_SOURCE_TYPE => self::SOURCE_TYPE_TABLE,
                self::FIELD_TIMESTAMP_FIELD => self::FIELD_OCCURRED_AT,
                self::FIELD_WHERE => [
                    self::FIELD_SCOPE_TYPE => self::FIELD_ACOS_WATCHDOG,
                    self::FIELD_SCOPE_ID => self::FIELD_UNIFIED,
                ],
                self::FIELD_TTL_DAYS => AtlasOperatorReviewDebtMeter::TTL_DAYS,
                self::FIELD_TTL_SOURCE => 'freeze:acos.operator_review_debt.v1',
            ],
            [
                self::FIELD_SLICE => 'MAXJ-01',
                self::FIELD_SERIES => AtlasLessonQualityService::MEASURE_ID,
                self::FIELD_PATH => 'atlas:ai:lesson-quality --json',
                self::FIELD_SOURCE_TYPE => self::SOURCE_TYPE_COMMAND,
                self::FIELD_TIMESTAMP_FIELD => self::FIELD_GENERATED_AT,
                self::FIELD_TTL_DAYS => (int) AtlasAcosFreezeCommand::lessonQualityFreezePayload()[self::FIELD_TTL_DAYS],
                self::FIELD_TTL_SOURCE => 'freeze:atlas.ai.lesson_quality.v2',
            ],
            [
                self::FIELD_SLICE => 'MAXJ-05',
                self::FIELD_SERIES => AtlasLearningRecallUseLiftService::LESSON_TYPE_YIELD_MEASURE_ID,
                self::FIELD_PATH => 'atlas:ai:lesson-type-yield --json',
                self::FIELD_SOURCE_TYPE => self::SOURCE_TYPE_COMMAND,
                self::FIELD_TIMESTAMP_FIELD => self::FIELD_GENERATED_AT,
                self::FIELD_TTL_DAYS => (int) AtlasAcosFreezeCommand::lessonTypeYieldFreezePayload()[self::FIELD_TTL_DAYS],
                self::FIELD_TTL_SOURCE => 'freeze:atlas.ai.lesson_type_yield.v2',
            ],
            [
                self::FIELD_SLICE => 'MAXK-01',
                self::FIELD_SERIES => AtlasDecideRouteRegretService::MEASURE_ID,
                self::FIELD_PATH => 'atlas:atlas-decide:live-feedback --regret --json',
                self::FIELD_SOURCE_TYPE => self::SOURCE_TYPE_COMMAND,
                self::FIELD_TIMESTAMP_FIELD => self::FIELD_GENERATED_AT,
                self::FIELD_TTL_DAYS => AtlasDecideRouteRegretService::TTL_DAYS,
                self::FIELD_TTL_SOURCE => 'freeze:atlas.decide.route_regret.v2',
            ],
            [
                self::FIELD_SLICE => 'MULTK-01',
                self::FIELD_SERIES => AtlasDecideCostOutcomeRouter::MULTK01_MEASURE_ID,
                self::FIELD_PATH => 'AtlasDecideCostOutcomeRouter::costOutcomeCandidates',
                self::FIELD_SOURCE_TYPE => self::SOURCE_TYPE_COMPUTED_READER_FIELD,
                self::FIELD_TIMESTAMP_FIELD => self::FIELD_GENERATED_AT,
                self::FIELD_TTL_DAYS => (int) AtlasDecideCostOutcomeRouter::multk01FreezePayload()[self::FIELD_TTL_DAYS],
                self::FIELD_TTL_SOURCE => 'freeze:atlas.decide.cost_outcome_uncertainty.v1',
            ],
            [
                self::FIELD_SLICE => 'MULTK-02',
                self::FIELD_SERIES => AtlasDecideCostOutcomeRouter::MULTK02_MEASURE_ID,
                self::FIELD_PATH => 'AtlasDecideCostOutcomeRouter::costOutcomeRoute.cascade',
                self::FIELD_SOURCE_TYPE => self::SOURCE_TYPE_COMPUTED_READER_FIELD,
                self::FIELD_TIMESTAMP_FIELD => self::FIELD_GENERATED_AT,
                self::FIELD_TTL_DAYS => 30,
                self::FIELD_TTL_SOURCE => 'freeze:atlas.decide.cascade_cost_router.v1',
            ],
            [
                self::FIELD_SLICE => 'MULTK-03',
                self::FIELD_SERIES => AtlasDecideReplayDivergenceService::MEASURE_ID,
                self::FIELD_PATH => 'atlas:decide:replay-divergence --json',
                self::FIELD_SOURCE_TYPE => self::SOURCE_TYPE_COMMAND,
                self::FIELD_TIMESTAMP_FIELD => self::FIELD_GENERATED_AT,
                self::FIELD_TTL_DAYS => 30,
                self::FIELD_TTL_SOURCE => 'freeze:atlas.decide.replay_divergence.v1',
            ],
            [
                self::FIELD_SLICE => 'ESP-05',
                self::FIELD_SERIES => AtlasDecideLiveOutcomeFeedbackService::ZERO_WEIGHT_MEASURE_ID,
                self::FIELD_PATH => 'AtlasDecideLiveOutcomeFeedbackService::routeStats.providers.*.zero_weight_success_rate',
                self::FIELD_SOURCE_TYPE => self::SOURCE_TYPE_COMPUTED_READER_FIELD,
                self::FIELD_TIMESTAMP_FIELD => self::FIELD_GENERATED_AT,
                self::FIELD_TTL_DAYS => (int) AtlasDecideLiveOutcomeFeedbackService::zeroWeightFreezePayload()[self::FIELD_TTL_DAYS],
                self::FIELD_TTL_SOURCE => 'freeze:atlas.decide.zero_weight_outcomes.v1',
            ],
            [
                self::FIELD_SLICE => 'ESP-06',
                self::FIELD_SERIES => OutcomeEnvelopeBridge::MEASURE_ID,
                self::FIELD_PATH => 'OutcomeEnvelopeBridge::producerConsumerMeta',
                self::FIELD_SOURCE_TYPE => self::SOURCE_TYPE_COMPUTED_READER_FIELD,
                self::FIELD_TIMESTAMP_FIELD => self::FIELD_GENERATED_AT,
                self::FIELD_TTL_DAYS => (int) OutcomeEnvelopeBridge::freezePayload()[self::FIELD_TTL_DAYS],
                self::FIELD_TTL_SOURCE => 'freeze:atlas.esp_06.outcome_envelope.v1',
            ],
            [
                self::FIELD_SLICE => 'ESP-09',
                self::FIELD_SERIES => Esp09IndependentChallengerService::MEASURE_ID,
                self::FIELD_PATH => 'Esp09IndependentChallengerService::refutationSeries',
                self::FIELD_SOURCE_TYPE => self::SOURCE_TYPE_COMPUTED_READER_FIELD,
                self::FIELD_TIMESTAMP_FIELD => self::FIELD_GENERATED_AT,
                self::FIELD_TTL_DAYS => (int) Esp09IndependentChallengerService::freezePayload()[self::FIELD_TTL_DAYS],
                self::FIELD_TTL_SOURCE => 'freeze:atlas.esp_09.challenger_advisory.v1',
            ],
            [
                self::FIELD_SLICE => 'MULTN15-02',
                self::FIELD_SERIES => OperatorApprovalHistoryMeter::MEASURE_ID,
                self::FIELD_PATH => 'atlas:operator-approval-history --json',
                self::FIELD_SOURCE_TYPE => self::SOURCE_TYPE_COMMAND,
                self::FIELD_TIMESTAMP_FIELD => self::FIELD_GENERATED_AT,
                self::FIELD_TTL_DAYS => OperatorApprovalHistoryMeter::TTL_DAYS,
                self::FIELD_TTL_SOURCE => 'freeze:operator.approval_history.v1',
            ],
            [
                self::FIELD_SLICE => 'MAXL-06',
                self::FIELD_SERIES => AcosMaxLote2MeasureService::MAXL06_MEASURE_ID,
                self::FIELD_PATH => 'atlas:acos:delta-attribution --json',
                self::FIELD_SOURCE_TYPE => self::SOURCE_TYPE_COMMAND,
                self::FIELD_TIMESTAMP_FIELD => self::FIELD_GENERATED_AT,
                self::FIELD_TTL_DAYS => (int) AcosMaxLote2MeasureService::freezePayload('MAXL-06')[self::FIELD_TTL_DAYS],
                self::FIELD_TTL_SOURCE => 'freeze:atlas.evidence.delta_attribution.v1',
            ],
            [
                self::FIELD_SLICE => 'MAXL-07',
                self::FIELD_SERIES => GoldenCounterfactualReplayService::MEASURE_ID,
                self::FIELD_PATH => 'atlas:context:golden-counterfactual --json',
                self::FIELD_SOURCE_TYPE => self::SOURCE_TYPE_COMMAND,
                self::FIELD_TIMESTAMP_FIELD => self::FIELD_GENERATED_AT,
                self::FIELD_TTL_DAYS => 90,
                self::FIELD_TTL_SOURCE => 'freeze:atlas.context.golden_counterfactual.v1',
            ],
            [
                self::FIELD_SLICE => 'MAXL-08',
                self::FIELD_SERIES => ExecutionContextCooccurrenceService::MEASURE_ID,
                self::FIELD_PATH => 'atlas:context:execution-cooccurrence --json',
                self::FIELD_SOURCE_TYPE => self::SOURCE_TYPE_COMMAND,
                self::FIELD_TIMESTAMP_FIELD => self::FIELD_GENERATED_AT,
                self::FIELD_TTL_DAYS => 90,
                self::FIELD_TTL_SOURCE => 'freeze:atlas.context.execution_cooccurrence.v1',
            ],
            [
                self::FIELD_SLICE => 'MULTN17-04',
                self::FIELD_SERIES => AcosMaxLote2MeasureService::MULTN1704_MEASURE_ID,
                self::FIELD_PATH => 'atlas:brain:predicted-impact --json',
                self::FIELD_SOURCE_TYPE => self::SOURCE_TYPE_COMMAND,
                self::FIELD_TIMESTAMP_FIELD => self::FIELD_GENERATED_AT,
                self::FIELD_TTL_DAYS => (int) AcosMaxLote2MeasureService::freezePayload('MULTN17-04')[self::FIELD_TTL_DAYS],
                self::FIELD_TTL_SOURCE => 'freeze:atlas.originator.predicted_impact_calibration.v1',
            ],
            [
                self::FIELD_SLICE => 'MULTX-01',
                self::FIELD_SERIES => AcosMaxLote2MeasureService::MULTX01_MEASURE_ID,
                self::FIELD_PATH => 'atlas:flywheel:loops --json',
                self::FIELD_SOURCE_TYPE => self::SOURCE_TYPE_COMMAND,
                self::FIELD_TIMESTAMP_FIELD => self::FIELD_GENERATED_AT,
                self::FIELD_TTL_DAYS => (int) AcosMaxLote2MeasureService::freezePayload('MULTX-01')[self::FIELD_TTL_DAYS],
                self::FIELD_TTL_SOURCE => 'freeze:acos.flywheel.loops.v1',
            ],
            [
                self::FIELD_SLICE => 'MULTX-02',
                self::FIELD_SERIES => AtlasFlywheelFunnelService::MEASURE_ID,
                self::FIELD_PATH => 'atlas:flywheel:funnel --json',
                self::FIELD_SOURCE_TYPE => self::SOURCE_TYPE_COMMAND,
                self::FIELD_TIMESTAMP_FIELD => self::FIELD_GENERATED_AT,
                self::FIELD_TTL_DAYS => 30,
                self::FIELD_TTL_SOURCE => 'freeze:atlas.m.funnel.v1',
            ],
            [
                self::FIELD_SLICE => 'MULTX-06',
                self::FIELD_SERIES => AcosMaxLote2MeasureService::MULTX06_MEASURE_ID,
                self::FIELD_PATH => 'atlas:flywheel:learning-latency --json',
                self::FIELD_SOURCE_TYPE => self::SOURCE_TYPE_COMMAND,
                self::FIELD_TIMESTAMP_FIELD => self::FIELD_GENERATED_AT,
                self::FIELD_TTL_DAYS => (int) AcosMaxLote2MeasureService::freezePayload('MULTX-06')[self::FIELD_TTL_DAYS],
                self::FIELD_TTL_SOURCE => 'freeze:acos.learning_latency.v1',
            ],
            [
                self::FIELD_SLICE => 'MULTX-09',
                self::FIELD_SERIES => AcosMaxLote2MeasureService::MULTX09_MEASURE_ID,
                self::FIELD_PATH => 'atlas:windows --json',
                self::FIELD_SOURCE_TYPE => self::SOURCE_TYPE_COMMAND,
                self::FIELD_TIMESTAMP_FIELD => self::FIELD_GENERATED_AT,
                self::FIELD_TTL_DAYS => (int) AcosMaxLote2MeasureService::freezePayload('MULTX-09')[self::FIELD_TTL_DAYS],
                self::FIELD_TTL_SOURCE => 'freeze:acos.windows_orchestrator.v1',
            ],
            [
                self::FIELD_SLICE => 'MULTJ-01',
                self::FIELD_SERIES => AcosMaxLote2MeasureService::MULTJ01_MEASURE_ID,
                self::FIELD_PATH => 'atlas:ai:lesson-half-life --json',
                self::FIELD_SOURCE_TYPE => self::SOURCE_TYPE_COMMAND,
                self::FIELD_TIMESTAMP_FIELD => self::FIELD_GENERATED_AT,
                self::FIELD_TTL_DAYS => (int) AcosMaxLote2MeasureService::freezePayload('MULTJ-01')[self::FIELD_TTL_DAYS],
                self::FIELD_TTL_SOURCE => 'freeze:atlas.ai.lesson_half_life.v2',
            ],
            [
                self::FIELD_SLICE => 'MULTJ-02',
                self::FIELD_SERIES => AcosMaxLote2MeasureService::MULTJ02_MEASURE_ID,
                self::FIELD_PATH => 'atlas:ai:lesson-dedup-calibration --json',
                self::FIELD_SOURCE_TYPE => self::SOURCE_TYPE_COMMAND,
                self::FIELD_TIMESTAMP_FIELD => self::FIELD_GENERATED_AT,
                self::FIELD_TTL_DAYS => (int) AcosMaxLote2MeasureService::freezePayload('MULTJ-02')[self::FIELD_TTL_DAYS],
                self::FIELD_TTL_SOURCE => 'freeze:atlas.ai.lesson_semantic_dedup.v1',
            ],
            [
                self::FIELD_SLICE => 'MULTJ-03',
                self::FIELD_SERIES => AcosMaxLote2MeasureService::MULTJ03_MEASURE_ID,
                self::FIELD_PATH => 'atlas:ai:counterfactual-lift --json',
                self::FIELD_SOURCE_TYPE => self::SOURCE_TYPE_COMMAND,
                self::FIELD_TIMESTAMP_FIELD => self::FIELD_GENERATED_AT,
                self::FIELD_TTL_DAYS => (int) AcosMaxLote2MeasureService::freezePayload('MULTJ-03')[self::FIELD_TTL_DAYS],
                self::FIELD_TTL_SOURCE => 'freeze:atlas.ai.counterfactual_lift.v2',
            ],
            [
                self::FIELD_SLICE => 'MULTJ-04',
                self::FIELD_SERIES => AcosMaxLote2MeasureService::MULTJ04_MEASURE_ID,
                self::FIELD_PATH => 'atlas:ai:procedural-skill-promoter --json',
                self::FIELD_SOURCE_TYPE => self::SOURCE_TYPE_COMMAND,
                self::FIELD_TIMESTAMP_FIELD => self::FIELD_GENERATED_AT,
                self::FIELD_TTL_DAYS => (int) AcosMaxLote2MeasureService::freezePayload('MULTJ-04')[self::FIELD_TTL_DAYS],
                self::FIELD_TTL_SOURCE => 'freeze:atlas.ai.procedural_skill_promoter.v1',
            ],
            [
                self::FIELD_SLICE => 'MULTJ-06',
                self::FIELD_SERIES => AcosMaxLote2MeasureService::MULTJ06_MEASURE_ID,
                self::FIELD_PATH => 'atlas:ai:abstraction-ladder --json',
                self::FIELD_SOURCE_TYPE => self::SOURCE_TYPE_COMMAND,
                self::FIELD_TIMESTAMP_FIELD => self::FIELD_GENERATED_AT,
                self::FIELD_TTL_DAYS => (int) AcosMaxLote2MeasureService::freezePayload('MULTJ-06')[self::FIELD_TTL_DAYS],
                self::FIELD_TTL_SOURCE => 'freeze:atlas.ai.abstraction_ladder.v1',
            ],
            [
                self::FIELD_SLICE => 'TETO-02',
                self::FIELD_SERIES => AcosMaxLote2MeasureService::TETO02_MEASURE_ID,
                self::FIELD_PATH => 'atlas:mission:e2e --json',
                self::FIELD_SOURCE_TYPE => self::SOURCE_TYPE_COMMAND,
                self::FIELD_TIMESTAMP_FIELD => self::FIELD_GENERATED_AT,
                self::FIELD_TTL_DAYS => (int) AcosMaxLote2MeasureService::freezePayload('TETO-02')[self::FIELD_TTL_DAYS],
                self::FIELD_TTL_SOURCE => 'freeze:mission_e2e.v1',
            ],
            [
                self::FIELD_SLICE => 'ELEV-27',
                self::FIELD_SERIES => 'atlas.resource_budget.v1',
                self::FIELD_PATH => 'AtlasResourceBudgetService::report',
                self::FIELD_SOURCE_TYPE => self::SOURCE_TYPE_COMPUTED_READER_FIELD,
                self::FIELD_TIMESTAMP_FIELD => self::FIELD_GENERATED_AT,
                self::FIELD_TTL_DAYS => 30,
                self::FIELD_TTL_SOURCE => 'elev-27-resource-budget',
            ],
            [
                self::FIELD_SLICE => 'ESP-03',
                self::FIELD_SERIES => 'atlas.test_attestation.v1',
                self::FIELD_PATH => 'AtlasTestAttestationService::attest',
                self::FIELD_SOURCE_TYPE => self::SOURCE_TYPE_COMPUTED_READER_FIELD,
                self::FIELD_TIMESTAMP_FIELD => self::FIELD_ATTESTED_AT,
                self::FIELD_TTL_DAYS => 30,
                self::FIELD_TTL_SOURCE => 'esp-03-test-attestation-seal',
            ],
            [
                self::FIELD_SLICE => 'MAXM-01',
                self::FIELD_SERIES => 'atlas.provider_leak_corpus.v1',
                self::FIELD_PATH => storage_path('app/atlas/evidence/acos-max-maxm01-provider-leak-corpus.jsonl'),
                self::FIELD_SOURCE_TYPE => self::SOURCE_TYPE_JSONL,
                self::FIELD_TIMESTAMP_FIELD => self::FIELD_RECORDED_AT,
                self::FIELD_TTL_DAYS => 180,
                self::FIELD_TTL_SOURCE => 'maxm-01-frozen-corpus-baseline',
            ],
            [
                self::FIELD_SLICE => 'MAXI-04',
                self::FIELD_SERIES => AtlasImmuneClassifierHybridFreeze::MEASURE_ID,
                self::FIELD_PATH => storage_path('app/atlas/evidence/acos-max-maxi-04-classifier-hybrid.jsonl'),
                self::FIELD_SOURCE_TYPE => self::SOURCE_TYPE_JSONL,
                self::FIELD_TIMESTAMP_FIELD => self::FIELD_RECORDED_AT,
                self::FIELD_TTL_DAYS => AtlasImmuneClassifierHybridFreeze::TTL_DAYS,
                self::FIELD_TTL_SOURCE => 'freeze:atlas.immune.classifier_hybrid.v1',
            ],
            [
                self::FIELD_SLICE => 'MAXI-05',
                self::FIELD_SERIES => AtlasImmuneSignatureFreeze::MEASURE_ID,
                self::FIELD_TABLE => ImmuneSignatureStore::TABLE,
                self::FIELD_SOURCE_TYPE => self::SOURCE_TYPE_TABLE,
                self::FIELD_TIMESTAMP_FIELD => self::FIELD_FIRST_SEEN,
                self::FIELD_TTL_DAYS => AtlasImmuneSignatureFreeze::TTL_DAYS,
                self::FIELD_TTL_SOURCE => 'freeze:atlas.immune.signature_store.v1',
            ],
            [
                self::FIELD_SLICE => 'TETO-01',
                self::FIELD_SERIES => AtlasNCaptureDrillService::MEASURE_ID,
                self::FIELD_PATH => storage_path(AtlasNCaptureDrillService::RELATIVE_LEDGER_PATH),
                self::FIELD_SOURCE_TYPE => self::SOURCE_TYPE_JSONL,
                self::FIELD_TIMESTAMP_FIELD => self::FIELD_RECORDED_AT,
                self::FIELD_TTL_DAYS => 365,
                self::FIELD_TTL_SOURCE => 'freeze:atlas.n_capture_drill.v1',
            ],
            [
                self::FIELD_SLICE => 'MAXA-06',
                self::FIELD_SERIES => AtlasKnowledgeItemEmbeddingCoverageService::MEASURE_ID,
                self::FIELD_PATH => 'AtlasKnowledgeItemEmbeddingCoverageService::report',
                self::FIELD_SOURCE_TYPE => self::SOURCE_TYPE_COMPUTED_READER_FIELD,
                self::FIELD_TIMESTAMP_FIELD => self::FIELD_GENERATED_AT,
                self::FIELD_TTL_DAYS => 60,
                self::FIELD_TTL_SOURCE => 'freeze:atlas.kb_embedding_coverage.v1',
            ],
            [
                self::FIELD_SLICE => 'MAXA-06',
                self::FIELD_SERIES => AtlasCodeSymbolEmbeddingCoverageService::MEASURE_ID,
                self::FIELD_PATH => 'AtlasCodeSymbolEmbeddingCoverageService::report',
                self::FIELD_SOURCE_TYPE => self::SOURCE_TYPE_COMPUTED_READER_FIELD,
                self::FIELD_TIMESTAMP_FIELD => self::FIELD_GENERATED_AT,
                self::FIELD_TTL_DAYS => 60,
                self::FIELD_TTL_SOURCE => 'freeze:atlas.code_symbol_embedding_coverage.v1',
            ],
            [
                self::FIELD_SLICE => 'MAXD-04',
                self::FIELD_SERIES => AtlasAurgPprShadowDualReadLedger::SCHEMA,
                self::FIELD_PATH => storage_path(AtlasAurgPprShadowDualReadLedger::RELATIVE_PATH),
                self::FIELD_SOURCE_TYPE => self::SOURCE_TYPE_JSONL,
                self::FIELD_TIMESTAMP_FIELD => self::FIELD_RECORDED_AT,
                self::FIELD_TTL_DAYS => 90,
                self::FIELD_TTL_SOURCE => 'maxd-04-ppr-shadow-dual-read-window',
            ],
            [
                self::FIELD_SLICE => 'MAXA-04',
                self::FIELD_SERIES => Maxa04JinaV3DualReadLedger::SCHEMA,
                self::FIELD_PATH => storage_path(Maxa04JinaV3DualReadLedger::RELATIVE_PATH),
                self::FIELD_SOURCE_TYPE => self::SOURCE_TYPE_JSONL,
                self::FIELD_TIMESTAMP_FIELD => self::FIELD_RECORDED_AT,
                self::FIELD_TTL_DAYS => 90,
                self::FIELD_TTL_SOURCE => 'maxa-04-jina-v3-dual-read-window',
            ],
            [
                self::FIELD_SLICE => 'RAGX-07',
                self::FIELD_SERIES => RagxChainMechanismService::AB_SCHEMA,
                self::FIELD_PATH => storage_path('app/atlas/evidence/ragx-ab-registrations.jsonl'),
                self::FIELD_SOURCE_TYPE => self::SOURCE_TYPE_JSONL,
                self::FIELD_TIMESTAMP_FIELD => self::FIELD_RECORDED_AT,
                self::FIELD_TTL_DAYS => 90,
                self::FIELD_TTL_SOURCE => 'ragx-07-records-only-ab-registration',
            ],
            [
                self::FIELD_SLICE => 'REC-06',
                self::FIELD_SERIES => MetaLoopBreakerService::SCHEMA_VERSION,
                self::FIELD_PATH => 'atlas:acos:rec06-breakers --json',
                self::FIELD_SOURCE_TYPE => self::SOURCE_TYPE_COMMAND,
                self::FIELD_TIMESTAMP_FIELD => self::FIELD_GENERATED_AT,
                self::FIELD_TTL_DAYS => 30,
                self::FIELD_TTL_SOURCE => 'rec-06-meta-loop-breaker-reader',
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
