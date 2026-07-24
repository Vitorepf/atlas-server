<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\SelfConstruction\AutonomosAutoApplyPreflightService;
use Illuminate\Console\Command;
use App\Support\YesNo;
use App\Console\Concerns\EmitsCanonicalJson;

/**
 * ASI-07 — Read-only preflight for the autonomous auto-apply floor.
 *
 * The flip `ATLAS_AUTONOMOUS_AUTO_APPLY=true` is operator-only.
 * The machine surfaces the floor checklist (flag default OFF; fail-closed on
 * sensitive privacy; reversal handle wired; digest metrics present) and STOPS.
 */
final class AtlasAutonomosAutoApplyPreflightCommand extends Command
{
    use EmitsCanonicalJson;

    protected $signature = 'atlas:autonomos:auto-apply-preflight
        {--json : Emit canonical JSON payload}';

    protected $description = 'Preflight for the auto-apply floor (operator-only flip).';

    public function handle(AutonomosAutoApplyPreflightService $service): int
    {
        $report = $service->preflight();

        if ((bool) $this->option('json')) {
            $this->line($this->encode($report));
        } else {
            $this->line(sprintf(
                'schema=%s passed=%d/%d ready=%s',
                $report['schema'],
                (int) $report['passed'],
                (int) $report['total'],
                YesNo::trueFalse($report['ready'] ?? false),
            ));
            foreach ((array) $report['checks'] as $check) {
                $this->line(sprintf(
                    ' [%s] %s — %s',
                    ($check['pass'] ?? false) ? 'GREEN' : 'RED',
                    (string) ($check['id'] ?? '-'),
                    (string) ($check['reason'] ?? '-'),
                ));
            }
            $this->line('NOTE: ATLAS_AUTONOMOUS_AUTO_APPLY flip is operator-only. This command never flips.');
        }

        return ($report['ready'] ?? false) === true ? self::SUCCESS : self::FAILURE;
    }
}
