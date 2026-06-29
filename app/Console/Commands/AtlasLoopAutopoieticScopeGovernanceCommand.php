<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AutonomousEvolution\Federation\AtlasLoopAutopoieticScopeGovernancePipeline;
use Illuminate\Console\Command;

/**
 * Arms the dormant {@see AtlasLoopAutopoieticScopeGovernancePipeline::evaluate()} at the operator surface: reads
 * a scope-origination proposal from a JSON file and emits the fail-closed governance verdict (admitted,
 * blocking_reasons, requires_operator_receipt) as deterministic facts.
 *
 * Read-only: it DECIDES whether a self-proposed scope is admissible — it never originates, registers, bootstraps,
 * or mutates. A loop-core root, an incomplete descriptor, or a missing operator receipt blocks admission.
 */
final class AtlasLoopAutopoieticScopeGovernanceCommand extends Command
{
    protected $signature = 'atlas:loop:autopoietic-scope-governance {--input=} {--json}';

    protected $description = 'Read-only autopoietic scope-governance verdict for a scope-origination proposal.';

    public function handle(): int
    {
        $input = trim((string) $this->option('input'));
        if ($input === '' || ! is_file($input) || ! is_readable($input)) {
            return $this->refuse('autopoietic-scope-governance requires --input=<path to a readable proposal JSON>');
        }

        $decoded = json_decode((string) file_get_contents($input), true);
        if (! is_array($decoded)) {
            return $this->refuse('input file is not a JSON object');
        }
        $proposal = isset($decoded['proposal']) && is_array($decoded['proposal']) ? $decoded['proposal'] : $decoded;

        $verdict = app(AtlasLoopAutopoieticScopeGovernancePipeline::class)->evaluate($proposal);

        if ($this->option('json')) {
            $this->line((string) json_encode($verdict, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        } else {
            $this->line('admitted: '.($verdict['admitted'] ? 'yes' : 'no'));
            foreach ($verdict['blocking_reasons'] as $r) {
                $this->line('  blocking: '.$r);
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
