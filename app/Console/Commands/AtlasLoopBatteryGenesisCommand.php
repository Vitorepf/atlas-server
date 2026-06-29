<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AutonomousEvolution\Constitution\AtlasLoopBatteryGenesis;
use App\Services\Ai\AutonomousEvolution\Constitution\AtlasLoopCertChainClosure;
use Illuminate\Console\Command;

/**
 * Arms the dormant {@see AtlasLoopBatteryGenesis::cases()} at the operator surface: generates the frozen
 * sentinel battery cases (the known-bad anti-patterns that must REFUTE, one blinder per cert-chain closure
 * class, and the known-good obligations that must CERTIFY) over the deterministic {@see AtlasLoopCertChainClosure}
 * and emits them as deterministic facts. Pure (io=0), read-only.
 *
 * The cert-chain closure is self-computing (it derives the chain from the certifier root); --closure is
 * accepted for forward compatibility, but the deterministic default is authoritative.
 */
final class AtlasLoopBatteryGenesisCommand extends Command
{
    protected $signature = 'atlas:loop:battery-genesis {--closure=} {--json}';

    protected $description = 'Read-only: generate the frozen battery cases from the cert-chain closure.';

    public function handle(): int
    {
        $cases = (new AtlasLoopBatteryGenesis)->cases(new AtlasLoopCertChainClosure);

        $this->line((string) json_encode([
            'schema' => 'atlas.loop.battery_genesis.v1',
            'count' => count($cases),
            'cases' => $cases,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
