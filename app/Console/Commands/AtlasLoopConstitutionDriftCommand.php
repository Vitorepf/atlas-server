<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AutonomousEvolution\Autopoiesis\Constitution\AtlasLoopAutopoieticConstitutionDriftDetector;
use Illuminate\Console\Command;

/**
 * Arms the dormant {@see AtlasLoopAutopoieticConstitutionDriftDetector::detect()} at the operator surface: emits
 * the DriftReport (current constitution fingerprint vs. the last operator-verified one) as deterministic facts —
 * so drift between the live constitution and the last sanctioned shape is visible. Read-only.
 */
final class AtlasLoopConstitutionDriftCommand extends Command
{
    protected $signature = 'atlas:loop:constitution-drift {--json}';

    protected $description = 'Read-only constitution drift report (live fingerprint vs. last operator-verified).';

    public function handle(AtlasLoopAutopoieticConstitutionDriftDetector $detector): int
    {
        $this->line((string) json_encode($detector->detect()->toArray(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
