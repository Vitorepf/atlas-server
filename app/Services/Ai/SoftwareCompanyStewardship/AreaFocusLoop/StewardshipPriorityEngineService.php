<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\Mission\MissionCanonicalHash;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\Support\StewardshipPriorityScoringSupport;

/**
 * AP-785 · Stewardship Priority Engine.
 *
 * Ranks findings/specs/work orders/branches/queue items by the operator's
 * current rule: maximize real advancement and robustness before cosmetic work.
 * The engine is deterministic and read-only; execution stays with owner gates.
 */
final class StewardshipPriorityEngineService implements StewardshipPriorityRanker
{
    public const REPORT_SCHEMA = 'atlas.software_company_stewardship.priority_engine.v1';

    public const STATUS_READY = 'ready';

    public const STATUS_BLOCKED = 'blocked';

    public const DEFAULT_AREA_ID = 'agentic_engineering_os';

    public const SCOPE_FACTORY_MAX = AreaFocusScopeProfileNormalizer::FACTORY_MAX;

    /** @var list<string> */
    private const TERMINAL_STARVATION_REPLENISHMENT_UNLOCK_CATEGORIES = [
        'owner_runtime',
        'forge_authority',
        'scheduler',
        'merge',
        'product_mode',
    ];

    /** @var list<string> */
    private const TERMINAL_STARVATION_REPLENISHMENT_LADDER = [
        'merge',
        'deep_scan',
        'priority_backlog',
    ];

    /**
     * Step 2 of 3 — context quality score entry seam.
     *
     * Validates bounded input keys and materializes {@see ContextQualityScoreContract}.
     * Empty input returns the default contract; no ranking boost wiring yet.
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function contextQualityScore(array $input = []): array
    {
        if ($input === []) {
            return ContextQualityScoreContract::defaults()->toArray();
        }

        return ContextQualityScoreContract::fromArray(
            $this->validateContextQualityScoreInput($input)
        )->toArray();
    }

    public function rank(array $input): array
    {
        $areaId = (string) ($input['area_id'] ?? self::DEFAULT_AREA_ID);
        $focus = (string) ($input['focus'] ?? 'dev_forge');
        $scopeProfile = strtolower(trim((string) ($input['scope_profile'] ?? '')));
        $hasLiveForgeAuthority = (bool) ($input['has_live_forge_authority'] ?? false);
        $candidates = $this->candidates($input);
        if ($candidates === []) {
            $candidates = $this->canonicalSeedCandidates();
        }

        $ranked = [];
        foreach ($candidates as $index => $candidate) {
            $ranked[] = $this->applyContextQualityPriorityBoost(
                $input,
                $candidate,
                StewardshipPriorityScoringSupport::scoreItem($candidate, $index, $scopeProfile, $hasLiveForgeAuthority),
            );
        }

        usort($ranked, static function (array $a, array $b): int {
            $laneOrder = ['now' => 0, 'next' => 1, 'later' => 2, 'blocked' => 3, 'completed' => 4];

            return (($laneOrder[(string) ($a['lane'] ?? 'later')] ?? 2) <=> ($laneOrder[(string) ($b['lane'] ?? 'later')] ?? 2))
                ?: ((float) ($b['final_priority_score'] ?? 0.0) <=> (float) ($a['final_priority_score'] ?? 0.0))
                ?: ((int) ($b['roi_score'] ?? 0) <=> (int) ($a['roi_score'] ?? 0))
                ?: ((int) ($a['original_index'] ?? 0) <=> (int) ($b['original_index'] ?? 0));
        });

        foreach ($ranked as $i => &$item) {
            $item['rank'] = $i + 1;
        }
        unset($item);

        $ranked = $this->applyPriorityBacklogMaterialization($ranked, $candidates, $scopeProfile, $input);

        $payload = [
            'schema_version' => self::REPORT_SCHEMA,
            'ap_contract' => 'AP-785',
            'status' => self::STATUS_READY,
            'area_id' => $areaId,
            'focus' => $focus,
            'stack' => 'Atlas Software Company Stewardship Stack',
            'source_ap_contracts' => ['AP-748', 'AP-765', 'AP-766', 'AP-767', 'AP-768', 'AP-776', 'AP-780', 'AP-785'],
            'scope_profile' => $scopeProfile !== '' ? $scopeProfile : 'balanced',
            'ranking_policy' => [
                'objective' => $scopeProfile === self::SCOPE_FACTORY_MAX
                    ? 'maximize_software_factory_execution_readiness_and_mergeable_roi'
                    : 'implement_in_order_of_largest_real_advancement_and_largest_possible_robustness',
                'score_components' => [
                    'advancement_score',
                    'robustness_score',
                    'operator_leverage_score',
                    'execution_safety_score',
                    'evidence_score',
                    'dependency_unlock_score',
                    'roi_score',
                    'execution_readiness_score',
                    'factory_leverage_score',
                    'risk_penalty',
                    'rejection_reason',
                    'final_priority_score',
                ],
                'canonical_expected_order' => [
                    'AP-783 integration lane promotion',
                    'live cycle audit / truth surface',
                    'owner runtime real execution bridge',
                    '24h scheduler with budgets/locks/kill switch',
                    'Product Mode controls/receipts',
                    'provider routing Opus/Sonnet/Gemini/Codex only after owner runtime boundaries are real',
                ],
                'deterministic' => true,
                'mutates_repo' => false,
            ],
            'candidate_count' => count($ranked),
            'priority_backlog_materialization' => $this->priorityBacklogMaterializationReport($ranked, $input),
            'top_candidate' => $ranked[0] ?? null,
            'ranked_items' => $ranked,
            // Backwards-compatible alias for older AP-771 consumers.
            'ranked_candidates' => $ranked,
            'claim_policy' => [
                'provider_invoked' => false,
                'branch_created' => false,
                'worktree_created' => false,
                'merge_performed' => false,
                'mutates_target_repo' => false,
                'deploys' => false,
                'touches_secrets' => false,
                'auto_approval' => false,
                'creates_runtime' => false,
                'creates_new_os' => false,
                'autonomous_selection_possible' => false,
                'operator_review_still_required_for_irreversible_actions' => true,
            ],
            'generated_at' => AreaFocusUtcClock::atomNow(),
        ];
        $payload['priority_hash'] = 'sha256:'.MissionCanonicalHash::sha256($this->identity($payload));

        return $payload;
    }

    /**
     * @param  array<string,mixed>  $input
     * @return list<array<string,mixed>>
     */
    private function candidates(array $input): array
    {
        if (is_array($input['candidates'] ?? null)) {
            return AreaFocusLoopPayloadNormalizer::listOfArrays($input['candidates']);
        }

        if (is_array($input['findings'] ?? null)) {
            return AreaFocusLoopPayloadNormalizer::listOfArrays($input['findings']);
        }

        if (is_array($input['deep_scan_report'] ?? null)) {
            return AreaFocusLoopPayloadNormalizer::listOfArrays($input['deep_scan_report']['findings'] ?? []);
        }

        if (is_array($input['branches'] ?? null)) {
            return AreaFocusLoopPayloadNormalizer::listOfArrays($input['branches']);
        }

        if (is_array($input['specs'] ?? null)) {
            return AreaFocusLoopPayloadNormalizer::listOfArrays($input['specs']);
        }

        if (is_array($input['work_orders'] ?? null)) {
            return AreaFocusLoopPayloadNormalizer::listOfArrays($input['work_orders']);
        }

        if (is_array($input['queue_items'] ?? null)) {
            return AreaFocusLoopPayloadNormalizer::listOfArrays($input['queue_items']);
        }

        return [];
    }

    private function applyPriorityBacklogMaterialization(
        array $ranked,
        array $candidates,
        string $scopeProfile,
        array $input,
    ): array {
        if ($ranked === []) {
            return $ranked;
        }

        $sourcesById = [];
        foreach ($candidates as $candidate) {
            if (! is_array($candidate)) {
                continue;
            }
            $id = (string) ($candidate['id'] ?? $candidate['item_id'] ?? $candidate['finding_id'] ?? '');
            if ($id !== '') {
                $sourcesById[$id] = $candidate;
            }
        }

        $terminalRebalance = $this->terminalBacklogRebalanceActive($input);
        $rebalanced = [];

        foreach ($ranked as $item) {
            $itemId = (string) ($item['item_id'] ?? '');
            $source = $sourcesById[$itemId] ?? (is_array($item['source'] ?? null) ? $item['source'] : []);
            $status = strtolower((string) ($item['completion_status'] ?? 'pending'));
            $unlockCategory = $this->priorityBacklogUnlockCategory($itemId, $source, $item);

            $replenishCompleted = $terminalRebalance
                && $this->terminalStarvationExhaustionActive($input)
                && $status === 'completed'
                && $unlockCategory !== ''
                && in_array($unlockCategory, self::TERMINAL_STARVATION_REPLENISHMENT_UNLOCK_CATEGORIES, true);

            if (($status !== 'completed' || $replenishCompleted) && $unlockCategory !== '') {
                if ($replenishCompleted) {
                    $item['completion_status'] = 'pending';
                    $item['priority_backlog_replenishment_anchor'] = true;
                    $item['lane'] = 'later';
                }
                $item = $this->enrichMaterializableBacklogItem($item, $source, $unlockCategory, $scopeProfile);
            }

            if ($terminalRebalance && $unlockCategory !== '' && ($status !== 'completed' || $replenishCompleted)) {
                $item = $this->rebalanceTerminalStarvationItem($item, $unlockCategory);
            }

            $rebalanced[] = $item;
        }

        if ($terminalRebalance && $this->terminalStarvationExhaustionActive($input)) {
            $rebalanced = $this->appendTerminalStarvationReplenishmentCandidates(
                $rebalanced,
                $scopeProfile,
                $input,
            );
        }

        if ($terminalRebalance || $scopeProfile === self::SCOPE_FACTORY_MAX) {
            usort($rebalanced, static function (array $a, array $b): int {
                $laneOrder = ['now' => 0, 'next' => 1, 'later' => 2, 'blocked' => 3, 'completed' => 4];

                return (($laneOrder[(string) ($a['lane'] ?? 'later')] ?? 2) <=> ($laneOrder[(string) ($b['lane'] ?? 'later')] ?? 2))
                    ?: ((float) ($b['final_priority_score'] ?? 0.0) <=> (float) ($a['final_priority_score'] ?? 0.0))
                    ?: ((int) ($b['roi_score'] ?? 0) <=> (int) ($a['roi_score'] ?? 0))
                    ?: ((int) ($a['original_index'] ?? 0) <=> (int) ($b['original_index'] ?? 0));
            });

            foreach ($rebalanced as $i => &$item) {
                $item['rank'] = $i + 1;
            }
            unset($item);
        }

        return $rebalanced;
    }

    /**
     * @param  array<string,mixed>  $input
     */
    private function terminalBacklogRebalanceActive(array $input): bool
    {
        $stateHash = trim((string) ($input['terminal_backlog_state_hash'] ?? ''));
        $reasons = AreaFocusStringListNormalizer::coercedStringValues($input['terminal_backlog_rejection_reasons'] ?? []);

        return $stateHash !== '' || $reasons !== [];
    }

    /**
     * @param  array<string,mixed>  $input
     */
    private function terminalStarvationExhaustionActive(array $input): bool
    {
        $reasons = AreaFocusStringListNormalizer::coercedStringValues($input['terminal_backlog_rejection_reasons'] ?? []);

        return in_array('no_executable_candidates_after_selection_pass', $reasons, true);
    }

    /**
     * @param  list<array<string,mixed>>  $ranked
     * @param  array<string,mixed>  $input
     * @return list<array<string,mixed>>
     */
    private function appendTerminalStarvationReplenishmentCandidates(
        array $ranked,
        string $scopeProfile,
        array $input,
    ): array {
        $presentCategories = [];
        $presentSeedIds = [];
        foreach ($ranked as $item) {
            $itemId = (string) ($item['item_id'] ?? '');
            if ($itemId !== '') {
                $presentSeedIds[$itemId] = true;
            }
            $category = (string) ($item['priority_backlog_unlock_category'] ?? '');
            if ($category !== '' && strtolower((string) ($item['completion_status'] ?? 'pending')) !== 'completed') {
                $presentCategories[$category] = true;
            }
        }

        $nextIndex = count($ranked);
        foreach (self::TERMINAL_STARVATION_REPLENISHMENT_LADDER as $requiredCategory) {
            $seedId = $this->terminalReplenishmentSeedIdForCategory($requiredCategory);
            if (isset($presentSeedIds[$seedId])) {
                continue;
            }
            if ($requiredCategory === 'merge' && isset($presentCategories['merge'])) {
                continue;
            }

            $seed = $this->terminalReplenishmentSeedForCategory($requiredCategory);
            $scored = StewardshipPriorityScoringSupport::scoreItem(
                $seed,
                $nextIndex,
                $scopeProfile,
                (bool) ($input['has_live_forge_authority'] ?? false),
            );
            $scored = $this->enrichMaterializableBacklogItem($scored, $seed, $requiredCategory, $scopeProfile);
            $scored = $this->rebalanceTerminalStarvationItem($scored, $requiredCategory);
            $scored['priority_backlog_replenishment_anchor'] = true;
            $ranked[] = $scored;
            $presentSeedIds[$seedId] = true;
            $presentCategories[$requiredCategory] = true;
            $nextIndex++;
        }

        return $ranked;
    }

    private function terminalReplenishmentSeedIdForCategory(string $category): string
    {
        return match ($category) {
            'merge' => 'terminal_backlog_replenish_merge_queue',
            'deep_scan' => 'terminal_backlog_replenish_deep_scan',
            'priority_backlog' => 'terminal_backlog_replenish_priority_backlog',
            default => 'terminal_backlog_replenish_'.$category,
        };
    }

    /**
     * @return array<string,mixed>
     */
    private function terminalReplenishmentSeedForCategory(string $category): array
    {
        return match ($category) {
            'merge' => [
                'id' => $this->terminalReplenishmentSeedIdForCategory('merge'),
                'title' => 'Replenish merge queue executable after terminal starvation',
                'ap_contract' => 'AP-772',
                'type' => 'safety_robustness_unlock',
                'dependency_unlocks' => ['merge_queue', 'integration_lane', 'repo_merge_lease'],
                'operator_touchpoints_reduced' => 2,
                'completion_status' => 'pending',
                'completion_evidence' => [
                    'service' => 'StewardshipMergeQueueService',
                    'contract' => 'docs/ap/AP-772-stewardship-merge-queue-contract.md',
                    'test' => 'tests/Unit/Ai/SoftwareCompanyStewardship/AreaFocusLoop/StewardshipMergeQueueServiceTest.php',
                ],
            ],
            'deep_scan' => [
                'id' => $this->terminalReplenishmentSeedIdForCategory('deep_scan'),
                'title' => 'Replenish deep-scan candidate discovery after terminal starvation',
                'ap_contract' => 'AP-748',
                'type' => 'gap',
                'dependency_unlocks' => ['deep_scan', 'candidate_discovery', 'ap748'],
                'operator_touchpoints_reduced' => 2,
                'completion_status' => 'pending',
                'completion_evidence' => [
                    'service' => 'AreaFocusDeepFindingEngineService',
                    'contract' => 'docs/ap/AP-748-stewardship-release-outcome-bridge-contract.md',
                    'test' => 'tests/Unit/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AreaFocusDeepFindingEngineServiceTest.php',
                ],
            ],
            'priority_backlog' => [
                'id' => $this->terminalReplenishmentSeedIdForCategory('priority_backlog'),
                'title' => 'Replenish priority backlog generation after terminal starvation',
                'ap_contract' => 'AP-785',
                'type' => 'safety_robustness_unlock',
                'dependency_unlocks' => ['priority_backlog', 'priority_engine', 'ap790'],
                'operator_touchpoints_reduced' => 2,
                'completion_status' => 'pending',
                'completion_evidence' => [
                    'service' => 'StewardshipPriorityEngineService',
                    'contract' => 'docs/ap/AP-785-stewardship-priority-engine-contract.md',
                    'test' => 'tests/Unit/Ai/SoftwareCompanyStewardship/AreaFocusLoop/StewardshipPriorityEngineServiceTest.php',
                ],
            ],
            default => [
                'id' => $this->terminalReplenishmentSeedIdForCategory($category),
                'title' => 'Replenish '.$category.' executable after terminal starvation',
                'type' => 'gap',
                'completion_status' => 'pending',
                'completion_evidence' => [],
            ],
        };
    }

    /**
     * @param  array<string,mixed>  $source
     * @param  array<string,mixed>  $item
     */
    private function priorityBacklogUnlockCategory(string $itemId, array $source, array $item): string
    {
        $haystack = strtolower(implode(' ', [
            $itemId,
            (string) ($source['type'] ?? ''),
            (string) ($source['kind'] ?? ''),
            (string) ($item['item_type'] ?? ''),
            (string) ($source['title'] ?? ''),
            (string) ($item['title'] ?? ''),
            implode(' ', StewardshipPriorityScoringSupport::listValue($source, 'dependency_unlocks')),
            implode(' ', StewardshipPriorityScoringSupport::listValue($item, 'dependency_unlocks')),
        ]));

        return match (true) {
            str_contains($haystack, 'owner_runtime') || str_contains($haystack, 'runtime_execution') => 'owner_runtime',
            str_contains($haystack, '24h_scheduler') || str_contains($haystack, 'continuous_24h') || str_contains($haystack, 'scheduler') => 'scheduler',
            str_contains($haystack, 'product_mode') || str_contains($haystack, 'controls_receipt') => 'product_mode',
            str_contains($haystack, 'provider_routing') || str_contains($haystack, 'provider_optimization') || str_contains($haystack, 'atlas_decide') || str_contains($haystack, 'ap789') || str_contains($haystack, 'forge_authority') || str_contains($haystack, 'real_forge_authority') => 'forge_authority',
            str_contains($haystack, 'senior_loop') || str_contains($haystack, 'repair_after_authority') => 'owner_runtime',
            str_contains($haystack, 'merge_queue') || str_contains($haystack, 'merge') => 'merge',
            str_contains($haystack, 'deep_scan') || str_contains($haystack, 'candidate_discovery') => 'deep_scan',
            str_contains($haystack, 'priority_backlog') || str_contains($haystack, 'priority_engine') => 'priority_backlog',
            default => '',
        };
    }

    /**
     * @param  array<string,mixed>  $item
     * @param  array<string,mixed>  $source
     * @return array<string,mixed>
     */
    private function enrichMaterializableBacklogItem(
        array $item,
        array $source,
        string $unlockCategory,
        string $scopeProfile,
    ): array {
        $paths = $this->materializationPathsFromSource($source);
        if ($paths['runtime'] !== '') {
            $item['affected_files'] = AreaFocusStringListNormalizer::uniqueMergedStringValues(
                (array) ($item['affected_files'] ?? []),
                [$paths['runtime']],
            );
        }
        if ($paths['test'] !== '') {
            $item['tests_required'] = AreaFocusStringListNormalizer::uniqueMergedStringValues(
                StewardshipPriorityScoringSupport::listValue($item, 'tests_required'),
                [$paths['test']],
            );
        }

        $item['priority_backlog_materializable'] = true;
        $item['priority_backlog_unlock_category'] = $unlockCategory;
        $item['factory_execution_ready'] = true;
        $item['owner_candidate'] = (string) ($source['owner_candidate'] ?? 'atlas_dev');

        if ($scopeProfile === self::SCOPE_FACTORY_MAX || (bool) ($item['factory_execution_ready'] ?? false)) {
            $item['execution_readiness_score'] = max((int) ($item['execution_readiness_score'] ?? 0), 92);
            $item['factory_leverage_score'] = max((int) ($item['factory_leverage_score'] ?? 0), 90);
            $item['roi_score'] = max((int) ($item['roi_score'] ?? 0), 88);
        }

        return $item;
    }

    /**
     * @param  array<string,mixed>  $item
     * @return array<string,mixed>
     */
    private function rebalanceTerminalStarvationItem(array $item, string $unlockCategory): array
    {
        $item['lane'] = 'now';
        $item['priority_band'] = StewardshipPriorityScoringSupport::band((float) ($item['final_priority_score'] ?? 0.0));
        $item['reason_machine'] = AreaFocusStringListNormalizer::uniqueMergedStringValues(
            (array) ($item['reason_machine'] ?? []),
            ['terminal_backlog_rebalance', 'unlock:'.$unlockCategory],
        );
        $item['final_priority_score'] = max((float) ($item['final_priority_score'] ?? 0.0), 78.0);
        $item['priority_score'] = $item['final_priority_score'];
        $item['execution_readiness_score'] = max((int) ($item['execution_readiness_score'] ?? 0), 94);
        $item['factory_leverage_score'] = max((int) ($item['factory_leverage_score'] ?? 0), 92);
        $item['roi_score'] = max((int) ($item['roi_score'] ?? 0), 90);
        $item['risk_penalty'] = min((int) ($item['risk_penalty'] ?? 0), 24);
        $item['rejection_reason'] = '';

        return $item;
    }

    /**
     * @param  array<string,mixed>  $source
     * @return array{runtime:string,test:string}
     */
    private function materializationPathsFromSource(array $source): array
    {
        $files = StewardshipPriorityScoringSupport::files($source);
        $runtime = $files[0] ?? '';
        $evidence = is_array($source['completion_evidence'] ?? null) ? $source['completion_evidence'] : [];
        if ($runtime === '' && is_string($evidence['service'] ?? null)) {
            $runtime = 'app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/'.ltrim((string) $evidence['service'], '/').'.php';
        }
        $test = is_string($evidence['test'] ?? null) ? (string) $evidence['test'] : '';
        if ($test === '' && $runtime !== '') {
            $basename = basename($runtime, '.php');
            $test = 'tests/Unit/Ai/SoftwareCompanyStewardship/AreaFocusLoop/'.$basename.'Test.php';
        }

        return ['runtime' => $runtime, 'test' => $test];
    }

    /**
     * @param  list<array<string,mixed>>  $ranked
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    private function priorityBacklogMaterializationReport(array $ranked, array $input): array
    {
        $executable = array_values(array_filter(
            $ranked,
            static fn (array $item): bool => ($item['priority_backlog_materializable'] ?? false) === true
                && strtolower((string) ($item['lane'] ?? '')) === 'now'
                && strtolower((string) ($item['completion_status'] ?? 'pending')) !== 'completed',
        ));

        $categories = AreaFocusStringListNormalizer::uniqueStringValues(array_filter(array_map(
            static fn (array $item): string => (string) ($item['priority_backlog_unlock_category'] ?? ''),
            $executable,
        )));

        $rejectionReasons = AreaFocusStringListNormalizer::coercedStringValues($input['terminal_backlog_rejection_reasons'] ?? []);
        $replenishmentGeneratedIds = array_values(array_map(
            static fn (array $item): string => (string) ($item['item_id'] ?? ''),
            array_filter(
                $ranked,
                static fn (array $item): bool => ($item['priority_backlog_replenishment_anchor'] ?? false) === true
                    && str_starts_with((string) ($item['item_id'] ?? ''), 'terminal_backlog_replenish_'),
            ),
        ));

        return [
            'schema_version' => 'atlas.software_company_stewardship.priority_backlog_materialization.v1',
            'terminal_backlog_rebalance' => $this->terminalBacklogRebalanceActive($input),
            'terminal_backlog_state_hash' => trim((string) ($input['terminal_backlog_state_hash'] ?? '')),
            'terminal_backlog_rejection_reason_count' => count($rejectionReasons),
            'executable_unlock_count' => count($executable),
            'executable_unlock_categories' => $categories,
            'executable_unlock_ids' => array_values(array_map(
                static fn (array $item): string => (string) ($item['item_id'] ?? ''),
                $executable,
            )),
            'replenishment_generated_count' => count($replenishmentGeneratedIds),
            'replenishment_generated_ids' => $replenishmentGeneratedIds,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function blocked(string $areaId, string $reason, string $detail): array
    {
        return [
            'schema_version' => self::REPORT_SCHEMA,
            'ap_contract' => 'AP-785',
            'status' => self::STATUS_BLOCKED,
            'area_id' => $areaId,
            'reason' => $reason,
            'detail' => $detail,
            'blockers' => [$reason],
            'claim_policy' => [
                'provider_invoked' => false,
                'branch_created' => false,
                'merge_performed' => false,
            ],
            'generated_at' => AreaFocusUtcClock::atomNow(),
        ];
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function canonicalSeedCandidates(): array
    {
        return [
            [
                'id' => 'AP-783',
                'title' => 'AP-783 integration lane promotion',
                'ap_contract' => 'AP-783',
                'type' => 'integration_lane_promotion',
                'evidence_refs' => ['ap782_integration_lane', 'ap780_review_packet'],
                'dependency_unlocks' => ['owner_runtime_execution', 'truth_surface', '24h_loop'],
                'operator_touchpoints_reduced' => 4,
                'completion_status' => $this->canonicalCompletionStatus([
                    'app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/StewardshipIntegrationLanePromotionService.php',
                    'app/Console/Commands/AtlasSoftwareCompanyIntegrationLanePromoteCommand.php',
                    'docs/ap/AP-783-stewardship-integration-lane-promotion-contract.md',
                    'tests/Unit/Ai/SoftwareCompanyStewardship/AreaFocusLoop/StewardshipIntegrationLanePromotionServiceTest.php',
                ]),
                'completion_evidence' => [
                    'service' => 'StewardshipIntegrationLanePromotionService',
                    'cli' => 'atlas:software-company-stewardship:integration-lane-promote',
                    'contract' => 'docs/ap/AP-783-stewardship-integration-lane-promotion-contract.md',
                    'test' => 'tests/Unit/Ai/SoftwareCompanyStewardship/AreaFocusLoop/StewardshipIntegrationLanePromotionServiceTest.php',
                ],
            ],
            [
                'id' => 'live_cycle_audit_truth_surface',
                'title' => 'Live cycle audit / truth surface',
                'ap_contract' => 'AP-784',
                'type' => 'live_cycle_audit',
                'evidence_refs' => ['cycle_receipt', 'runner_receipt'],
                'dependency_unlocks' => ['no_false_complete', 'operator_visibility', 'provider_boundary_review'],
                'operator_touchpoints_reduced' => 3,
                'completion_status' => $this->canonicalCompletionStatus([
                    'app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/StewardshipLiveCycleAuditService.php',
                    'app/Console/Commands/AtlasSoftwareCompanyLiveCycleAuditCommand.php',
                    'docs/ap/AP-784-stewardship-live-cycle-audit-contract.md',
                    'tests/Unit/Ai/SoftwareCompanyStewardship/AreaFocusLoop/StewardshipLiveCycleAuditServiceTest.php',
                ]),
                'completion_evidence' => [
                    'service' => 'StewardshipLiveCycleAuditService',
                    'cli' => 'atlas:software-company-stewardship:live-cycle-audit',
                    'contract' => 'docs/ap/AP-784-stewardship-live-cycle-audit-contract.md',
                    'test' => 'tests/Unit/Ai/SoftwareCompanyStewardship/AreaFocusLoop/StewardshipLiveCycleAuditServiceTest.php',
                ],
            ],
            [
                'id' => 'owner_runtime_real_execution_bridge',
                'title' => 'Owner runtime real execution bridge',
                'ap_contract' => 'AP-767',
                'type' => 'owner_runtime_real_execution_bridge',
                'evidence_refs' => ['ap765_contract', 'ap767_contract'],
                'dependency_unlocks' => ['real_dev_forge_result', 'cycle_closure'],
                'operator_touchpoints_reduced' => 3,
                'completion_status' => $this->canonicalCompletionStatus([
                    'app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/OwnerFlow/Ap786OwnerFlowExecutor.php',
                    'docs/ap/AP-767-dev-forge-runtime-execution-bridge-contract.md',
                    'tests/Unit/Ai/SoftwareCompanyStewardship/AreaFocusLoop/OwnerFlow/Ap786OwnerFlowExecutorTest.php',
                ]),
                'completion_evidence' => [
                    'service' => 'OwnerFlow/Ap786OwnerFlowExecutor',
                    'contract' => 'docs/ap/AP-767-dev-forge-runtime-execution-bridge-contract.md',
                    'test' => 'tests/Unit/Ai/SoftwareCompanyStewardship/AreaFocusLoop/OwnerFlow/Ap786OwnerFlowExecutorTest.php',
                ],
            ],
            [
                'id' => 'continuous_24h_scheduler',
                'title' => '24h scheduler with budgets/locks/kill switch',
                'ap_contract' => 'AP-790',
                'type' => '24h_scheduler',
                'evidence_refs' => ['ap766_runner'],
                'dependency_unlocks' => ['continuous_operation', 'budgeted_loop'],
                'operator_touchpoints_reduced' => 2,
                'completion_status' => $this->canonicalCompletionStatus([
                    'app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/Reliable24hLoopRunnerService.php',
                    'docs/ap/AP-790-reliable-24h-autonomous-loop-runner-contract.md',
                    'tests/Unit/Ai/SoftwareCompanyStewardship/AreaFocusLoop/Reliable24hLoopRunnerServiceTest.php',
                ]),
                'completion_evidence' => [
                    'service' => 'Reliable24hLoopRunnerService',
                    'contract' => 'docs/ap/AP-790-reliable-24h-autonomous-loop-runner-contract.md',
                    'test' => 'tests/Unit/Ai/SoftwareCompanyStewardship/AreaFocusLoop/Reliable24hLoopRunnerServiceTest.php',
                ],
            ],
            [
                'id' => 'product_mode_controls_receipts',
                'title' => 'Product Mode controls/receipts',
                'ap_contract' => 'AP-755',
                'type' => 'product_mode_controls',
                'evidence_refs' => ['ap754_controls', 'ap755_receipts'],
                'dependency_unlocks' => ['operator_policy_visibility'],
                'operator_touchpoints_reduced' => 2,
                'completion_status' => $this->canonicalCompletionStatus([
                    'app/Services/Ai/SoftwareCompanyStewardship/ProductMode/ProductModeOperationalControlReceiptService.php',
                    'docs/ap/AP-755-product-mode-operational-control-receipts-contract.md',
                    'tests/Unit/Ai/SoftwareCompanyStewardship/ProductMode/ProductModeOperationalControlReceiptServiceTest.php',
                ]),
                'completion_evidence' => [
                    'service' => 'ProductMode/ProductModeOperationalControlReceiptService',
                    'contract' => 'docs/ap/AP-755-product-mode-operational-control-receipts-contract.md',
                    'test' => 'tests/Unit/Ai/SoftwareCompanyStewardship/ProductMode/ProductModeOperationalControlReceiptServiceTest.php',
                ],
            ],
            [
                'id' => 'provider_routing_after_owner_boundaries',
                'title' => 'Provider routing authority bridge after owner boundaries',
                'ap_contract' => 'AP-789',
                'type' => 'safety_robustness_unlock',
                'evidence_refs' => ['ap787_forge_owner_runtime_dispatch', 'ap789_forge_authority_bootstrap'],
                'requires_provider_without_sandbox' => false,
                'dependency_unlocks' => ['provider_optimization', 'real_forge_authority', 'atlas_decide_topology'],
                'operator_touchpoints_reduced' => 3,
                'completion_status' => 'pending',
                'completion_evidence' => [
                    'service' => 'ForgeLiveAuthorityBootstrapService',
                    'contract' => 'docs/ap/AP-787-forge-owner-runtime-dispatch-bridge-contract.md',
                    'test' => 'tests/Unit/Ai/SoftwareCompanyStewardship/AreaFocusLoop/ForgeLiveAuthorityBootstrapServiceTest.php',
                ],
            ],
            [
                'id' => 'owner_senior_loop_repair_after_authority_blocker',
                'title' => 'Owner senior loop repair after authority blocker',
                'ap_contract' => 'AP-786',
                'type' => 'safety_robustness_unlock',
                'evidence_refs' => ['ap786_failed_gate_capsule', 'owner_runtime_senior_loop_execution_not_passed'],
                'requires_provider_without_sandbox' => false,
                'dependency_unlocks' => ['repair_loop', 'candidate_refill', 'provider_authority_progress'],
                'operator_touchpoints_reduced' => 3,
                'completion_status' => 'pending',
                'completion_evidence' => [
                    'service' => 'OwnerFlow/Ap786OwnerFlowExecutor',
                    'contract' => 'docs/ap/AP-786-autonomous-evolution-session-contract.md',
                    'test' => 'tests/Unit/Ai/SoftwareCompanyStewardship/AreaFocusLoop/OwnerFlow/Ap786OwnerFlowExecutorTest.php',
                ],
            ],
        ];
    }

    /**
     * @param  list<string>  $relativePaths
     */
    private function canonicalCompletionStatus(array $relativePaths): string
    {
        foreach ($relativePaths as $path) {
            if (! is_file($this->repoPath($path))) {
                return 'pending';
            }
        }

        return 'completed';
    }

    private function repoPath(string $relativePath): string
    {
        $root = function_exists('base_path') ? base_path() : getcwd();

        return rtrim((string) $root, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.ltrim($relativePath, DIRECTORY_SEPARATOR);
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function identity(array $payload): array
    {
        $copy = $payload;
        unset($copy['priority_hash'], $copy['generated_at']);

        return $copy;
    }

    /**
     * Step 3 of 3 — first ranking rule only: when rank input carries degraded
     * context certification, boost candidates tagged context_memory_retrieval_gap.
     * Per-candidate kinds and remaining rules are future steps.
     *
     * @param  array<string,mixed>  $input
     * @param  array<string,mixed>  $candidate
     * @param  array<string,mixed>  $rankedItem
     * @return array<string,mixed>
     */
    private function applyContextQualityPriorityBoost(array $input, array $candidate, array $rankedItem): array
    {
        $contextInput = $input['context_quality_score'] ?? null;
        if (! is_array($contextInput) || $contextInput === []) {
            return $rankedItem;
        }

        if ($this->candidateFindingKind($candidate) !== ContextQualityScoreContract::FINDING_KIND_CONTEXT_MEMORY_RETRIEVAL_GAP) {
            return $rankedItem;
        }

        $contextQuality = $this->contextQualityScore(array_merge(
            $contextInput,
            ['finding_kind' => ContextQualityScoreContract::FINDING_KIND_CONTEXT_MEMORY_RETRIEVAL_GAP],
        ));
        $boostPoints = (int) ($contextQuality['outputs']['priority_boost_points'] ?? 0);
        if ($boostPoints <= 0) {
            return $rankedItem;
        }

        $boostedScore = round(min(100.0, (float) ($rankedItem['final_priority_score'] ?? 0.0) + $boostPoints), 2);
        $rankedItem['final_priority_score'] = $boostedScore;
        $rankedItem['priority_score'] = $boostedScore;
        $rankedItem['context_quality_priority_boost_points'] = $boostPoints;
        $rankedItem['reason_machine'] = AreaFocusStringListNormalizer::uniqueMergedStringValues(
            (array) ($rankedItem['reason_machine'] ?? []),
            ['context_quality_priority_boost'],
        );
        if (is_array($rankedItem['score_breakdown'] ?? null)) {
            $rankedItem['score_breakdown']['context_quality_priority_boost_points'] = $boostPoints;
            $rankedItem['score_breakdown']['final_priority_score'] = $boostedScore;
        }

        return $rankedItem;
    }

    /**
     * @param  array<string,mixed>  $candidate
     */
    private function candidateFindingKind(array $candidate): string
    {
        foreach (['finding_kind', 'kind', 'type', 'classification'] as $key) {
            $value = strtolower(trim((string) ($candidate[$key] ?? '')));
            if ($value === ContextQualityScoreContract::FINDING_KIND_CONTEXT_MEMORY_RETRIEVAL_GAP) {
                return ContextQualityScoreContract::FINDING_KIND_CONTEXT_MEMORY_RETRIEVAL_GAP;
            }
        }

        return '';
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    private function validateContextQualityScoreInput(array $input): array
    {
        $validated = [];
        foreach ([
            'area_id',
            'focus',
            'certification_quality_score',
            'certification_target_score',
            'certification_status',
            'finding_kind',
        ] as $key) {
            if (array_key_exists($key, $input)) {
                $validated[$key] = $input[$key];
            }
        }

        return $validated;
    }
}
