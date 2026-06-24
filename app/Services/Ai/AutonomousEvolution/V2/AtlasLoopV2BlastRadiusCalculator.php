<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\V2;

final class AtlasLoopV2BlastRadiusCalculator
{
    public const SCHEMA_VERSION = 'atlas.ai.loop_v2.blast_radius.v1';

    /**
     * @param  list<string>  $changedFiles
     * @param  array<string,list<string>>  $repoFileImportGraph file => direct consumer files
     * @param  list<string>  $criticalPaths
     * @return array{
     *     schema_version:string,
     *     files_touched:int,
     *     transitive_consumers:int,
     *     critical_path_hits:list<string>,
     *     radius_score:int,
     *     tier:'safe'|'moderate'|'wide'|'critical',
     *     reasons:list<string>
     * }
     */
    public function calculate(array $changedFiles, array $repoFileImportGraph, array $criticalPaths): array
    {
        $changed = $this->uniqueStrings($changedFiles);
        $criticalHits = $this->criticalPathHits($changed, $criticalPaths);
        $transitiveConsumers = $this->transitiveConsumerCount($changed, $repoFileImportGraph);
        $filesTouched = count($changed);
        $score = min(100, max(0, ($filesTouched * 2) + $transitiveConsumers + (count($criticalHits) * 15)));
        $tier = $this->tier($filesTouched, $transitiveConsumers, $criticalHits);

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'files_touched' => $filesTouched,
            'transitive_consumers' => $transitiveConsumers,
            'critical_path_hits' => $criticalHits,
            'radius_score' => $score,
            'tier' => $tier,
            'reasons' => $this->reasons($tier, $filesTouched, $transitiveConsumers, $criticalHits),
        ];
    }

    /**
     * @param  list<string>  $changed
     * @param  array<string,list<string>>  $graph
     */
    private function transitiveConsumerCount(array $changed, array $graph): int
    {
        $visited = array_fill_keys($changed, true);
        $consumers = [];
        $queue = $changed;

        while ($queue !== []) {
            $file = array_shift($queue);
            foreach ($this->uniqueStrings((array) ($graph[$file] ?? [])) as $consumer) {
                if (isset($visited[$consumer])) {
                    continue;
                }
                $visited[$consumer] = true;
                $consumers[$consumer] = true;
                $queue[] = $consumer;
            }
        }

        return count($consumers);
    }

    /**
     * @param  list<string>  $changed
     * @param  list<string>  $criticalPaths
     * @return list<string>
     */
    private function criticalPathHits(array $changed, array $criticalPaths): array
    {
        $prefixes = $this->criticalPrefixes($criticalPaths);
        $hits = [];
        foreach ($changed as $file) {
            foreach ($prefixes as $prefix) {
                if (str_starts_with($file, $prefix)) {
                    $hits[] = $file;
                    break;
                }
            }
        }

        return $this->uniqueStrings($hits);
    }

    /**
     * @param  list<string>  $paths
     * @return list<string>
     */
    private function criticalPrefixes(array $paths): array
    {
        return $this->uniqueStrings(array_map(
            static fn (string $path): string => rtrim(trim($path), '*'),
            $paths,
        ));
    }

    /**
     * @param  list<string>  $values
     * @return list<string>
     */
    private function uniqueStrings(array $values): array
    {
        $out = [];
        foreach ($values as $value) {
            $value = trim((string) $value);
            if ($value === '') {
                continue;
            }
            $out[$value] = true;
        }

        $values = array_keys($out);
        sort($values);

        return array_values($values);
    }

    /**
     * @param  list<string>  $criticalHits
     * @return 'safe'|'moderate'|'wide'|'critical'
     */
    private function tier(int $filesTouched, int $transitiveConsumers, array $criticalHits): string
    {
        if ($criticalHits !== []) {
            return 'critical';
        }
        if ($transitiveConsumers >= 50 || $filesTouched >= 20) {
            return 'wide';
        }
        if ($transitiveConsumers >= 10 || $filesTouched >= 5) {
            return 'moderate';
        }

        return 'safe';
    }

    /**
     * @param  list<string>  $criticalHits
     * @return list<string>
     */
    private function reasons(string $tier, int $filesTouched, int $transitiveConsumers, array $criticalHits): array
    {
        if ($tier === 'critical') {
            return ['critical_path_hit:'.implode(',', $criticalHits)];
        }
        if ($tier === 'wide') {
            return array_values(array_filter([
                $filesTouched >= 20 ? 'files_touched_ge_20' : null,
                $transitiveConsumers >= 50 ? 'transitive_consumers_ge_50' : null,
            ]));
        }
        if ($tier === 'moderate') {
            return array_values(array_filter([
                $filesTouched >= 5 ? 'files_touched_ge_5' : null,
                $transitiveConsumers >= 10 ? 'transitive_consumers_ge_10' : null,
            ]));
        }

        return ['within_safe_blast_radius'];
    }
}
