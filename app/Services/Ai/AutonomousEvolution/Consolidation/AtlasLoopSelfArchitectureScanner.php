<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Consolidation;

use Symfony\Component\Finder\Finder;

final class AtlasLoopSelfArchitectureScanner
{
    public const SCHEMA = 'atlas.loop.self_architecture_scan.v1';

    private const REFILLER_BASENAME = 'AtlasLoopQueueRefiller.php';

    public function __construct(
        private readonly string $basePath = '',
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function scan(?string $directory = null): array
    {
        $dir = $directory ?? $this->defaultDirectory();
        if (! is_dir($dir)) {
            return $this->envelope([], 0, 0, []);
        }

        $finder = (new Finder)->files()->name('*.php')->in($dir)->sortByName();
        $perFileLoc = [];
        $perFileEdges = [];
        $totalLoc = 0;
        $refillerLoc = 0;

        foreach ($finder as $file) {
            $relativePath = $file->getRelativePathname();
            $contents = (string) $file->getContents();
            $loc = $this->countLoc($contents);
            $edges = $this->extractEdges($contents);

            $perFileLoc[$relativePath] = $loc;
            $perFileEdges[$relativePath] = $edges;
            $totalLoc += $loc;

            if ($file->getBasename() === self::REFILLER_BASENAME) {
                $refillerLoc = $loc;
            }
        }

        ksort($perFileLoc, SORT_STRING);
        ksort($perFileEdges, SORT_STRING);

        return $this->envelope($perFileLoc, $totalLoc, $refillerLoc, $perFileEdges);
    }

    private function countLoc(string $contents): int
    {
        $lines = explode("\n", $contents);
        $count = 0;
        foreach ($lines as $line) {
            if (trim($line) !== '') {
                $count++;
            }
        }

        return $count;
    }

    /**
     * @return list<string>
     */
    private function extractEdges(string $contents): array
    {
        $edges = [];
        if (preg_match_all('/^use\s+(App\\\\Services\\\\Ai\\\\AutonomousEvolution\\\\[^\s;]+)/m', $contents, $matches)) {
            foreach ($matches[1] as $fqcn) {
                $edges[] = $fqcn;
            }
        }
        sort($edges, SORT_STRING);

        return array_values(array_unique($edges));
    }

    private function defaultDirectory(): string
    {
        $base = $this->basePath !== '' ? $this->basePath : base_path();

        return $base.'/app/Services/Ai/AutonomousEvolution';
    }

    /**
     * @param  array<string, int>  $perFileLoc
     * @param  array<string, list<string>>  $perFileEdges
     * @return array<string, mixed>
     */
    private function envelope(array $perFileLoc, int $totalLoc, int $refillerLoc, array $perFileEdges): array
    {
        return [
            'schema_version' => self::SCHEMA,
            'totalLoc' => $totalLoc,
            'refillerLoc' => $refillerLoc,
            'fileCount' => count($perFileLoc),
            'perFileLoc' => $perFileLoc,
            'perFileEdges' => $perFileEdges,
        ];
    }
}
