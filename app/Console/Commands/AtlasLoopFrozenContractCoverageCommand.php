<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AutonomousEvolution\FrozenContracts\AtlasLoopFrozenContractCoverageReporter;
use Illuminate\Console\Command;

/**
 * Arms the dormant {@see AtlasLoopFrozenContractCoverageReporter::report()} at the operator surface: a read-only
 * report of which loop-critical classes carry a frozen contract (with_contract) vs. not (without_contract), and
 * which frozen contracts have a sentinel test vs. are orphaned — emitted as deterministic facts (JSON).
 */
final class AtlasLoopFrozenContractCoverageCommand extends Command
{
    protected $signature = 'atlas:loop:frozen-contract-coverage {--json}';

    protected $description = 'Read-only frozen-contract coverage report (covered vs. uncovered, sentinel vs. orphan).';

    public function handle(AtlasLoopFrozenContractCoverageReporter $reporter): int
    {
        $this->line((string) json_encode($reporter->report(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
