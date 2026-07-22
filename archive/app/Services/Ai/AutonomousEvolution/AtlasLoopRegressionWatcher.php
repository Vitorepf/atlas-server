<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution;

use App\Models\AtlasLoopProposal;
use App\Services\Ai\AutonomousEvolution\Persistence\AtlasLoopStore;
use App\Services\Ai\Support\DatabaseTableAvailability;

/**
 * L6 — the post-merge ANTI-REGRESSION NET (the production wire on top of {@see AtlasLoopRegressionSentinel}).
 *
 * A long unsupervised run must not silently knock down a lower rung: when a check that was GREEN goes RED on
 * main, the loop attributes it (by file overlap) to its OWN recent merge and ENQUEUES a FIX-FORWARD repair —
 * closing the ship→watch→repair antifragile loop. The sentinel is the pure attribution brain; this watcher is
 * the wire that turns its repair objectives into real claimable tasks (source='regression_sentinel', high
 * priority, deduped by the sentinel's source_key so re-triaging the same regression never floods the queue).
 *
 * It NEVER self-blames external breakage (an unattributed failure enqueues nothing) and NEVER touches the
 * cert-moat. Flag `atlas.loop.regression_sentinel_enabled` default-OFF => enqueues nothing => byte-identical.
 * Pure over injected (failures, recentMerges) — the suite-runner that produces the RED set lives in the
 * post-merge command, so this is provable deterministically with fakes (no live suite run).
 */
final class AtlasLoopRegressionWatcher
{
    private const DEFAULT_WINDOW_SIZE = 10;

    public function __construct(
        private readonly AtlasLoopStore $store,
        private readonly ?AtlasLoopRegressionSentinel $sentinel = null,
    ) {}

    /**
     * @param  list<array{id:string, related_files?:list<string>, detail?:string}>  $failures  newly-RED checks
     * @param  list<array{commit:string, files?:list<string>, merged_at?:string, proposal_id?:string}>  $recentMerges
     * @return array{attributed:int, enqueued:int, unattributed:int}
     */
    public function enqueueRepairs(string $campaignId, array $failures, array $recentMerges): array
    {
        if (! (bool) config('atlas.loop.regression_sentinel_enabled', false)) {
            return ['attributed' => 0, 'enqueued' => 0, 'unattributed' => 0];
        }

        if ($recentMerges === []) {
            return $this->enqueueRepairsForWindow($campaignId, $failures);
        }

        return $this->enqueueFromRecentMerges($campaignId, $failures, $recentMerges);
    }

    /**
     * @param  list<array{id:string, related_files?:list<string>, detail?:string}>  $failures
     * @return array{attributed:int, enqueued:int, unattributed:int}
     */
    public function enqueueRepairsForWindow(string $campaignId, array $failures, ?int $windowSize = null): array
    {
        if (! (bool) config('atlas.loop.regression_sentinel_enabled', false)) {
            return ['attributed' => 0, 'enqueued' => 0, 'unattributed' => 0];
        }
        if (! DatabaseTableAvailability::has('atlas_loop_proposals')) {
            return ['attributed' => 0, 'enqueued' => 0, 'unattributed' => count($failures)];
        }

        $windowSize = $windowSize !== null ? max(1, $windowSize) : max(5, (int) config('atlas.loop.regression_sentinel_window', self::DEFAULT_WINDOW_SIZE));
        $recentMerges = [];
        foreach (AtlasLoopProposal::query()
            ->where('merged_to_main', true)
            ->orderByDesc('reviewed_at')
            ->limit($windowSize)
            ->get() as $proposal) {
            $quality = is_array($proposal->quality ?? null) ? $proposal->quality : [];
            $mergeSha = trim((string) ($proposal->merge_commit_sha ?? ''));
            if ($mergeSha === '') {
                $candidate = data_get($quality, '_merge_sha');
                if (is_array($candidate)) {
                    $candidate = reset($candidate) ?: '';
                }
                $mergeSha = trim((string) $candidate);
            }
            $changedFiles = data_get($quality, '_changed_files');
            $changedFiles = is_array($changedFiles)
                ? array_values(array_filter($changedFiles, static fn ($file): bool => is_string($file) && trim($file) !== ''))
                : [];
            $recentMerges[] = [
                'commit' => $mergeSha !== '' ? $mergeSha : (string) $proposal->id,
                'files' => $changedFiles,
                'merged_at' => $proposal->reviewed_at?->toIso8601String(),
                'proposal_id' => (string) $proposal->id,
            ];
        }

        return $this->enqueueFromRecentMerges($campaignId, $failures, $recentMerges);
    }

    /**
     * @param  list<array{id:string, related_files?:list<string>, detail?:string}>  $failures
     * @param  list<array{commit:string, files?:list<string>, merged_at?:string|null, proposal_id?:string}>  $recentMerges
     * @return array{attributed:int, enqueued:int, unattributed:int}
     */
    private function enqueueFromRecentMerges(string $campaignId, array $failures, array $recentMerges): array
    {
        $triage = ($this->sentinel ?? new AtlasLoopRegressionSentinel)->triage($failures, $recentMerges);

        $enqueued = 0;
        $enqueuedRepairs = [];
        foreach ($triage['repairs'] as $repair) {
            if (! is_array($repair) || trim((string) ($repair['objective'] ?? '')) === '') {
                continue;
            }
            $targetFiles = array_values(array_filter((array) ($repair['target_files'] ?? []), 'is_string'));
            $task = $this->store->enqueueTask(
                $campaignId,
                (string) $repair['objective'],
                ['_regression_repair' => true, 'failure_id' => (string) ($repair['failure_id'] ?? ''), 'target_files' => $targetFiles],
                'regression_sentinel',
                $targetFiles[0] ?? null,
                50,   // high priority — a regression repair preempts new (lower-rung) work
                true,
                (string) ($repair['source_key'] ?? ''), // dedupe: re-triaging the same regression never floods
            );
            if ($task !== null) {
                $enqueued++;
                $enqueuedRepairs[] = $repair;
            }
        }

        // SURFACE the triage to the morning digest: append a record per attribution, per unattributed (external/
        // pre-existing breakage the loop will NOT auto-repair — otherwise invisible to the operator), and per
        // enqueued repair. Only reached when regression_sentinel_enabled is ON (the public entrypoints early-
        // return when OFF), so OFF ⇒ no file written ⇒ byte-identical.
        $this->recordTriageToDigestLog($campaignId, $triage, $enqueuedRepairs);

        return [
            'attributed' => count($triage['attributed']),
            'enqueued' => $enqueued,
            'unattributed' => count($triage['unattributed']),
        ];
    }

    /**
     * Append the triage outcome to the regression-triage JSONL the morning digest reads. Best-effort: a logging
     * failure must NEVER break the repair wire. Schema `atlas.loop.regression_triage.v1`.
     *
     * @param  array{attributed:list<array<string,mixed>>, unattributed:list<string>, repairs:list<array<string,mixed>>}  $triage
     * @param  list<array<string,mixed>>  $enqueuedRepairs  the repairs that were actually enqueued
     */
    private function recordTriageToDigestLog(string $campaignId, array $triage, array $enqueuedRepairs): void
    {
        $path = (string) config(
            'atlas.loop.morning_digest.regression_triage_log_path',
            storage_path('app/atlas/loop/regression-triage.jsonl'),
        );
        if ($path === '') {
            return;
        }

        try {
            $ts = now()->toIso8601String();
            $records = [];
            foreach ($triage['attributed'] as $attribution) {
                if (! is_array($attribution)) {
                    continue;
                }
                $records[] = ['kind' => 'attributed', 'failure_id' => (string) ($attribution['failure_id'] ?? ''), 'commit' => (string) ($attribution['commit'] ?? '')];
            }
            foreach ($triage['unattributed'] as $failureId) {
                $records[] = ['kind' => 'unattributed', 'failure_id' => (string) $failureId];
            }
            foreach ($enqueuedRepairs as $repair) {
                $records[] = ['kind' => 'repair_enqueued', 'failure_id' => (string) ($repair['failure_id'] ?? ''), 'source_key' => (string) ($repair['source_key'] ?? '')];
            }
            if ($records === []) {
                return;
            }

            @mkdir(dirname($path), 0o775, true);
            $blob = '';
            foreach ($records as $record) {
                $blob .= json_encode(
                    ['schema_version' => 'atlas.loop.regression_triage.v1', 'ts' => $ts, 'campaign_id' => $campaignId] + $record,
                    JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
                )."\n";
            }
            file_put_contents($path, $blob, FILE_APPEND | LOCK_EX);
            AtlasLoopMorningDigestService::trimJsonl($path, (int) config('atlas.loop.morning_digest.regression_triage_log_max_lines', 2000));
        } catch (\Throwable) {
            // Digest evidence is best-effort; the repair wire must never fail on logging.
        }
    }
}
