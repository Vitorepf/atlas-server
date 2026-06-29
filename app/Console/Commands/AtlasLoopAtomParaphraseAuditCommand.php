<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AutonomousEvolution\AtlasLoopAtomParaphraseAudit;
use Illuminate\Console\Command;

/**
 * Arms the dormant {@see AtlasLoopAtomParaphraseAudit::nearDuplicatePairs()} at the operator surface: reads the
 * human-frozen verification atoms from a JSON file and emits pairs whose text reads as near-paraphrases
 * (pairwise Jaccard >= threshold) as a read-only authoring ADVISORY.
 *
 * Read-only + pure: it surfaces possible redundant criteria — it NEVER infers, authors, weakens, removes, or
 * grades an atom, and never blocks a feature. No provider/DB/mutation.
 */
final class AtlasLoopAtomParaphraseAuditCommand extends Command
{
    protected $signature = 'atlas:loop:atom-paraphrase-audit {--input=} {--threshold=0.85} {--json}';

    protected $description = 'Read-only paraphrase audit over human verification atoms (near-duplicate pairs advisory).';

    public function handle(): int
    {
        $input = trim((string) $this->option('input'));
        if ($input === '' || ! is_file($input) || ! is_readable($input)) {
            return $this->refuse('atom-paraphrase-audit requires --input=<path to a readable atoms JSON>');
        }

        $decoded = json_decode((string) file_get_contents($input), true);
        if (! is_array($decoded)) {
            return $this->refuse('input file is not a JSON array/object');
        }
        $atoms = isset($decoded['atoms']) && is_array($decoded['atoms']) ? $decoded['atoms'] : $decoded;
        $threshold = (float) $this->option('threshold');

        $pairs = app(AtlasLoopAtomParaphraseAudit::class)->nearDuplicatePairs(array_values($atoms), $threshold);

        $facts = [
            'schema' => 'atlas.loop.atom_paraphrase_audit.v1',
            'threshold' => $threshold,
            'pair_count' => count($pairs),
            'pairs' => $pairs,
        ];

        if ($this->option('json')) {
            $this->line((string) json_encode($facts, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        } else {
            $this->line('pair_count: '.$facts['pair_count']);
            foreach ($pairs as $p) {
                $this->line('  ['.$p['a'].','.$p['b'].'] sim='.$p['similarity']);
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
