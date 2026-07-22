<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Resilience;

/**
 * HUNG GRIND DETECTOR — guards the WORKER grinds (run-scenario / hermes children) by spotting pids that have
 * been alive a long time AND made no progress on their target row during the same window. Unlike the existing
 * supervisor-side hung guard, this scope is the WORKER side: a hermes child can sit at 0% CPU for hours
 * blocking a target's single attempt — that is the failure mode this detector surfaces.
 *
 * INPUTS: a snapshot from {@see AtlasLoopProcessTopologyProbe} plus a progress oracle the caller wires to the
 * DB (atlas_loop_targets / atlas_loop_tasks) — the oracle MUST return the age in seconds of the most recent
 * heartbeat for the row owned by the given pid, or null when no row is known. The detector itself opens no
 * connections, spawns no processes, talks to no provider, and makes no network call (proven by an isolation
 * test); all I/O is in the oracle closure the caller controls.
 *
 * ANTI-GOODHART: a pid is marked `hung` only when BOTH conditions hold — etime_s >= etime_threshold AND
 * last_progress_age_s >= progress_age_threshold. The swap-thrashing-slow case (long etime but recent progress)
 * is `slow_but_live`, never `hung`, so the detector cannot ever recommend killing a working grind. Unknown
 * progress (oracle returns null) ALSO yields `slow_but_live`: "we don't know" is not enough evidence to call
 * hung. DOES NOT KILL — kill policy is packet 04.
 */
final class AtlasLoopHungGrindDetector
{
    public const VERDICT_HUNG = 'hung';

    public const VERDICT_SLOW_BUT_LIVE = 'slow_but_live';

    public const VERDICT_HEALTHY = 'healthy';

    public const ROLE_GRIND = 'grind';

    public const ROLE_HERMES = 'hermes';

    /**
     * @param  callable(int $pid, ?string $campaignId, string $command): ?int  $progressOracle
     *         caller-supplied DB lookup: returns the age (s) of the most recent heartbeat for the row owned
     *         by this pid, or null when no row was found. The detector does NOT call out itself.
     */
    public function __construct(
        private readonly int $etimeThresholdSec = 900,
        private readonly int $progressAgeThresholdSec = 600,
        private $progressOracle = null,
    ) {
    }

    /**
     * @param  array<string,mixed>  $snapshot  output of {@see AtlasLoopProcessTopologyProbe::snapshot()}
     * @return list<array{
     *     pid:int,
     *     role:string,
     *     campaign_id:?string,
     *     verdict:string,
     *     evidence:array{etime_s:int,last_progress_age_s:?int,cpu_avg:float}
     * }>
     */
    public function detect(array $snapshot): array
    {
        $verdicts = [];
        $processes = $this->grindLikeProcesses($snapshot);

        foreach ($processes as $process) {
            $pid = (int) ($process['pid'] ?? 0);
            $role = (string) ($process['role'] ?? '');
            $campaignId = isset($process['campaign_id_or_null']) ? (string) $process['campaign_id_or_null'] : null;
            if ($campaignId === '') {
                $campaignId = null;
            }
            $etime = max(0, (int) ($process['etime_s'] ?? 0));
            $cpu = (float) ($process['cpu'] ?? 0.0);
            $command = (string) ($process['command'] ?? '');

            $lastProgressAge = $this->probeProgress($pid, $campaignId, $command);

            $verdict = $this->classify($etime, $lastProgressAge);

            $verdicts[] = [
                'pid' => $pid,
                'role' => $role,
                'campaign_id' => $campaignId,
                'verdict' => $verdict,
                'evidence' => [
                    'etime_s' => $etime,
                    'last_progress_age_s' => $lastProgressAge,
                    'cpu_avg' => $cpu,
                ],
            ];
        }

        usort($verdicts, static fn (array $x, array $y): int => $x['pid'] <=> $y['pid']);

        return $verdicts;
    }

    /**
     * @param  array<string,mixed>  $snapshot
     * @return list<array<string,mixed>>
     */
    private function grindLikeProcesses(array $snapshot): array
    {
        $processes = $snapshot['processes'] ?? [];
        if (! is_array($processes)) {
            return [];
        }
        $out = [];
        foreach ($processes as $process) {
            if (! is_array($process)) {
                continue;
            }
            $role = (string) ($process['role'] ?? '');
            if ($role === self::ROLE_GRIND || $role === self::ROLE_HERMES) {
                $out[] = $process;
            }
        }

        return $out;
    }

    private function probeProgress(int $pid, ?string $campaignId, string $command): ?int
    {
        $oracle = $this->progressOracle;
        if (! is_callable($oracle)) {
            return null; // no oracle wired ⇒ we don't know; classify() will treat as slow_but_live, never hung
        }
        $result = $oracle($pid, $campaignId, $command);
        if ($result === null) {
            return null;
        }

        return max(0, (int) $result);
    }

    private function classify(int $etime, ?int $progressAge): string
    {
        if ($etime < $this->etimeThresholdSec) {
            return self::VERDICT_HEALTHY;
        }
        if ($progressAge !== null && $progressAge >= $this->progressAgeThresholdSec) {
            return self::VERDICT_HUNG;
        }

        return self::VERDICT_SLOW_BUT_LIVE;
    }
}
