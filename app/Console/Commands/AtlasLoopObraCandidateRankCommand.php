<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopObraCandidateRanker;
use Illuminate\Console\Command;

/**
 * Arms the dormant {@see AtlasLoopObraCandidateRanker::rank()} at the operator surface: reads loop-detected
 * obra-cluster candidate payloads from a JSON file and emits the leverage-ranked selection (pick + ranked
 * order with each candidate's class + trust gate) as deterministic facts.
 *
 * Read-only + pure: a read-rank over already-parked candidates using only their MEASURED leverage signals
 * (never an agent-emitted number). Fail-open — empty/unmappable input or a DB hiccup yields an empty ranking.
 */
final class AtlasLoopObraCandidateRankCommand extends Command
{
    protected $signature = 'atlas:loop:obra-candidate-rank {--input=} {--json}';

    protected $description = 'Read-only leverage ranking of detected obra-cluster candidates (highest-proven-value first).';

    public function handle(): int
    {
        $input = trim((string) $this->option('input'));
        if ($input === '' || ! is_file($input) || ! is_readable($input)) {
            $this->line((string) json_encode([
                'outcome' => 'refused',
                'reason' => 'usage_error',
                'message' => 'obra-candidate-rank requires --input=<path to a readable payloads JSON>',
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

            return self::FAILURE;
        }

        $decoded = json_decode((string) file_get_contents($input), true);
        if (! is_array($decoded)) {
            $this->line((string) json_encode([
                'outcome' => 'refused',
                'reason' => 'usage_error',
                'message' => 'input file is not a JSON array/object',
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

            return self::FAILURE;
        }
        $payloads = $decoded['payloads'] ?? $decoded['candidates'] ?? $decoded;
        if (! is_array($payloads)) {
            $payloads = [];
        }

        $result = app(AtlasLoopObraCandidateRanker::class)->rank(array_values($payloads));

        $facts = [
            'schema' => 'atlas.loop.obra_candidate_rank.v1',
            'pick' => $result['pick'],
            'count' => count($result['ranked']),
            'ranked' => $result['ranked'],
        ];

        if ($this->option('json')) {
            $this->line((string) json_encode($facts, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        } else {
            $this->line('pick: '.($facts['pick'] ?? '(none)'));
            foreach ($facts['ranked'] as $r) {
                $this->line($r['candidateId'].'  '.$r['class'].'  '.$r['gate']);
            }
        }

        return self::SUCCESS;
    }
}
