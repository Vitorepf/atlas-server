<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AutonomousEvolution\AtlasLoopBehavioralEquivalenceGate;
use Illuminate\Console\Command;

/**
 * Arms the dormant {@see AtlasLoopBehavioralEquivalenceGate::evaluate()} at the operator surface: given the
 * mutants sampled / killed and the kill-ratio floor, emits the deterministic behavioral-equivalence verdict
 * (passes + kill_ratio + reason). Pure scoring — it runs NO mutation; it only scores counts handed to it.
 */
final class AtlasLoopBehavioralEquivalenceCommand extends Command
{
    protected $signature = 'atlas:loop:behavioral-equivalence {--sampled=} {--killed=} {--floor=} {--json}';

    protected $description = 'Read-only behavioral-equivalence verdict from mutation kill-ratio vs floor (no mutation run).';

    public function handle(AtlasLoopBehavioralEquivalenceGate $gate): int
    {
        $sampled = $this->option('sampled');
        if ($sampled === null || trim((string) $sampled) === '') {
            $this->line((string) json_encode(['status' => 'usage_error', 'reason' => 'sampled_required'], JSON_UNESCAPED_SLASHES));

            return self::INVALID;
        }

        $verdict = $gate->evaluate((int) $sampled, (int) $this->option('killed'), (float) $this->option('floor'));

        $this->line((string) json_encode(
            ['schema' => 'atlas.loop.behavioral_equivalence.v1'] + $verdict,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        ));

        return self::SUCCESS;
    }
}
