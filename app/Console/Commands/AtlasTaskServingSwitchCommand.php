<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AutonomousEvolution\AtlasLoopMasterSwitch;
use App\Services\Ai\SelfConstruction\AtlasTaskServingSwitch;
use Illuminate\Console\Command;

/**
 * PART 2 — operator control of the task-serving surface, DECOUPLED from the Autônomos master switch.
 *
 *   atlas:task:serving on      # AIs can now pull tasks (next/report) — WITHOUT arming Autônomos farm
 *   atlas:task:serving off
 *   atlas:task:serving status
 *
 * `on` flips ONLY the serving flag. It does NOT enable Autônomos (brain/task farm) —
 * that stays governed by atlas:agents:on autonomos (alias: loop). So you can serve tasks to your AIs
 * today with zero risk of Autônomos burning tokens on its own.
 */
class AtlasTaskServingSwitchCommand extends Command
{
    protected $signature = 'atlas:task:serving {action=status : on|off|status} {--json}';

    protected $description = 'Turn the task-serving surface (atlas:task next/report) on/off — independent of the Autônomos master switch.';

    public function handle(): int
    {
        $action = (string) $this->argument('action');

        $ok = match ($action) {
            'on' => AtlasTaskServingSwitch::on(),
            'off' => AtlasTaskServingSwitch::off(),
            'status' => true,
            default => false,
        };

        if ($action !== 'status' && ! $ok) {
            $this->error('failed to write the serving switch');

            return self::FAILURE;
        }
        if (! in_array($action, ['on', 'off', 'status'], true)) {
            $this->error('unknown action: '.$action.' (use on|off|status)');

            return self::FAILURE;
        }

        $master = AtlasLoopMasterSwitch::state();
        $payload = [
            'serving_enabled' => AtlasTaskServingSwitch::enabled(),
            'serving_flag' => AtlasTaskServingSwitch::state(),
            'autonomos_master' => $master,
            // legacy key — keep for one period so existing dashboards keep parsing
            'autonomous_loop_master' => $master,
        ];

        if ($this->option('json')) {
            $this->line((string) json_encode($payload, JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        $this->line('task-serving: <fg='.($payload['serving_enabled'] ? 'green' : 'red').'>'.($payload['serving_enabled'] ? 'ON' : 'OFF').'</>'
            .'   (autonomos master: '.$payload['autonomos_master'].')');
        if ($payload['serving_enabled']) {
            $this->line('AIs can now: php artisan atlas:task next --client=<id> --json');
        }

        return self::SUCCESS;
    }
}
