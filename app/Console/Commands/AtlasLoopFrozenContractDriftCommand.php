<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AutonomousEvolution\Frozen\AtlasLoopFrozenContractDriftDetector;
use App\Services\Ai\AutonomousEvolution\Frozen\AtlasLoopFrozenContractRegistry;
use Illuminate\Console\Command;

/**
 * Arms the dormant {@see AtlasLoopFrozenContractDriftDetector::detect()} at the operator surface: compares each
 * registered frozen contract's expected assertions-sha against its live test block and emits the per-contract
 * drift facts (fqcn, test_path, expected_sha, actual_sha, drift) as JSON. Read-only.
 */
final class AtlasLoopFrozenContractDriftCommand extends Command
{
    protected $signature = 'atlas:loop:frozen-contract-drift {--json}';

    protected $description = 'Read-only frozen-contract drift check (expected vs. live assertions sha per contract).';

    public function handle(AtlasLoopFrozenContractDriftDetector $detector, AtlasLoopFrozenContractRegistry $registry): int
    {
        $contracts = $detector->detect($registry);

        $this->line((string) json_encode([
            'schema_version' => 'atlas.loop.frozen_contract_drift.v1',
            'drift_count' => count(array_filter($contracts, static fn (array $c): bool => (bool) ($c['drift'] ?? false))),
            'contracts_count' => count($contracts),
            'contracts' => $contracts,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
