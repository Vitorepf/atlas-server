<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

use App\Support\YesNo;

/**
 * Pure planner. Turns an organ inventory into ranked consolidation candidates.
 *
 * GROUPING DIMENSIONS (merge candidates, first-match-per-group wins):
 *   capability_label — two or more organs share a capability_labels entry
 *   purpose_tag      — two or more organs share the same purpose_tag (semantic intent)
 *   io_semantic_overlap — two or more organs share ≥1 input_type AND ≥1 output_type
 *
 * Per-organ action rules (first match wins):
 *   delete   — stale scaffold + replacement_owner present + test_coverage=true
 *   keep     — stale scaffold but no safe delete (no owner OR no coverage)
 *   simplify — line_count >= growth_threshold + test_coverage=true
 *   keep     — line_count >= growth_threshold but no coverage
 *   (no action emitted for healthy organs below growth_threshold)
 *
 * NEW OUTPUT FIELDS (all candidates):
 *   expected_line_reduction   — abs(expected_line_delta), ≥0
 *   preserved_contracts       — contracts that must survive the action
 *   required_tests            — tests that must stay green before/after action
 *   retire_now                — true ONLY for low-risk delete of non-behavior-unique organ
 *   proof_gates                — evidence gates required before executing a compression action
 *   next_task_recommendation   — deterministic next step for this candidate
 *
 * PROOF-GATE FACTS (per organ, default true so existing callers are unaffected):
 *   consumer_impact_assessed    — has the blast radius on active consumers been assessed
 *   parity_proof_available      — is there proof the change preserves behavior
 *   rollback_evidence_available — is there a proven rollback path
 * A delete/merge/simplify candidate is never proposed when any of these three facts is
 * explicitly false for a participating organ — it is downgraded to keep with
 * reason=missing_proof_gate_evidence instead.
 *
 * COMPRESSION SCORE (deterministic, higher = more valuable to execute first):
 *   Base:    delete=40, merge=30, simplify=20, keep=0
 *   +line_delta_bonus: abs(expected_line_delta) / 10
 *   +risk_bonus:       low=10, medium=5, high=0
 *   +coverage_bonus:   test_coverage=true → +10
 *   -owner_penalty:    no replacement_owner → -5
 *
 * INVARIANTS:
 *   - Never proposes delete without replacement_owner AND test_coverage.
 *   - behavior_unique=true organs are never marked retire_now.
 *   - Merge groups are deduplicated: same organ set emitted once regardless of
 *     how many grouping dimensions matched.
 *   - Pure: no I/O, no provider calls.
 */
final class AtlasExternalBrainArchitectureCompressionPlanner
{
    public const SCHEMA = 'atlas.external_brain.architecture_compression_planner.v1';

    public const ACTION_MERGE    = 'merge';
    public const ACTION_DELETE   = 'delete';
    public const ACTION_SIMPLIFY = 'simplify';
    public const ACTION_KEEP     = 'keep';

    private const DEFAULT_DUPLICATE_THRESHOLD = 2;
    private const DEFAULT_GROWTH_THRESHOLD    = 200;

    private const ACTION_ORDER = [
        self::ACTION_MERGE    => 0,
        self::ACTION_DELETE   => 1,
        self::ACTION_SIMPLIFY => 2,
        self::ACTION_KEEP     => 3,
    ];

    /**
     * @param  array{
     *   organs?: list<array<string,mixed>>,
     *   duplicate_threshold?: int,
     *   growth_threshold?: int,
     * }  $inventory
     * @return array<string,mixed>
     */
    public function plan(array $inventory): array
    {
        $organs          = is_array($inventory['organs'] ?? null) ? $inventory['organs'] : [];
        $dupThreshold    = max(2, (int) ($inventory['duplicate_threshold'] ?? self::DEFAULT_DUPLICATE_THRESHOLD));
        $growthThreshold = max(1, (int) ($inventory['growth_threshold']    ?? self::DEFAULT_GROWTH_THRESHOLD));
        $workerFloorLow  = (bool) ($inventory['worker_floor_low'] ?? false);

        $organMeta = $this->buildOrganMeta($organs);

        // ── Grouping indices ──────────────────────────────────────────────────
        $labelToIds   = [];
        $purposeToIds = [];
        foreach ($organs as $organ) {
            $id  = (string) ($organ['id'] ?? '');
            if ($id === '') {
                continue;
            }
            foreach ((array) ($organ['capability_labels'] ?? []) as $label) {
                $l = (string) $label;
                if ($l !== '') {
                    $labelToIds[$l][] = $id;
                }
            }
            $tag = (string) ($organ['purpose_tag'] ?? '');
            if ($tag !== '') {
                $purposeToIds[$tag][] = $id;
            }
        }

        $candidates    = [];
        $emittedMerges = []; // sorted-id key → true, prevents duplicate merge groups

        // ── Pass 1a: merge by shared capability_label ─────────────────────────
        foreach ($labelToIds as $label => $ids) {
            $uniqueIds = array_values(array_unique($ids));
            if (count($uniqueIds) < $dupThreshold) {
                continue;
            }
            sort($uniqueIds);
            $key = implode('+', $uniqueIds);
            if (isset($emittedMerges[$key])) {
                continue;
            }
            $emittedMerges[$key] = true;
            $candidates[]        = $this->buildMergeCandidate($uniqueIds, $organMeta, 'capability_label', $label, $workerFloorLow);
        }

        // ── Pass 1b: merge by shared purpose_tag ──────────────────────────────
        foreach ($purposeToIds as $tag => $ids) {
            $uniqueIds = array_values(array_unique($ids));
            if (count($uniqueIds) < $dupThreshold) {
                continue;
            }
            sort($uniqueIds);
            $key = implode('+', $uniqueIds);
            if (isset($emittedMerges[$key])) {
                continue;
            }
            $emittedMerges[$key] = true;
            $candidates[]        = $this->buildMergeCandidate($uniqueIds, $organMeta, 'purpose_tag', $tag, $workerFloorLow);
        }

        // ── Pass 1c: merge by I/O semantic overlap ────────────────────────────
        foreach ($this->findIoSemanticGroups($organs, $dupThreshold) as $groupIds) {
            sort($groupIds);
            $key = implode('+', $groupIds);
            if (isset($emittedMerges[$key])) {
                continue;
            }
            $emittedMerges[$key] = true;
            $candidates[]        = $this->buildMergeCandidate($groupIds, $organMeta, 'io_semantic_overlap', implode('+', $groupIds), $workerFloorLow);
        }

        // ── Pass 2: per-organ delete / simplify / keep ────────────────────────
        foreach ($organs as $organ) {
            $id             = (string) ($organ['id'] ?? '');
            $files          = array_values(array_filter(array_map('strval', (array) ($organ['files'] ?? [])), static fn (string $f): bool => $f !== ''));
            $lineCount      = max(0, (int) ($organ['line_count'] ?? 0));
            $isStale        = (bool) ($organ['stale_scaffold_marker'] ?? false) || (bool) ($organ['is_scaffold'] ?? false);
            $hasCoverage    = (bool) ($organ['test_coverage'] ?? false);
            $hasOwner       = (string) ($organ['replacement_owner'] ?? '') !== '';
            $behaviorUnique = (bool) ($organ['behavior_unique'] ?? false);
            $contracts      = is_array($organ['contracts'] ?? null) ? array_map('strval', $organ['contracts']) : [];
            $requiredTests  = is_array($organ['required_tests'] ?? null) ? array_map('strval', $organ['required_tests']) : [];
            $feedsActiveWorkers = (bool) ($organ['feeds_active_workers'] ?? false);
            $replacementClaimablePath = (bool) ($organ['replacement_claimable_path'] ?? false);
            $activeConsumers = array_values(array_filter(array_map('strval', (array) ($organ['active_consumers'] ?? []))));
            $proofGatesPassed = $this->proofGatesPassed($organ);
            sort($files);
            sort($contracts);
            sort($requiredTests);

            if ($isStale) {
                // behavior_unique never blocks the delete action itself — it only forces
                // retire_now=false (an organ can be safely deleted-and-replaced while still
                // being behaviorally unique). active_consumers DOES block delete outright: a
                // live consumer makes deletion unsafe regardless of owner/coverage. Proof
                // gates (consumer impact, parity proof, rollback evidence) default true so
                // callers that never set them are unaffected.
                $structurallySafe = $hasOwner && $hasCoverage && $activeConsumers === [];
                $safeToDelete     = $structurallySafe && $proofGatesPassed;

                if ($structurallySafe && ! $proofGatesPassed) {
                    $candidates[] = [
                        'candidate_id'            => 'keep:'.$id.':missing_proof_gate_evidence',
                        'action'                  => self::ACTION_KEEP,
                        'impacted_files'          => $files,
                        'expected_line_delta'     => 0,
                        'risk_level'              => 'high',
                        'evidence_floor'          => 'consumer_impact_assessed:'.($organ['consumer_impact_assessed'] ?? YesNo::trueFalse(true))
                            .' AND parity_proof_available:'.($organ['parity_proof_available'] ?? YesNo::trueFalse(true))
                            .' AND rollback_evidence_available:'.($organ['rollback_evidence_available'] ?? YesNo::trueFalse(true)),
                        'reason'                  => 'missing_proof_gate_evidence',
                        'compression_score'       => $this->scoreCandidate(self::ACTION_KEEP, 0, 'high', $hasCoverage, $hasOwner),
                        'expected_line_reduction' => 0,
                        'preserved_contracts'     => $contracts,
                        'required_tests'          => $requiredTests,
                        'retire_now'              => false,
                    ];
                    continue;
                }

                if ($safeToDelete && ! ($workerFloorLow && $feedsActiveWorkers && ! $replacementClaimablePath)) {
                    $lineDelta = -$lineCount;
                    $candidates[] = [
                        'candidate_id'            => 'delete:'.$id,
                        'action'                  => self::ACTION_DELETE,
                        'impacted_files'          => $files,
                        'expected_line_delta'     => $lineDelta,
                        'risk_level'              => 'low',
                        'evidence_floor'          => 'stale_scaffold_marker:true AND test_coverage:true AND replacement_owner:'.$organ['replacement_owner'],
                        'compression_score'       => $this->scoreCandidate(self::ACTION_DELETE, $lineDelta, 'low', true, true),
                        'expected_line_reduction' => $lineCount,
                        'preserved_contracts'     => $contracts,
                        'required_tests'          => $requiredTests,
                        'retire_now'              => ! $behaviorUnique,
                        'worker_feed_preserved'   => ! $feedsActiveWorkers || $replacementClaimablePath,
                    ];
                } elseif ($hasOwner && $hasCoverage && $activeConsumers === []) {
                    // Worker-floor protection: this organ would otherwise be a safe delete, but it
                    // feeds active workers with no replacement claimable path, and the worker floor
                    // is currently low — stranding workers is never acceptable, so the delete is
                    // rejected in favour of keep until a replacement path is supplied.
                    $candidates[] = [
                        'candidate_id'            => 'keep:'.$id.':worker_feed_capacity_protected',
                        'action'                  => self::ACTION_KEEP,
                        'impacted_files'          => $files,
                        'expected_line_delta'     => 0,
                        'risk_level'              => 'high',
                        'evidence_floor'          => 'feeds_active_workers:true AND replacement_claimable_path:false AND worker_floor_low:true',
                        'reason'                  => 'worker_feed_capacity_protected',
                        'compression_score'       => $this->scoreCandidate(self::ACTION_KEEP, 0, 'high', $hasCoverage, $hasOwner),
                        'expected_line_reduction' => 0,
                        'preserved_contracts'     => $contracts,
                        'required_tests'          => $requiredTests,
                        'retire_now'              => false,
                        'worker_feed_preserved'   => false,
                    ];
                } elseif ($activeConsumers !== []) {
                    // Never safe to delete an organ with live consumers, regardless of
                    // owner/coverage — that is what active_consumers exists to prevent.
                    $candidates[] = [
                        'candidate_id'            => 'keep:'.$id.':unsafe_delete',
                        'action'                  => self::ACTION_KEEP,
                        'impacted_files'          => $files,
                        'expected_line_delta'     => 0,
                        'risk_level'              => 'high',
                        'evidence_floor'          => 'active_consumers_count:'.count($activeConsumers),
                        'reason'                  => 'has_active_consumers',
                        'compression_score'       => $this->scoreCandidate(self::ACTION_KEEP, 0, 'high', $hasCoverage, $hasOwner),
                        'expected_line_reduction' => 0,
                        'preserved_contracts'     => $contracts,
                        'required_tests'          => $requiredTests,
                        'retire_now'              => false,
                    ];
                } else {
                    $reason = ! $hasOwner ? 'no_replacement_owner' : 'missing_test_coverage';
                    $candidates[] = [
                        'candidate_id'            => 'keep:'.$id.':stale_no_safe_delete',
                        'action'                  => self::ACTION_KEEP,
                        'impacted_files'          => $files,
                        'expected_line_delta'     => 0,
                        'risk_level'              => 'high',
                        'evidence_floor'          => 'stale_scaffold_marker:true',
                        'reason'                  => $reason,
                        'compression_score'       => $this->scoreCandidate(self::ACTION_KEEP, 0, 'high', $hasCoverage, $hasOwner),
                        'expected_line_reduction' => 0,
                        'preserved_contracts'     => $contracts,
                        'required_tests'          => $requiredTests,
                        'retire_now'              => false,
                    ];
                }
                continue;
            }

            if ($lineCount >= $growthThreshold) {
                if ($hasCoverage && ! $proofGatesPassed) {
                    $candidates[] = [
                        'candidate_id'            => 'keep:'.$id.':missing_proof_gate_evidence',
                        'action'                  => self::ACTION_KEEP,
                        'impacted_files'          => $files,
                        'expected_line_delta'     => 0,
                        'risk_level'              => 'high',
                        'evidence_floor'          => 'consumer_impact_assessed:'.($organ['consumer_impact_assessed'] ?? YesNo::trueFalse(true))
                            .' AND parity_proof_available:'.($organ['parity_proof_available'] ?? YesNo::trueFalse(true))
                            .' AND rollback_evidence_available:'.($organ['rollback_evidence_available'] ?? YesNo::trueFalse(true)),
                        'reason'                  => 'missing_proof_gate_evidence',
                        'compression_score'       => $this->scoreCandidate(self::ACTION_KEEP, 0, 'high', $hasCoverage, $hasOwner),
                        'expected_line_reduction' => 0,
                        'preserved_contracts'     => $contracts,
                        'required_tests'          => $requiredTests,
                        'retire_now'              => false,
                    ];
                } elseif ($hasCoverage) {
                    if ($workerFloorLow && $feedsActiveWorkers && ! $replacementClaimablePath) {
                        // Worker-floor protection: simplifying this organ would reduce capacity
                        // for active muscles while the worker floor is low and no replacement
                        // path exists — block the simplify in favour of keep.
                        $candidates[] = [
                            'candidate_id'            => 'keep:'.$id.':worker_feed_capacity_protected',
                            'action'                  => self::ACTION_KEEP,
                            'impacted_files'          => $files,
                            'expected_line_delta'     => 0,
                            'risk_level'              => 'high',
                            'evidence_floor'          => 'line_count:gte_'.$growthThreshold
                                .' AND test_coverage:true'
                                .' AND feeds_active_workers:true'
                                .' AND worker_floor_low:true'
                                .' AND replacement_claimable_path:false',
                            'reason'                  => 'worker_feed_capacity_protected',
                            'compression_score'       => $this->scoreCandidate(self::ACTION_KEEP, 0, 'high', $hasCoverage, $hasOwner),
                            'expected_line_reduction' => 0,
                            'preserved_contracts'     => $contracts,
                            'required_tests'          => $requiredTests,
                            'retire_now'              => false,
                            'worker_feed_preserved'   => false,
                        ];
                    } else {
                    $simplifyDelta = -(int) round($lineCount * 0.15);
                    $candidates[]  = [
                        'candidate_id'            => 'simplify:'.$id,
                        'action'                  => self::ACTION_SIMPLIFY,
                        'impacted_files'          => $files,
                        'expected_line_delta'     => $simplifyDelta,
                        'risk_level'              => 'low',
                        'evidence_floor'          => 'line_count:gte_'.$growthThreshold.' AND test_coverage:true',
                        'compression_score'       => $this->scoreCandidate(self::ACTION_SIMPLIFY, $simplifyDelta, 'low', true, $hasOwner),
                        'expected_line_reduction' => max(0, -$simplifyDelta),
                        'preserved_contracts'     => $contracts,
                        'required_tests'          => $requiredTests,
                        'retire_now'              => false,
                    ];
                    }
                } else {
                    $candidates[] = [
                        'candidate_id'            => 'keep:'.$id.':high_lines_no_coverage',
                        'action'                  => self::ACTION_KEEP,
                        'impacted_files'          => $files,
                        'expected_line_delta'     => 0,
                        'risk_level'              => 'medium',
                        'evidence_floor'          => 'line_count:gte_'.$growthThreshold,
                        'reason'                  => 'missing_test_coverage_for_simplification',
                        'compression_score'       => $this->scoreCandidate(self::ACTION_KEEP, 0, 'medium', false, $hasOwner),
                        'expected_line_reduction' => 0,
                        'preserved_contracts'     => $contracts,
                        'required_tests'          => $requiredTests,
                        'retire_now'              => false,
                    ];
                }
            }
        }

        foreach ($candidates as &$candidate) {
            $candidate['proof_gates']              = $this->proofGatesFor((string) $candidate['action']);
            $candidate['next_task_recommendation'] = $this->nextTaskRecommendation(
                (string) $candidate['action'],
                isset($candidate['reason']) ? (string) $candidate['reason'] : null,
            );
        }
        unset($candidate);

        usort($candidates, static function (array $a, array $b): int {
            $ao = self::ACTION_ORDER[$a['action']] ?? 4;
            $bo = self::ACTION_ORDER[$b['action']] ?? 4;

            return $ao !== $bo ? $ao <=> $bo : strcmp((string) $a['candidate_id'], (string) $b['candidate_id']);
        });

        $summary = $this->buildSummary($candidates);

        return [
            'schema'     => self::SCHEMA,
            'candidates' => $candidates,
            'summary'    => $summary,
            'plan_hash'  => 'compression_'.substr(hash('sha256', (string) json_encode($candidates, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)), 0, 32),
        ];
    }

    /**
     * Ranks delete/merge/refactor compression moves BEFORE additive (new-organ) proposals.
     * Compression candidates (merge, delete, simplify, keep — already ordered by {@see plan()})
     * always precede every additive proposal; a real missing capability with no duplication still
     * permits its additive proposal to surface, just after whatever compression candidates exist
     * (even an empty or keep-only set).
     *
     * @param  array{organs?: list<array<string,mixed>>, duplicate_threshold?: int, growth_threshold?: int}  $inventory
     * @param  list<array<string,mixed>>  $additiveProposals  {proposal_id, ...}
     * @return array<string,mixed>
     */
    public function rankWithAdditiveProposals(array $inventory, array $additiveProposals): array
    {
        $compressionPlan = $this->plan($inventory);
        $candidates = $compressionPlan['candidates'];

        $additive = [];
        foreach (array_filter($additiveProposals, 'is_array') as $proposal) {
            $additive[] = array_merge($proposal, ['is_additive_proposal' => true]);
        }

        return [
            'schema' => self::SCHEMA,
            'ranked' => array_merge($candidates, array_values($additive)),
            'compression_candidates_count' => count($candidates),
            'additive_proposal_count' => count($additive),
            'compression_summary' => $compressionPlan['summary'],
            'plan_hash' => (string) $compressionPlan['plan_hash'],
        ];
    }

    /**
     * Builds a merge candidate from an organ group, collecting contracts and required_tests.
     *
     * @param  list<string>              $uniqueIds
     * @param  array<string,array<string,mixed>>  $organMeta
     */
    private function buildMergeCandidate(
        array  $uniqueIds,
        array  $organMeta,
        string $groupType,
        string $groupLabel,
        bool   $workerFloorLow = false,
    ): array {
        $files      = [];
        $totalLines = 0;
        $contracts  = [];
        $reqTests   = [];
        $groupFeedsActiveWorkers = false;
        $groupHasReplacementClaimablePath = false;
        $groupProofGatesPassed = true;

        foreach ($uniqueIds as $id) {
            $meta = $organMeta[$id] ?? [];
            $groupFeedsActiveWorkers = $groupFeedsActiveWorkers || ($meta['feeds_active_workers'] ?? false);
            $groupHasReplacementClaimablePath = $groupHasReplacementClaimablePath || ($meta['replacement_claimable_path'] ?? false);
            $groupProofGatesPassed = $groupProofGatesPassed && ($meta['proof_gates_passed'] ?? true);
            foreach ($meta['files'] ?? [] as $f) {
                if ($f !== '' && ! in_array($f, $files, true)) {
                    $files[] = $f;
                }
            }
            $totalLines += $meta['line_count'] ?? 0;
            foreach ($meta['contracts'] ?? [] as $c) {
                if ($c !== '' && ! in_array($c, $contracts, true)) {
                    $contracts[] = $c;
                }
            }
            foreach ($meta['required_tests'] ?? [] as $t) {
                if ($t !== '' && ! in_array($t, $reqTests, true)) {
                    $reqTests[] = $t;
                }
            }
        }
        sort($files);
        sort($contracts);
        sort($reqTests);

        $mergeDelta = -(int) round($totalLines * 0.20);
        $mergeRisk  = count($uniqueIds) > 3 ? 'high' : 'medium';
        $workerFeedPreserved = ! $groupFeedsActiveWorkers || $groupHasReplacementClaimablePath;

        if (! $groupProofGatesPassed) {
            return [
                'candidate_id'            => 'keep:merge_blocked:'.$groupType.':'.implode('+', $uniqueIds),
                'action'                  => self::ACTION_KEEP,
                'impacted_files'          => $files,
                'expected_line_delta'     => 0,
                'risk_level'              => 'high',
                'evidence_floor'          => 'proof_gates_passed:false',
                'reason'                  => 'missing_proof_gate_evidence',
                'group_type'              => $groupType,
                'group_label'             => $groupLabel,
                'organ_ids'               => $uniqueIds,
                'duplicate_label'         => $groupLabel,
                'expected_line_reduction' => 0,
                'preserved_contracts'     => $contracts,
                'required_tests'          => $reqTests,
                'retire_now'              => false,
                'worker_feed_preserved'   => $workerFeedPreserved,
                'compression_score'       => $this->scoreCandidate(self::ACTION_KEEP, 0, 'high', false, true),
            ];
        }

        if ($workerFloorLow && $groupFeedsActiveWorkers && ! $groupHasReplacementClaimablePath) {
            // Worker-floor protection: merging these organs would temporarily strand active
            // workers with no replacement claimable path while the worker floor is breached —
            // reject the merge in favour of keep until a replacement path is supplied.
            return [
                'candidate_id'            => 'keep:merge_blocked:'.$groupType.':'.implode('+', $uniqueIds),
                'action'                  => self::ACTION_KEEP,
                'impacted_files'          => $files,
                'expected_line_delta'     => 0,
                'risk_level'              => 'high',
                'evidence_floor'          => 'feeds_active_workers:true AND replacement_claimable_path:false AND worker_floor_low:true',
                'reason'                  => 'worker_feed_capacity_protected',
                'group_type'              => $groupType,
                'group_label'             => $groupLabel,
                'organ_ids'               => $uniqueIds,
                'duplicate_label'         => $groupLabel,
                'expected_line_reduction' => 0,
                'preserved_contracts'     => $contracts,
                'required_tests'          => $reqTests,
                'retire_now'              => false,
                'worker_feed_preserved'   => false,
                'compression_score'       => $this->scoreCandidate(self::ACTION_KEEP, 0, 'high', false, true),
            ];
        }

        return [
            'candidate_id'            => 'merge:'.$groupType.':'.implode('+', $uniqueIds),
            'action'                  => self::ACTION_MERGE,
            'impacted_files'          => $files,
            'expected_line_delta'     => $mergeDelta,
            'risk_level'              => $mergeRisk,
            'evidence_floor'          => $groupType.':'.$groupLabel.':organs:'.implode(',', $uniqueIds),
            'group_type'              => $groupType,
            'group_label'             => $groupLabel,
            'organ_ids'               => $uniqueIds,
            'duplicate_label'         => $groupLabel,
            'expected_line_reduction' => max(0, -$mergeDelta),
            'preserved_contracts'     => $contracts,
            'required_tests'          => $reqTests,
            'retire_now'              => false,
            'worker_feed_preserved'   => $workerFeedPreserved,
            'compression_score'       => $this->scoreCandidate(self::ACTION_MERGE, $mergeDelta, $mergeRisk, false, true),
        ];
    }

    /**
     * Finds groups of organs that semantically overlap on both input_types and output_types.
     * Two organs are adjacent when they share ≥1 input_type AND ≥1 output_type.
     * Returns connected components with ≥ dupThreshold members.
     *
     * @param  list<array<string,mixed>>  $organs
     * @return list<list<string>>
     */
    private function findIoSemanticGroups(array $organs, int $dupThreshold): array
    {
        $ids        = [];
        $inputSets  = [];
        $outputSets = [];

        foreach ($organs as $organ) {
            $id = (string) ($organ['id'] ?? '');
            if ($id === '') {
                continue;
            }
            $ids[]         = $id;
            $inputSets[$id]  = array_values(array_unique(array_filter(array_map('strval', (array) ($organ['input_types']  ?? [])))));
            $outputSets[$id] = array_values(array_unique(array_filter(array_map('strval', (array) ($organ['output_types'] ?? [])))));
        }

        // Build adjacency list.
        $adj = [];
        $n   = count($ids);
        for ($i = 0; $i < $n; $i++) {
            for ($j = $i + 1; $j < $n; $j++) {
                $a = $ids[$i];
                $b = $ids[$j];
                if (array_intersect($inputSets[$a], $inputSets[$b]) !== []
                    && array_intersect($outputSets[$a], $outputSets[$b]) !== []
                ) {
                    $adj[$a][] = $b;
                    $adj[$b][] = $a;
                }
            }
        }

        // BFS connected components.
        $visited = [];
        $groups  = [];
        foreach ($ids as $id) {
            if (isset($visited[$id]) || ! isset($adj[$id])) {
                continue;
            }
            $group = [];
            $queue = [$id];
            while ($queue !== []) {
                $cur = array_shift($queue);
                if (isset($visited[$cur])) {
                    continue;
                }
                $visited[$cur] = true;
                $group[]       = $cur;
                foreach ($adj[$cur] ?? [] as $neighbor) {
                    if (! isset($visited[$neighbor])) {
                        $queue[] = $neighbor;
                    }
                }
            }
            if (count($group) >= $dupThreshold) {
                $groups[] = $group;
            }
        }

        return $groups;
    }

    /**
     * Builds a flat metadata map for organs by ID (used by buildMergeCandidate).
     *
     * @param  list<array<string,mixed>>  $organs
     * @return array<string,array<string,mixed>>
     */
    private function buildOrganMeta(array $organs): array
    {
        $meta = [];
        foreach ($organs as $organ) {
            $id = (string) ($organ['id'] ?? '');
            if ($id === '') {
                continue;
            }
            $files = array_values(array_filter(array_map('strval', (array) ($organ['files'] ?? [])), static fn (string $f): bool => $f !== ''));
            sort($files);
            $meta[$id] = [
                'files'          => $files,
                'line_count'     => max(0, (int) ($organ['line_count'] ?? 0)),
                'contracts'      => is_array($organ['contracts'] ?? null) ? array_map('strval', (array) $organ['contracts']) : [],
                'required_tests' => is_array($organ['required_tests'] ?? null) ? array_map('strval', (array) $organ['required_tests']) : [],
                'feeds_active_workers' => (bool) ($organ['feeds_active_workers'] ?? false),
                'replacement_claimable_path' => (bool) ($organ['replacement_claimable_path'] ?? false),
                'proof_gates_passed' => $this->proofGatesPassed($organ),
            ];
        }

        return $meta;
    }

    /**
     * A compression action is never proposed for an organ whose consumer impact, parity
     * proof, or rollback evidence is explicitly marked unavailable. All three facts default
     * to true (assessed/available) so existing callers that never set them are unaffected.
     *
     * @param  array<string,mixed>  $organ
     */
    private function proofGatesPassed(array $organ): bool
    {
        return (bool) ($organ['consumer_impact_assessed'] ?? true)
            && (bool) ($organ['parity_proof_available'] ?? true)
            && (bool) ($organ['rollback_evidence_available'] ?? true);
    }

    /** @return list<string> */
    private function proofGatesFor(string $action): array
    {
        return match ($action) {
            self::ACTION_DELETE, self::ACTION_MERGE, self::ACTION_SIMPLIFY => [
                'consumer_impact_assessed',
                'parity_proof_available',
                'rollback_evidence_available',
            ],
            default => [],
        };
    }

    private function nextTaskRecommendation(string $action, ?string $reason): string
    {
        return match (true) {
            $action === self::ACTION_DELETE => 'execute_delete_then_verify_required_tests_and_preserved_contracts',
            $action === self::ACTION_MERGE => 'consolidate_organs_then_verify_required_tests_and_preserved_contracts',
            $action === self::ACTION_SIMPLIFY => 'apply_simplification_then_verify_required_tests_and_preserved_contracts',
            $reason === 'worker_feed_capacity_protected' => 'supply_replacement_claimable_path_then_retry',
            $reason === 'has_active_consumers' => 'migrate_active_consumers_off_organ_then_retry',
            $reason === 'no_replacement_owner' => 'assign_replacement_owner_then_retry',
            $reason === 'missing_test_coverage' => 'add_test_coverage_then_retry',
            $reason === 'missing_test_coverage_for_simplification' => 'add_test_coverage_then_retry_simplification',
            $reason === 'missing_proof_gate_evidence' => 'gather_consumer_impact_parity_and_rollback_evidence_then_retry',
            default => 'no_action_required',
        };
    }

    /**
     * Single summary/count derivation path: iterates candidates once so
     * safe_delete_count, merge_count, simplify_count, and blocked_count cannot
     * drift independently when a new action branch is added.
     *
     * @param  list<array<string,mixed>>  $candidates
     * @return array{total_expected_line_delta:int, safe_delete_count:int, merge_count:int, simplify_count:int, blocked_count:int}
     */
    private function buildSummary(array $candidates): array
    {
        $counts = [
            self::ACTION_DELETE   => 0,
            self::ACTION_MERGE    => 0,
            self::ACTION_SIMPLIFY => 0,
            self::ACTION_KEEP     => 0,
        ];
        $totalLineDelta = 0;

        foreach ($candidates as $candidate) {
            $action = (string) ($candidate['action'] ?? '');
            if (isset($counts[$action])) {
                $counts[$action]++;
            }
            $totalLineDelta += (int) ($candidate['expected_line_delta'] ?? 0);
        }

        return [
            'total_expected_line_delta' => $totalLineDelta,
            'safe_delete_count'         => $counts[self::ACTION_DELETE],
            'merge_count'               => $counts[self::ACTION_MERGE],
            'simplify_count'            => $counts[self::ACTION_SIMPLIFY],
            'blocked_count'             => $counts[self::ACTION_KEEP],
        ];
    }

    private function scoreCandidate(
        string $action,
        int    $expectedLineDelta,
        string $riskLevel,
        bool   $hasCoverage,
        bool   $hasOwner,
    ): float {
        $score = match ($action) {
            self::ACTION_DELETE   => 40.0,
            self::ACTION_MERGE    => 30.0,
            self::ACTION_SIMPLIFY => 20.0,
            default               => 0.0,
        };
        $score += abs($expectedLineDelta) / 10.0;
        $score += match ($riskLevel) {
            'low'    => 10.0,
            'medium' => 5.0,
            default  => 0.0,
        };
        if ($hasCoverage) {
            $score += 10.0;
        }
        if (! $hasOwner) {
            $score -= 5.0;
        }

        return round($score, 2);
    }
}
