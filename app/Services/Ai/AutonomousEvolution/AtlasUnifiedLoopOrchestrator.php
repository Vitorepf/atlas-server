<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution;

use App\Services\Ai\AutonomousEvolution\Verify\AtlasEngineeringHonestyGate;
use App\Services\Ai\AutonomousEvolution\Verify\AtlasP3FindingDispatcher;

/**
 * THE UNIFIED LOOP — one durable, propose-only supervisor over every verifier-backed
 * evolution mode, each held to its honest ceiling, with one shared proposal queue and
 * one visible report.
 *
 * It is the orchestrator that makes the operator's four points interlink:
 *   • dead-code (P1/P3) — remove unused private members, frozen by an AST verifier;
 *   • docs-structure (P2) — complete canonical-module docs, frozen by the doc linter;
 *   • fake-implemented (P3) — phantom App\ class / artisan command claims a doc makes:
 *       DISCOVERY + a FLAGGED backlog routed to a human / the Forge arm (never auto-edited,
 *       because the fix is a judgment call);
 *   • the code campaign (P1/P4) — the generated-RED-test loop runs as a sibling and its
 *       status is folded into the same dashboard.
 *
 * Every winner the per-scenario frozen judge accepts must ALSO clear the engineering
 * honesty gate (a holdout the candidate never optimized against) before it is recorded
 * as certified-for-review. Nothing is ever merged — three layers below the loop forbid
 * it; this orchestrator only ever proposes. A drained queue with proposals waiting for a
 * human is the system working, not a failure.
 */
final class AtlasUnifiedLoopOrchestrator
{
    public const SCHEMA = 'atlas.loop.unified_run.v1';

    /** Modes whose fix is behavior-free / structurally checkable → safe to grind autonomously. */
    private const AUTO_MODES = ['deadcode', 'docs_structure'];

    public function __construct(
        private readonly AtlasP3FindingDispatcher $dispatcher,
        private readonly AtlasEvolutionLoopRunner $runner,
        private readonly AtlasEngineeringHonestyGate $gate,
        private readonly AtlasLoopResourceGate $resourceGate,
        private readonly AtlasLoopProposalDiffReconstructor $diffReconstructor,
        private readonly AtlasLoopIntelligenceOverlay $intelligence,
    ) {}

    /**
     * The durable 24h driver. Loops cycles until the time budget, a kill-switch, or (for a
     * bounded run) the queue drains and no new findings appear. Between sweeps it idles and
     * re-scans — propose-only means findings persist until a human applies them, so the loop
     * surfaces everything once, then watches for new work.
     *
     * @param  array{
     *     modes?:list<string>, provider?:?string, scenarios_per_task?:int, max_per_cycle?:int,
     *     max_seconds?:int, idle_seconds?:int, once?:bool, run_id?:string,
     *     code_roots?:list<string>, docs_roots?:list<string>, max_deadcode?:int
     * }  $opts
     * @return array<string,mixed>
     */
    public function run(string $repoRoot, array $opts = []): array
    {
        // A 24h loop is a long-running CLI process; the 128M CLI default OOMs after a few
        // cycles of subprocess output + AST parsing. Raise it generously and run PHP's cycle
        // collector after each grind to keep the parent bounded (see runCycle).
        @ini_set('memory_limit', (string) config('atlas.loop.unified.memory_limit', '1536M'));

        $repoRoot = rtrim($repoRoot, '/');
        $modes = $opts['modes'] ?? self::AUTO_MODES;
        $provider = $opts['provider'] ?? (string) config('atlas.loop.default_provider', 'hermes_cli');
        $scenarios = max(1, (int) ($opts['scenarios_per_task'] ?? 2));
        $maxPerCycle = max(1, (int) ($opts['max_per_cycle'] ?? 6));
        $maxSeconds = max(0, (int) ($opts['max_seconds'] ?? 0));
        $idleSeconds = max(5, (int) ($opts['idle_seconds'] ?? 60));
        $once = (bool) ($opts['once'] ?? false);

        $runId = (string) ($opts['run_id'] ?? ('run-'.date('Ymd-His').'-'.bin2hex(random_bytes(3))));
        $runDir = $this->runDir($runId);
        @mkdir($runDir, 0o755, true);
        $stopFile = $this->stopFile();

        $start = microtime(true);
        $state = $this->loadState($runDir);
        $state['run_id'] = $runId;
        $state['provider'] = $provider;
        $state['modes'] = array_values($modes);
        $state['started_at'] ??= time();
        if (isset($opts['code_roots'])) {
            $state['code_roots'] = $opts['code_roots'];
        }
        if (isset($opts['docs_roots'])) {
            $state['docs_roots'] = $opts['docs_roots'];
        }
        if (isset($opts['max_deadcode'])) {
            $state['max_deadcode'] = (int) $opts['max_deadcode'];
        }

        // Boot reap: clear crash-orphaned loop/scenario/reconstruction temp dirs the happy-path
        // finally{} blocks can never catch (SIGKILL/OOM/reboot). Without this a 24h run that
        // crashes repeatedly leaks /tmp unbounded — the unified path had no reaper before.
        $orphanTtl = (int) config('atlas.loop.campaign.orphan_ttl_seconds', 1800);
        $this->reap($orphanTtl);

        $stopReason = 'completed';
        $cycle = 0;
        while (true) {
            if (is_file($stopFile)) {
                $stopReason = 'kill_switch';
                break;
            }
            if ($maxSeconds > 0 && (microtime(true) - $start) >= $maxSeconds) {
                $stopReason = 'time_budget_reached';
                break;
            }

            $cycle++;
            $cycleResult = $this->runCycle($repoRoot, $modes, $provider, $scenarios, $maxPerCycle, $runDir, $state, $start, $maxSeconds, $stopFile);
            $state['cycles'] = $cycle;
            $this->persistState($runDir, $state);
            $this->writeReport($runDir, $repoRoot, $state, $start, 'running');
            $this->heartbeat($runDir, $cycle, $cycleResult);

            if ($cycleResult['stopped'] === 'kill_switch') {
                $stopReason = 'kill_switch';
                break;
            }
            if ($once) {
                $stopReason = 'once';
                break;
            }
            // Drained sweep: nothing new attempted this cycle → idle then re-scan (watch mode).
            if ($cycleResult['attempted'] === 0) {
                if ($maxSeconds === 0) {
                    $stopReason = 'drained';
                    break; // no time budget → finish after a full clean sweep
                }
                $this->reap($orphanTtl); // periodic reap on the long-running watch path
                $this->idle($idleSeconds, $start, $maxSeconds, $stopFile);
            }
        }

        $report = $this->writeReport($runDir, $repoRoot, $state, $start, $stopReason);

        return [
            'schema_version' => self::SCHEMA,
            'run_id' => $runId,
            'run_dir' => $runDir,
            'merged_to_main' => false,
            'stop_reason' => $stopReason,
            'cycles' => $cycle,
            'elapsed_seconds' => round(microtime(true) - $start, 1),
            'report' => $report,
        ];
    }

    /**
     * One sweep: scan, then grind+gate up to $maxPerCycle previously-unseen auto-loop
     * findings, refreshing the flagged backlog. Returns what happened this cycle.
     *
     * @param  list<string>  $modes
     * @param  array<string,mixed>  $state
     * @return array{attempted:int, certified:int, gate_rejected:int, reconstruction_failed:int, no_winner:int, stopped:?string}
     */
    private function runCycle(
        string $repoRoot,
        array $modes,
        string $provider,
        int $scenarios,
        int $maxPerCycle,
        string $runDir,
        array &$state,
        float $start,
        int $maxSeconds,
        string $stopFile,
    ): array {
        $scanOpts = ['max_deadcode' => (int) ($state['max_deadcode'] ?? 50)];
        if (! empty($state['code_roots'])) {
            $scanOpts['code_roots'] = $state['code_roots'];
        }
        if (! empty($state['docs_roots'])) {
            $scanOpts['docs_roots'] = $state['docs_roots'];
        }
        $scan = $this->dispatcher->scan($repoRoot, $scanOpts);
        $intelligence = $this->intelligence->overlay($repoRoot, $runDir, [
            'flags' => $scan['flags'],
            'summary' => $scan['summary'],
        ], $state);

        // Refresh the flagged backlog (discovery is always-on, even when grinding is drained).
        $this->writeJson($runDir.'/backlog.json', [
            'updated_at' => time(),
            'flags' => $intelligence['prioritized_flags'],
            'summary' => $scan['summary'],
            'intelligence' => $intelligence,
        ]);
        $state['last_scan_summary'] = array_merge($scan['summary'], [
            'top_impact_score' => data_get($intelligence, 'summary.top_impact_score', 0),
            'feedback_count' => data_get($intelligence, 'summary.feedback_count', 0),
            'cross_domain_slots' => data_get($intelligence, 'summary.cross_domain_slots', 0),
        ]);

        $seen = $state['seen'] ?? [];
        $attempted = 0;
        $certified = 0;
        $gateRejected = 0;
        $reconstructionFailed = 0;
        $noWinner = 0;
        $stopped = null;

        foreach ($scan['auto_loop'] as $finding) {
            if (! in_array($finding['mode'], $modes, true)) {
                continue;
            }
            if (isset($seen[$finding['id']])) {
                continue; // already attempted this run (propose-only ⇒ finding persists)
            }
            if ($attempted >= $maxPerCycle) {
                break;
            }
            if (is_file($stopFile)) {
                $stopped = 'kill_switch';
                break;
            }
            if ($maxSeconds > 0 && (microtime(true) - $start) >= $maxSeconds) {
                $stopped = 'time_budget';
                break;
            }

            $outcome = $this->grindAndGate($repoRoot, $finding, $provider, $scenarios);
            $attempted++;
            $seen[$finding['id']] = ['mode' => $finding['mode'], 'path' => $finding['path'], 'at' => time(), 'outcome' => $outcome['outcome']];

            match ($outcome['outcome']) {
                'certified' => $certified++,
                'gate_rejected' => $gateRejected++,
                'reconstruction_failed' => $reconstructionFailed++,
                default => $noWinner++,
            };

            $this->appendJsonl($runDir.'/'.($outcome['outcome'] === 'certified' ? 'proposals.jsonl' : 'rejected.jsonl'), $outcome['record']);
            $state['seen'] = $seen;
            $state['totals'] = $this->bumpTotals($state['totals'] ?? [], $outcome['outcome'], $finding['mode'], $outcome['scenarios_explored']);
            $state['cycles'] = (int) ($state['cycles'] ?? 0);
            $this->persistState($runDir, $state);
            // Live dashboard: refresh after EVERY grind so the report is never stale mid-cycle.
            $this->writeReport($runDir, $repoRoot, $state, $start, 'running');
            // Reclaim php-parser ASTs + subprocess buffers so the long-running parent stays bounded.
            gc_collect_cycles();
        }

        return ['attempted' => $attempted, 'certified' => $certified, 'gate_rejected' => $gateRejected, 'reconstruction_failed' => $reconstructionFailed, 'no_winner' => $noWinner, 'stopped' => $stopped];
    }

    /**
     * Grind ONE finding through the frozen-judge loop, then holdout it through the
     * engineering honesty gate. Returns a normalized outcome record.
     *
     * @param  array<string,mixed>  $finding
     * @return array{outcome:string, scenarios_explored:int, record:array<string,mixed>}
     */
    private function grindAndGate(string $repoRoot, array $finding, string $provider, int $scenarios): array
    {
        $built = $finding['mode'] === 'deadcode'
            ? $this->dispatcher->toDeadCodeTask($finding, $repoRoot, $provider)
            : $this->dispatcher->toDocStructureTask($finding, $repoRoot, $provider);

        if ($built === null) {
            return $this->outcome('no_winner', 0, $finding, ['reason' => 'task_build_failed']);
        }

        $task = $built['task'];
        $cleanup = $built['cleanup'];
        try {
            $run = $this->runner->run([$task], ['scenarios_per_task' => $scenarios, 'max_tasks' => 1, 'propose_only' => true]);
        } finally {
            $cleanup();
        }

        $exploration = $run['explorations'][0] ?? [];
        $scenariosExplored = (int) ($exploration['scenarios_explored'] ?? 0);
        $proposal = $run['proposals'][0] ?? null;

        if ($proposal === null) {
            return $this->outcome('no_winner', $scenariosExplored, $finding, [
                'reason' => 'no_scenario_passed_frozen_judge',
                'rejected_reasons' => $exploration['rejected_reasons'] ?? [],
            ]);
        }

        // HONESTY-GATE HOLDOUT — reconstruct the proposed content from the diff and prove the
        // change is safe against checks the candidate never optimized against.
        $originRel = (string) ($finding['path'] ?? '');
        $originalContent = (string) @file_get_contents($repoRoot.'/'.$originRel);
        $reconstructed = $this->diffReconstructor->reconstruct($originalContent, (string) $proposal['diff_text'], $finding['mode'] === 'deadcode' ? 'target.php' : 'target.md');
        $proposedContent = $reconstructed['ok'] ? $reconstructed['content'] : null;

        if ($proposedContent === null) {
            // Distinct from a gate veto: the diff did not apply (stale base / truncated). It fails
            // CLOSED (no content produced), but it is a liveness signal, NOT an honesty catch — so
            // it must not inflate the "held honest" counter.
            return $this->outcome('reconstruction_failed', $scenariosExplored, $finding, [
                'reason' => $reconstructed['reason'] ?? 'could_not_reconstruct_proposed_content',
                'proposal_hash' => $proposal['proposal_hash'] ?? null,
            ], $proposal);
        }

        $verdict = $finding['mode'] === 'deadcode'
            ? $this->gate->evaluateDeadCodeRemoval($repoRoot, $originRel, $originalContent, $proposedContent, $finding['dead_members'] ?? [])
            : $this->gate->evaluateDocEdit($originRel, $originalContent, $proposedContent);

        if (! $verdict['certified']) {
            return $this->outcome('gate_rejected', $scenariosExplored, $finding, [
                'reason' => 'honesty_gate_rejected',
                'gate_reasons' => $verdict['reasons'],
                'gate_report' => $verdict['report'],
            ], $proposal);
        }

        return $this->outcome('certified', $scenariosExplored, $finding, [
            'gate_reasons' => $verdict['reasons'],
            'gate_report' => $verdict['report'],
        ], $proposal);
    }

    /**
     * @param  array<string,mixed>  $finding
     * @param  array<string,mixed>  $extra
     * @param  array<string,mixed>|null  $proposal
     * @return array{outcome:string, scenarios_explored:int, record:array<string,mixed>}
     */
    private function outcome(string $outcome, int $scenarios, array $finding, array $extra, ?array $proposal = null): array
    {
        $record = [
            'at' => time(),
            'outcome' => $outcome,
            'mode' => $finding['mode'],
            'path' => $finding['path'] ?? '',
            'finding' => $finding['detail'] ?? '',
            'scenarios_explored' => $scenarios,
        ] + $extra;
        if ($proposal !== null) {
            $record['proposal'] = $proposal;
        }

        return ['outcome' => $outcome, 'scenarios_explored' => $scenarios, 'record' => $record];
    }

    /**
     * @param  array<string,mixed>  $totals
     * @return array<string,mixed>
     */
    private function bumpTotals(array $totals, string $outcome, string $mode, int $scenarios): array
    {
        $totals['attempted'] = (int) ($totals['attempted'] ?? 0) + 1;
        $totals[$outcome] = (int) ($totals[$outcome] ?? 0) + 1;
        $totals['scenarios_explored'] = (int) ($totals['scenarios_explored'] ?? 0) + $scenarios;
        $byMode = $totals['by_mode'][$mode] ?? ['attempted' => 0, 'certified' => 0, 'gate_rejected' => 0, 'reconstruction_failed' => 0, 'no_winner' => 0];
        $byMode['attempted']++;
        $byMode[$outcome] = (int) ($byMode[$outcome] ?? 0) + 1;
        $totals['by_mode'][$mode] = $byMode;

        return $totals;
    }

    /**
     * Build the visible utilization + performance dashboard.
     *
     * @param  array<string,mixed>  $state
     * @return array<string,mixed>
     */
    public function writeReport(string $runDir, string $repoRoot, array $state, float $start, string $status): array
    {
        $totals = $state['totals'] ?? [];
        $attempted = (int) ($totals['attempted'] ?? 0);
        $certified = (int) ($totals['certified'] ?? 0);
        $yield = $attempted > 0 ? round($certified / $attempted, 4) : 0.0;

        $report = [
            'schema_version' => self::SCHEMA.'.report',
            'run_id' => $state['run_id'] ?? '',
            'status' => $status,
            'merged_to_main' => false,
            'provider' => $state['provider'] ?? '',
            'modes' => $state['modes'] ?? [],
            'started_at' => $state['started_at'] ?? null,
            'elapsed_seconds' => round(microtime(true) - $start, 1),
            'cycles' => (int) ($state['cycles'] ?? 0),
            'utilization' => [
                'tasks_attempted' => $attempted,
                'certified_for_review' => $certified,
                'gate_rejected' => (int) ($totals['gate_rejected'] ?? 0),
                'reconstruction_failed' => (int) ($totals['reconstruction_failed'] ?? 0),
                'no_winner' => (int) ($totals['no_winner'] ?? 0),
                'yield' => $yield, // aproveitamento: certified / attempted
                'scenarios_explored' => (int) ($totals['scenarios_explored'] ?? 0),
                'by_mode' => $totals['by_mode'] ?? [],
            ],
            'backlog' => $this->backlogSummary($runDir),
            'intelligence' => $this->intelligenceSummary($runDir),
            'independent_verification' => $this->independentVerificationSummary($runDir),
            'last_scan' => $state['last_scan_summary'] ?? null,
            'code_campaign' => $this->codeCampaignStatus(),
        ];
        $this->writeJson($runDir.'/report.json', $report);

        return $report;
    }

    /**
     * @return array<string,mixed>
     */
    private function backlogSummary(string $runDir): array
    {
        $backlog = $this->readJson($runDir.'/backlog.json');
        $flags = is_array($backlog['flags'] ?? null) ? $backlog['flags'] : [];
        $byMode = [];
        foreach ($flags as $flag) {
            $mode = (string) ($flag['mode'] ?? 'unknown');
            $byMode[$mode] = ($byMode[$mode] ?? 0) + 1;
        }

        return [
            'flagged_items' => count($flags),
            'flagged_docs' => count($flags), // compatibility key for older dashboards
            'flagged_phantoms' => array_sum(array_map(
                static fn ($f): int => ($f['mode'] ?? null) === 'fake_implemented' ? (int) ($f['count'] ?? 0) : 0,
                $flags,
            )),
            'by_mode' => $byMode,
            'top' => array_slice(array_map(static fn ($f): array => [
                'path' => $f['path'] ?? '',
                'mode' => $f['mode'] ?? '',
                'count' => $f['count'] ?? 0,
                'route' => $f['route'] ?? '',
            ], $flags), 0, 10),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function independentVerificationSummary(string $runDir): array
    {
        $summary = $this->readJson($runDir.'/independent_verification_summary.json');

        return [
            'independently_verified' => $this->jsonlCount($runDir.'/independently_verified.jsonl'),
            'refuted' => $this->jsonlCount($runDir.'/refuted.jsonl'),
            'last_summary' => $summary === [] ? null : $summary,
        ];
    }

    /**
     * @return array<string,mixed>|null
     */
    private function intelligenceSummary(string $runDir): ?array
    {
        $backlog = $this->readJson($runDir.'/backlog.json');
        $intelligence = $backlog['intelligence'] ?? null;
        if (! is_array($intelligence)) {
            return null;
        }

        return [
            'schema_version' => $intelligence['schema_version'] ?? AtlasLoopIntelligenceOverlay::SCHEMA,
            'summary' => $intelligence['summary'] ?? [],
            'learning' => $intelligence['learning'] ?? [],
            'provider_matrix' => $intelligence['provider_matrix'] ?? [],
            'cross_domain_slots' => $intelligence['cross_domain_slots'] ?? [],
            'meta_clusters' => array_slice((array) ($intelligence['meta_clusters'] ?? []), 0, 10),
        ];
    }

    /**
     * Fold the sibling code campaign (P1/P4 generated-test loop) into the same dashboard.
     *
     * @return array<string,mixed>|null
     */
    private function codeCampaignStatus(): ?array
    {
        try {
            $campaign = \App\Models\AtlasLoopCampaign::query()->orderByDesc('created_at')->first();
            if ($campaign === null) {
                return null;
            }

            return [
                'campaign_id' => (string) $campaign->id,
                'status' => (string) $campaign->status,
                'provider' => (string) ($campaign->provider ?: '(loop default)'),
                'tasks_processed' => (int) $campaign->tasks_processed,
                'proposals_certified' => (int) $campaign->proposals_count,
                'elapsed_seconds' => (int) $campaign->elapsed_seconds,
                'merged_to_main' => false,
            ];
        } catch (\Throwable) {
            // campaign tables unavailable — the unified loop stands alone
        }

        return null;
    }

    private function idle(int $seconds, float $start, int $maxSeconds, string $stopFile): void
    {
        $deadline = microtime(true) + $seconds;
        while (microtime(true) < $deadline) {
            if (is_file($stopFile)) {
                return;
            }
            if ($maxSeconds > 0 && (microtime(true) - $start) >= $maxSeconds) {
                return;
            }
            usleep(500_000);
        }
    }

    /** Reap crash-orphaned loop/scenario/reconstruction temp dirs older than the TTL (best effort). */
    private function reap(int $ttlSeconds): void
    {
        try {
            $this->resourceGate->sweepOrphans(sys_get_temp_dir(), $ttlSeconds);
        } catch (\Throwable) {
            // best effort — never let reaping crash the loop
        }
    }

    private function heartbeat(string $runDir, int $cycle, array $cycleResult): void
    {
        $this->writeJson($runDir.'/heartbeat.json', [
            'at' => time(),
            'cycle' => $cycle,
            'last_cycle' => $cycleResult,
        ]);
    }

    // ---- persistence helpers ----

    private function runDir(string $runId): string
    {
        return storage_path('atlas/loop/unified/'.$runId);
    }

    private function stopFile(): string
    {
        return storage_path('atlas/loop/unified/STOP');
    }

    /**
     * @return array<string,mixed>
     */
    private function loadState(string $runDir): array
    {
        return $this->readJson($runDir.'/state.json');
    }

    /**
     * @param  array<string,mixed>  $state
     */
    private function persistState(string $runDir, array $state): void
    {
        $this->writeJson($runDir.'/state.json', $state);
    }

    /**
     * @param  array<string,mixed>  $data
     */
    private function writeJson(string $path, array $data): void
    {
        @mkdir(dirname($path), 0o755, true);
        file_put_contents($path, (string) json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /**
     * @param  array<string,mixed>  $record
     */
    private function appendJsonl(string $path, array $record): void
    {
        @mkdir(dirname($path), 0o755, true);
        file_put_contents($path, json_encode($record, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).PHP_EOL, FILE_APPEND);
    }

    /**
     * @return array<string,mixed>
     */
    private function readJson(string $path): array
    {
        if (! is_file($path)) {
            return [];
        }
        $decoded = json_decode((string) @file_get_contents($path), true);

        return is_array($decoded) ? $decoded : [];
    }

    private function jsonlCount(string $path): int
    {
        if (! is_file($path)) {
            return 0;
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        return is_array($lines) ? count($lines) : 0;
    }
}
