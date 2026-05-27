<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\Reliable24hLoopRunnerService;
use Illuminate\Console\Command;

/**
 * AP-790 · reliable 24h autonomous loop runner CLI.
 *
 * Drives the real AP-786 autonomous evolution session in a durable, resilient loop
 * with locks, budgets, rate limit, pause/kill switch, crash recovery and an
 * append-only ledger. It never reimplements selection/execution/merge.
 */
final class AtlasSoftwareCompanyReliable24hLoopCommand extends Command
{
    protected $signature = 'atlas:software-company-stewardship:reliable-24h-loop
        {--area=agentic_engineering_os : Canonical area_id}
        {--focus=dev_forge : Area focus slice}
        {--scope-profile=factory_max : Selection scope profile: balanced or factory_max}
        {--provider=cursor_cli : Atlas provider driver passed to AP-786}
        {--model=composer-2.5-fast : Provider model passed to AP-786}
        {--repo-root= : Git repository root}
        {--actor=operator : Operator/session actor}
        {--max-runtime-minutes=1440 : Stop the loop after this many wall-clock minutes}
        {--max-cycles= : Stop after this many cycles this run (default: runtime/other budgets)}
        {--max-merges= : Stop after this many merges this run}
        {--max-blocked-in-row=3 : Stop after this many consecutive blocked cycles}
        {--sleep-seconds=0 : Rate limit: seconds to sleep between cycles}
        {--lock-lease-seconds=3600 : Exclusive lock lease TTL; stale locks expire for crash recovery}
        {--execute : Drive AP-786 in execute mode (real sandbox/provider/commit/merge via AP-786)}
        {--auto-merge : Ask AP-769/AP-774 ff-only merge of eligible branches (via AP-786)}
        {--allow-code-auto-merge : Allow AP-774 bugfix/cleanup code auto-merge when validation passes}
        {--allow-direct-provider-driver : Legacy diagnostic only; forwarded to AP-786}
        {--continue-on-blocked : Keep looping when a cycle is blocked or waiting review}
        {--pull-main : Let AP-786 pull/update main after a successful merge}
        {--cleanup-worktrees : Safe cleanup of clean, merged sandbox worktrees after a merge (AP-756)}
        {--record : Append AP-786 session receipts}
        {--dry-run : Plan only; forces AP-786 execute=false (no provider/branch/commit/merge)}
        {--json : Emit JSON}';

    protected $description = 'AP-790 · reliable 24h autonomous loop runner wrapping AP-786 (locks, budgets, pause/kill, crash recovery, append-only ledger).';

    public function handle(Reliable24hLoopRunnerService $runner): int
    {
        $payload = $runner->run([
            'area_id' => (string) $this->option('area'),
            'focus' => (string) $this->option('focus'),
            'scope_profile' => (string) $this->option('scope-profile'),
            'provider' => (string) $this->option('provider'),
            'model' => (string) ($this->option('model') ?: ''),
            'repo_root' => (string) ($this->option('repo-root') ?: ''),
            'actor' => (string) $this->option('actor'),
            'max_runtime_minutes' => (int) $this->option('max-runtime-minutes'),
            'max_cycles' => $this->option('max-cycles'),
            'max_merges' => $this->option('max-merges'),
            'max_blocked_in_row' => (int) $this->option('max-blocked-in-row'),
            'sleep_seconds' => (int) $this->option('sleep-seconds'),
            'lock_lease_seconds' => (int) $this->option('lock-lease-seconds'),
            'execute' => (bool) $this->option('execute'),
            'auto_merge' => (bool) $this->option('auto-merge'),
            'allow_code_auto_merge' => (bool) $this->option('allow-code-auto-merge'),
            'allow_direct_provider_driver' => (bool) $this->option('allow-direct-provider-driver'),
            'continue_on_blocked' => (bool) $this->option('continue-on-blocked'),
            'pull_main' => (bool) $this->option('pull-main'),
            'cleanup_worktrees' => (bool) $this->option('cleanup-worktrees'),
            'record' => (bool) $this->option('record'),
            'dry_run' => (bool) $this->option('dry-run'),
        ]);

        $this->emit($payload);

        return in_array((string) ($payload['status'] ?? ''), [
            Reliable24hLoopRunnerService::STATUS_LOCK_HELD,
        ], true) ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function emit(array $payload): void
    {
        if ((bool) $this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return;
        }

        $this->components->twoColumnDetail('AP-790 reliable 24h loop', (string) ($payload['status'] ?? 'unknown'));
        $this->components->twoColumnDetail('Area / focus', (string) ($payload['area_id'] ?? '').' / '.(string) ($payload['focus'] ?? ''));
        $this->components->twoColumnDetail('Mode', (string) ($payload['mode'] ?? ''));
        $this->components->twoColumnDetail('Stop reason', (string) ($payload['stop_reason'] ?? ''));
        $this->components->twoColumnDetail('Cycles this run', (string) ($payload['cycles_this_run'] ?? 0));
        $this->components->twoColumnDetail('Resumed from', (string) ($payload['resumed_from_cycle_index'] ?? 0));
        $this->components->twoColumnDetail('Merges', (string) ($payload['merges_total'] ?? 0));
        $this->components->twoColumnDetail('Ledger', (string) ($payload['ledger_path'] ?? ''));
        foreach ((array) ($payload['next_actions'] ?? []) as $action) {
            $this->line('  next: '.(string) $action);
        }
    }
}
