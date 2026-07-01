<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Invariant planner: some compression is blocked by a concrete dependency pointing the wrong
 * direction — two modules can't safely consolidate while one depends directly on the other's
 * implementation. Dependency inversion is the enabling move, but it is never proposed blindly:
 * this advisor recommends invert_dependency only when a stable abstraction already exists,
 * consumer proof shows who depends on the concrete type today, and contract tests pin the
 * abstraction's behavior. Any one of those three missing holds the recommendation and names
 * exactly what prework is needed instead of a generic "add an interface" suggestion.
 *
 * Input contract:
 *   stable_abstraction_exists?:  bool
 *   consumer_proof_available?:   bool
 *   contract_tests_exist?:       bool
 *
 * Pure PHP, deterministic, no I/O.
 */
final class AtlasExternalBrainDependencyInversionAdvisor
{
    public const SCHEMA = 'atlas.external_brain.dependency_inversion_advisor.v1';

    public const RECOMMENDATION_INVERT_DEPENDENCY = 'invert_dependency';
    public const RECOMMENDATION_HOLD              = 'hold';

    private const PREREQUISITE_FACT_TO_PREWORK = [
        'stable_abstraction_exists' => 'define_stable_abstraction',
        'consumer_proof_available'  => 'gather_consumer_proof',
        'contract_tests_exist'      => 'add_contract_tests',
    ];

    /**
     * @param  array<string,mixed>  $facts
     * @return array{schema:string, recommendation:string, required_prework:list<string>}
     */
    public function advise(array $facts): array
    {
        $requiredPrework = [];

        foreach (self::PREREQUISITE_FACT_TO_PREWORK as $fact => $prework) {
            if (! (bool) ($facts[$fact] ?? false)) {
                $requiredPrework[] = $prework;
            }
        }

        if ($requiredPrework !== []) {
            return [
                'schema'            => self::SCHEMA,
                'recommendation'    => self::RECOMMENDATION_HOLD,
                'required_prework'  => $requiredPrework,
            ];
        }

        return [
            'schema'            => self::SCHEMA,
            'recommendation'    => self::RECOMMENDATION_INVERT_DEPENDENCY,
            'required_prework'  => [],
        ];
    }
}
