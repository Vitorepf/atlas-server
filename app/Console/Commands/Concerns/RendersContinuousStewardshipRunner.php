<?php

declare(strict_types=1);

namespace App\Console\Commands\Concerns;

use App\Services\Ai\SoftwareCompanyStewardship\ContinuousStewardship\ContinuousStewardshipRunnerService;

/**
 * AP-766 · CLI renderers for the Continuous Stewardship Runner control plane.
 *
 * Kept in a trait so the AP-766 surface lives in its own file and does not
 * contend with the (heavily shared) AtlasSoftwareCompanyStewardshipCommand. The
 * trait relies only on the host command's public/protected Command surface
 * ($this->option, $this->components, $this->line/warn/error) plus the command's
 * own emit(), blockedResult() and operatorReceiptsFromOption() helpers.
 *
 * @mixin \App\Console\Commands\AtlasSoftwareCompanyStewardshipCommand
 */
trait RendersContinuousStewardshipRunner
{
    private function runContinuousRunner(ContinuousStewardshipRunnerService $service): int
    {
        $operatorReceipts = $this->operatorReceiptsFromOption();
        if ($operatorReceipts === null) {
            return $this->blockedResult('operator_receipts_file_invalid', '--operator-receipts-file must be a readable JSON object, JSON array, or JSONL file.');
        }

        $maxRunsPerDay = $this->option('max-runs-per-day');
        $lockTtl = $this->option('runner-lock-ttl-seconds');

        $input = [
            'area_id' => (string) $this->option('area'),
            'mode' => (string) $this->option('mode'),
            'enabled' => (bool) $this->option('enable-continuous-runner'),
            'global_kill_switch' => (bool) $this->option('kill-switch'),
            'area_kill_switch' => (bool) $this->option('area-kill-switch'),
            'pause_until' => (string) ($this->option('pause-until') ?? ''),
            'min_interval_seconds' => (int) $this->option('min-interval-seconds'),
            'force_scheduler_run' => (bool) $this->option('force-scheduler-run'),
            'record_runner_run' => (bool) $this->option('record-runner-run'),
            'operator_receipts' => $operatorReceipts,
        ];
        if ($maxRunsPerDay !== null && $maxRunsPerDay !== '') {
            $input['max_runs_per_day'] = (int) $maxRunsPerDay;
        }
        if ($lockTtl !== null && $lockTtl !== '') {
            $input['lock_ttl_seconds'] = (int) $lockTtl;
        }

        $payload = $service->run($input);

        $this->emit($payload, function (array $p): void {
            $this->components->twoColumnDetail('AP-766 Continuous Stewardship Runner', (string) ($p['status'] ?? 'unknown'));
            $this->components->twoColumnDetail('Area', (string) ($p['area_id'] ?? ''));
            $this->components->twoColumnDetail('Runner', (string) ($p['runner_id'] ?? ''));
            $this->components->twoColumnDetail('Mode', (string) ($p['mode'] ?? ''));
            $this->components->twoColumnDetail('Tick', (string) ($p['tick_status'] ?? 'not_attempted'));
            $this->components->twoColumnDetail('Kill switch', (string) ($p['kill_switch_status'] ?? ''));
            $this->components->twoColumnDetail('Pause', (string) ($p['pause_status'] ?? ''));
            $this->components->twoColumnDetail('Budget', (string) ($p['budget_status'] ?? '').' ('.(string) data_get($p, 'budget.used_today', 0).'/'.(string) data_get($p, 'budget.max_runs_per_day', 0).')');
            $this->components->twoColumnDetail('Lock', (string) ($p['lock_status'] ?? ''));
            $this->components->twoColumnDetail('Recorded', (string) ($p['runner_storage_status'] ?? 'projected'));
            foreach ((array) ($p['invoked_components'] ?? []) as $component) {
                if (! is_array($component)) {
                    continue;
                }
                $this->line('  component: '.(string) ($component['key'] ?? '').' · '.(string) ($component['status'] ?? ''));
            }
            foreach ((array) ($p['blockers'] ?? []) as $blocker) {
                $this->warn('  blocker: '.(string) $blocker);
            }
            foreach ((array) ($p['errors'] ?? []) as $error) {
                $this->error('  error: '.(string) $error);
            }
            foreach ((array) ($p['next_actions'] ?? []) as $action) {
                $this->line('  next: '.(string) $action);
            }
        });

        return in_array((string) ($payload['status'] ?? ''), [
            ContinuousStewardshipRunnerService::STATUS_BLOCKED,
            ContinuousStewardshipRunnerService::STATUS_LOCKED,
        ], true)
            ? self::FAILURE
            : self::SUCCESS;
    }

    private function runContinuousRunnerStatus(ContinuousStewardshipRunnerService $service): int
    {
        $maxRunsPerDay = $this->option('max-runs-per-day');

        $input = [
            'area_id' => (string) $this->option('area'),
            'enabled' => (bool) $this->option('enable-continuous-runner'),
            'global_kill_switch' => (bool) $this->option('kill-switch'),
            'area_kill_switch' => (bool) $this->option('area-kill-switch'),
            'pause_until' => (string) ($this->option('pause-until') ?? ''),
            'min_interval_seconds' => (int) $this->option('min-interval-seconds'),
        ];
        if ($maxRunsPerDay !== null && $maxRunsPerDay !== '') {
            $input['max_runs_per_day'] = (int) $maxRunsPerDay;
        }

        $payload = $service->status($input);

        $this->emit($payload, function (array $p): void {
            $this->components->twoColumnDetail('AP-766 Continuous Stewardship Runner status', (string) ($p['status'] ?? 'unknown'));
            $this->components->twoColumnDetail('Area', (string) ($p['area_id'] ?? ''));
            $this->components->twoColumnDetail('Kill switch', (string) ($p['kill_switch_status'] ?? ''));
            $this->components->twoColumnDetail('Pause', (string) ($p['pause_status'] ?? ''));
            $this->components->twoColumnDetail('Budget', (string) ($p['budget_status'] ?? '').' ('.(string) data_get($p, 'budget.used_today', 0).'/'.(string) data_get($p, 'budget.max_runs_per_day', 0).')');
            $this->components->twoColumnDetail('Lock', (string) ($p['lock_status'] ?? ''));
            $this->components->twoColumnDetail('Next allowed at', (string) ($p['next_allowed_at'] ?? 'now'));
            $this->components->twoColumnDetail('Last run', (string) data_get($p, 'last_run.status', 'none').' · '.(string) data_get($p, 'last_run.mode', ''));
            foreach ((array) ($p['next_actions'] ?? []) as $action) {
                $this->line('  next: '.(string) $action);
            }
        });

        return self::SUCCESS;
    }
}
