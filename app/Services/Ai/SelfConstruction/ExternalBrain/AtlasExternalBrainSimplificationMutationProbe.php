<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Invariant tester for simplification readiness: a refactor task is never ready on the strength
 * of "all tests pass" alone — shallow tests can pass while a mutation quietly removes a branch,
 * changes an output, or drops a consumer. This probe demands NAMED mutation-guard coverage for
 * each of those three mutation classes before marking ready.
 *
 * Input shape:
 *   { mutation_test_coverage: {
 *       removed_branch?:   bool,   // a test fails if a conditional branch is deleted
 *       changed_output?:   bool,   // a test fails if a return/output value changes
 *       missing_consumer?: bool,   // a test fails if a known consumer stops being wired
 *   } }
 *
 * Any missing guard makes ready=false, and missing_mutation_guards names EXACTLY which guard is
 * absent so the caller can add the specific coverage instead of writing more generic tests.
 *
 * Pure: no I/O, no provider calls, deterministic — callers supply the coverage facts.
 */
final class AtlasExternalBrainSimplificationMutationProbe
{
    public const SCHEMA = 'atlas.self_construction.external_brain.simplification_mutation_probe.v1';

    /** @var list<string> */
    private const REQUIRED_MUTATION_GUARDS = [
        'removed_branch',
        'changed_output',
        'missing_consumer',
    ];

    /**
     * @param  array{mutation_test_coverage?: array<string,mixed>}  $facts
     * @return array<string,mixed>
     */
    public function probe(array $facts): array
    {
        $coverage = is_array($facts['mutation_test_coverage'] ?? null) ? $facts['mutation_test_coverage'] : [];

        $missingGuards = [];
        foreach (self::REQUIRED_MUTATION_GUARDS as $guard) {
            if (! (bool) ($coverage[$guard] ?? false)) {
                $missingGuards[] = $guard;
            }
        }

        return [
            'schema' => self::SCHEMA,
            'ready' => $missingGuards === [],
            'missing_mutation_guards' => $missingGuards,
        ];
    }
}
