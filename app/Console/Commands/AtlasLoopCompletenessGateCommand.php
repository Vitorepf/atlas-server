<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AutonomousEvolution\AtlasLoopCompletenessGate;
use Illuminate\Console\Command;

/**
 * Arms the dormant {@see AtlasLoopCompletenessGate::evaluate()} at the operator surface: reads a goal's
 * acceptance-criteria checklist (each with its `satisfied` already resolved upstream) from a JSON file and
 * emits the completeness verdict + coverage as deterministic facts.
 *
 * Read-only + pure: completeness math only — no provider/DB/mutation. Fail-open on an empty checklist (a
 * delivery with no declared criteria is not gated here).
 */
final class AtlasLoopCompletenessGateCommand extends Command
{
    protected $signature = 'atlas:loop:completeness-gate {--input=} {--min-coverage=} {--json}';

    protected $description = 'Read-only completeness verdict + coverage over a goal acceptance-criteria checklist.';

    public function handle(): int
    {
        $input = trim((string) $this->option('input'));
        if ($input === '' || ! is_file($input) || ! is_readable($input)) {
            $this->line((string) json_encode([
                'outcome' => 'refused',
                'reason' => 'usage_error',
                'message' => 'completeness-gate requires --input=<path to a readable criteria JSON>',
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
        $criteria = isset($decoded['criteria']) && is_array($decoded['criteria']) ? $decoded['criteria'] : $decoded;

        $minCoverage = $this->option('min-coverage') !== null && trim((string) $this->option('min-coverage')) !== ''
            ? (float) $this->option('min-coverage')
            : (isset($decoded['min_coverage']) && is_numeric($decoded['min_coverage']) ? (float) $decoded['min_coverage'] : null);

        $verdict = app(AtlasLoopCompletenessGate::class)->evaluate(array_values($criteria), $minCoverage);

        $facts = ['schema' => 'atlas.loop.completeness_gate.v1'] + $verdict;

        if ($this->option('json')) {
            $this->line((string) json_encode($facts, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        } else {
            $this->line('complete: '.($facts['complete'] ? 'yes' : 'no').'  coverage: '.$facts['coverage'].' ('.$facts['satisfied'].'/'.$facts['total'].')');
            if ($facts['reason'] !== null) {
                $this->line('reason: '.$facts['reason']);
            }
        }

        return self::SUCCESS;
    }
}
