<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Safe simplification engine for the ExternalBrain organ inventory. Ranks
 * retire, merge, simplify and keep actions using evidence, replacement
 * ownership, test coverage, consumer count, overlap, line count and capability
 * preservation. Refuses unsafe deletion.
 *
 * Classification (first match per organ, AC2):
 *   retire_blocked — needs retirement but missing replacement_owner or test_coverage
 *   retire         — low evidence or scaffold retired, replacement+tests present
 *   merge_blocked  — overlap organs present but missing replacement_owner or test_coverage
 *   merge          — overlapping capability, replacement+tests present
 *   simplify       — oversized organ with few consumers
 *   keep           — otherwise (emits required_tests per preserved capability)
 *
 * first_safe_batch: retire + simplify organs, sorted by highest |line_delta|
 *   then fewest capability_labels (AC3: highest line reduction with lowest risk).
 *
 * AC4: pure PHP, deterministic, no file mutations.
 */
final class AtlasExternalBrainOrganSprawlReductionPlanner
{
    public const SCHEMA = 'atlas.external_brain.organ_sprawl_reduction_planner.v1';

    public const ACTION_RETIRE_BLOCKED = 'retire_blocked';
    public const ACTION_RETIRE         = 'retire';
    public const ACTION_MERGE_BLOCKED  = 'merge_blocked';
    public const ACTION_MERGE          = 'merge';
    public const ACTION_SIMPLIFY       = 'simplify';
    public const ACTION_KEEP           = 'keep';

    private const LOW_EVIDENCE_THRESHOLD    = 0.20;
    private const SIMPLIFY_LINE_FLOOR       = 200;
    private const SIMPLIFY_CONSUMER_CEILING = 2;

    private const ACTION_RANK = [
        self::ACTION_RETIRE_BLOCKED => 0,
        self::ACTION_RETIRE         => 1,
        self::ACTION_MERGE_BLOCKED  => 2,
        self::ACTION_MERGE          => 3,
        self::ACTION_SIMPLIFY       => 4,
        self::ACTION_KEEP           => 5,
    ];

    /**
     * @param  array{organs?: list<array<string,mixed>>}  $input
     * @return array{schema:string, ranked_actions:list<array<string,mixed>>, expected_line_delta:int, capability_preserved_count:int, first_safe_batch:list<string>, required_tests:list<string>}
     */
    public function plan(array $input): array
    {
        $organs = (array) ($input['organs'] ?? []);

        $actions             = [];
        $totalLineDelta      = 0;
        $capabilityPreserved = 0;
        $safeBatchEntries    = [];
        $allRequiredTests    = [];

        foreach ($organs as $organ) {
            $entry   = $this->classify($organ);
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
                $safeBatchEntries[] = [
                    'organ_id'         => $entry['organ_id'],
                    'line_delta'       => $entry['line_delta'],
                    'capability_count' => count((array) ($organ['capability_labels'] ?? [])),
                ];
            }
        }

        // Sort by action rank
        usort($actions, static fn ($a, $b) =>
            (self::ACTION_RANK[$a['action']] ?? 99) <=> (self::ACTION_RANK[$b['action']] ?? 99)
        );

        // first_safe_batch: highest |line_delta| first, then fewest capabilities (lowest risk)
        usort($safeBatchEntries, static fn ($a, $b) =>
            $b['line_delta'] !== $a['line_delta']
                ? abs($b['line_delta']) <=> abs($a['line_delta'])
                : $a['capability_count'] <=> $b['capability_count']
        );

        return [
            'schema'                     => self::SCHEMA,
            'ranked_actions'             => $actions,
            'expected_line_delta'        => $totalLineDelta,
            'capability_preserved_count' => $capabilityPreserved,
            'first_safe_batch'           => array_column($safeBatchEntries, 'organ_id'),
            'required_tests'             => array_values($allRequiredTests),
            'capability_groups'          => $this->buildCapabilityGroups($organs),
        ];
    }

    private function classify(array $organ): array
    {
        $id            = (string) ($organ['organ_id']              ?? 'unknown');
        $labels        = (array)  ($organ['capability_labels']     ?? []);
        $evidence      = max(0.0, min(1.0, (float) ($organ['evidence_strength']    ?? 1.0)));
        $consumers     = max(0,   (int)   ($organ['consumer_count']               ?? 0));
        $scaffoldStatus = (string) ($organ['scaffold_status']      ?? 'active');
        $lineCount     = max(0,   (int)   ($organ['line_count']    ?? 0));
        $hasReplacement = (bool)  ($organ['has_replacement_owner'] ?? false);
        $hasTests      = (bool)   ($organ['has_test_coverage']     ?? false);
        $overlapOrgans = (array)  ($organ['overlap_organs']        ?? []);
        $organType     = strtolower(trim((string) ($organ['organ_type'] ?? 'service')));

        $needsRetirement = $evidence < self::LOW_EVIDENCE_THRESHOLD || $scaffoldStatus === 'retired';

        // RETIRE_BLOCKED
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
            ], 0, $this->requiredTests($id, $labels),
            'Cannot safely retire without replacement owner and test coverage.',
            'high');
        }

        // RETIRE
        if ($needsRetirement) {
            return $this->entry($id, self::ACTION_RETIRE, [
                $evidence < self::LOW_EVIDENCE_THRESHOLD
                    ? sprintf('evidence_strength:%.4f<%.2f', $evidence, self::LOW_EVIDENCE_THRESHOLD)
                    : 'scaffold_status:retired',
            ], -$lineCount, $this->requiredTests($id, $labels),
            'Low evidence or scaffold retired; safe to retire with replacement and tests in place.',
            'low');
        }

        // MERGE_BLOCKED — overlap exists but safety not met
        if ($overlapOrgans !== [] && (! $hasReplacement || ! $hasTests)) {
            $missing = [];
            if (! $hasReplacement) {
                $missing[] = 'replacement_owner';
            }
            if (! $hasTests) {
                $missing[] = 'test_coverage';
            }
            return $this->entry($id, self::ACTION_MERGE_BLOCKED, [
                'overlaps_with:' . implode(',', $overlapOrgans),
                'missing:' . implode(',', $missing),
            ], 0, $this->requiredTests($id, $labels),
            'Overlap detected but cannot safely merge without replacement owner and test coverage.',
            'medium');
        }

        // MERGE
        if ($overlapOrgans !== []) {
            return $this->entry($id, self::ACTION_MERGE, [
                'overlaps_with:' . implode(',', $overlapOrgans),
            ], -(int) ($lineCount * 0.5), $this->requiredTests($id, $labels),
            'Overlapping capability with safety prerequisites met; merge reduces surface.',
            'low');
        }

        // AC2: Wrapper/template organs are lower-value consolidation candidates.
        if (in_array($organType, ['wrapper', 'template', 'scaffold'], true)) {
            return $this->entry($id, self::ACTION_SIMPLIFY, [
                "organ_type:{$organType}:consolidation_candidate",
            ], -(int) ($lineCount * 0.40), $this->requiredTests($id, $labels),
            ucfirst($organType).' organs are lower-value; consolidate into core service to reduce indirection.',
            'low');
        }

        // SIMPLIFY
        if ($lineCount > self::SIMPLIFY_LINE_FLOOR && $consumers < self::SIMPLIFY_CONSUMER_CEILING) {
            return $this->entry($id, self::ACTION_SIMPLIFY, [
                sprintf('line_count:%d>%d', $lineCount, self::SIMPLIFY_LINE_FLOOR),
                sprintf('consumer_count:%d<%d', $consumers, self::SIMPLIFY_CONSUMER_CEILING),
            ], -(int) ($lineCount * 0.30), $this->requiredTests($id, $labels),
            'Oversized organ with few consumers; simplification reduces maintenance burden.',
            'low');
        }

        // KEEP — include required_tests for every preserved capability (AC3)
        return $this->entry($id, self::ACTION_KEEP, ['sufficient_evidence_and_consumers'], 0,
            $this->requiredTests($id, $labels),
            'Sufficient evidence and consumer demand; preserve with required tests.',
            'low');
    }

    /** @return list<string> */
    private function requiredTests(string $id, array $labels): array
    {
        if ($labels === []) {
            return ["{$id}:behavior_must_be_verified_before_change"];
        }
        return array_map(
            static fn ($label) => "{$id}:{$label}:must_pass_after_change",
            $labels,
        );
    }

    private function entry(string $id, string $action, array $reasons, int $lineDelta, array $tests, string $rationale = '', string $riskLevel = 'low'): array
    {
        return [
            'organ_id'       => $id,
            'action'         => $action,
            'reasons'        => $reasons,
            'line_delta'     => $lineDelta,
            'required_tests' => $tests,
            'rationale'      => $rationale,
            'risk_level'     => $riskLevel,
        ];
    }

    /** @param list<array<string,mixed>> $organs @return list<array<string,mixed>> */
    private function buildCapabilityGroups(array $organs): array
    {
        $labelToOrgans = [];
        $organTypes    = [];

        foreach ($organs as $organ) {
            $id   = (string) ($organ['organ_id']    ?? 'unknown');
            $type = strtolower(trim((string) ($organ['organ_type'] ?? 'service')));
            $organTypes[$id] = $type;

            foreach ((array) ($organ['capability_labels'] ?? []) as $label) {
                $labelToOrgans[(string) $label][] = $id;
            }
        }

        // Build overlap groups: organs sharing at least one label.
        $groups  = []; // groupKey → ['labels'=>[], 'organ_ids'=>[]]
        $assigned = []; // organ_id → groupKey

        foreach ($labelToOrgans as $label => $ids) {
            if (count($ids) < 2) {
                continue;
            }

            // Find an existing group that already contains one of these organs.
            $groupKey = null;
            foreach ($ids as $oid) {
                if (isset($assigned[$oid])) {
                    $groupKey = $assigned[$oid];
                    break;
                }
            }

            if ($groupKey === null) {
                $groupKey = implode('|', $ids);
                $groups[$groupKey] = ['labels' => [], 'organ_ids' => []];
            }

            foreach ($ids as $oid) {
                if (! in_array($oid, $groups[$groupKey]['organ_ids'], true)) {
                    $groups[$groupKey]['organ_ids'][] = $oid;
                    $assigned[$oid] = $groupKey;
                }
            }

            if (! in_array($label, $groups[$groupKey]['labels'], true)) {
                $groups[$groupKey]['labels'][] = $label;
            }
        }

        $result = [];
        foreach ($groups as $group) {
            $ids  = $group['organ_ids'];
            $overlappingTypes = array_unique(array_map(static fn (string $oid): string => $organTypes[$oid] ?? 'service', $ids));

            $hasWrapper  = in_array('wrapper',  $overlappingTypes, true);
            $hasTemplate = in_array('template', $overlappingTypes, true);

            $overlapType = match (true) {
                $hasWrapper  => 'wrapper',
                $hasTemplate => 'template',
                default      => 'redundant',
            };

            $result[] = [
                'capability_intent'              => implode('+', $group['labels']),
                'organ_ids'                      => array_values($ids),
                'overlap_type'                   => $overlapType,
                'consolidation_recommendation'   => in_array($overlapType, ['wrapper', 'template'], true)
                    ? 'consolidate_into_core_service'
                    : 'merge_or_delegate_to_primary',
            ];
        }

        return $result;
    }
}
