<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AutonomousEvolution\AtlasEvolutionScenarioExplorer;
use App\Services\Ai\AutonomousEvolution\Parallel\ScenarioWaveDispatcher;
use Illuminate\Console\Command;

/**
 * Atlas Evolution Loop — per-scenario GRIND subprocess.
 *
 * The per-scenario unit that {@see ScenarioWaveDispatcher}
 * spawns to run a bounded parallel wave: one scenario, one process. It is the
 * scenario-level analogue of {@see AtlasLoopGrindTaskCommand} (which is task-level).
 *
 * It exists because runScenario needs the in-process, container-resolved
 * LoopExecutionDriver + AtlasEvolutionFrozenJudge that cannot be serialized into the
 * parent's subprocess any other way — so the child boots its own kernel, resolves the
 * SAME real driver binding the parent has, decodes the base64(json) spec, runs ONE
 * scenario through the exact (unchanged) runScenario logic via the thin public
 * runScenarioForWave shim, and prints the attempt as JSON for the parent to harvest.
 */
final class AtlasLoopRunScenarioCommand extends Command
{
    protected $signature = 'atlas:loop:run-scenario
        {--spec= : base64(json) scenario spec}
        {--json : Print the canonical JSON attempt}';

    protected $description = 'Run ONE Evolution Loop scenario (the per-scenario unit of a bounded parallel wave) and print its attempt JSON.';

    public function handle(AtlasEvolutionScenarioExplorer $explorer): int
    {
        if (function_exists('posix_setsid')) {
            @posix_setsid(); // own process group so a kill reaps the whole provider subtree
        }

        $raw = (string) ($this->option('spec') ?: '');
        $decoded = $raw === '' ? null : base64_decode($raw, true);
        $spec = $decoded === false || $decoded === null ? null : json_decode($decoded, true);

        if (! is_array($spec)) {
            $this->error('Invalid --spec (expected base64(json) scenario spec).');

            return self::FAILURE;
        }

        $attempt = $explorer->runScenarioForWave($spec);

        $this->line((string) json_encode($attempt, JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }
}
