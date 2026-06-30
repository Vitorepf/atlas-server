<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AutonomousEvolution\Consolidation\AtlasLoopConsolidationCertifier;
use App\Services\Ai\AutonomousEvolution\Consolidation\AtlasLoopSelfArchitectureScanner;
use App\Services\Ai\AutonomousEvolution\Consolidation\AtlasLoopSelfDependencyGraphReporter;
use Illuminate\Console\Command;

final class AtlasLoopSelfArchitectureCertifyCommand extends Command
{
    protected $signature = 'atlas:loop:self-architecture:certify {--baseline=}';

    protected $description = 'Certify that the AutonomousEvolution subtree has monotonically decreased in LOC, edges, cycles, and Refiller LOC vs baseline.';

    public function handle(): int
    {
        $baselinePath = (string) $this->option('baseline');
        if ($baselinePath === '') {
            $baselinePath = base_path('docs/loop-self-architecture-baseline.json');
        }

        $certifier = new AtlasLoopConsolidationCertifier(
            new AtlasLoopSelfArchitectureScanner,
            new AtlasLoopSelfDependencyGraphReporter,
        );

        $result = $certifier->certify($baselinePath);

        $this->line((string) json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return $result['verdict'] === 'certified' ? self::SUCCESS : self::FAILURE;
    }
}
