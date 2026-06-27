<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Brain;

/**
 * NEXT-CYCLE PROJECTOR — simulation-twin lens. Combines the plan adviser's recommended path,
 * the red-team verdict (approve/veto+alternative) and per-path momentum to project which path
 * the next cycle is most likely to take. Returns projected_path + reason + confidence label.
 *
 * Pure decision over already-built inputs. No IO. Pétreo: a réu would skew the projection to
 * always endorse its own preferred path.
 */
final class AtlasBrainNextCycleProjector
{
    public const SCHEMA = 'atlas.brain.next_cycle_projector.v1';

    /**
     * @param  array{verdict:string, recommended_path:string, alternative_path:?string, reason:string}  $redTeam
     * @param  array<string, array{trend:string, delta:float}>  $momentumByPath  shape from AtlasBrainPathYieldMomentum::compute()['by_path']
     * @return array{schema:string, projected_path:string, source:string, confidence:string, reason:string}
     */
    public function project(string $recommendedPath, array $redTeam, array $momentumByPath): array
    {
        if ($redTeam['verdict'] === 'veto' && $redTeam['alternative_path'] !== null) {
            $alt = $redTeam['alternative_path'];
            $confidence = $this->confidenceFromMomentum($alt, $momentumByPath, fallback: 'medium');

            return [
                'schema' => self::SCHEMA,
                'projected_path' => $alt,
                'source' => 'red_team_alternative',
                'confidence' => $confidence,
                'reason' => $redTeam['reason'],
            ];
        }

        $confidence = $this->confidenceFromMomentum($recommendedPath, $momentumByPath, fallback: 'low');

        return [
            'schema' => self::SCHEMA,
            'projected_path' => $recommendedPath,
            'source' => 'plan_adviser',
            'confidence' => $confidence,
            'reason' => $redTeam['reason'],
        ];
    }

    /**
     * @param  array<string, array{trend:string, delta:float}>  $momentumByPath
     */
    private function confidenceFromMomentum(string $path, array $momentumByPath, string $fallback): string
    {
        if (! isset($momentumByPath[$path])) {
            return $fallback;
        }
        $m = $momentumByPath[$path];

        return match ($m['trend']) {
            'improving' => 'high',
            'declining' => 'low',
            default => 'medium',
        };
    }
}
