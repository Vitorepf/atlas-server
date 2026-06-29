<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopAmbitionDecider;
use Illuminate\Console\Command;

/**
 * Arms the dormant {@see AtlasLoopAmbitionDecider::rank()} at the operator surface: reads candidate evolutions
 * from a JSON file and emits the ambition-weighted ranking (winner, ranked order, per-candidate required
 * verification tier) as deterministic facts.
 *
 * Read-only + pure: it DECIDES what to attempt (magnitude · P(land)^riskTolerance − cost); it never attempts,
 * dispatches, or mutates. The frozen out-of-process gates still dispose of whatever is attempted.
 */
final class AtlasLoopAmbitionDecideCommand extends Command
{
    protected $signature = 'atlas:loop:ambition-decide {--input=} {--risk-tolerance=} {--json}';

    protected $description = 'Read-only ambition-weighted ranking of candidate evolutions (magnitude over probability).';

    public function handle(): int
    {
        $input = trim((string) $this->option('input'));
        if ($input === '' || ! is_file($input) || ! is_readable($input)) {
            return $this->refuse('ambition-decide requires --input=<path to a readable candidates JSON>');
        }

        $decoded = json_decode((string) file_get_contents($input), true);
        if (! is_array($decoded)) {
            return $this->refuse('input file is not a JSON array/object');
        }
        $candidates = isset($decoded['candidates']) && is_array($decoded['candidates']) ? $decoded['candidates'] : $decoded;

        $rt = $this->option('risk-tolerance') !== null && trim((string) $this->option('risk-tolerance')) !== ''
            ? (float) $this->option('risk-tolerance')
            : (isset($decoded['risk_tolerance']) && is_numeric($decoded['risk_tolerance']) ? (float) $decoded['risk_tolerance'] : null);

        $result = app(AtlasLoopAmbitionDecider::class)->rank(array_values($candidates), $rt);

        if ($this->option('json')) {
            $this->line((string) json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        } else {
            $this->line('winner: '.($result['winner']['candidateId'] ?? '(none)'));
            foreach ($result['ranked'] as $r) {
                $this->line($r['score'].'  '.$r['candidateId'].'  mag='.$r['leap_magnitude'].'  '.$r['required_verification']['tier']);
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
