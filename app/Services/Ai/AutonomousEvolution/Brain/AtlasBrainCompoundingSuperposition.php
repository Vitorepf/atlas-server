<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Brain;

/**
 * COMPOUNDING SUPERPOSITION — compounding organ. Per-path fusion of four compounding signals:
 * momentum trend, velocity label, ewma yield, streak ratio label. Produces a single per-path row
 * the operator can read at a glance.
 *
 * Pure composition over already-built outputs. No IO. Pétreo: réu would drop a layer to hide
 * deterioration.
 */
final class AtlasBrainCompoundingSuperposition
{
    public const SCHEMA = 'atlas.brain.compounding_superposition.v1';

    /**
     * @param  array<string, array{trend:string}>  $momentum  from AtlasBrainPathYieldMomentum
     * @param  array<string, array{label:string}>  $velocity  from AtlasBrainCompoundingVelocity
     * @param  array<string, array{ewma:float}>  $ewma  from AtlasBrainPathYieldEwma
     * @param  array<string, array{label:string}>  $streakRatio  from AtlasBrainPathStreakRatio
     * @return array{schema:string, by_path:array<string, array{momentum:string, velocity:string, ewma:float, streak:string}>}
     */
    public function fuse(array $momentum, array $velocity, array $ewma, array $streakRatio): array
    {
        $paths = array_unique(array_merge(
            array_keys($momentum),
            array_keys($velocity),
            array_keys($ewma),
            array_keys($streakRatio),
        ));
        $out = [];
        foreach ($paths as $path) {
            $out[$path] = [
                'momentum' => $momentum[$path]['trend'] ?? 'unknown',
                'velocity' => $velocity[$path]['label'] ?? 'unknown',
                'ewma' => (float) ($ewma[$path]['ewma'] ?? 0.0),
                'streak' => $streakRatio[$path]['label'] ?? 'no_data',
            ];
        }

        return ['schema' => self::SCHEMA, 'by_path' => $out];
    }
}
