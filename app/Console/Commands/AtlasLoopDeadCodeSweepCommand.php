<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AutonomousEvolution\AtlasLoopDeadCodeProducer;
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
        {--persist : persist each certified removal as a propose-only proposal (requires --campaign)}
        {--campaign= : campaign id to attach persisted proposals to}
        {--json}';

    protected $description = 'Deterministic dead-code work-type sweep: certify real removals with NO provider (propose-only).';

    public function handle(): int
    {
        $scanDir = (string) ($this->option('path') ?: 'app/Services/Ai/AutonomousEvolution');
        $limit = max(1, (int) $this->option('limit'));

        // --persist: write each certified removal as a propose-only proposal for a campaign (operator-review;
        // never auto-merges). This is the IN-CAMPAIGN form — a campaign MILLS→CERTIFIES real value, no provider.
        if ((bool) $this->option('persist')) {
            $campaignId = trim((string) ($this->option('campaign') ?? ''));
            if ($campaignId === '') {
                $this->error('--persist requires --campaign=<id>.');

                return self::FAILURE;
            }
            $out = (new AtlasLoopDeadCodeProducer)->persistSweep($campaignId, base_path(), $scanDir, $limit);
            if ((bool) $this->option('json')) {
                $this->line((string) json_encode($out));
            } else {
                $this->info("Persisted {$out['persisted']} certified dead-code removal proposal(s) to campaign {$campaignId} (scanned {$out['scanned']}, NO provider).");
            }

            return self::SUCCESS;
        }

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
