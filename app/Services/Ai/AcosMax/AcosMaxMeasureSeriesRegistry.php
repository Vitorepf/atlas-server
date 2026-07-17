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
            $entry['slice'] = (string) ($entry['slice'] ?? '');
            $entry['series'] = (string) ($entry['series'] ?? '');
            $entry['ttl_days'] = max(1, (int) ($entry['ttl_days'] ?? 1));

            return $entry;
        }, $this->entries));
    }

    /** @return list<string> */
    public function seriesIds(): array
    {
        return $this->uniqueColumn('series');
    }

    /** @return list<string> */
    public function sliceIds(): array
    {
        return $this->uniqueColumn('slice');
    }

    /**
     * @return list<array<string,mixed>>
     */
    public static function defaultEntries(): array
    {
        return [
            [
                'slice' => 'MAXG-01',
                'series' => 'aobg.latency_ledger.v1',
                'path' => storage_path(AtlasAobgLatencyLedger::DEFAULT_RELATIVE_DIR),
                'source_type' => 'jsonl_dir',
                'timestamp_field' => 'ts',
                'ttl_days' => (int) AtlasAcosFreezeCommand::defaultFreezePayload()['ttl_days'],
                'ttl_source' => 'freeze:aobg.latency_ledger.v1',
            ],
            [
                'slice' => 'ELEV-02',
                'series' => AtlasAcosMSeriesCommand::MEASURE_ID,
                'path' => storage_path(AtlasAcosMSeriesCommand::DEFAULT_SERIES_RELATIVE_PATH),
                'source_type' => 'jsonl',
                'timestamp_field' => 'recorded_at',
                'ttl_days' => (int) AtlasAcosFreezeCommand::asiMetricMFreezePayload()['ttl_days'],
                'ttl_source' => 'freeze:asi.metric.m.v1',
            ],
            [
                'slice' => 'ELEV-12',
                'series' => AcosMaxVerifiedShareService::MEASURE_ID,
                'path' => storage_path('atlas/atlas_decide/live_outcomes.jsonl'),
                'source_type' => 'jsonl',
                'timestamp_field' => 'recorded_at',
                'ttl_days' => (int) AcosMaxVerifiedShareService::freezePayload()['ttl_days'],
                'ttl_source' => 'freeze:acos.verified_share.v1',
            ],
            [
                'slice' => 'ASI-05',
                'series' => 'acos.asi05.ledger_cleanup.v1',
                'path' => storage_path('app/atlas/evidence/acos-max-asi-05-ledger-cleanup.jsonl'),
                'source_type' => 'jsonl',
                'timestamp_field' => 'recorded_at',
                'ttl_days' => 365,
                'ttl_source' => 'one_time_cleanup_receipt',
            ],
            [
                'slice' => 'ESP-00',
                'series' => 'acos.esp00.ground_truth.v1',
                'path' => storage_path('app/atlas/evidence/acos-max-esp-00-ground-truth.jsonl'),
                'source_type' => 'jsonl',
                'timestamp_field' => 'recorded_at',
                'ttl_days' => 365,
                'ttl_source' => 'ground_truth_receipt',
            ],
            [
                'slice' => 'MAXL-02',
                'series' => 'atlas.evidence_ledger.hash_chain.v1',
                'table' => 'atlas_ledger_events',
                'source_type' => 'table',
                'timestamp_field' => 'occurred_at',
                'ttl_days' => 30,
                'ttl_source' => 'maxl-02-freeze-equivalent',
            ],
            [
                'slice' => 'MAXH-01',
                'series' => AtlasMemoryTemporalQualityService::MEASURE_ID,
                'path' => 'atlas:memory:temporal-quality --json',
                'source_type' => 'command',
                'timestamp_field' => 'generated_at',
                'ttl_days' => (int) AtlasMemoryTemporalQualityService::freezePayload()['ttl_days'],
                'ttl_source' => 'freeze:atlas.memory.temporal_truth.v2',
            ],
            [
                'slice' => 'MAXI-02',
                'series' => 'atlas.capture.cognitive_immune_audit.v2',
                'table' => 'captures',
                'source_type' => 'table',
                'timestamp_field' => 'created_at',
                'ttl_days' => ImmuneCalibrationService::TTL_DAYS,
                'ttl_source' => 'maxi-02-shadow-audit-v2',
            ],
            [
                'slice' => 'MAXI-03',
                'series' => ImmuneCalibrationService::MEASURE_ID,
                'table' => ImmuneVerdictLedger::TABLE,
                'source_type' => 'table',
                'timestamp_field' => 'decided_at',
                'ttl_days' => ImmuneCalibrationService::TTL_DAYS,
                'ttl_source' => 'freeze:atlas.immune.calibration.v1',
            ],
            [
                'slice' => 'ELEV-20s',
                'series' => 'acos.dead_series_watchdog.v1',
                'table' => 'atlas_ledger_events',
                'source_type' => 'table',
                'timestamp_field' => 'occurred_at',
                'where' => [
                    'scope_type' => 'acos_watchdog',
                    'scope_id' => 'unified',
                ],
                'ttl_days' => 30,
                'ttl_source' => 'elev-20s-freeze-equivalent',
            ],
            [
                'slice' => 'ELEV-25',
                'series' => AtlasOperatorReviewDebtMeter::MEASURE_ID,
                'table' => 'atlas_ledger_events',
                'source_type' => 'table',
                'timestamp_field' => 'occurred_at',
                'where' => [
                    'scope_type' => 'acos_watchdog',
                    'scope_id' => 'unified',
                ],
                'ttl_days' => AtlasOperatorReviewDebtMeter::TTL_DAYS,
                'ttl_source' => 'freeze:acos.operator_review_debt.v1',
            ],
            [
                'slice' => 'MAXJ-01',
                'series' => AtlasLessonQualityService::MEASURE_ID,
                'path' => 'atlas:ai:lesson-quality --json',
                'source_type' => 'command',
                'timestamp_field' => 'generated_at',
                'ttl_days' => (int) AtlasAcosFreezeCommand::lessonQualityFreezePayload()['ttl_days'],
                'ttl_source' => 'freeze:atlas.ai.lesson_quality.v2',
            ],
            [
                'slice' => 'MAXJ-05',
                'series' => AtlasLearningRecallUseLiftService::LESSON_TYPE_YIELD_MEASURE_ID,
                'path' => 'atlas:ai:lesson-type-yield --json',
                'source_type' => 'command',
                'timestamp_field' => 'generated_at',
                'ttl_days' => (int) AtlasAcosFreezeCommand::lessonTypeYieldFreezePayload()['ttl_days'],
                'ttl_source' => 'freeze:atlas.ai.lesson_type_yield.v2',
            ],
            [
                'slice' => 'MAXK-01',
                'series' => AtlasDecideRouteRegretService::MEASURE_ID,
                'path' => 'atlas:atlas-decide:live-feedback --regret --json',
                'source_type' => 'command',
                'timestamp_field' => 'generated_at',
                'ttl_days' => AtlasDecideRouteRegretService::TTL_DAYS,
                'ttl_source' => 'freeze:atlas.decide.route_regret.v2',
            ],
            [
                'slice' => 'MULTK-01',
                'series' => AtlasDecideCostOutcomeRouter::MULTK01_MEASURE_ID,
                'path' => 'AtlasDecideCostOutcomeRouter::costOutcomeCandidates',
                'source_type' => 'computed_reader_field',
                'timestamp_field' => 'generated_at',
                'ttl_days' => (int) AtlasDecideCostOutcomeRouter::multk01FreezePayload()['ttl_days'],
                'ttl_source' => 'freeze:atlas.decide.cost_outcome_uncertainty.v1',
            ],
            [
                'slice' => 'MULTK-02',
                'series' => AtlasDecideCostOutcomeRouter::MULTK02_MEASURE_ID,
                'path' => 'AtlasDecideCostOutcomeRouter::costOutcomeRoute.cascade',
                'source_type' => 'computed_reader_field',
                'timestamp_field' => 'generated_at',
                'ttl_days' => 30,
                'ttl_source' => 'freeze:atlas.decide.cascade_cost_router.v1',
            ],
            [
                'slice' => 'MULTK-03',
                'series' => AtlasDecideReplayDivergenceService::MEASURE_ID,
                'path' => 'atlas:decide:replay-divergence --json',
                'source_type' => 'command',
                'timestamp_field' => 'generated_at',
                'ttl_days' => 30,
                'ttl_source' => 'freeze:atlas.decide.replay_divergence.v1',
            ],
            [
                'slice' => 'ESP-05',
                'series' => AtlasDecideLiveOutcomeFeedbackService::ZERO_WEIGHT_MEASURE_ID,
                'path' => 'AtlasDecideLiveOutcomeFeedbackService::routeStats.providers.*.zero_weight_success_rate',
                'source_type' => 'computed_reader_field',
                'timestamp_field' => 'generated_at',
                'ttl_days' => (int) AtlasDecideLiveOutcomeFeedbackService::zeroWeightFreezePayload()['ttl_days'],
                'ttl_source' => 'freeze:atlas.decide.zero_weight_outcomes.v1',
            ],
            [
                'slice' => 'ESP-06',
                'series' => OutcomeEnvelopeBridge::MEASURE_ID,
                'path' => 'OutcomeEnvelopeBridge::producerConsumerMeta',
                'source_type' => 'computed_reader_field',
                'timestamp_field' => 'generated_at',
                'ttl_days' => (int) OutcomeEnvelopeBridge::freezePayload()['ttl_days'],
                'ttl_source' => 'freeze:atlas.esp_06.outcome_envelope.v1',
            ],
            [
                'slice' => 'ESP-09',
                'series' => Esp09IndependentChallengerService::MEASURE_ID,
                'path' => 'Esp09IndependentChallengerService::refutationSeries',
                'source_type' => 'computed_reader_field',
                'timestamp_field' => 'generated_at',
                'ttl_days' => (int) Esp09IndependentChallengerService::freezePayload()['ttl_days'],
                'ttl_source' => 'freeze:atlas.esp_09.challenger_advisory.v1',
            ],
            [
                'slice' => 'MULTN15-02',
                'series' => OperatorApprovalHistoryMeter::MEASURE_ID,
                'path' => 'atlas:operator-approval-history --json',
                'source_type' => 'command',
                'timestamp_field' => 'generated_at',
                'ttl_days' => OperatorApprovalHistoryMeter::TTL_DAYS,
                'ttl_source' => 'freeze:operator.approval_history.v1',
            ],
            [
                'slice' => 'MAXL-06',
                'series' => AcosMaxLote2MeasureService::MAXL06_MEASURE_ID,
                'path' => 'atlas:acos:delta-attribution --json',
                'source_type' => 'command',
                'timestamp_field' => 'generated_at',
                'ttl_days' => (int) AcosMaxLote2MeasureService::freezePayload('MAXL-06')['ttl_days'],
                'ttl_source' => 'freeze:atlas.evidence.delta_attribution.v1',
            ],
            [
                'slice' => 'MAXL-07',
                'series' => GoldenCounterfactualReplayService::MEASURE_ID,
                'path' => 'atlas:context:golden-counterfactual --json',
                'source_type' => 'command',
                'timestamp_field' => 'generated_at',
                'ttl_days' => 90,
                'ttl_source' => 'freeze:atlas.context.golden_counterfactual.v1',
            ],
            [
                'slice' => 'MAXL-08',
                'series' => ExecutionContextCooccurrenceService::MEASURE_ID,
                'path' => 'atlas:context:execution-cooccurrence --json',
                'source_type' => 'command',
                'timestamp_field' => 'generated_at',
                'ttl_days' => 90,
                'ttl_source' => 'freeze:atlas.context.execution_cooccurrence.v1',
            ],
            [
                'slice' => 'MULTN17-04',
                'series' => AcosMaxLote2MeasureService::MULTN1704_MEASURE_ID,
                'path' => 'atlas:brain:predicted-impact --json',
                'source_type' => 'command',
                'timestamp_field' => 'generated_at',
                'ttl_days' => (int) AcosMaxLote2MeasureService::freezePayload('MULTN17-04')['ttl_days'],
                'ttl_source' => 'freeze:atlas.originator.predicted_impact_calibration.v1',
            ],
            [
                'slice' => 'MULTX-01',
                'series' => AcosMaxLote2MeasureService::MULTX01_MEASURE_ID,
                'path' => 'atlas:flywheel:loops --json',
                'source_type' => 'command',
                'timestamp_field' => 'generated_at',
                'ttl_days' => (int) AcosMaxLote2MeasureService::freezePayload('MULTX-01')['ttl_days'],
                'ttl_source' => 'freeze:acos.flywheel.loops.v1',
            ],
            [
                'slice' => 'MULTX-02',
                'series' => AtlasFlywheelFunnelService::MEASURE_ID,
                'path' => 'atlas:flywheel:funnel --json',
                'source_type' => 'command',
                'timestamp_field' => 'generated_at',
                'ttl_days' => 30,
                'ttl_source' => 'freeze:atlas.m.funnel.v1',
            ],
            [
                'slice' => 'MULTX-06',
                'series' => AcosMaxLote2MeasureService::MULTX06_MEASURE_ID,
                'path' => 'atlas:flywheel:learning-latency --json',
                'source_type' => 'command',
                'timestamp_field' => 'generated_at',
                'ttl_days' => (int) AcosMaxLote2MeasureService::freezePayload('MULTX-06')['ttl_days'],
                'ttl_source' => 'freeze:acos.learning_latency.v1',
            ],
            [
                'slice' => 'MULTX-09',
                'series' => AcosMaxLote2MeasureService::MULTX09_MEASURE_ID,
                'path' => 'atlas:windows --json',
                'source_type' => 'command',
                'timestamp_field' => 'generated_at',
                'ttl_days' => (int) AcosMaxLote2MeasureService::freezePayload('MULTX-09')['ttl_days'],
                'ttl_source' => 'freeze:acos.windows_orchestrator.v1',
            ],
            [
                'slice' => 'MULTJ-01',
                'series' => AcosMaxLote2MeasureService::MULTJ01_MEASURE_ID,
                'path' => 'atlas:ai:lesson-half-life --json',
                'source_type' => 'command',
                'timestamp_field' => 'generated_at',
                'ttl_days' => (int) AcosMaxLote2MeasureService::freezePayload('MULTJ-01')['ttl_days'],
                'ttl_source' => 'freeze:atlas.ai.lesson_half_life.v2',
            ],
            [
                'slice' => 'MULTJ-02',
                'series' => AcosMaxLote2MeasureService::MULTJ02_MEASURE_ID,
                'path' => 'atlas:ai:lesson-dedup-calibration --json',
                'source_type' => 'command',
                'timestamp_field' => 'generated_at',
                'ttl_days' => (int) AcosMaxLote2MeasureService::freezePayload('MULTJ-02')['ttl_days'],
                'ttl_source' => 'freeze:atlas.ai.lesson_semantic_dedup.v1',
            ],
            [
                'slice' => 'MULTJ-03',
                'series' => AcosMaxLote2MeasureService::MULTJ03_MEASURE_ID,
                'path' => 'atlas:ai:counterfactual-lift --json',
                'source_type' => 'command',
                'timestamp_field' => 'generated_at',
                'ttl_days' => (int) AcosMaxLote2MeasureService::freezePayload('MULTJ-03')['ttl_days'],
                'ttl_source' => 'freeze:atlas.ai.counterfactual_lift.v2',
            ],
            [
                'slice' => 'MULTJ-04',
                'series' => AcosMaxLote2MeasureService::MULTJ04_MEASURE_ID,
                'path' => 'atlas:ai:procedural-skill-promoter --json',
                'source_type' => 'command',
                'timestamp_field' => 'generated_at',
                'ttl_days' => (int) AcosMaxLote2MeasureService::freezePayload('MULTJ-04')['ttl_days'],
                'ttl_source' => 'freeze:atlas.ai.procedural_skill_promoter.v1',
            ],
            [
                'slice' => 'MULTJ-06',
                'series' => AcosMaxLote2MeasureService::MULTJ06_MEASURE_ID,
                'path' => 'atlas:ai:abstraction-ladder --json',
                'source_type' => 'command',
                'timestamp_field' => 'generated_at',
                'ttl_days' => (int) AcosMaxLote2MeasureService::freezePayload('MULTJ-06')['ttl_days'],
                'ttl_source' => 'freeze:atlas.ai.abstraction_ladder.v1',
            ],
            [
                'slice' => 'TETO-02',
                'series' => AcosMaxLote2MeasureService::TETO02_MEASURE_ID,
                'path' => 'atlas:mission:e2e --json',
                'source_type' => 'command',
                'timestamp_field' => 'generated_at',
                'ttl_days' => (int) AcosMaxLote2MeasureService::freezePayload('TETO-02')['ttl_days'],
                'ttl_source' => 'freeze:mission_e2e.v1',
            ],
            [
                'slice' => 'ELEV-27',
                'series' => 'atlas.resource_budget.v1',
                'path' => 'AtlasResourceBudgetService::report',
                'source_type' => 'computed_reader_field',
                'timestamp_field' => 'generated_at',
                'ttl_days' => 30,
                'ttl_source' => 'elev-27-resource-budget',
            ],
            [
                'slice' => 'ESP-03',
                'series' => 'atlas.test_attestation.v1',
                'path' => 'AtlasTestAttestationService::attest',
                'source_type' => 'computed_reader_field',
                'timestamp_field' => 'attested_at',
                'ttl_days' => 30,
                'ttl_source' => 'esp-03-test-attestation-seal',
            ],
            [
                'slice' => 'MAXM-01',
                'series' => 'atlas.provider_leak_corpus.v1',
                'path' => storage_path('app/atlas/evidence/acos-max-maxm01-provider-leak-corpus.jsonl'),
                'source_type' => 'jsonl',
                'timestamp_field' => 'recorded_at',
                'ttl_days' => 180,
                'ttl_source' => 'maxm-01-frozen-corpus-baseline',
            ],
            [
                'slice' => 'MAXI-04',
                'series' => AtlasImmuneClassifierHybridFreeze::MEASURE_ID,
                'path' => storage_path('app/atlas/evidence/acos-max-maxi-04-classifier-hybrid.jsonl'),
                'source_type' => 'jsonl',
                'timestamp_field' => 'recorded_at',
                'ttl_days' => AtlasImmuneClassifierHybridFreeze::TTL_DAYS,
                'ttl_source' => 'freeze:atlas.immune.classifier_hybrid.v1',
            ],
            [
                'slice' => 'MAXI-05',
                'series' => AtlasImmuneSignatureFreeze::MEASURE_ID,
                'table' => ImmuneSignatureStore::TABLE,
                'source_type' => 'table',
                'timestamp_field' => 'first_seen',
                'ttl_days' => AtlasImmuneSignatureFreeze::TTL_DAYS,
                'ttl_source' => 'freeze:atlas.immune.signature_store.v1',
            ],
            [
                'slice' => 'TETO-01',
                'series' => AtlasNCaptureDrillService::MEASURE_ID,
                'path' => storage_path(AtlasNCaptureDrillService::RELATIVE_LEDGER_PATH),
                'source_type' => 'jsonl',
                'timestamp_field' => 'recorded_at',
                'ttl_days' => 365,
                'ttl_source' => 'freeze:atlas.n_capture_drill.v1',
            ],
            [
                'slice' => 'MAXA-06',
                'series' => AtlasKnowledgeItemEmbeddingCoverageService::MEASURE_ID,
                'path' => 'AtlasKnowledgeItemEmbeddingCoverageService::report',
                'source_type' => 'computed_reader_field',
                'timestamp_field' => 'generated_at',
                'ttl_days' => 60,
                'ttl_source' => 'freeze:atlas.kb_embedding_coverage.v1',
            ],
            [
                'slice' => 'MAXA-06',
                'series' => AtlasCodeSymbolEmbeddingCoverageService::MEASURE_ID,
                'path' => 'AtlasCodeSymbolEmbeddingCoverageService::report',
                'source_type' => 'computed_reader_field',
                'timestamp_field' => 'generated_at',
                'ttl_days' => 60,
                'ttl_source' => 'freeze:atlas.code_symbol_embedding_coverage.v1',
            ],
            [
                'slice' => 'MAXD-04',
                'series' => AtlasAurgPprShadowDualReadLedger::SCHEMA,
                'path' => storage_path(AtlasAurgPprShadowDualReadLedger::RELATIVE_PATH),
                'source_type' => 'jsonl',
                'timestamp_field' => 'recorded_at',
                'ttl_days' => 90,
                'ttl_source' => 'maxd-04-ppr-shadow-dual-read-window',
            ],
            [
                'slice' => 'MAXA-04',
                'series' => Maxa04JinaV3DualReadLedger::SCHEMA,
                'path' => storage_path(Maxa04JinaV3DualReadLedger::RELATIVE_PATH),
                'source_type' => 'jsonl',
                'timestamp_field' => 'recorded_at',
                'ttl_days' => 90,
                'ttl_source' => 'maxa-04-jina-v3-dual-read-window',
            ],
            [
                'slice' => 'RAGX-07',
                'series' => RagxChainMechanismService::AB_SCHEMA,
                'path' => storage_path('app/atlas/evidence/ragx-ab-registrations.jsonl'),
                'source_type' => 'jsonl',
                'timestamp_field' => 'recorded_at',
                'ttl_days' => 90,
                'ttl_source' => 'ragx-07-records-only-ab-registration',
            ],
            [
                'slice' => 'REC-06',
                'series' => MetaLoopBreakerService::SCHEMA_VERSION,
                'path' => 'atlas:acos:rec06-breakers --json',
                'source_type' => 'command',
                'timestamp_field' => 'generated_at',
                'ttl_days' => 30,
                'ttl_source' => 'rec-06-meta-loop-breaker-reader',
            ],
        ];
    }

    /** @return list<string> */
    private function uniqueColumn(string $column): array
    {
        $values = [];
        foreach ($this->entries() as $entry) {
            $value = AiValueNormalizer::trimmedString($entry[$column] ?? '');
            if ($value !== '') {
                $values[$value] = true;
            }
        }

        return array_keys($values);
    }
}
