<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Invariant planner for dependency-cone shrinkage: code can be short and still hard to maintain
 * when its dependency cone stays large and tangled. This reducer ranks candidate dependencies by
 * how safely they can be removed — fewer preserved contracts first — and holds anything that
 * shares a public contract, has unknown consumers, or is not yet proven removable, naming the
 * exact proof still required rather than offering generic decoupling advice.
 *
 * Input shape:
 *   { candidates: list<{
 *       dependency?:                string,
 *       removable?:                 bool,   // already proven unused/replaceable
 *       shared_public_contract?:    bool,   // this dependency is part of a public contract
 *       consumers_known?:           bool,   // every consumer of this dependency is enumerated
 *       preserved_contract_count?:  int,    // how many contracts survive removal (fewer = safer)
 *   }> }
 *
 * Pure PHP, deterministic, no I/O.
 */
final class AtlasExternalBrainDependencyConeReducer
{
    public const SCHEMA = 'atlas.self_construction.external_brain.dependency_cone_reducer.v1';

    /**
     * @param  array{candidates?: list<array<string,mixed>>}  $facts
     * @return array{schema:string, ranked_candidates:list<string>, ranked_details:list<array<string,mixed>>, held:list<array<string,mixed>>}
     */
    public function rank(array $facts): array
    {
        $candidates = is_array($facts['candidates'] ?? null) ? $facts['candidates'] : [];

        $ranked = [];
        $held = [];

        foreach ($candidates as $c) {
            if (! is_array($c)) {
                continue;
            }
            $dependency = trim((string) ($c['dependency'] ?? ''));
            if ($dependency === '') {
                continue;
            }

            $removable = (bool) ($c['removable'] ?? false);
            $sharedPublicContract = (bool) ($c['shared_public_contract'] ?? false);
            $consumersKnown = (bool) ($c['consumers_known'] ?? false);
            $preservedContractCount = max(0, (int) ($c['preserved_contract_count'] ?? 0));

            $requiredProof = [];
            if ($sharedPublicContract) {
                $requiredProof[] = 'public_contract_migration_proof';
            }
            if (! $consumersKnown) {
                $requiredProof[] = 'consumer_enumeration_proof';
            }
            if (! $removable) {
                $requiredProof[] = 'removability_proof';
            }

            if ($requiredProof !== []) {
                $held[] = [
                    'dependency' => $dependency,
                    'required_proof' => $requiredProof,
                ];

                continue;
            }

            $ranked[] = [
                'dependency' => $dependency,
                'preserved_contract_count' => $preservedContractCount,
            ];
        }

        usort($ranked, static function (array $a, array $b): int {
            $cmp = $a['preserved_contract_count'] <=> $b['preserved_contract_count'];

            return $cmp !== 0 ? $cmp : strcmp($a['dependency'], $b['dependency']);
        });

        return [
            'schema' => self::SCHEMA,
            'ranked_candidates' => array_column($ranked, 'dependency'),
            'ranked_details' => $ranked,
            'held' => $held,
        ];
    }
}
