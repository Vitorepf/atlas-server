<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Simplification;

/**
 * Turns a redundancy cluster into a delete_or_merge plan ONLY when behavior
 * equivalence, consumer coverage, a replacement owner, and rollback notes are
 * all present. Pure, deterministic, no I/O: refuses (action=blocked) rather
 * than guessing when any of the four safety facts is missing.
 */
final class AtlasSelfConstructionSafeDeletionPlanner
{
    /**
     * @param  array<string,mixed>  $cluster
     * @return array<string,mixed>
     */
    public function plan(array $cluster): array
    {
        $reasons = [];

        $behaviorEquivalenceProven = (bool) ($cluster['behavior_equivalence_proven'] ?? false);
        if (! $behaviorEquivalenceProven) {
            $reasons[] = 'behavior_equivalence_not_proven';
        }

        $consumers = array_values((array) ($cluster['consumers'] ?? []));
        $uncoveredConsumers = array_values(array_filter(
            $consumers,
            static fn ($consumer): bool => ! (bool) (is_array($consumer) ? ($consumer['covered'] ?? false) : false),
        ));
        if ($consumers === [] || $uncoveredConsumers !== []) {
            $reasons[] = 'consumer_coverage_incomplete';
        }

        $replacementOwner = trim((string) ($cluster['replacement_owner'] ?? ''));
        if ($replacementOwner === '') {
            $reasons[] = 'replacement_owner_missing';
        }

        $rollbackPlan = trim((string) ($cluster['rollback_notes'] ?? ''));
        if ($rollbackPlan === '') {
            $reasons[] = 'rollback_notes_missing';
        }

        if ($reasons !== []) {
            return [
                'action' => 'blocked',
                'risk_reasons' => $reasons,
            ];
        }

        return [
            'action' => 'delete_or_merge',
            'allowed_files' => array_values((array) ($cluster['allowed_files'] ?? [])),
            'required_tests' => array_values((array) ($cluster['required_tests'] ?? [])),
            'rollback_plan' => $rollbackPlan,
            'risk_reasons' => [],
        ];
    }
}
