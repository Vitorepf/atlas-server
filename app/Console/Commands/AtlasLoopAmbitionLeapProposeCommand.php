<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AutonomousEvolution\AtlasLoopAmbitionLeapProposer;
use Illuminate\Console\Command;

/**
 * Arms the dormant {@see AtlasLoopAmbitionLeapProposer::propose()} at the operator surface: reads frontier gaps
 * from a JSON file and emits the ambition-leap proposals — each either a grounded leap (hypothesis + target
 * capability delta) or an abstention with its reason — as deterministic facts.
 *
 * Read-only + pure: it abstains on insufficient grounded evidence (no plateau signal / < 3 refs) and rejects
 * proxy deltas (cyclomatic/refactor/rename/...). No provider/DB/mutation.
 */
final class AtlasLoopAmbitionLeapProposeCommand extends Command
{
    protected $signature = 'atlas:loop:ambition-leap-propose {--input=} {--json}';

    protected $description = 'Read-only ambition-leap proposals (grounded leaps + abstain reasons) from frontier gaps.';

    public function handle(): int
    {
        $input = trim((string) $this->option('input'));
        if ($input === '' || ! is_file($input) || ! is_readable($input)) {
            return $this->refuse('ambition-leap-propose requires --input=<path to a readable frontier-gaps JSON>');
        }

        $decoded = json_decode((string) file_get_contents($input), true);
        if (! is_array($decoded)) {
            return $this->refuse('input file is not a JSON array/object');
        }
        $gaps = isset($decoded['frontier_gaps']) && is_array($decoded['frontier_gaps']) ? $decoded['frontier_gaps'] : $decoded;

        $leaps = app(AtlasLoopAmbitionLeapProposer::class)->propose(array_values($gaps));

        $facts = [
            'schema' => 'atlas.loop.ambition_leap_propose.v1',
            'proposal_count' => count($leaps),
            'leaps' => $leaps,
        ];

        if ($this->option('json')) {
            $this->line((string) json_encode($facts, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        } else {
            $this->line('proposal_count: '.$facts['proposal_count']);
            foreach ($leaps as $l) {
                $this->line($l['gap_id'].'  '.($l['abstain_reason'] ?? 'LEAP'));
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
