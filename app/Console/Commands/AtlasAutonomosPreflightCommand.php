<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\SelfConstruction\AutonomosPreflightService;
use Illuminate\Console\Command;
use App\Support\YesNo;
use App\Console\Concerns\EmitsCanonicalJson;

/**
 * ASI-06 — Autonomos muscle preflight. READ-ONLY.
 *
 * This command NEVER flips the master (`ATLAS_AUTONOMOS_MASTER_ENABLED`) —
 * operator-exclusive trigger. Emits an 8/8 checklist with per-check evidence
 * and exits 0 only when all 8 pass. Any red check ⇒ exit != 0 so scripting
 * can detect it without inspecting the payload.
 */
final class AtlasAutonomosPreflightCommand extends Command
{
    use EmitsCanonicalJson;

    protected $signature = 'atlas:autonomos:preflight
        {--json : Emit canonical JSON payload}';

    protected $description = 'Read-only preflight for the Autonomos muscle (8 checks; the master flip is operator-only).';

    public function handle(AutonomosPreflightService $service): int
    {
        $report = $service->preflight();

        if ((bool) $this->option('json')) {
            $this->line($this->encode($report));
        } else {
            $this->line(sprintf('schema=%s passed=%d/%d ready=%s', $report['schema'], (int) $report['passed'], (int) $report['total'], YesNo::trueFalse($report['ready'])));
            $rows = [];
            foreach ((array) $report['checks'] as $check) {
                $rows[] = [
                    (string) ($check['id'] ?? '-'),
                    ($check['pass'] ?? false) ? 'GREEN' : 'RED',
                    (string) ($check['reason'] ?? '-'),
                    (string) ($check['evidence_path'] ?? ($check['evidence_table'] ?? '')),
                ];
            }
            $this->table(['check', 'status', 'reason', 'evidence'], $rows);
            $this->line('NOTE: master flip ATLAS_AUTONOMOS_MASTER_ENABLED is operator-only. This command never flips.');
        }

        return ($report['ready'] ?? false) === true ? self::SUCCESS : self::FAILURE;
    }
}
