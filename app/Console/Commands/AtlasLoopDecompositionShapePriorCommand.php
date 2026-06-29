<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AutonomousEvolution\AtlasLoopDecompositionShapePrior;
use Illuminate\Console\Command;

/**
 * Arms the dormant {@see AtlasLoopDecompositionShapePrior::assess()} at the operator surface: given a shape's
 * terminal history (certified / total), emits the Wilson-lower-bound shape-prior verdict (unknown / suspect /
 * ok) as deterministic facts. Read-only and pure — fixed counts yield a fixed verdict; it never enqueues.
 */
final class AtlasLoopDecompositionShapePriorCommand extends Command
{
    protected $signature = 'atlas:loop:decomposition-shape-prior {--certified=} {--total=} {--json}';

    protected $description = 'Read-only Wilson-lower-bound shape-prior verdict for a decomposition shape (certified/total).';

    public function handle(AtlasLoopDecompositionShapePrior $prior): int
    {
        $totalOption = $this->option('total');
        if ($totalOption === null || trim((string) $totalOption) === '') {
            $this->line((string) json_encode(['status' => 'usage_error', 'reason' => 'total_required'], JSON_UNESCAPED_SLASHES));

            return self::INVALID;
        }

        $assessment = $prior->assess((int) $this->option('certified'), (int) $totalOption);

        $this->line((string) json_encode(
            ['schema' => 'atlas.loop.decomposition_shape_prior.v1'] + $assessment,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        ));

        return self::SUCCESS;
    }
}
