<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\Support;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AreaFocusScopeProfileNormalizer;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AreaFocusStringListNormalizer;

/**
 * Pure priority backlog materialization helpers for AP-785 Stewardship Priority Engine.
 *
 * Extracted from StewardshipPriorityEngineService private pure methods:
 * terminal starvation rebalance, unlock category classification, seed replenishment,
 * materializable enrichment, and materialization report.
 *
 * No I/O, no DI, no provider calls, no clock, no filesystem.
 */
final class StewardshipPriorityBacklogMaterializationSupport
{
    private function __construct()
    {
    }

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
     * @param  list<array<string,mixed>>  $ranked
     * @param  list<array<string,mixed>>  $candidates
     * @param  array<string,mixed>  $input
     * @return list<array<string,mixed>>
     */
    public static function apply(
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

        $terminalRebalance = self::terminalBacklogRebalanceActive($input);
        $rebalanced = [];

        foreach ($ranked as $item) {
            $itemId = (string) ($item['item_id'] ?? '');
            $source = $sourcesById[$itemId] ?? (is_array($item['source'] ?? null) ? $item['source'] : []);
            $status = strtolower((string) ($item['completion_status'] ?? 'pending'));
            $unlockCategory = self::priorityBacklogUnlockCategory($itemId, $source, $item);

            $replenishCompleted = $terminalRebalance
                && self::terminalStarvationExhaustionActive($input)
                && $status === 'completed'
                && $unlockCategory !== ''
                && in_array($unlockCategory, self::TERMINAL_STARVATION_REPLENISHMENT_UNLOCK_CATEGORIES, true);

            if (($status !== 'completed' || $replenishCompleted) && $unlockCategory !== '') {
                if ($replenishCompleted) {
                    $item['completion_status'] = 'pending';
                    $item['priority_backlog_replenishment_anchor'] = true;
                    $item['lane'] = 'later';
                }
                $item = self::enrichMaterializableBacklogItem($item, $source, $unlockCategory, $scopeProfile);
            }

            if ($terminalRebalance && $unlockCategory !== '' && ($status !== 'completed' || $replenishCompleted)) {
                $item = self::rebalanceTerminalStarvationItem($item, $unlockCategory);
            }

            $rebalanced[] = $item;
        }

        if ($terminalRebalance && self::terminalStarvationExhaustionActive($input)) {
            $rebalanced = self::appendTerminalStarvationReplenishmentCandidates(
                $rebalanced,
                $scopeProfile,
                $input,
            );
        }

        if ($terminalRebalance || $scopeProfile === AreaFocusScopeProfileNormalizer::FACTORY_MAX) {
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
    public static function terminalBacklogRebalanceActive(array $input): bool
    {
        $stateHash = trim((string) ($input['terminal_backlog_state_hash'] ?? ''));
        $reasons = AreaFocusStringListNormalizer::coercedStringValues($input['terminal_backlog_rejection_reasons'] ?? []);

        return $stateHash !== '' || $reasons !== [];
    }

    /**
     * @param  array<string,mixed>  $input
     */
    public static function terminalStarvationExhaustionActive(array $input): bool
    {
        $reasons = AreaFocusStringListNormalizer::coercedStringValues($input['terminal_backlog_rejection_reasons'] ?? []);

        return in_array('no_executable_candidates_after_selection_pass', $reasons, true);
    }

    /**
     * @param  list<array<string,mixed>>  $ranked
     * @param  array<string,mixed>  $input
     * @return list<array<string,mixed>>
     */
    public static function appendTerminalStarvationReplenishmentCandidates(
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
            $seedId = self::terminalReplenishmentSeedIdForCategory($requiredCategory);
            if (isset($presentSeedIds[$seedId])) {
                continue;
            }
            if ($requiredCategory === 'merge' && isset($presentCategories['merge'])) {
                continue;
            }

            $seed = self::terminalReplenishmentSeedForCategory($requiredCategory);
            $scored = StewardshipPriorityScoringSupport::scoreItem(
                $seed,
                $nextIndex,
                $scopeProfile,
                (bool) ($input['has_live_forge_authority'] ?? false),
            );
            $scored = self::enrichMaterializableBacklogItem($scored, $seed, $requiredCategory, $scopeProfile);
            $scored = self::rebalanceTerminalStarvationItem($scored, $requiredCategory);
            $scored['priority_backlog_replenishment_anchor'] = true;
            $ranked[] = $scored;
            $presentSeedIds[$seedId] = true;
            $presentCategories[$requiredCategory] = true;
            $nextIndex++;
        }

        return $ranked;
    }

    public static function terminalReplenishmentSeedIdForCategory(string $category): string
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
    public static function terminalReplenishmentSeedForCategory(string $category): array
    {
        return match ($category) {
            'merge' => [
                'id' => self::terminalReplenishmentSeedIdForCategory('merge'),
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
                'id' => self::terminalReplenishmentSeedIdForCategory('deep_scan'),
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
                'id' => self::terminalReplenishmentSeedIdForCategory('priority_backlog'),
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
                'id' => self::terminalReplenishmentSeedIdForCategory($category),
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
    public static function priorityBacklogUnlockCategory(string $itemId, array $source, array $item): string
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
    public static function enrichMaterializableBacklogItem(
        array $item,
        array $source,
        string $unlockCategory,
        string $scopeProfile,
    ): array {
        $paths = self::materializationPathsFromSource($source);
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

        if ($scopeProfile === AreaFocusScopeProfileNormalizer::FACTORY_MAX || (bool) ($item['factory_execution_ready'] ?? false)) {
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
    public static function rebalanceTerminalStarvationItem(array $item, string $unlockCategory): array
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
    public static function materializationPathsFromSource(array $source): array
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
    public static function report(array $ranked, array $input): array
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
            'terminal_backlog_rebalance' => self::terminalBacklogRebalanceActive($input),
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
}
