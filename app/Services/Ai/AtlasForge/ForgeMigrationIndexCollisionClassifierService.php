<?php

declare(strict_types=1);

namespace App\Services\Ai\AtlasForge;

final class ForgeMigrationIndexCollisionClassifierService
{
    private const SCHEMA_VERSION = 'atlas.collision.report.v1';

    /**
     * @param  list<int>  $branchMigrationIndexes  Migration indexes added by the candidate branch.
     * @param  array<string, list<int>>  $otherMigrationIndexes  otherAgentId => list of indexes held by that agent.
     * @return array{schema_version: string, collisions: list<array{kind: string, ref: string, index: int, agents: list<string>}>, auto_resolvable: bool, blocking: bool}
     */
    public function classify(array $branchMigrationIndexes, array $otherMigrationIndexes): array
    {
        $candidateIndexes = $this->normaliseIndexSet($branchMigrationIndexes);

        $collisions = [];

        foreach ($candidateIndexes as $index) {
            $agents = $this->otherAgentsHolding($index, $otherMigrationIndexes);

            if ($agents === []) {
                continue;
            }

            $agents[] = 'self';
            sort($agents);

            $collisions[$index] = [
                'kind' => 'migration',
                'ref' => (string) $index,
                'index' => $index,
                'agents' => array_values($agents),
            ];
        }

        ksort($collisions);

        $orderedCollisions = array_values($collisions);
        $hasCollision = $orderedCollisions !== [];

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'collisions' => $orderedCollisions,
            'auto_resolvable' => ! $hasCollision,
            'blocking' => $hasCollision,
        ];
    }

    /**
     * Distinct candidate indexes as ints.
     *
     * @param  list<int>  $indexes
     * @return list<int>
     */
    private function normaliseIndexSet(array $indexes): array
    {
        $set = [];

        foreach ($indexes as $value) {
            $set[(int) $value] = true;
        }

        return array_map('intval', array_keys($set));
    }

    /**
     * Distinct other-agent ids that also hold the given index.
     *
     * @param  array<string, list<int>>  $otherMigrationIndexes
     * @return list<string>
     */
    private function otherAgentsHolding(int $index, array $otherMigrationIndexes): array
    {
        $agents = [];

        foreach ($otherMigrationIndexes as $agentId => $heldIndexes) {
            foreach ($heldIndexes as $held) {
                if ((int) $held === $index) {
                    $agents[(string) $agentId] = true;

                    break;
                }
            }
        }

        return array_keys($agents);
    }
}
