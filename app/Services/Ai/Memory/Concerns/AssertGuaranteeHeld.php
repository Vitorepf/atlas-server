<?php

declare(strict_types=1);

namespace App\Services\Ai\Memory\Concerns;

/**
 * Prove that no result flipped a non-execution guarantee key (or the two
 * restated guarantees) to a truthy value. Returns "surface.key" violations
 * (empty = intact).
 *
 * Consolidation census 05/07 — extracted from 5 byte-identical copies across
 * Generated contract services (CollisionGuardBlueprintContractService,
 * CollisionGuardContractService, LeaseLifecycleBlueprintContractService,
 * LeaseLifecycleContractService, RepositoryBlueprintContractService).
 * Each consuming class provides its own GUARANTEE_KEYS constant.
 *
 * @see docs/engineering-knowledge-base/self-construction/
 */
trait AssertGuaranteeHeld
{
    /**
     * Prove that no result flipped a non-execution guarantee key (or the two
     * restated guarantees) to a truthy value. Returns "surface.key" violations
     * (empty = intact).
     *
     * @param  list<array<string,mixed>>  $results
     * @return list<string>
     */
    public function assertGuaranteeHeld(array $results): array
    {
        $violations = [];
        foreach ($results as $result) {
            $label = is_string($result['surface'] ?? null) ? $result['surface'] : 'unknown';

            $guarantee = is_array($result['guarantee'] ?? null) ? $result['guarantee'] : [];
            foreach (self::GUARANTEE_KEYS as $key) {
                if (! array_key_exists($key, $guarantee) || $guarantee[$key] !== false) {
                    $violations[] = $label.'.'.$key;
                }
            }

            foreach (['claim_persisted', 'is_execution'] as $extra) {
                if (array_key_exists($extra, $result) && $result[$extra] !== false) {
                    $violations[] = $label.'.'.$extra;
                }
            }
        }

        return $violations;
    }
}
