<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AtlasAobgWorkspaceOnboardingService;
use Illuminate\Console\Command;

/**
 * AOBG N1.F3 — `atlas:aobg:workspace`: the CLI mirror of the multi-project surface, for
 * testing + audit + operator onboarding. The gateway works in ANY project, AUTO-SCOPED.
 *
 *   atlas:aobg:workspace status  --cwd=/path/to/project --json
 *   atlas:aobg:workspace onboard --cwd=/path/to/project --json
 *   atlas:aobg:workspace activate --cwd=/path/to/project --json
 *   atlas:aobg:workspace activate-all --json
 *   atlas:aobg:workspace map      --workspace=/path/to/project --json
 *
 * `status` (default) answers HONESTLY what the brain knows about THIS project
 * ({workspace_id, indexed, symbols, last_index, needs_onboarding}) — read-only, local
 * DB, zero provider spend. `onboard` reports + OFFERS the index command; it only RUNS a
 * heavy index when auto-onboard is ON (config atlas.aobg.auto_onboard, default false) —
 * a heavy index of an arbitrary repo is an operator decision, never implicit.
 * `activate` is that explicit decision: bind AWIS, write provider bootstrap, then index.
 * `activate-all` applies that explicit activation to every registered local workspace.
 * `map` reads the bounded Code Intelligence inventory for one workspace.
 *
 * Always exits 0 — needs_onboarding / offer_only is a normal, audited answer, not a
 * command failure.
 */
class AtlasAobgWorkspaceCommand extends Command
{
    public const SCHEMA = 'atlas.aobg.workspace_command.v1';

    protected $signature = 'atlas:aobg:workspace
        {action=status : status (default), onboard, activate, activate-all, or map}
        {--cwd= : Caller working directory (the project dir) — resolved to a workspace id}
        {--workspace= : Workspace path or id (wins over cwd; defaults to the primary atlas-server)}
        {--force : For onboard/activate: re-run the index even when already indexed}
        {--limit=12 : For map: maximum sample rows per section}
        {--detail=summary : For map: summary (default, no samples) or samples}
        {--json : Output the result envelope as JSON}';

    protected $description = 'AOBG N1.F3: multi-project workspace status/onboarding/activation — does the brain know THIS project?';

    public function handle(AtlasAobgWorkspaceOnboardingService $service): int
    {
        $action = strtolower((string) $this->argument('action'));
        $opts = array_filter([
            'cwd' => $this->stringOpt('cwd'),
            'workspace' => $this->stringOpt('workspace'),
        ], static fn ($v): bool => $v !== null);

        if (in_array($action, ['activate-all', 'activate_all', 'all'], true)) {
            $opts['force'] = (bool) $this->option('force');
            $result = $service->activateAll($opts);
            $status = (array) ($result['status'] ?? []);
        } elseif ($action === 'activate') {
            $opts['force'] = (bool) $this->option('force');
            $result = $service->activate($opts);
            $status = (array) ($result['status'] ?? []);
        } elseif ($action === 'map') {
            $opts['limit'] = $this->option('limit');
            $opts['detail'] = $this->stringOpt('detail') ?? 'summary';
            $result = $service->map($opts);
            $status = $result;
        } elseif ($action === 'onboard') {
            $opts['force'] = (bool) $this->option('force');
            $result = $service->onboard($opts);
            $status = (array) ($result['status'] ?? []);
        } else {
            $result = $service->status($opts);
            $status = $result;
        }

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        $this->info(sprintf(
            'workspace=%s  indexed=%s  symbols=%d  needs_onboarding=%s  auto_onboard=%s',
            (string) ($status['workspace_id'] ?? 'n/a'),
            ($status['indexed'] ?? false) ? 'yes' : 'no',
            (int) ($status['symbols'] ?? 0),
            ($status['needs_onboarding'] ?? false) ? 'yes' : 'no',
            ($status['auto_onboard'] ?? false) ? 'on' : 'off',
        ));

        if (in_array($action, ['onboard', 'activate', 'activate-all', 'activate_all', 'all'], true)) {
            $this->line(sprintf(
                'action=%s  triggered_index=%s',
                (string) ($result['action'] ?? 'n/a'),
                ($result['triggered_index'] ?? false) ? 'yes' : 'no',
            ));
        }

        if (($status['needs_onboarding'] ?? false) === true) {
            $this->warn('offer: '.(string) ($status['onboard_command'] ?? 'n/a'));
        }
        if ($action === 'map') {
            $inventory = (array) ($result['inventory'] ?? []);
            $this->components->twoColumnDetail('modules', (string) ($inventory['module_count'] ?? 0));
            $this->components->twoColumnDetail('symbols', (string) ($inventory['symbol_count'] ?? 0));
            $this->components->twoColumnDetail('routes', (string) ($inventory['route_count'] ?? 0));
            $this->components->twoColumnDetail('commands', (string) ($inventory['command_count'] ?? 0));
            $this->components->twoColumnDetail('migrations', (string) ($inventory['migration_count'] ?? 0));
            $this->components->twoColumnDetail('tests', (string) ($inventory['test_count'] ?? 0));
        }

        return self::SUCCESS;
    }

    private function stringOpt(string $key): ?string
    {
        $value = $this->option($key);
        if (! is_string($value)) {
            return null;
        }
        $value = trim($value);

        return $value !== '' ? $value : null;
    }
}
