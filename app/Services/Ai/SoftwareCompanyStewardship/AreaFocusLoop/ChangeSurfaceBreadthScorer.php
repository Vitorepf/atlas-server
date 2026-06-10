<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

final class ChangeSurfaceBreadthScorer
{
    public const SCHEMA_VERSION = 'atlas.software_company_stewardship.change_surface_breadth.v1';

    /**
     * @param  list<string>  $allowedFiles
     * @return array{
     *     schema_version: string,
     *     file_count: int,
     *     layer_count: int,
     *     directory_count: int,
     *     breadth_band: 'narrow'|'moderate'|'broad',
     *     breadth_score: float,
     *     bounded: bool,
     *     reasons: list<string>
     * }
     */
    public function score(array $allowedFiles): array
    {
        $files = AreaFocusPathNormalizer::trimmedRepoRelativeUniqueStrings($allowedFiles);
        if ($files === []) {
            return [
                'schema_version' => self::SCHEMA_VERSION,
                'file_count' => 0,
                'layer_count' => 0,
                'directory_count' => 0,
                'breadth_band' => 'broad',
                'breadth_score' => 0.0,
                'bounded' => false,
                'reasons' => ['no_allowed_files'],
            ];
        }

        $productionFiles = array_values(array_filter(
            $files,
            fn (string $path): bool => $this->layerOf($path) !== null,
        ));

        $fileCount = count($files);
        $layerCount = $this->layerCount($productionFiles, $fileCount);
        $directoryCount = $this->directoryCount($productionFiles);
        $breadthBand = $this->breadthBand($fileCount, $layerCount, $directoryCount);

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'file_count' => $fileCount,
            'layer_count' => $layerCount,
            'directory_count' => $directoryCount,
            'breadth_band' => $breadthBand,
            'breadth_score' => $this->breadthScore($fileCount, $layerCount, $directoryCount),
            'bounded' => $breadthBand !== 'broad',
            'reasons' => $this->reasons($fileCount, $layerCount, $directoryCount, $breadthBand),
        ];
    }

    private function layerOf(string $path): ?string
    {
        $segments = explode('/', $path);
        $firstSegment = strtolower($segments[0] ?? '');
        if ($firstSegment === 'tests' || $firstSegment === 'test' || str_ends_with($path, 'Test.php')) {
            return null;
        }

        return implode('/', array_slice($segments, 0, 2));
    }

    /**
     * @param  list<string>  $productionFiles
     */
    private function layerCount(array $productionFiles, int $fileCount): int
    {
        $layers = [];
        foreach ($productionFiles as $path) {
            $layer = $this->layerOf($path);
            if ($layer !== null && $layer !== '') {
                $layers[$layer] = true;
            }
        }

        if ($layers === [] && $fileCount > 0) {
            return 1;
        }

        return count($layers);
    }

    /**
     * @param  list<string>  $productionFiles
     */
    private function directoryCount(array $productionFiles): int
    {
        $directories = [];
        foreach ($productionFiles as $path) {
            $directories[dirname($path)] = true;
        }

        return count($directories);
    }

    private function breadthBand(int $fileCount, int $layerCount, int $directoryCount): string
    {
        if (
            $fileCount > FindingSlicePlannerService::MAX_FILES_PER_SLICE
            || $layerCount > 1
            || $directoryCount > 2
        ) {
            return 'broad';
        }

        if ($fileCount > 2 || $directoryCount > 1) {
            return 'moderate';
        }

        return 'narrow';
    }

    private function breadthScore(int $fileCount, int $layerCount, int $directoryCount): float
    {
        $pressure = ($fileCount / 4 + $layerCount / 2 + $directoryCount / 3) / 3;

        return round(max(0.0, min(1.0, $pressure)), 2);
    }

    /** @return list<string> */
    private function reasons(int $fileCount, int $layerCount, int $directoryCount, string $breadthBand): array
    {
        $reasons = [];
        if ($fileCount > FindingSlicePlannerService::MAX_FILES_PER_SLICE) {
            $reasons[] = 'too_many_files';
        }
        if ($layerCount > 1) {
            $reasons[] = 'multiple_layers';
        }
        if ($directoryCount > 2) {
            $reasons[] = 'directory_spread';
        }
        if ($reasons === [] && ($breadthBand === 'narrow' || $breadthBand === 'moderate')) {
            $reasons[] = 'within_breadth_budget';
        }

        return $reasons;
    }
}
