<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AutonomousEvolution\AtlasLoopHarnessGuard;
use App\Services\Ai\AutonomousEvolution\V4\AtlasLoopV4SelfArchitectureProposer;
use Illuminate\Console\Command;

/**
 * Arms the dormant {@see AtlasLoopV4SelfArchitectureProposer::propose()} at the operator surface: reads the
 * brain topology from a JSON file, takes the constitutional forbidden-self-targets from the harness guard, and
 * emits the validated self-architecture proposal (kind, target_path, rationale) or the refusal reason.
 *
 * ADVISORY + read-only: it proposes and reports; it NEVER enqueues, materializes, or mutates anything. A target
 * that is off-topology or constitutionally forbidden is refused. Without an architect seam it refuses
 * no_architect (the live architect is wired elsewhere).
 */
final class AtlasLoopV4SelfArchitectureProposeCommand extends Command
{
    protected $signature = 'atlas:loop:v4-self-architecture-propose {--input=} {--json}';

    protected $description = 'Read-only self-architecture proposal from the brain topology (constitution-guarded, never enqueues).';

    public function handle(): int
    {
        $input = trim((string) $this->option('input'));
        if ($input === '' || ! is_file($input) || ! is_readable($input)) {
            return $this->refuse('v4-self-architecture-propose requires --input=<path to a readable topology JSON>');
        }

        $decoded = json_decode((string) file_get_contents($input), true);
        if (! is_array($decoded)) {
            return $this->refuse('input file is not a JSON array/object');
        }
        $topology = is_array($decoded['brain_topology'] ?? null) ? $decoded['brain_topology']
            : (is_array($decoded['topology'] ?? null) ? $decoded['topology'] : $decoded);

        // The constitutional off-limits set is the guard's frozen list — the proposer can never target it.
        $forbidden = AtlasLoopHarnessGuard::FORBIDDEN_SELF_TARGETS;

        $proposal = app(AtlasLoopV4SelfArchitectureProposer::class)->propose(array_values($topology), $forbidden);

        $facts = ['schema' => 'atlas.loop.v4_self_architecture.v1'] + $proposal;

        if ($this->option('json')) {
            $this->line((string) json_encode($facts, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        } else {
            $this->line('proposed: '.($facts['proposed'] ? 'yes' : 'no').'  refuse_reason: '.($facts['refuse_reason'] ?? '-'));
            if ($facts['proposed']) {
                $this->line($facts['kind'].' -> '.$facts['target_path']);
            }
        }

        return self::SUCCESS;
    }

    private function refuse(string $message): int
    {
        $this->line((string) json_encode([
            'outcome' => 'refused',
            'reason' => 'usage_error',
            'message' => $message,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return self::FAILURE;
    }
}
