<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AutonomousEvolution\AtlasLoopDeterministicDeadCodeWorkType;
use Illuminate\Console\Command;

/**
 * §3/§6 — run the DETERMINISTIC dead-code work-type over a scope: mill → author → CERTIFY real removals of
 * provably-dead private members with ZERO provider calls. Propose-only (it never writes or merges) — it
 * reports what the loop WOULD deliver. This is the operator's runnable hermes-FREE soak-readiness check:
 * it proves the loop produces certified real value without the provider, no thrash, no token burn.
 */
final class AtlasLoopDeadCodeSweepCommand extends Command
{
    protected $signature = 'atlas:loop:deadcode-sweep
        {--path=app/Services/Ai/AutonomousEvolution : directory (repo-relative) to sweep}
        {--limit=20 : max certified removals to collect}
        {--json}';

    protected $description = 'Deterministic dead-code work-type sweep: certify real removals with NO provider (propose-only).';

    public function handle(): int
    {
        $scanDir = (string) ($this->option('path') ?: 'app/Services/Ai/AutonomousEvolution');
        $limit = max(1, (int) $this->option('limit'));

        $sweep = (new AtlasLoopDeterministicDeadCodeWorkType)->sweepDirectory(base_path(), $scanDir, $limit);

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode([
                'scanned' => $sweep['scanned'],
                'certified_removals' => count($sweep['certified']),
                'provider_used' => $sweep['provider_used'],
                'removals' => $sweep['certified'],
            ]));

            return self::SUCCESS;
        }

        $this->info("Deterministic dead-code sweep of {$scanDir} — NO provider used.");
        $this->line("  scanned: {$sweep['scanned']} files   certified removals: ".count($sweep['certified']));
        foreach ($sweep['certified'] as $r) {
            $members = implode(', ', array_map(static fn (array $m): string => $m['kind'].' '.$m['name'], $r['removed']));
            $this->line("  • {$r['rel_path']} — {$members}");
        }

        return self::SUCCESS;
    }
}
