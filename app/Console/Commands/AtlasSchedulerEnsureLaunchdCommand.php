<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * Self-healing launchd probe. Runs once per day via scheduler. Checks
 * `launchctl list` for `com.atlas.scheduler`; if absent, re-invokes
 * `atlas:scheduler:install-launchd` to restore the agent.
 *
 * Authority doc: docs/engineering-knowledge-base/atlas-scheduler-os.md (§ A3 self-healing)
 */
class AtlasSchedulerEnsureLaunchdCommand extends Command
{
    protected $signature = 'atlas:scheduler:ensure-launchd
        {--label=com.atlas.scheduler : launchd label to probe}
        {--json : Emit machine-readable JSON}';

    protected $description = 'Verify launchd agent is loaded; reinstall if missing (self-healing).';

    public function handle(): int
    {
        $label = (string) $this->option('label');
        $isMac = PHP_OS_FAMILY === 'Darwin';

        if (! $isMac) {
            return $this->emit([
                'action' => 'ensure-launchd',
                'label' => $label,
                'platform' => PHP_OS_FAMILY,
                'status' => 'skipped_non_darwin',
            ]);
        }

        $list = (string) @shell_exec('launchctl list 2>/dev/null');
        $present = str_contains($list, $label);

        $payload = [
            'action' => 'ensure-launchd',
            'label' => $label,
            'platform' => 'Darwin',
            'agent_present' => $present,
        ];

        if ($present) {
            $payload['status'] = 'healthy';

            return $this->emit($payload);
        }

        // Reinstall.
        $payload['status'] = 'reinstalling';
        try {
            $exit = $this->call('atlas:scheduler:install-launchd', [
                '--label' => $label,
                '--json' => true,
            ]);
            $payload['reinstall_exit_code'] = $exit;
            $payload['status'] = $exit === 0 ? 'reinstalled' : 'reinstall_failed';
        } catch (\Throwable $e) {
            $payload['status'] = 'reinstall_error';
            $payload['error'] = substr($e->getMessage(), 0, 160);
        }

        return $this->emit($payload);
    }

    private function emit(array $payload): int
    {
        if ((bool) $this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }
        foreach ($payload as $k => $v) {
            $this->components->twoColumnDetail((string) $k, is_scalar($v) ? (string) $v : json_encode($v));
        }

        return self::SUCCESS;
    }
}
