<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Invariant planner for module-seam leakage: simplification should not only delete code — it
 * should tighten module boundaries so complexity does not leak back in through the same seam.
 * For each leaked internal, this planner proposes a boundary owner and the exact contract test
 * that must exist once the boundary tightens. Tightening HOLDS whenever real consumers still
 * depend on the leaked internal and no replacement entrypoint exists yet — cutting off a
 * consumer's only path in is never approved without a proven alternative.
 *
 * Input shape:
 *   { leaks: list<{
 *       internal?:                    string,
 *       proposed_owner?:              string,
 *       consumer_count?:              int,
 *       has_replacement_entrypoint?:  bool,
 *   }> }
 *
 * Pure PHP, deterministic, no I/O.
 */
final class AtlasExternalBrainModuleBoundaryTightener
{
    public const SCHEMA = 'atlas.self_construction.external_brain.module_boundary_tightener.v1';

    public const ACTION_TIGHTEN_BOUNDARY = 'tighten_boundary';

    /**
     * @param  array{leaks?: list<array<string,mixed>>}  $facts
     * @return array{schema:string, actions:list<array<string,mixed>>, held:list<array<string,mixed>>, required_contract_tests:list<string>}
     */
    public function tighten(array $facts): array
    {
        $leaks = is_array($facts['leaks'] ?? null) ? $facts['leaks'] : [];

        $actions = [];
        $held = [];
        $requiredContractTests = [];

        foreach ($leaks as $leak) {
            if (! is_array($leak)) {
                continue;
            }
            $internal = trim((string) ($leak['internal'] ?? ''));
            if ($internal === '') {
                continue;
            }

            $proposedOwner = trim((string) ($leak['proposed_owner'] ?? ''));
            $consumerCount = max(0, (int) ($leak['consumer_count'] ?? 0));
            $hasReplacementEntrypoint = (bool) ($leak['has_replacement_entrypoint'] ?? false);

            if ($consumerCount > 0 && ! $hasReplacementEntrypoint) {
                $held[] = [
                    'internal' => $internal,
                    'reason' => 'consumers_depend_on_leaked_internal_without_replacement_entrypoint',
                    'required_proof' => ['replacement_entrypoint_proof'],
                ];

                continue;
            }

            $contractTest = "contract_test_for:{$internal}";
            $requiredContractTests[] = $contractTest;
            $actions[] = [
                'internal' => $internal,
                'proposed_owner' => $proposedOwner,
                'action' => self::ACTION_TIGHTEN_BOUNDARY,
                'required_contract_test' => $contractTest,
            ];
        }

        return [
            'schema' => self::SCHEMA,
            'actions' => $actions,
            'held' => $held,
            'required_contract_tests' => $requiredContractTests,
        ];
    }
}
