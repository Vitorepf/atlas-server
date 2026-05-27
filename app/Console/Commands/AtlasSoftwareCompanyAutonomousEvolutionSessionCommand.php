<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AutonomousEvolutionSessionService;
use Illuminate\Console\Command;

final class AtlasSoftwareCompanyAutonomousEvolutionSessionCommand extends Command
{
    protected $signature = 'atlas:software-company-stewardship:autonomous-evolution-session
        {--area=agentic_engineering_os : Canonical area_id}
        {--focus=dev_forge : Area focus slice}
        {--cycles=1 : Maximum cycles for this invocation}
        {--provider=cursor_cli : Atlas provider driver}
        {--model= : Provider model; defaults to cursor_cli configured model}
        {--repo-root= : Git repository root}
        {--actor=operator : Operator/session actor}
        {--execute : Actually materialize sandbox, call provider, commit, emit inbox and evaluate merge}
        {--auto-merge : Ask AP-769/AP-774 to ff-only merge eligible branches}
        {--allow-code-auto-merge : Allow AP-774 bugfix/cleanup code auto-merge when validation passes}
        {--pull-main : Pull/update main after a successful merge}
        {--record : Append AP-786 session receipt}
        {--max-findings=40 : Maximum findings to scan before priority ranking}
        {--max-auto-merge-files=5 : AP-769 max changed files for auto-merge}
        {--validation-command=* : Validation command(s) run in sandbox; default git diff --check}
        {--json : Emit JSON}';

    protected $description = 'AP-786 · run an Atlas-owned autonomous evolution session with Cursor CLI, Inbox, governed ff-only merge and loop continuation.';

    public function handle(AutonomousEvolutionSessionService $service): int
    {
        $payload = $service->run([
            'area_id' => (string) $this->option('area'),
            'focus' => (string) $this->option('focus'),
            'cycles' => (int) $this->option('cycles'),
            'provider' => (string) $this->option('provider'),
            'model' => (string) ($this->option('model') ?: ''),
            'repo_root' => (string) ($this->option('repo-root') ?: ''),
            'actor' => (string) $this->option('actor'),
            'execute' => (bool) $this->option('execute'),
            'auto_merge' => (bool) $this->option('auto-merge'),
            'allow_code_auto_merge' => (bool) $this->option('allow-code-auto-merge'),
            'pull_main' => (bool) $this->option('pull-main'),
            'record' => (bool) $this->option('record'),
            'max_findings' => (int) $this->option('max-findings'),
            'max_auto_merge_files' => (int) $this->option('max-auto-merge-files'),
            'validation_commands' => array_values(array_filter((array) $this->option('validation-command'), 'is_string')),
        ]);

        if ((bool) $this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return ($payload['status'] ?? '') === AutonomousEvolutionSessionService::STATUS_BLOCKED
                ? self::FAILURE
                : self::SUCCESS;
        }

        $this->components->twoColumnDetail('AP-786 session', (string) ($payload['status'] ?? 'unknown'));
        $this->components->twoColumnDetail('Session', (string) ($payload['session_id'] ?? ''));
        $this->components->twoColumnDetail('Provider', (string) ($payload['provider'] ?? '').' / '.(string) ($payload['model'] ?? ''));
        $this->components->twoColumnDetail('Cycles', (string) ($payload['cycles_completed'] ?? 0).' completed / '.(string) ($payload['cycles_attempted'] ?? 0).' attempted');

        foreach ((array) ($payload['cycles'] ?? []) as $cycle) {
            $this->line(sprintf(
                '  #%d %s · %s · branch=%s · inbox=%s · merged=%s',
                (int) ($cycle['cycle_index'] ?? 0),
                (string) ($cycle['final_status'] ?? ''),
                (string) data_get($cycle, 'selected_finding.title', ''),
                (string) ($cycle['branch_ref'] ?? ''),
                (string) ($cycle['inbox_item_id'] ?? ''),
                ((bool) ($cycle['merge_performed'] ?? false)) ? 'yes' : 'no',
            ));
        }
        foreach ((array) ($payload['blockers'] ?? []) as $blocker) {
            $this->warn('  blocker: '.(string) $blocker);
        }

        return ($payload['status'] ?? '') === AutonomousEvolutionSessionService::STATUS_BLOCKED
            ? self::FAILURE
            : self::SUCCESS;
    }
}
