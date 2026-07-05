<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Maestro\Health;

/**
 * Compiles post-round health, malformed sweep and queued-target
 * checks into one receipt that seed credit can consume.
 *
 * Read-only: never starts processes, never calls providers.
 */
final class AtlasMaestroRoundHealthReceiptCompiler
{
    public const SCHEMA = 'atlas.self_construction.maestro_round_health_receipt.v1';

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function compile(array $input): array
    {
        $healthPass = (bool) ($input['health_pass'] ?? true);
        $malformedPass = (bool) ($input['malformed_pass'] ?? true);
        $emittedCollisionPass = (bool) ($input['emitted_collision_pass'] ?? true);

        $healthBlockers = (array) ($input['health_blockers'] ?? []);
        $malformedBlockers = (array) ($input['malformed_blockers'] ?? []);
        $collisionBlockers = (array) ($input['collision_blockers'] ?? []);

        $allPass = $healthPass && $malformedPass && $emittedCollisionPass;

        $blockerSummary = [];
        if (! $healthPass) {
            $blockerSummary['health'] = $healthBlockers;
        }
        if (! $malformedPass) {
            $blockerSummary['malformed'] = $malformedBlockers;
        }
        if (! $emittedCollisionPass) {
            $blockerSummary['collision'] = $collisionBlockers;
        }

        return [
            'schema' => self::SCHEMA,
            'health_pass' => $healthPass,
            'malformed_pass' => $malformedPass,
            'emitted_collision_pass' => $emittedCollisionPass,
            'all_pass' => $allPass,
            'blocker_summary' => $blockerSummary,
            'health_blockers' => $healthBlockers,
            'malformed_blockers' => $malformedBlockers,
            'collision_blockers' => $collisionBlockers,
        ];
    }
}
