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
 *
 * `status` (default) answers HONESTLY what the brain knows about THIS project
 * ({workspace_id, indexed, symbols, last_index, needs_onboarding}) — read-only, local
 * DB, zero provider spend. `onboard` reports + OFFERS the index command; it only RUNS a
 * heavy index when auto-onboard is ON (config atlas.aobg.auto_onboard, default false) —
 * a heavy index of an arbitrary repo is an operator decision, never implicit.
 *
 * Always exits 0 — needs_onboarding / offer_only is a normal, audited answer, not a
 * command failure.
 */
class AtlasAobgWorkspaceCommand extends Command
{
    public const SCHEMA = 'atlas.aobg.workspace_command.v1';

    protected $signature = 'atlas:aobg:workspace
        {action=status : status (default) or onboard}
        {--cwd= : Caller working directory (the project dir) — resolved to a workspace id}
        {--workspace= : Workspace path or id (wins over cwd; defaults to the primary atlas-server)}
        {--force : For onboard: re-run the index even when already indexed (still gated by auto_onboard)}
        {--json : Output the result envelope as JSON}';

    protected $description = 'AOBG N1.F3: multi-project workspace status/onboarding — does the brain know THIS project? (auto-scoped, honest, gated onboarding).';

    public function handle(AtlasAobgWorkspaceOnboardingService $service): int
    {
        $action = strtolower((string) $this->argument('action'));
        $opts = array_filter([
            'cwd' => $this->stringOpt('cwd'),
            'workspace' => $this->stringOpt('workspace'),
        ], static fn ($v): bool => $v !== null);

        if ($action === 'onboard') {
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

        if ($action === 'onboard') {
            $this->line(sprintf(
                'action=%s  triggered_index=%s',
                (string) ($result['action'] ?? 'n/a'),
                ($result['triggered_index'] ?? false) ? 'yes' : 'no',
            ));
        }

        if (($status['needs_onboarding'] ?? false) === true) {
            $this->warn('offer: '.(string) ($status['onboard_command'] ?? 'n/a'));
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
