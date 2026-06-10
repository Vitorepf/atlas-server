<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\Mission\MissionCanonicalHash;
use Throwable;

/**
 * AP-786/AP-790 · Autonomous Evolution + 24h loop observability read model.
 *
 * Reads append-only session receipts ({@see AutonomousEvolutionSessionService})
 * and the AP-790 durable ledger ({@see Reliable24hLoopRunnerService}) so Product
 * Mode and CLI can review cycles, merges, blockers and health without opening JSONL.
 *
 * NEVER executes providers, materializes branches, merges or writes anything.
 * The optional backlog snapshot uses the same read-only deep finding engine that
 * AP-786 uses for candidate selection, so readiness cannot count rejected
 * structural findings as runnable work.
 */
final class AutonomousEvolutionSessionReadModelService
{
    public const SCHEMA = 'atlas.software_company_stewardship.autonomous_evolution_session_read_model.v1';

    public const OBSERVABILITY_SCHEMA = 'atlas.software_company_stewardship.loop_24h_observability.v1';

    public const DEFAULT_AREA_ID = 'agentic_engineering_os';

    public const DEFAULT_FOCUS = 'dev_forge';

    private ?string $storageDirOverride = null;

    public function __construct(
        private readonly Reliable24hLoopRunnerService $loopRunner,
        private readonly AutonomousLoopReceiptIntegrityService $receiptIntegrity,
        private readonly AreaFocusBranchSandboxMaterializerService $sandboxMaterializer,
        private readonly AreaFocusDeepFindingEngineService $findingEngine,
        private readonly AreaFocusCandidateQuarantineService $candidateQuarantine,
    ) {}

    public function setStorageRootForTesting(?string $dir): void
    {
        $this->storageDirOverride = $dir !== null ? rtrim($dir, DIRECTORY_SEPARATOR) : null;
        if ($dir !== null) {
            $root = rtrim($dir, DIRECTORY_SEPARATOR);
            $this->loopRunner->setStorageRootForTesting($root.'/reliable_24h_loop');
            $this->sandboxMaterializer->setStorageRootForTesting($root.'/area_focus_branch_sandboxes');
            $this->candidateQuarantine->setStorageRootForTesting($root.'/area_focus_candidate_quarantine');
        } else {
            $this->loopRunner->setStorageRootForTesting(null);
            $this->sandboxMaterializer->setStorageRootForTesting(null);
            $this->candidateQuarantine->setStorageRootForTesting(null);
        }
    }

    public function storageDir(): string
    {
        if ($this->storageDirOverride !== null) {
            return $this->storageDirOverride;
        }

        return function_exists('storage_path')
            ? storage_path('atlas/software_company_stewardship/autonomous_evolution_sessions')
            : sys_get_temp_dir().'/atlas/software_company_stewardship/autonomous_evolution_sessions';
    }

    public function recordPath(string $areaId): string
    {
        return $this->storageDir().DIRECTORY_SEPARATOR.AreaFocusSlugNormalizer::lowerFileToken($areaId, self::DEFAULT_AREA_ID).'.jsonl';
    }

    /**
     * Most-recent recorded sessions for an area, oldest-first within the window.
     *
     * @return list<array<string,mixed>>
     */
    public function listSessions(string $areaId, int $limit = 5): array
    {
        $limit = max(1, $limit);
        $path = $this->recordPath($areaId);
        if (! is_file($path)) {
            return [];
        }

        $records = [];
        foreach ($this->tailLines($path, $limit) as $line) {
            $decoded = json_decode($line, true);
            if (is_array($decoded)) {
                $records[] = $decoded;
            }
        }

        return $records;
    }

    /**
     * Read-only projection envelope (legacy session list).
     *
     * @return array<string,mixed>
     */
    public function project(string $areaId = self::DEFAULT_AREA_ID, int $limit = 5): array
    {
        $sessions = $this->listSessions($areaId, $limit);
        $cycles = $this->flattenCycles($sessions);

        return [
            'schema_version' => self::SCHEMA,
            'area_id' => AreaFocusSlugNormalizer::lowerFileToken($areaId, self::DEFAULT_AREA_ID),
            'read_only' => true,
            'session_count' => count($sessions),
            'cycles_total' => count($cycles),
            'sessions' => $sessions,
            'cycle_receipts' => $cycles,
            'claim_policy' => [
                'read_only' => true,
                'invokes_provider' => false,
                'mutates_repo' => false,
                'materializes_branch' => false,
                'performs_merge' => false,
                'no_test_doubles_at_runtime' => true,
            ],
            'generated_at' => AreaFocusUtcClock::atomNow(),
        ];
    }

    /**
     * AP-790/AP-786 24h observability aggregate for operator review.
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function project24hObservability(array $input = []): array
    {
        $areaId = AreaFocusSlugNormalizer::lowerFileToken((string) ($input['area_id'] ?? self::DEFAULT_AREA_ID), self::DEFAULT_AREA_ID);
        $focus = AreaFocusSlugNormalizer::lowerFileToken((string) ($input['focus'] ?? self::DEFAULT_FOCUS), self::DEFAULT_FOCUS);
        $sessionLimit = max(1, (int) ($input['session_limit'] ?? 10));
        $repoRoot = trim((string) ($input['repo_root'] ?? ''));
        if ($repoRoot === '' && function_exists('base_path')) {
            $repoRoot = base_path();
        }

        $sessions = $this->listSessions($areaId, $sessionLimit);
        $ledger = $this->loopRunner->readLedgerRecords($areaId, $focus);
        $cycles = $this->flattenCycles($sessions);
        $inboxSummaries = $this->cycleInboxSummaries($cycles);
        $metrics = $this->metrics($cycles, $ledger, $inboxSummaries);
        $worktrees = $this->activeWorktrees($areaId);
        $quarantined = $this->quarantinedFindingKeys($areaId, $focus);

        $payload = [
            'schema_version' => self::OBSERVABILITY_SCHEMA,
            'ap_contract' => 'AP-790',
            'area_id' => $areaId,
            'focus' => $focus,
            'read_only' => true,
            'metrics' => $metrics,
            'blocked_by_reason' => $metrics['blocked_by_reason'],
            'latest_commit' => $metrics['latest_commit'],
            'latest_inbox_item' => $metrics['latest_inbox_item'],
            'active_worktrees' => $worktrees,
            'quarantined_count' => count($quarantined),
            'quarantined_finding_keys' => array_values(array_keys($quarantined)),
            'cycle_inbox_summaries' => $inboxSummaries,
            'ledger' => [
                'path' => $this->loopRunner->ledgerPath($areaId, $focus),
                'record_count' => count($ledger),
                'records' => array_slice($ledger, -20),
            ],
            'lock' => $this->loopRunner->lockStatus($areaId, $focus),
            'kill_switch' => $this->loopRunner->killSwitchStatus($areaId, $focus),
            'backlog' => is_array($input['backlog_snapshot'] ?? null)
                ? $input['backlog_snapshot']
                : $this->backlogSnapshot($areaId, $repoRoot, $quarantined, $input),
            'sessions_inspected' => count($sessions),
            'claim_policy' => [
                'read_only' => true,
                'invokes_provider' => false,
                'mutates_repo' => false,
                'no_test_doubles_at_runtime' => true,
            ],
            'generated_at' => AreaFocusUtcClock::atomNow(),
        ];
        $payload['observability_hash'] = 'sha256:'.MissionCanonicalHash::sha256($this->identity($payload));

        return $payload;
    }

    /**
     * Per-cycle inbox summaries for the most recent real cycles.
     *
     * @return list<array<string,mixed>>
     */
    public function cycleInboxSummaries(array $cycles): array
    {
        $summaries = [];
        foreach ($cycles as $cycle) {
            if (! is_array($cycle)) {
                continue;
            }
            $finalStatus = (string) ($cycle['final_status'] ?? '');
            if ($finalStatus === '' || $finalStatus === 'dry_run_planned') {
                continue;
            }
            $summaries[] = $this->receiptIntegrity->cycleInboxSummary($cycle, [
                'session_id' => (string) ($cycle['_session_id'] ?? ''),
                'area_id' => (string) ($cycle['_area_id'] ?? self::DEFAULT_AREA_ID),
                'focus' => (string) ($cycle['_focus'] ?? self::DEFAULT_FOCUS),
            ]);
        }

        return $summaries;
    }

    /**
     * @param  list<array<string,mixed>>  $sessions
     * @return list<array<string,mixed>>
     */
    private function flattenCycles(array $sessions): array
    {
        $flat = [];
        foreach ($sessions as $session) {
            if (! is_array($session)) {
                continue;
            }
            $sessionId = (string) ($session['session_id'] ?? '');
            $areaId = (string) ($session['area_id'] ?? self::DEFAULT_AREA_ID);
            $focus = (string) ($session['focus'] ?? self::DEFAULT_FOCUS);
            foreach (AreaFocusLoopPayloadNormalizer::listOfArrays($session['cycles'] ?? []) as $cycle) {
                $cycle['_session_id'] = $sessionId;
                $cycle['_area_id'] = $areaId;
                $cycle['_focus'] = $focus;
                $cycle['_recorded_at'] = $this->sessionRecordedAt($session);
                $flat[] = $cycle;
            }
        }

        return $flat;
    }

    /**
     * @param  list<array<string,mixed>>  $cycles
     * @param  list<array<string,mixed>>  $ledger
     * @param  list<array<string,mixed>>  $inboxSummaries
     * @return array<string,mixed>
     */
    private function metrics(array $cycles, array $ledger, array $inboxSummaries): array
    {
        $cyclesTotal = max(count($cycles), count($ledger));
        $mergesTotal = 0;
        $blockedTotal = 0;
        $completedTotal = 0;
        $blockedByReason = [];
        $latestCommit = null;
        $latestInbox = null;

        foreach ($cycles as $cycle) {
            $merged = (bool) ($cycle['merge_performed'] ?? false);
            $finalStatus = (string) ($cycle['final_status'] ?? '');
            $blockers = AreaFocusStringListNormalizer::coercedStringValues($cycle['blockers'] ?? []);

            if ($merged) {
                $mergesTotal++;
            }
            if ($finalStatus === 'blocked' || $blockers !== []) {
                $blockedTotal++;
                foreach ($blockers as $reason) {
                    $blockedByReason[$reason] = ($blockedByReason[$reason] ?? 0) + 1;
                }
            }
            if (in_array($finalStatus, ['cycle_completed', 'cycle_completed_waiting_review_or_merge'], true)) {
                $completedTotal++;
            }

            $commitHash = (string) data_get($cycle, 'commit.commit_hash', '');
            if ($commitHash !== '') {
                $latestCommit = [
                    'commit_hash' => $commitHash,
                    'cycle_id' => (string) ($cycle['cycle_id'] ?? ''),
                    'session_id' => (string) ($cycle['_session_id'] ?? ''),
                    'recorded_at' => (string) ($cycle['_recorded_at'] ?? ''),
                ];
            }

            $inboxId = (string) ($cycle['inbox_item_id'] ?? '');
            if ($inboxId !== '') {
                $latestInbox = [
                    'inbox_item_id' => $inboxId,
                    'cycle_id' => (string) ($cycle['cycle_id'] ?? ''),
                    'session_id' => (string) ($cycle['_session_id'] ?? ''),
                ];
            }
        }

        if ($ledger !== []) {
            $last = $ledger[array_key_last($ledger)];
            $mergesTotal = max($mergesTotal, (int) data_get($last, 'cumulative.merges_total', $mergesTotal));
            $cyclesTotal = max($cyclesTotal, (int) ($last['cycle_index'] ?? $cyclesTotal));
        }

        ksort($blockedByReason);
        $successRate = 0.0;
        if ($cyclesTotal > 0) {
            $successRate = round(max(0, $cyclesTotal - $blockedTotal) / $cyclesTotal, 4);
        }

        return [
            'cycles_total' => $cyclesTotal,
            'cycles_completed' => $completedTotal,
            'merges_total' => $mergesTotal,
            'blocked_total' => $blockedTotal,
            'blocked_by_reason' => $blockedByReason,
            'success_rate' => $successRate,
            'merge_rate_per_hour' => $this->mergeRatePerHour($ledger, $mergesTotal),
            'avg_cycle_duration_seconds' => $this->avgCycleDurationSeconds($ledger),
            'latest_commit' => $latestCommit,
            'latest_inbox_item' => $latestInbox,
            'inbox_summary_count' => count($inboxSummaries),
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $ledger
     */
    private function mergeRatePerHour(array $ledger, int $mergesTotal): float
    {
        if ($mergesTotal === 0 || $ledger === []) {
            return 0.0;
        }

        $times = [];
        foreach ($ledger as $record) {
            $at = (string) ($record['recorded_at'] ?? '');
            if ($at !== '') {
                $times[] = strtotime($at) ?: 0;
            }
        }
        $times = array_values(array_filter($times, static fn (int $t): bool => $t > 0));
        if (count($times) < 2) {
            return (float) $mergesTotal;
        }

        $hours = max(1 / 3600, (max($times) - min($times)) / 3600);

        return round($mergesTotal / $hours, 4);
    }

    /**
     * @param  list<array<string,mixed>>  $ledger
     */
    private function avgCycleDurationSeconds(array $ledger): ?float
    {
        if (count($ledger) < 2) {
            return null;
        }

        $durations = [];
        $prev = null;
        foreach ($ledger as $record) {
            $at = strtotime((string) ($record['recorded_at'] ?? '')) ?: null;
            if ($at === null) {
                continue;
            }
            if ($prev !== null) {
                $durations[] = max(0, $at - $prev);
            }
            $prev = $at;
        }

        if ($durations === []) {
            return null;
        }

        return round(array_sum($durations) / count($durations), 2);
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function activeWorktrees(string $areaId): array
    {
        try {
            $list = $this->sandboxMaterializer->listSandboxes($areaId);
        } catch (Throwable) {
            return [];
        }

        $active = [];
        foreach ((array) ($list['sandboxes'] ?? []) as $sandbox) {
            if (! is_array($sandbox)) {
                continue;
            }
            if ((string) ($sandbox['lifecycle_state'] ?? '') === AreaFocusBranchSandboxMaterializerService::STATUS_CLEANED) {
                continue;
            }
            if ((string) ($sandbox['status'] ?? '') !== AreaFocusBranchSandboxMaterializerService::STATUS_MATERIALIZED) {
                continue;
            }
            $worktreePath = (string) ($sandbox['worktree_path'] ?? data_get($sandbox, 'materialization.worktree_path', ''));
            if ($worktreePath === '' || ! is_dir($worktreePath)) {
                continue;
            }
            $active[] = [
                'sandbox_id' => (string) ($sandbox['sandbox_id'] ?? ''),
                'branch_ref' => (string) ($sandbox['branch_ref'] ?? data_get($sandbox, 'materialization.branch_name', data_get($sandbox, 'branch_plan.branch_name', ''))),
                'worktree_path' => $worktreePath,
                'lifecycle_state' => (string) ($sandbox['lifecycle_state'] ?? ''),
                'recorded_at' => (string) ($sandbox['recorded_at'] ?? ''),
            ];
        }

        return $active;
    }

    /**
     * Review-locked / wasted-cycle finding keys (read-only mirror of AP-786 quarantine).
     *
     * @return array<string,true>
     */
    private function quarantinedFindingKeys(string $areaId, string $focus): array
    {
        $locked = $this->candidateQuarantine->quarantinedFindingKeys($areaId, $focus);
        $path = $this->recordPath($areaId);
        if (! is_file($path)) {
            return $locked;
        }

        foreach ($this->tailLines($path, 500) as $line) {
            $record = json_decode($line, true);
            if (! is_array($record)) {
                continue;
            }
            foreach ((array) ($record['cycles'] ?? []) as $cycle) {
                if (! is_array($cycle)) {
                    continue;
                }
                $status = (string) ($cycle['final_status'] ?? '');
                $blockers = AreaFocusStringListNormalizer::coercedStringValues($cycle['blockers'] ?? []);
                $wasted = array_intersect($blockers, [
                    'full_atlas_forge_flow_required',
                    'provider_produced_no_changes',
                    'commit_no_changes',
                    'validation_failed',
                ]) !== [];
                if ($status === 'cycle_completed' || $wasted || $status === 'blocked') {
                    foreach ($this->findingKeys((array) ($cycle['selected_finding'] ?? [])) as $key) {
                        $locked[$key] = true;
                    }
                }
            }
        }

        return $locked;
    }

    /**
     * Return the most recent non-empty JSONL lines without loading the full
     * append-only ledger into memory. Product Mode calls this while the 24h loop
     * is running, so `file()` is not acceptable once the ledger grows.
     *
     * @return list<string>
     */
    private function tailLines(string $path, int $limit, int $maxBytes = 8388608): array
    {
        $limit = max(1, $limit);
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            return [];
        }

        try {
            $chunkSize = 8192;
            $buffer = '';
            $position = filesize($path);
            if ($position === false || $position <= 0) {
                return [];
            }

            while ($position > 0 && substr_count($buffer, "\n") <= $limit && strlen($buffer) < $maxBytes) {
                $read = min($chunkSize, $position);
                $position -= $read;
                fseek($handle, $position);
                $chunk = fread($handle, $read);
                if ($chunk === false || $chunk === '') {
                    break;
                }
                $buffer = $chunk.$buffer;
            }

            $lines = preg_split('/\r\n|\r|\n/', $buffer) ?: [];
            $lines = array_values(array_filter($lines, static fn (string $line): bool => trim($line) !== ''));

            return array_slice($lines, -$limit);
        } finally {
            fclose($handle);
        }
    }

    /**
     * @param  array<string,mixed>  $finding
     * @return list<string>
     */
    private function findingKeys(array $finding): array
    {
        return AreaFocusStringListNormalizer::uniqueStringValues(array_filter([
            (string) ($finding['finding_id'] ?? ''),
            (string) ($finding['finding_hash'] ?? ''),
            (string) ($finding['title'] ?? ''),
        ], static fn (string $v): bool => $v !== ''));
    }

    /**
     * @param  array<string,true>  $quarantined
     * @return array<string,mixed>
     */
    /**
     * @param  array<string,mixed>  $input
     */
    private function backlogSnapshot(string $areaId, string $repoRoot, array $quarantined, array $input = []): array
    {
        try {
            $scanInput = array_merge([
                'area_id' => $areaId,
                'repo_root' => $repoRoot,
                'max_findings' => 50,
            ], is_array($input['finding_scan'] ?? null) ? $input['finding_scan'] : []);
            $scan = $this->findingEngine->scan($scanInput);
        } catch (Throwable $e) {
            return ['status' => 'unavailable', 'error' => $e->getMessage(), 'available_count' => 0];
        }

        $findings = AreaFocusLoopPayloadNormalizer::listOfArrays($scan['findings'] ?? []);
        $available = 0;
        foreach ($findings as $finding) {
            $keys = $this->findingKeys($finding);
            $locked = false;
            foreach ($keys as $key) {
                if (isset($quarantined[$key])) {
                    $locked = true;
                    break;
                }
            }
            if (! $locked) {
                $available++;
            }
        }

        return [
            'status' => (string) ($scan['status'] ?? 'unknown'),
            'finding_count' => count($findings),
            'available_count' => $available,
            'quarantined_excluded' => count($quarantined),
        ];
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function identity(array $payload): array
    {
        $copy = $payload;
        unset($copy['generated_at'], $copy['observability_hash']);

        return $copy;
    }

    /**
     * @param  array<string,mixed>  $session
     */
    private function sessionRecordedAt(array $session): string
    {
        $generatedAt = trim((string) ($session['generated_at'] ?? ''));
        if ($generatedAt !== '') {
            return $generatedAt;
        }

        return trim((string) ($session['recorded_at'] ?? ''));
    }
}
