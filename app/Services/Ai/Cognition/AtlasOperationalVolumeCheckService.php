<?php

declare(strict_types=1);

namespace App\Services\Ai\Cognition;

use App\Models\AiForgeWorkPacketExecutionCycle;
use App\Models\AiRunOutcome;
use App\Models\AtlasAemorExecutionEpisode;
use App\Services\Ai\Support\DatabaseTableAvailability;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * ACOS Excellence VOL-01 — operational volume-real check with pinned thresholds.
 *
 * Read-only: counts real Dev runs and Forge cycles from live tables. Fail-open
 * when sources are missing. Alerts "janela faminta" on D+1 (previous business
 * day for Dev; rolling 7d ending yesterday for Forge).
 *
 * Prerequisite (NOT fixed here): GAP-HERMES-01 — Hermes transport can drop the
 * final stdout chunk, degrading the executor that most feeds these series
 * (Autônomos / brain writer). Volume may read falsely low until resolved.
 *
 * @see docs/engineering-knowledge-base/atlas-acos-excellence-10-10-plan-v1.md §VOL-01
 */
final class AtlasOperationalVolumeCheckService
{
    public const SCHEMA_VERSION = 'atlas.acos.operational_volume.v1';

    /** @var int PIN — ≥3 Dev runs per business day (plan VOL-01). */
    public const DEV_RUNS_PER_BUSINESS_DAY_MIN = 3;

    /** @var int PIN — ≥5 Forge cycles per rolling week (plan VOL-01). */
    public const FORGE_CYCLES_PER_WEEK_MIN = 5;

    public const PREREQUISITE_GAP_HERMES_01 = 'GAP-HERMES-01';

    public const STATUS_HEALTHY = 'healthy';

    public const STATUS_SKIPPED = 'skipped';

    public const STATUS_ALERT = 'alert';
    public const FIELD_AVAILABLE = 'available';
    public const FIELD_COUNT = 'count';
    public const FIELD_SOURCES = 'sources';
    public const FIELD_STATUS = 'status';
    public const FIELD_REASON = 'reason';
    public const FIELD_OK = 'ok';
    public const FIELD_VOLUME = 'volume';
    public const FIELD_THRESHOLD = 'threshold';

    /** @var list<string> */
    public const DEV_FLOW_IDS = ['atlas_dev', 'atlas.dev', 'engineering.dev'];

    /** @var list<string> */
    public const FORGE_FLOW_IDS = ['atlas_forge', 'engineering.forge'];

    /**
     * @return array<string,mixed>
     */
    public function check(?CarbonImmutable $asOf = null): array
    {
        $asOf = ($asOf ?? CarbonImmutable::now())->utc();
        $devDay = $this->previousBusinessDay($asOf);
        $devWindowStart = $devDay->startOfDay();
        $devWindowEnd = $devDay->endOfDay();

        $forgeWindowEnd = $asOf->copy()->subDay()->endOfDay();
        $forgeWindowStart = $forgeWindowEnd->copy()->subDays(6)->startOfDay();

        $devCount = $this->countDevRuns($devWindowStart, $devWindowEnd);
        $forgeCount = $this->countForgeCycles($forgeWindowStart, $forgeWindowEnd);

        $devAlert = $devCount[self::FIELD_AVAILABLE] && $devCount[self::FIELD_COUNT] < self::DEV_RUNS_PER_BUSINESS_DAY_MIN;
        $forgeAlert = $forgeCount[self::FIELD_AVAILABLE] && $forgeCount[self::FIELD_COUNT] < self::FORGE_CYCLES_PER_WEEK_MIN;
        $alert = $devAlert || $forgeAlert;

        $status = self::STATUS_HEALTHY;
        if (! $devCount[self::FIELD_AVAILABLE] && ! $forgeCount[self::FIELD_AVAILABLE]) {
            $status = self::STATUS_SKIPPED;
        } elseif ($alert) {
            $status = self::STATUS_ALERT;
        }

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'checked_at' => $asOf->toIso8601String(),
            self::FIELD_STATUS => $status,
            self::STATUS_ALERT => $alert,
            'alert_code' => $alert ? 'janela_faminta' : null,
            'thresholds' => [
                'dev_runs_per_business_day_min' => self::DEV_RUNS_PER_BUSINESS_DAY_MIN,
                'forge_cycles_per_week_min' => self::FORGE_CYCLES_PER_WEEK_MIN,
            ],
            'prerequisites' => [
                [
                    'id' => self::PREREQUISITE_GAP_HERMES_01,
                    self::FIELD_STATUS => 'named_prerequisite',
                    'note' => 'Hermes transport may drop final stdout chunks; Autônomos/brain-writer volume can read falsely low until resolved upstream.',
                ],
            ],
            'windows' => [
                'dev' => [
                    'label' => 'previous_business_day',
                    'start' => $devWindowStart->toIso8601String(),
                    'end' => $devWindowEnd->toIso8601String(),
                    self::FIELD_COUNT => $devCount[self::FIELD_COUNT],
                    self::FIELD_THRESHOLD => self::DEV_RUNS_PER_BUSINESS_DAY_MIN,
                    self::FIELD_AVAILABLE => $devCount[self::FIELD_AVAILABLE],
                    self::FIELD_SOURCES => $devCount[self::FIELD_SOURCES],
                    self::STATUS_ALERT => $devAlert,
                ],
                'forge' => [
                    'label' => 'rolling_7d_ending_yesterday',
                    'start' => $forgeWindowStart->toIso8601String(),
                    'end' => $forgeWindowEnd->toIso8601String(),
                    self::FIELD_COUNT => $forgeCount[self::FIELD_COUNT],
                    self::FIELD_THRESHOLD => self::FORGE_CYCLES_PER_WEEK_MIN,
                    self::FIELD_AVAILABLE => $forgeCount[self::FIELD_AVAILABLE],
                    self::FIELD_SOURCES => $forgeCount[self::FIELD_SOURCES],
                    self::STATUS_ALERT => $forgeAlert,
                ],
            ],
        ];
    }

    private function previousBusinessDay(CarbonImmutable $asOf): CarbonImmutable
    {
        $candidate = $asOf->copy()->startOfDay()->subDay();
        while ($candidate->isWeekend()) {
            $candidate = $candidate->subDay();
        }

        return $candidate;
    }

    /**
     * @return array{count:int,available:bool,sources:list<string>}
     */
    private function countDevRuns(CarbonImmutable $start, CarbonImmutable $end): array
    {
        $sources = [];
        $total = 0;
        $available = false;

        if (DatabaseTableAvailability::has('ai_run_outcomes')) {
            $available = true;
            $sources[] = 'ai_run_outcomes';
            $total += (int) AiRunOutcome::query()
                ->whereIn('flow_id', self::DEV_FLOW_IDS)
                ->whereBetween('created_at', [$start, $end])
                ->count();
        }

        if (DatabaseTableAvailability::has('atlas_aemor_execution_episodes')) {
            $available = true;
            $sources[] = 'atlas_aemor_execution_episodes';
            $total += (int) AtlasAemorExecutionEpisode::query()
                ->whereIn('flow_id', self::DEV_FLOW_IDS)
                ->whereBetween('created_at', [$start, $end])
                ->count();
        }

        return [
            self::FIELD_COUNT => $total,
            self::FIELD_AVAILABLE => $available,
            self::FIELD_SOURCES => $sources,
        ];
    }

    /**
     * @return array{count:int,available:bool,sources:list<string>}
     */
    private function countForgeCycles(CarbonImmutable $start, CarbonImmutable $end): array
    {
        $sources = [];
        $total = 0;
        $available = false;

        if (DatabaseTableAvailability::has('ai_forge_work_packet_execution_cycles')) {
            $available = true;
            $sources[] = 'ai_forge_work_packet_execution_cycles';
            try {
                $total += (int) AiForgeWorkPacketExecutionCycle::query()
                    ->whereBetween('created_at', [$start, $end])
                    ->count();
            } catch (Throwable) {
                // fail-open: table declared but unreadable
            }
        }

        if (DatabaseTableAvailability::has('ai_run_outcomes')) {
            $available = true;
            if (! in_array('ai_run_outcomes', $sources, true)) {
                $sources[] = 'ai_run_outcomes';
            }
            $total += (int) AiRunOutcome::query()
                ->whereIn('flow_id', self::FORGE_FLOW_IDS)
                ->whereBetween('created_at', [$start, $end])
                ->count();
        }

        if (DatabaseTableAvailability::has('atlas_aemor_execution_episodes')) {
            $available = true;
            if (! in_array('atlas_aemor_execution_episodes', $sources, true)) {
                $sources[] = 'atlas_aemor_execution_episodes';
            }
            $total += (int) AtlasAemorExecutionEpisode::query()
                ->whereIn('flow_id', self::FORGE_FLOW_IDS)
                ->whereBetween('created_at', [$start, $end])
                ->count();
        }

        return [
            self::FIELD_COUNT => $total,
            self::FIELD_AVAILABLE => $available,
            self::FIELD_SOURCES => $sources,
        ];
    }
}
