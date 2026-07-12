<?php

declare(strict_types=1);

namespace App\Services\Ai\AcosMax;

use App\Console\Commands\AtlasAcosFreezeCommand;
use App\Console\Commands\AtlasAcosMSeriesCommand;
use App\Services\Ai\AtlasDecide\AtlasDecideCostOutcomeRouter;
use App\Services\Ai\AtlasDecide\AtlasDecideRouteRegretService;
use App\Services\Ai\Autonomy\AtlasOperatorReviewDebtMeter;
use App\Services\Ai\Compounding\AtlasLessonQualityService;
use App\Services\Ai\Memory\AtlasMemoryTemporalQualityService;
use App\Services\Ai\OpenBrain\AtlasAobgLatencyLedger;

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
        ];
    }

    /** @return list<string> */
    private function uniqueColumn(string $column): array
    {
        $values = [];
        foreach ($this->entries() as $entry) {
            $value = trim((string) ($entry[$column] ?? ''));
            if ($value !== '') {
                $values[$value] = true;
            }
        }

        return array_keys($values);
    }
}
