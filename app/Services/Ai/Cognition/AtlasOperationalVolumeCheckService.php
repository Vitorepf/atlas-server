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

    /** @var list<string> */
    private const DEV_FLOW_IDS = ['atlas_dev', 'atlas.dev', 'engineering.dev'];

    /** @var list<string> */
    private const FORGE_FLOW_IDS = ['atlas_forge', 'engineering.forge'];

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

        $devAlert = $devCount['available'] && $devCount['count'] < self::DEV_RUNS_PER_BUSINESS_DAY_MIN;
        $forgeAlert = $forgeCount['available'] && $forgeCount['count'] < self::FORGE_CYCLES_PER_WEEK_MIN;
        $alert = $devAlert || $forgeAlert;

        $status = 'healthy';
        if (! $devCount['available'] && ! $forgeCount['available']) {
            $status = 'skipped';
        } elseif ($alert) {
            $status = 'alert';
        }

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'checked_at' => $asOf->toIso8601String(),
            'status' => $status,
            'alert' => $alert,
            'alert_code' => $alert ? 'janela_faminta' : null,
            'thresholds' => [
                'dev_runs_per_business_day_min' => self::DEV_RUNS_PER_BUSINESS_DAY_MIN,
                'forge_cycles_per_week_min' => self::FORGE_CYCLES_PER_WEEK_MIN,
            ],
            'prerequisites' => [
                [
                    'id' => self::PREREQUISITE_GAP_HERMES_01,
                    'status' => 'named_prerequisite',
                    'note' => 'Hermes transport may drop final stdout chunks; Autônomos/brain-writer volume can read falsely low until resolved upstream.',
                ],
            ],
            'windows' => [
                'dev' => [
                    'label' => 'previous_business_day',
                    'start' => $devWindowStart->toIso8601String(),
                    'end' => $devWindowEnd->toIso8601String(),
                    'count' => $devCount['count'],
                    'threshold' => self::DEV_RUNS_PER_BUSINESS_DAY_MIN,
                    'available' => $devCount['available'],
                    'sources' => $devCount['sources'],
                    'alert' => $devAlert,
                ],
                'forge' => [
                    'label' => 'rolling_7d_ending_yesterday',
                    'start' => $forgeWindowStart->toIso8601String(),
                    'end' => $forgeWindowEnd->toIso8601String(),
                    'count' => $forgeCount['count'],
                    'threshold' => self::FORGE_CYCLES_PER_WEEK_MIN,
                    'available' => $forgeCount['available'],
                    'sources' => $forgeCount['sources'],
                    'alert' => $forgeAlert,
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
            'count' => $total,
            'available' => $available,
            'sources' => $sources,
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
            'count' => $total,
            'available' => $available,
            'sources' => $sources,
        ];
    }
}
