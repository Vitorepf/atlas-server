<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AutonomousEvolution\AtlasLoopContractGapScanner;
use Illuminate\Console\Command;

/**
 * Arms the dormant {@see AtlasLoopContractGapScanner::capabilityGaps()} at the operator surface: globs the
 * AutonomousEvolution php files and reports the declared capability contracts (interfaces) with their method
 * surface and implementer count — the doc-stated capability gaps — as deterministic facts (JSON). Read-only.
 */
final class AtlasLoopContractGapScanCommand extends Command
{
    protected $signature = 'atlas:loop:contract-gap-scan {--json}';

    protected $description = 'Read-only capability-contract gap scan over the AutonomousEvolution tree (interfaces + implementers).';

    public function handle(AtlasLoopContractGapScanner $scanner): int
    {
        $gaps = $scanner->capabilityGaps(
            $this->phpFiles(base_path('app/Services/Ai/AutonomousEvolution')),
            base_path(),
        );

        $this->line((string) json_encode([
            'schema_version' => 'atlas.loop.contract_gap_scan.v1',
            'gaps_count' => count($gaps),
            'gaps' => $gaps,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }

    /**
     * @return list<string>
     */
    private function phpFiles(string $root): array
    {
        if (! is_dir($root)) {
            return [];
        }
        $files = [];
        $iter = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($iter as $entry) {
            if ($entry instanceof \SplFileInfo && $entry->isFile() && strtolower($entry->getExtension()) === 'php') {
                $files[] = $entry->getPathname();
            }
        }
        sort($files, SORT_STRING);

        return $files;
    }
}
