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
 * AC2: capability_groups detect overlap across four signals — responsibility_overlap
 *   (capability_labels), duplicated_inputs (organ['inputs']), duplicated_outputs
 *   (organ['outputs']), shared_consumers (organ['consumer_ids']) — any shared key merges
 *   organs into one group; each group reports its overlap_signals.
 *
 * AC4: wrapper/template/scaffold organs only earn line-delta credit when
 *   adds_unique_value !== false; a pure rename/wrap is penalized with zero credit and a
 *   distinct reason so it can't fake a complexity reduction.
 *
 * Pure PHP, deterministic, no file mutations.
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
        $safeHandoffEntries  = [];
        $allRequiredTests    = [];
        $handoffCountBefore  = 0;
        $yieldBefore         = 0;
        $mergedHandoffReduction = 0;
        $yieldAfter          = 0;

        foreach ($organs as $organ) {
            $entry   = $this->classify($organ);
            $actions[] = $entry;

            $organYield = max(0, (int) ($organ['claimable_yield'] ?? ($organ['consumer_count'] ?? 0)));
            $handoffCountBefore += count((array) ($organ['overlap_organs'] ?? []));
            $yieldBefore += $organYield;

            if ($entry['action'] === self::ACTION_MERGE) {
                $mergedHandoffReduction += count((array) ($organ['overlap_organs'] ?? []));
                $yieldAfter += array_key_exists('merge_expected_yield_after', $organ)
                    ? max(0, (int) $organ['merge_expected_yield_after'])
                    : $organYield;
            } else {
                $yieldAfter += $organYield;
            }

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

            if (in_array($entry['action'], [self::ACTION_RETIRE, self::ACTION_MERGE, self::ACTION_SIMPLIFY], true)) {
                $safeHandoffEntries[] = [
                    'organ_id'          => $entry['organ_id'],
                    'handoff_reduction' => $entry['action'] === self::ACTION_MERGE ? count((array) ($organ['overlap_organs'] ?? [])) : 0,
                    'line_delta'        => $entry['line_delta'],
                    'capability_count'  => count((array) ($organ['capability_labels'] ?? [])),
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

        // first_safe_handoff_batch: highest handoff_reduction first, then highest |line_delta|,
        // then fewest capabilities — prefers retire/merge/simplify actions that cut handoffs, not
        // just line count, so consolidation reduces cognitive load without losing capability.
        usort($safeHandoffEntries, static fn ($a, $b) =>
            $b['handoff_reduction'] !== $a['handoff_reduction']
                ? $b['handoff_reduction'] <=> $a['handoff_reduction']
                : ($b['line_delta'] !== $a['line_delta']
                    ? abs($b['line_delta']) <=> abs($a['line_delta'])
                    : $a['capability_count'] <=> $b['capability_count'])
        );

        $handoffCountAfter = max(0, $handoffCountBefore - $mergedHandoffReduction);
        $handoffReductionScore = $handoffCountBefore > 0
            ? round($mergedHandoffReduction / $handoffCountBefore, 4)
            : 0.0;

        return [
            'schema'                     => self::SCHEMA,
            'ranked_actions'             => $actions,
            'expected_line_delta'        => $totalLineDelta,
            'capability_preserved_count' => $capabilityPreserved,
            'first_safe_batch'           => array_column($safeBatchEntries, 'organ_id'),
            'first_safe_handoff_batch'   => array_column($safeHandoffEntries, 'organ_id'),
            'handoff_reduction_score'    => $handoffReductionScore,
            'required_tests'             => array_values($allRequiredTests),
            'capability_groups'          => $this->buildCapabilityGroups($organs),
            'task_feed_impact'           => [
                'handoff_count_before' => $handoffCountBefore,
                'handoff_count_after' => $handoffCountAfter,
                'expected_claimable_yield_before' => $yieldBefore,
                'expected_claimable_yield_after' => $yieldAfter,
                'yield_preserved' => $yieldAfter >= $yieldBefore,
            ],
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
            'high', "{$id}:provide ".implode(' and ', $missing).' before retirement');
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
            'medium', "{$id}:provide ".implode(' and ', $missing).' before merge, or prove consumer migration');
        }

        // MERGE — only when the resulting circuit keeps at least the same claimable-task yield.
        if ($overlapOrgans !== []) {
            $claimableYield = max(0, (int) ($organ['claimable_yield'] ?? $consumers));
            $expectedYieldAfter = array_key_exists('merge_expected_yield_after', $organ)
                ? max(0, (int) $organ['merge_expected_yield_after'])
                : $claimableYield;
            $hasCompensatingAction = (bool) ($organ['has_compensating_repair_or_topup'] ?? false);

            if ($expectedYieldAfter < $claimableYield && ! $hasCompensatingAction) {
                return $this->entry($id, self::ACTION_MERGE_BLOCKED, [
                    'overlaps_with:' . implode(',', $overlapOrgans),
                    'consolidation_rejected:yield_drop_without_compensating_action',
                ], 0, $this->requiredTests($id, $labels),
                'Merge would drop claimable-task yield without a compensating repair/top-up action; consolidation rejected.',
                'medium');
            }

            return $this->entry($id, self::ACTION_MERGE, [
                'overlaps_with:' . implode(',', $overlapOrgans),
            ], -(int) ($lineCount * 0.5), $this->requiredTests($id, $labels),
            'Overlapping capability with safety prerequisites met; merge reduces surface.',
            'low');
        }

        // AC2/AC4: Wrapper/template organs are lower-value consolidation candidates —
        // but only earn a real line-delta credit when they add value beyond a rename/wrap.
        // A pure rename/wrap (adds_unique_value === false) is penalized: no credit, distinct
        // reason, so it can't game first_safe_batch ranking with a fake complexity reduction.
        if (in_array($organType, ['wrapper', 'template', 'scaffold'], true)) {
            $addsUniqueValue = (bool) ($organ['adds_unique_value'] ?? true);

            if (! $addsUniqueValue) {
                return $this->entry($id, self::ACTION_SIMPLIFY, [
                    "organ_type:{$organType}:rename_or_wrap_only_no_complexity_reduction",
                ], 0, $this->requiredTests($id, $labels),
                'Rename/wrap-only proposal adds no unique value; penalized — no line-delta credit until it fully delegates and is deleted.',
                'low');
            }

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

    private function entry(string $id, string $action, array $reasons, int $lineDelta, array $tests, string $rationale = '', string $riskLevel = 'low', ?string $prerequisiteTaskHint = null): array
    {
        return [
            'organ_id'       => $id,
            'action'         => $action,
            'reasons'        => $reasons,
            'line_delta'     => $lineDelta,
            'required_tests' => $tests,
            'rationale'      => $rationale,
            'risk_level'     => $riskLevel,
            'prerequisite_task_hint' => $prerequisiteTaskHint,
        ];
    }

    /**
     * AC2: groups organs by responsibility overlap (capability_labels), duplicated inputs,
     * duplicated outputs, and shared consumers — any shared key merges organs into one group.
     *
     * @param  list<array<string,mixed>>  $organs
     * @return list<array<string,mixed>>
     */
    private function buildCapabilityGroups(array $organs): array
    {
        $keyToOrgans = []; // key → [organ_id, ...]
        $keyToSignal = []; // key → overlap signal name
        $organTypes  = [];

        foreach ($organs as $organ) {
            $id   = (string) ($organ['organ_id']    ?? 'unknown');
            $type = strtolower(trim((string) ($organ['organ_type'] ?? 'service')));
            $organTypes[$id] = $type;

            foreach ((array) ($organ['capability_labels'] ?? []) as $label) {
                $key = 'capability:'.$label;
                $keyToOrgans[$key][] = $id;
                $keyToSignal[$key]   = 'responsibility_overlap';
            }
            foreach ((array) ($organ['inputs'] ?? []) as $input) {
                $key = 'input:'.$input;
                $keyToOrgans[$key][] = $id;
                $keyToSignal[$key]   = 'duplicated_inputs';
            }
            foreach ((array) ($organ['outputs'] ?? []) as $output) {
                $key = 'output:'.$output;
                $keyToOrgans[$key][] = $id;
                $keyToSignal[$key]   = 'duplicated_outputs';
            }
            foreach ((array) ($organ['consumer_ids'] ?? []) as $consumer) {
                $key = 'consumer:'.$consumer;
                $keyToOrgans[$key][] = $id;
                $keyToSignal[$key]   = 'shared_consumers';
            }
        }

        // Build overlap groups: organs sharing at least one key of any signal type.
        $groups   = []; // groupKey → ['labels'=>[], 'organ_ids'=>[], 'overlap_signals'=>[]]
        $assigned = []; // organ_id → groupKey

        foreach ($keyToOrgans as $key => $ids) {
            $ids = array_values(array_unique($ids));
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
                $groups[$groupKey] = ['labels' => [], 'organ_ids' => [], 'overlap_signals' => []];
            }

            foreach ($ids as $oid) {
                if (! in_array($oid, $groups[$groupKey]['organ_ids'], true)) {
                    $groups[$groupKey]['organ_ids'][] = $oid;
                    $assigned[$oid] = $groupKey;
                }
            }

            $signal = $keyToSignal[$key];
            if (! in_array($signal, $groups[$groupKey]['overlap_signals'], true)) {
                $groups[$groupKey]['overlap_signals'][] = $signal;
            }

            if ($signal === 'responsibility_overlap') {
                $label = substr($key, strlen('capability:'));
                if (! in_array($label, $groups[$groupKey]['labels'], true)) {
                    $groups[$groupKey]['labels'][] = $label;
                }
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
                'capability_intent'              => $group['labels'] !== []
                    ? implode('+', $group['labels'])
                    : implode('+', $group['overlap_signals']),
                'organ_ids'                      => array_values($ids),
                'overlap_type'                   => $overlapType,
                'overlap_signals'                => $group['overlap_signals'],
                'consolidation_recommendation'   => in_array($overlapType, ['wrapper', 'template'], true)
                    ? 'consolidate_into_core_service'
                    : 'merge_or_delegate_to_primary',
            ];
        }

        return $result;
    }
}
