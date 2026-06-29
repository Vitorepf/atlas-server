<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopCloneDetector;
use Illuminate\Console\Command;

/**
 * Arms the dormant {@see AtlasLoopCloneDetector::detectClones()} at the operator surface: builds a path => source
 * map of the AutonomousEvolution tree and emits the detected structural clone pairs (a, b, similarity) as a
 * deterministic read-only report. No edits, no dedup applied — facts only.
 */
final class AtlasLoopCloneScanCommand extends Command
{
    protected $signature = 'atlas:loop:clone-scan {--json}';

    protected $description = 'Read-only structural clone scan over the AutonomousEvolution tree (clone pairs + similarity).';

    public function handle(AtlasLoopCloneDetector $detector): int
    {
        $clones = $detector->detectClones($this->filesByPath(base_path('app/Services/Ai/AutonomousEvolution')));

        $this->line((string) json_encode([
            'schema_version' => 'atlas.loop.clone_scan.v1',
            'clone_pairs_count' => count($clones),
            'clones' => $clones,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }

    /**
     * @return array<string,string>  relative path => PHP source
     */
    private function filesByPath(string $root): array
    {
        if (! is_dir($root)) {
            return [];
        }
        $base = rtrim(base_path(), '/').'/';
        $map = [];
        $iter = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($iter as $entry) {
            if ($entry instanceof \SplFileInfo && $entry->isFile() && strtolower($entry->getExtension()) === 'php') {
                $rel = ltrim(str_replace($base, '', $entry->getPathname()), '/');
                $map[$rel] = (string) @file_get_contents($entry->getPathname());
            }
        }
        ksort($map, SORT_STRING);

        return $map;
    }
}
