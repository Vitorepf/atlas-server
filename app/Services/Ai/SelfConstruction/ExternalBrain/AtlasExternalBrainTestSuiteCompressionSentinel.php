<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Fail-closed test-suite quality gate: code simplification can accidentally simplify the TESTS
 * too, producing a green suite that verifies less than it did before. A passing test count alone
 * is never proof — this sentinel compares three independent test-intent categories before and
 * after compression and holds on any category that shrank, naming exactly which intent weakened.
 *
 * INTENT CATEGORIES compared (post-compression count must be >= pre-compression count for each):
 *   behavior_case_count  — tests proving the documented happy-path behavior still holds.
 *   negative_case_count  — tests proving invalid/edge-case input is still rejected correctly.
 *   mutation_guard_count — tests strong enough to kill an introduced mutant (not just execute the
 *                          line).
 *
 * Input shape:
 *   { before: {behavior_case_count?:int, negative_case_count?:int, mutation_guard_count?:int},
 *     after:  {behavior_case_count?:int, negative_case_count?:int, mutation_guard_count?:int} }
 *
 * Verdict: approved ONLY when no category weakened; hold otherwise, naming the exact missing test
 * intent per category — never a generic "coverage decreased" label.
 *
 * Pure: no I/O, no provider calls, no queue mutation.
 */
final class AtlasExternalBrainTestSuiteCompressionSentinel
{
    public const SCHEMA = 'atlas.external_brain.test_suite_compression_sentinel.v1';

    public const VERDICT_APPROVED = 'approved';

    public const VERDICT_HOLD = 'hold';

    private const CATEGORIES = ['behavior_case_count', 'negative_case_count', 'mutation_guard_count'];

    /**
     * @param  array{before?: array<string,mixed>, after?: array<string,mixed>}  $input
     * @return array{schema:string, verdict:string, category_results:list<array<string,mixed>>, missing_test_intent:list<string>}
     */
    public function evaluate(array $input): array
    {
        $before = is_array($input['before'] ?? null) ? $input['before'] : [];
        $after = is_array($input['after'] ?? null) ? $input['after'] : [];

        $categoryResults = [];
        $missingIntent = [];

        foreach (self::CATEGORIES as $category) {
            $beforeCount = max(0, (int) ($before[$category] ?? 0));
            $afterCount = max(0, (int) ($after[$category] ?? 0));
            $weakened = $afterCount < $beforeCount;

            $categoryResults[] = [
                'category' => $category,
                'before' => $beforeCount,
                'after' => $afterCount,
                'weakened' => $weakened,
            ];

            if ($weakened) {
                $missingIntent[] = "{$category}:reduced_from_{$beforeCount}_to_{$afterCount}";
            }
        }

        $verdict = $missingIntent === [] ? self::VERDICT_APPROVED : self::VERDICT_HOLD;

        return [
            'schema' => self::SCHEMA,
            'verdict' => $verdict,
            'category_results' => $categoryResults,
            'missing_test_intent' => $missingIntent,
        ];
    }
}
