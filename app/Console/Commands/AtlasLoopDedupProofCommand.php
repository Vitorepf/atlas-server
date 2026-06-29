<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopDedupProof;
use Illuminate\Console\Command;

/**
 * Arms the dormant {@see AtlasLoopDedupProof::evaluate()} at the operator surface: reads a baseline and a
 * candidate (each a relPath => source map) from a JSON file and emits whether the candidate genuinely REMOVED
 * a clone that ≥2 baseline files shared — the ungameable count-drop dedup proof — as deterministic facts.
 *
 * Read-only + pure (source-in / result-out): no git, no FS, no provider. A baseline with no shared clone yields
 * has_baseline_clone=false (fail-closed: nothing to certify).
 */
final class AtlasLoopDedupProofCommand extends Command
{
    protected $signature = 'atlas:loop:dedup-proof {--input=} {--json}';

    protected $description = 'Read-only dedup proof: did the candidate structurally remove a baseline clone?';

    public function handle(): int
    {
        $input = trim((string) $this->option('input'));
        if ($input === '' || ! is_file($input) || ! is_readable($input)) {
            return $this->refuse('dedup-proof requires --input=<path to a readable {baseline, candidate} JSON>');
        }

        $decoded = json_decode((string) file_get_contents($input), true);
        if (! is_array($decoded) || ! is_array($decoded['baseline'] ?? null) || ! is_array($decoded['candidate'] ?? null)) {
            return $this->refuse('input JSON must be an object with `baseline` and `candidate` relPath=>source maps');
        }

        $result = app(AtlasLoopDedupProof::class)->evaluate($decoded['baseline'], $decoded['candidate']);

        $facts = $result === null
            ? ['schema' => 'atlas.loop.dedup_proof.v1', 'has_baseline_clone' => false, 'removed' => false]
            : ['schema' => 'atlas.loop.dedup_proof.v1', 'has_baseline_clone' => true] + $result;

        if ($this->option('json')) {
            $this->line((string) json_encode($facts, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        } else {
            $this->line('has_baseline_clone: '.($facts['has_baseline_clone'] ? 'yes' : 'no').'  removed: '.($facts['removed'] ? 'yes' : 'no'));
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
