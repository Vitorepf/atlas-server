<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AutonomousEvolution\AtlasLoopMasterSwitch;
use App\Services\Ai\SelfConstruction\AtlasTaskServingSwitch;
use Illuminate\Console\Command;

/**
 * PART 2 — operator control of the task-serving surface, DECOUPLED from the autonomous-loop master switch.
 *
 *   atlas:task:serving on      # AIs can now pull tasks (next/report) — WITHOUT arming the autonomous farm
 *   atlas:task:serving off
 *   atlas:task:serving status
 *
 * `on` flips ONLY the serving flag. It does NOT enable the autonomous evolution loop (campaign launch,
 * keepalive respawn, auto-merge) — that stays governed by atlas:loop:on. So you can serve tasks to your AIs
 * today with zero risk of the loop burning tokens on its own.
 */
class AtlasTaskServingSwitchCommand extends Command
{
    protected $signature = 'atlas:task:serving {action=status : on|off|status} {--json}';

    protected $description = 'Turn the task-serving surface (atlas:task next/report) on/off — independent of the autonomous loop master switch.';

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

        $payload = [
            'serving_enabled' => AtlasTaskServingSwitch::enabled(),
            'serving_flag' => AtlasTaskServingSwitch::state(),
            'autonomous_loop_master' => AtlasLoopMasterSwitch::state(),
        ];

        if ($this->option('json')) {
            $this->line((string) json_encode($payload, JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        $this->line('task-serving: <fg='.($payload['serving_enabled'] ? 'green' : 'red').'>'.($payload['serving_enabled'] ? 'ON' : 'OFF').'</>'
            .'   (autonomous loop master: '.$payload['autonomous_loop_master'].')');
        if ($payload['serving_enabled']) {
            $this->line('AIs can now: php artisan atlas:task next --client=<id> --json');
        }

        return self::SUCCESS;
    }
}
