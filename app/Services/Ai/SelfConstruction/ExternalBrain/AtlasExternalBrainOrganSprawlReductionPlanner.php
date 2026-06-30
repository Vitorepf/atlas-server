<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Pure organ sprawl reduction planner. Ranks merge, retire, simplify and keep
 * actions across the ExternalBrain organ inventory to reduce class count while
 * preserving all wired capability.
 *
 * Classification (first match per organ):
 *   retire_blocked — needs retirement but is missing replacement_owner or test_coverage
 *   retire         — low evidence or scaffold retired, replacement+tests present
 *   merge          — overlapping organs present, replacement+tests present
 *   simplify       — oversized organ with few consumers
 *   keep           — otherwise
 *
 * AC3: refuses deletion without replacement_owner AND test_coverage; emits
 *      required_tests for every behavior that must survive.
 *
 * AC4: output always includes ranked_actions, expected_line_delta,
 *      capability_preserved_count, first_safe_batch, required_tests.
 *
 * Pure: no file mutations, no provider calls.
 */
final class AtlasExternalBrainOrganSprawlReductionPlanner
{
    public const SCHEMA = 'atlas.external_brain.organ_sprawl_reduction_planner.v1';

    public const ACTION_RETIRE_BLOCKED = 'retire_blocked';
    public const ACTION_RETIRE         = 'retire';
    public const ACTION_MERGE          = 'merge';
    public const ACTION_SIMPLIFY       = 'simplify';
    public const ACTION_KEEP           = 'keep';

    private const LOW_EVIDENCE_THRESHOLD    = 0.20;
    private const SIMPLIFY_LINE_FLOOR       = 200;
    private const SIMPLIFY_CONSUMER_CEILING = 2;

    // Priority rank for sorting (lower = higher priority in output)
    private const ACTION_RANK = [
        self::ACTION_RETIRE_BLOCKED => 0,
        self::ACTION_RETIRE         => 1,
        self::ACTION_MERGE          => 2,
        self::ACTION_SIMPLIFY       => 3,
        self::ACTION_KEEP           => 4,
    ];

    /**
     * @param  array{organs?: list<array<string,mixed>>}  $input
     * @return array{schema:string, ranked_actions:list<array<string,mixed>>, expected_line_delta:int, capability_preserved_count:int, first_safe_batch:list<string>, required_tests:list<string>}
     */
    public function plan(array $input): array
    {
        $organs = (array) ($input['organs'] ?? []);

        $actions              = [];
        $totalLineDelta       = 0;
        $capabilityPreserved  = 0;
        $firstSafeBatch       = [];
        $allRequiredTests     = [];

        foreach ($organs as $organ) {
            $entry = $this->classify($organ);
            $actions[] = $entry;

            $totalLineDelta += $entry['line_delta'];

            foreach ($entry['required_tests'] as $t) {
                if (! in_array($t, $allRequiredTests, true)) {
                    $allRequiredTests[] = $t;
                }
            }

            if ($entry['action'] === self::ACTION_KEEP) {
                $capabilityPreserved += count((array) ($organ['capability_labels'] ?? []));
            }

            if (in_array($entry['action'], [self::ACTION_RETIRE, self::ACTION_SIMPLIFY], true)) {
                $firstSafeBatch[] = (string) ($organ['organ_id'] ?? 'unknown');
            }
        }

        // Sort by action rank (retire_blocked first, keep last)
        usort($actions, static fn ($a, $b) =>
            (self::ACTION_RANK[$a['action']] ?? 99) <=> (self::ACTION_RANK[$b['action']] ?? 99)
        );

        return [
            'schema'                    => self::SCHEMA,
            'ranked_actions'            => $actions,
            'expected_line_delta'       => $totalLineDelta,
            'capability_preserved_count' => $capabilityPreserved,
            'first_safe_batch'          => $firstSafeBatch,
            'required_tests'            => array_values($allRequiredTests),
        ];
    }

    private function classify(array $organ): array
    {
        $id               = (string) ($organ['organ_id']             ?? 'unknown');
        $labels           = (array)  ($organ['capability_labels']    ?? []);
        $evidence         = max(0.0, min(1.0, (float) ($organ['evidence_strength']   ?? 1.0)));
        $consumers        = max(0,   (int)   ($organ['consumer_count']              ?? 0));
        $scaffoldStatus   = (string) ($organ['scaffold_status']      ?? 'active');
        $lineCount        = max(0,   (int)   ($organ['line_count']   ?? 0));
        $hasReplacement   = (bool)   ($organ['has_replacement_owner'] ?? false);
        $hasTests         = (bool)   ($organ['has_test_coverage']     ?? false);
        $overlapOrgans    = (array)  ($organ['overlap_organs']        ?? []);

        $needsRetirement = $evidence < self::LOW_EVIDENCE_THRESHOLD || $scaffoldStatus === 'retired';

        // RETIRE_BLOCKED — wants to retire but safety conditions not met
        if ($needsRetirement && (! $hasReplacement || ! $hasTests)) {
            $missing = [];
            if (! $hasReplacement) {
                $missing[] = 'replacement_owner';
            }
            if (! $hasTests) {
                $missing[] = 'test_coverage';
            }
            return $this->entry($id, self::ACTION_RETIRE_BLOCKED, [
                'missing:' . implode(',', $missing),
            ], 0, $this->requiredTests($id, $labels));
        }

        // RETIRE — low evidence/retired scaffold, safety met
        if ($needsRetirement) {
            return $this->entry($id, self::ACTION_RETIRE, [
                $evidence < self::LOW_EVIDENCE_THRESHOLD
                    ? sprintf('evidence_strength:%.4f<%.2f', $evidence, self::LOW_EVIDENCE_THRESHOLD)
                    : 'scaffold_status:retired',
            ], -$lineCount, $this->requiredTests($id, $labels));
        }

        // MERGE — overlapping capability, safety met
        if ($overlapOrgans !== [] && $hasReplacement && $hasTests) {
            return $this->entry($id, self::ACTION_MERGE, [
                'overlaps_with:' . implode(',', $overlapOrgans),
            ], -(int) ($lineCount * 0.5), $this->requiredTests($id, $labels));
        }

        // SIMPLIFY — oversized with few consumers
        if ($lineCount > self::SIMPLIFY_LINE_FLOOR && $consumers < self::SIMPLIFY_CONSUMER_CEILING) {
            return $this->entry($id, self::ACTION_SIMPLIFY, [
                sprintf('line_count:%d>%d', $lineCount, self::SIMPLIFY_LINE_FLOOR),
                sprintf('consumer_count:%d<%d', $consumers, self::SIMPLIFY_CONSUMER_CEILING),
            ], -(int) ($lineCount * 0.30), $this->requiredTests($id, $labels));
        }

        // KEEP
        return $this->entry($id, self::ACTION_KEEP, ['sufficient_evidence_and_consumers'], 0, []);
    }

    /** @return list<string> */
    private function requiredTests(string $id, array $labels): array
    {
        if ($labels === []) {
            return ["{$id}:behavior_must_be_verified_before_removal"];
        }
        return array_map(
            static fn ($label) => "{$id}:{$label}:must_pass_after_change",
            $labels,
        );
    }

    private function entry(string $id, string $action, array $reasons, int $lineDelta, array $tests): array
    {
        return [
            'organ_id'       => $id,
            'action'         => $action,
            'reasons'        => $reasons,
            'line_delta'     => $lineDelta,
            'required_tests' => $tests,
        ];
    }
}
