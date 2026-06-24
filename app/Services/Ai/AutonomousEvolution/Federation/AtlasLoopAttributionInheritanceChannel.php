<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Federation;

use App\Services\Ai\AutonomousEvolution\MultiCycle\AtlasLoopMultiCycleSubScopePartitioner;
use InvalidArgumentException;

/**
 * Federates per-cycle attribution priors into a deterministic global prior.
 *
 * The channel preserves the disjoint file-scope assumption used by
 * {@see AtlasLoopMultiCycleSubScopePartitioner}: if callers provide cycle files, overlap is rejected instead
 * of blending attribution from colliding work.
 */
final class AtlasLoopAttributionInheritanceChannel
{
    public const SCHEMA_VERSION = 'atlas.loop.attribution_inheritance_channel.v1';

    /**
     * @param  list<array<string,mixed>>  $perCyclePriors
     * @return array{schema:string, global_prior:list<array{shape_token:string, samples:int, mean_delta:float}>, contributing_cycles:list<array{cycle_id:string, shape_tokens:list<string>}>}
     */
    public function mergePriors(array $perCyclePriors): array
    {
        $this->assertDisjointFiles($perCyclePriors);

        /** @var array<string,array{samples:int, weighted_delta:float, cycles:array<string,bool>}> $byShape */
        $byShape = [];
        /** @var array<string,array<string,bool>> $cycleShapes */
        $cycleShapes = [];

        foreach ($perCyclePriors as $index => $cycle) {
            if (! is_array($cycle)) {
                continue;
            }

            $cycleId = $this->cycleId($cycle, $index);
            foreach ($this->priorRows($cycle) as $row) {
                $shape = trim((string) ($row['shape_token'] ?? ''));
                $samples = max(0, (int) ($row['samples'] ?? 0));
                if ($shape === '' || $samples <= 0) {
                    continue;
                }

                $mean = (float) ($row['mean_delta'] ?? 0.0);
                $byShape[$shape] ??= ['samples' => 0, 'weighted_delta' => 0.0, 'cycles' => []];
                $byShape[$shape]['samples'] += $samples;
                $byShape[$shape]['weighted_delta'] += $mean * $samples;
                $byShape[$shape]['cycles'][$cycleId] = true;
                $cycleShapes[$cycleId][$shape] = true;
            }
        }

        ksort($byShape, SORT_STRING);

        $global = [];
        foreach ($byShape as $shape => $data) {
            $samples = max(1, (int) $data['samples']);
            $global[] = [
                'shape_token' => $shape,
                'samples' => $samples,
                'mean_delta' => round(((float) $data['weighted_delta']) / $samples, 3),
            ];
        }

        ksort($cycleShapes, SORT_STRING);
        $cycles = [];
        foreach ($cycleShapes as $cycleId => $shapeSet) {
            $shapeTokens = array_keys($shapeSet);
            sort($shapeTokens, SORT_STRING);
            $cycles[] = ['cycle_id' => $cycleId, 'shape_tokens' => $shapeTokens];
        }

        return [
            'schema' => self::SCHEMA_VERSION,
            'global_prior' => $global,
            'contributing_cycles' => $cycles,
        ];
    }

    /** @param array<string,mixed> $cycle */
    private function cycleId(array $cycle, int $index): string
    {
        foreach (['cycle_id', 'id'] as $key) {
            $value = $cycle[$key] ?? null;
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return 'cycle-'.($index + 1);
    }

    /**
     * @param  array<string,mixed>  $cycle
     * @return list<array<string,mixed>>
     */
    private function priorRows(array $cycle): array
    {
        if (isset($cycle['shape_token'])) {
            return [$cycle];
        }

        foreach (['global_prior', 'by_shape', 'shape_priors', 'prior'] as $key) {
            if (is_array($cycle[$key] ?? null)) {
                return array_values(array_filter((array) $cycle[$key], 'is_array'));
            }
        }

        return [];
    }

    /** @param list<array<string,mixed>> $perCyclePriors */
    private function assertDisjointFiles(array $perCyclePriors): void
    {
        $seen = [];
        foreach ($perCyclePriors as $index => $cycle) {
            if (! is_array($cycle)) {
                continue;
            }
            $cycleId = $this->cycleId($cycle, $index);
            foreach ($this->files($cycle) as $file) {
                if (isset($seen[$file])) {
                    throw new InvalidArgumentException('overlapping_cycle_scope:'.$file.':'.$seen[$file].':'.$cycleId);
                }
                $seen[$file] = $cycleId;
            }
        }
    }

    /**
     * @param  array<string,mixed>  $cycle
     * @return list<string>
     */
    private function files(array $cycle): array
    {
        foreach (['files', 'scope_files', 'allowed_files'] as $key) {
            if (! is_array($cycle[$key] ?? null)) {
                continue;
            }

            $files = [];
            foreach ($cycle[$key] as $file) {
                if (is_string($file) && trim($file) !== '') {
                    $files[] = ltrim(str_replace('\\', '/', trim($file)), '/');
                }
            }
            $files = array_values(array_unique($files));
            sort($files, SORT_STRING);

            return $files;
        }

        return [];
    }
}
