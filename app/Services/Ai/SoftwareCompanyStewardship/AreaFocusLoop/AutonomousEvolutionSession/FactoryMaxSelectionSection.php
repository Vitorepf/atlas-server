<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AutonomousEvolutionSession;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AreaFocusLoopPayloadNormalizer;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AreaFocusStringListNormalizer;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AutonomousEvolutionSessionService;
use App\Services\Ai\Mission\MissionCanonicalHash;

/**
 * Factory-max candidate selection + AP-790 terminal-backlog replenishment section,
 * extracted VERBATIM from AutonomousEvolutionSessionService by the GOD-DEBULK
 * split. Deterministic, provider-free candidate origination for the factory_max
 * scope: starvation-recovery, terminal-backlog rank/replenishment, priority
 * backlog promotion, and structural-runtime-gap promotion. The AP-806 honest-stop
 * predicate (isFactoryMaxStarvationRecoveryFinding) stays on the parent facade;
 * shared finding helpers and the priority ranker are reached through the parent.
 */
final class FactoryMaxSelectionSection
{
    public function __construct(
        private readonly AutonomousEvolutionSessionService $parent,
    ) {}

    public function factoryMaxStarvationRecoveryCandidate(array $rejections): array
    {
        $context = $this->starvationExhaustionStateContext($rejections);
        $reasons = $context['reasons'];
        $rejectedIds = $context['rejected_ids'];
        $stateHash = $context['state_hash'];
        $blockingReasons = $this->terminalBacklogRejectionReasons($rejections);
        $runtimeHash = $this->factoryRuntimeVersionHash([
            'app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AutonomousEvolutionSessionService.php',
            'tests/Unit/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AutonomousEvolutionSessionServiceTest.php',
        ]);
        $findingId = AutonomousEvolutionSessionService::FACTORY_MAX_STARVATION_RECOVERY_FINDING_ID.'_'.$stateHash.'_rv_'.$runtimeHash;

        $detail = 'The AP-790 long loop exhausted executable factory candidates while high-value backlog remained blocked by governance or authority. Improve AP-786 selection refill so the loop converts that state into a bounded next action instead of repeating empty selection.';

        $finding = $this->parent->factorySeed(
            'ap790_candidate_starvation_recovery_'.$stateHash,
            'Recover AP-790 from empty executable candidate selection · '.$stateHash.' · rv '.$runtimeHash,
            $detail.' Rejection reason count: '.count($blockingReasons).'. Rejection state hash: '.$stateHash.'.',
            'app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AutonomousEvolutionSessionService.php',
            'AutonomousEvolutionSessionServiceTest.php',
            'atlas_dev',
            'bug',
        );
        $finding['autonomous_selection_refill'] = true;
        $finding['finding_id'] = $findingId;
        $finding['spec_seed']['candidate_id'] = $findingId;
        $versionedHash = 'sha256:'.MissionCanonicalHash::sha256(['AP-786', AutonomousEvolutionSessionService::SCOPE_FACTORY_MAX, $findingId]);
        $finding['finding_hash'] = $versionedHash;
        $finding['spec_seed']['candidate_hash'] = $versionedHash;
        $finding['origin_type'] = 'ap790_candidate_starvation_recovery';
        $finding['starvation_state_hash'] = $stateHash;
        $finding['runtime_version_hash'] = $runtimeHash;
        $finding['starvation_rejection_reasons'] = $reasons;
        $finding['starvation_rejected_ids'] = array_slice($rejectedIds, 0, 24);
        $finding['spec_seed']['state_hash'] = $stateHash;

        return $finding;
    }

    /**
     * Recovery candidates must be able to re-enter after the factory runtime has
     * changed. A prior terminal lock for the same starvation state should not
     * block a materially newer selector implementation.
     *
     * @param  list<string>  $relativeFiles
     */
    public function factoryRuntimeVersionHash(array $relativeFiles): string
    {
        $parts = [];
        foreach ($relativeFiles as $relativeFile) {
            $path = base_path($relativeFile);
            $parts[$relativeFile] = is_file($path)
                ? hash('sha256', (string) file_get_contents($path))
                : 'missing';
        }

        return substr(MissionCanonicalHash::sha256($parts), 0, 10);
    }

    /**
     * @param  list<string>  $relativeFiles
     */
    public function versionedFactoryItemId(string $baseId, array $relativeFiles): string
    {
        return $baseId.'_rv_'.$this->factoryRuntimeVersionHash($relativeFiles);
    }

    /**
     * @param  list<array<string,string>>  $rejections
     * @return array<string,mixed>
     */
    public function factoryMaxSelectionRefillReceipt(array $rejections): array
    {
        $context = $this->starvationExhaustionStateContext($rejections);
        $rejectedIds = $context['rejected_ids'];
        $stateHash = $context['state_hash'];
        $terminalReasons = $this->terminalBacklogRejectionReasons($rejections);
        $runtimeHash = $this->factoryRuntimeVersionHash([
            'app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AutonomousEvolutionSessionService.php',
            'tests/Unit/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AutonomousEvolutionSessionServiceTest.php',
        ]);

        return [
            'schema_version' => 'atlas.software_company_stewardship.ap786_selection_refill.v1',
            'strategy' => 'ap790_candidate_starvation_recovery',
            'finding_id' => AutonomousEvolutionSessionService::FACTORY_MAX_STARVATION_RECOVERY_FINDING_ID,
            'recovery_finding_id' => AutonomousEvolutionSessionService::FACTORY_MAX_STARVATION_RECOVERY_FINDING_ID.'_'.$stateHash.'_rv_'.$runtimeHash,
            'starvation_state_hash' => $stateHash,
            'runtime_version_hash' => $runtimeHash,
            'rejection_reason_count' => count($terminalReasons),
            'rejection_reasons' => $terminalReasons,
            'rejected_finding_count' => count($rejectedIds),
            'terminal_backlog_state_hash' => $stateHash,
            'terminal_backlog_rejection_reasons' => $terminalReasons,
            'terminal_backlog_rejection_reason_count' => count($terminalReasons),
            'bounded_next_action' => 'Improve AP-786 selection refill so exhausted factory backlog becomes one bounded owner-runtime cycle instead of repeating empty selection.',
        ];
    }

    /**
     * @param  list<array<string,string>>  $rejections
     * @return list<string>
     */
    public function terminalBacklogRejectionReasons(array $rejections): array
    {
        $reasons = AreaFocusStringListNormalizer::uniqueStringValues(array_filter(array_map(
            static fn (array $rejection): string => (string) ($rejection['reason'] ?? ''),
            $rejections,
        )));
        sort($reasons);

        return $reasons;
    }

    /**
     * @param  list<array<string,string>>  $rejections
     * @return array{terminal_backlog_state_hash:string,terminal_backlog_rejection_reasons:list<string>}
     */
    public function terminalBacklogRankContext(array $rejections): array
    {
        $context = $this->starvationExhaustionStateContext($rejections);
        $terminalReasons = $this->terminalBacklogRejectionReasons($rejections);

        return [
            'terminal_backlog_state_hash' => $context['state_hash'],
            'terminal_backlog_rejection_reasons' => $terminalReasons,
        ];
    }

    /**
     * @param  array<string,true>  $reviewLocked
     * @param  array<string,true>  $terminalLocked
     * @param  array<string,true>  $candidateKeys
     * @param  list<array<string,string>>  $rejections
     * @param  array<string,mixed>  $selectionRefill
     * @param  array<string,mixed>  $priority
     * @return array{finding:array<string,mixed>|null,priority_report:array<string,mixed>,selection_rejections:list<array<string,string>>,selection_refill:array<string,mixed>|null}|null
     */
    public function tryFactoryMaxTerminalBacklogReplenishmentSelection(
        string $areaId,
        array $forgeInputs,
        string $scopeProfile,
        array $reviewLocked,
        array $terminalLocked,
        array $candidateKeys,
        array $rejections,
        array $selectionRefill,
        array $priority,
    ): ?array {
        $replenishmentPriority = $this->parent->priorityEngine->rank([
            'area_id' => $areaId,
            'focus' => AutonomousEvolutionSessionService::DEFAULT_FOCUS,
            'candidates' => [],
            'scope_profile' => $scopeProfile,
            'has_live_forge_authority' => $this->parent->hasLiveForgeAuthority($forgeInputs),
        ] + $this->terminalBacklogRankContext($rejections));

        $replenishmentCandidates = $this->factoryMaxPriorityBacklogCandidates($replenishmentPriority);
        foreach ($this->terminalBacklogReplenishmentFallbackItems() as $fallbackItem) {
            $fallbackCandidate = $this->factoryMaxPriorityBacklogCandidate($fallbackItem);
            if ($fallbackCandidate !== null) {
                $fallbackId = (string) ($fallbackCandidate['finding_id'] ?? '');
                $candidateIds = array_map(
                    static fn (array $candidate): string => (string) ($candidate['finding_id'] ?? ''),
                    $replenishmentCandidates,
                );
                if ($fallbackId !== '' && ! in_array($fallbackId, $candidateIds, true)) {
                    $replenishmentCandidates[] = $fallbackCandidate;
                }
            }
        }

        foreach ($replenishmentCandidates as $replenishmentCandidate) {
            if ($this->parent->findingIsReviewLocked($replenishmentCandidate, $reviewLocked + $terminalLocked + $candidateKeys)) {
                $rejections[] = [
                    'finding_id' => (string) ($replenishmentCandidate['finding_id'] ?? ''),
                    'title' => (string) ($replenishmentCandidate['title'] ?? ''),
                    'reason' => 'terminal_unlock_candidate_locked',
                ];

                continue;
            }

            $rankedPriority = $this->parent->priorityEngine->rank([
                'area_id' => $areaId,
                'focus' => AutonomousEvolutionSessionService::DEFAULT_FOCUS,
                'candidates' => [$replenishmentCandidate],
                'scope_profile' => $scopeProfile,
                'has_live_forge_authority' => $this->parent->hasLiveForgeAuthority($forgeInputs),
            ] + $this->terminalBacklogRankContext($rejections));

            return [
                'finding' => $replenishmentCandidate,
                'priority_report' => $rankedPriority,
                'selection_rejections' => $rejections,
                'selection_refill' => $selectionRefill + [
                    'terminal_unlock_strategy' => 'ap790_terminal_backlog_unlock',
                    'terminal_backlog_replenishment' => true,
                ],
            ];
        }

        $timeoutRecovery = $this->factoryMaxTerminalRuntimeRecoveryCandidate($rejections, $terminalLocked);
        if (! $this->parent->findingIsReviewLocked($timeoutRecovery, $reviewLocked + $terminalLocked + $candidateKeys)) {
            $rankedPriority = $this->parent->priorityEngine->rank([
                'area_id' => $areaId,
                'focus' => AutonomousEvolutionSessionService::DEFAULT_FOCUS,
                'candidates' => [$timeoutRecovery],
                'scope_profile' => $scopeProfile,
                'has_live_forge_authority' => $this->parent->hasLiveForgeAuthority($forgeInputs),
            ] + $this->terminalBacklogRankContext($rejections));

            return [
                'finding' => $timeoutRecovery,
                'priority_report' => $rankedPriority,
                'selection_rejections' => $rejections,
                'selection_refill' => $selectionRefill + [
                    'terminal_unlock_strategy' => 'ap790_terminal_backlog_unlock',
                    'terminal_backlog_replenishment' => true,
                    'terminal_runtime_recovery' => true,
                ],
            ];
        }

        return null;
    }

    /**
     * Final bounded fallback after the normal starvation, terminal-unlock and
     * replenishment ladders are exhausted. This targets the owner runtime that
     * actually timed out, so the next cycle improves timeout/fallback behavior
     * instead of looping forever on empty candidate selection.
     *
     * @param  list<array<string,string>>  $rejections
     * @param  array<string,true>  $terminalLocked
     * @return array<string,mixed>
     */
    public function factoryMaxTerminalRuntimeRecoveryCandidate(array $rejections, array $terminalLocked): array
    {
        $context = $this->starvationExhaustionStateContext($rejections);
        $runtimeHash = $this->factoryRuntimeVersionHash([
            'app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/OwnerFlow/Ap786OwnerFlowExecutor.php',
            'tests/Unit/Ai/SoftwareCompanyStewardship/AreaFocusLoop/OwnerFlow/Ap786OwnerFlowExecutorTest.php',
        ]);
        $terminalHash = substr(MissionCanonicalHash::sha256(array_keys($terminalLocked)), 0, 8);
        $id = 'ap786_owner_runtime_timeout_recovery_'.$context['state_hash'].'_'.$terminalHash.'_rv_'.$runtimeHash;

        $finding = $this->parent->factorySeed(
            $id,
            'Recover owner runtime provider timeout after terminal AP-790 starvation · '.$context['state_hash'].' · '.$terminalHash,
            'The AP-790 loop exhausted selection, terminal-unlock and replenishment candidates, then owner-runtime execution timed out. Improve AP-786 owner flow timeout diagnostics, fallback routing or retry behavior so provider timeouts become bounded recoverable work instead of ending the 24h loop.',
            'app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/OwnerFlow/Ap786OwnerFlowExecutor.php',
            'OwnerFlow/Ap786OwnerFlowExecutorTest.php',
            'atlas_dev',
            'bug',
        );
        $finding['origin_type'] = 'ap790_terminal_runtime_recovery';
        $finding['terminal_backlog_state_hash'] = $context['state_hash'];
        $finding['terminal_runtime_recovery_hash'] = $terminalHash;
        $finding['runtime_version_hash'] = $runtimeHash;
        $finding['spec_seed']['state_hash'] = $context['state_hash'];
        $finding['spec_seed']['acceptance'][] = 'Provider timeout or senior-loop repair exhaustion becomes a bounded recoverable condition and AP-790 can select a fresh next action.';

        return $finding;
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function terminalBacklogReplenishmentFallbackItems(): array
    {
        return [
            [
                'item_id' => $this->versionedFactoryItemId(
                    'terminal_backlog_replenish_merge_queue',
                    ['app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/StewardshipMergeQueueService.php'],
                ),
                'item_type' => 'merge_queue',
                'lane' => 'now',
                'completion_status' => 'pending',
                'final_priority_score' => 990,
            ],
            [
                'item_id' => $this->versionedFactoryItemId(
                    'terminal_backlog_replenish_deep_scan',
                    ['app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AreaFocusDeepFindingEngineService.php'],
                ),
                'item_type' => 'deep_scan',
                'lane' => 'now',
                'completion_status' => 'pending',
                'final_priority_score' => 980,
            ],
            [
                'item_id' => $this->versionedFactoryItemId(
                    'terminal_backlog_replenish_priority_backlog',
                    ['app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/StewardshipPriorityEngineService.php'],
                ),
                'item_type' => 'priority_backlog',
                'lane' => 'now',
                'completion_status' => 'pending',
                'final_priority_score' => 970,
            ],
        ];
    }

    /**
     * @param  list<array<string,string>>  $rejections
     * @return list<array<string,string>>
     */
    public function starvationExhaustionRejections(array $rejections): array
    {
        return array_values(array_filter(
            $rejections,
            function (array $rejection): bool {
                $findingId = (string) ($rejection['finding_id'] ?? '');
                $reason = (string) ($rejection['reason'] ?? '');

                if (str_starts_with($findingId, AutonomousEvolutionSessionService::FACTORY_MAX_STARVATION_RECOVERY_FINDING_ID)) {
                    return false;
                }
                if ($this->isFactoryMaxTerminalBacklogUnlockFindingId($findingId)) {
                    return false;
                }
                if (in_array($reason, AutonomousEvolutionSessionService::STARVATION_META_REJECTION_REASONS, true)) {
                    return false;
                }

                return true;
            },
        ));
    }

    public function isFactoryMaxTerminalBacklogUnlockFindingId(string $findingId): bool
    {
        return str_starts_with($findingId, 'factory_max_ap790_terminal_backlog_unlock_')
            || str_starts_with($findingId, 'factory_max_ap748_terminal_backlog_discovery_')
            || str_starts_with($findingId, 'factory_max_ap785_terminal_backlog_rebalance_');
    }

    /**
     * @param  list<array<string,string>>  $rejections
     * @return array{reasons:list<string>,rejected_ids:list<string>,state_hash:string}
     */
    public function starvationExhaustionStateContext(array $rejections): array
    {
        $exhaustionRejections = $this->starvationExhaustionRejections($rejections);
        $reasons = AreaFocusStringListNormalizer::uniqueStringValues(array_filter(array_map(
            static fn (array $rejection): string => (string) ($rejection['reason'] ?? ''),
            $exhaustionRejections,
        )));
        sort($reasons);
        $rejectedIds = AreaFocusStringListNormalizer::uniqueStringValues(array_filter(array_map(
            static fn (array $rejection): string => (string) ($rejection['finding_id'] ?? ''),
            $exhaustionRejections,
        )));
        sort($rejectedIds);
        $rejectedIds = array_slice($rejectedIds, 0, 24);
        $stateHash = substr(MissionCanonicalHash::sha256([
            'reasons' => $reasons,
            'rejected_ids' => $rejectedIds,
        ]), 0, 12);

        return [
            'reasons' => $reasons,
            'rejected_ids' => $rejectedIds,
            'state_hash' => $stateHash,
        ];
    }

    /**
     * AP-785 can still rank canonical high-impact backlog when AP-748 finds no
     * executable item. The long loop must turn that ranked backlog into bounded
     * owner-runtime work instead of stopping at no_candidate_with_allowed_files.
     *
     * @param  array<string,mixed>  $priority
     * @return list<array<string,mixed>>
     */
    public function factoryMaxPriorityBacklogCandidates(array $priority): array
    {
        $ranked = AreaFocusLoopPayloadNormalizer::listOfArrays($priority['ranked_items'] ?? []);
        if ($ranked === []) {
            $ranked = AreaFocusLoopPayloadNormalizer::listOfArrays($priority['ranked_candidates'] ?? []);
        }

        $candidates = [];
        foreach ($ranked as $item) {
            $lane = strtolower((string) ($item['lane'] ?? ''));
            $status = strtolower((string) ($item['completion_status'] ?? 'pending'));
            if ($lane !== 'now' || $status === 'completed') {
                continue;
            }

            $candidate = $this->factoryMaxPriorityBacklogCandidate($item);
            if ($candidate === null) {
                continue;
            }
            $candidates[] = $candidate;
        }

        return $candidates;
    }

    /**
     * @param  array<string,mixed>  $item
     * @return array<string,mixed>|null
     */
    public function factoryMaxPriorityBacklogCandidate(array $item): ?array
    {
        $id = strtolower((string) ($item['item_id'] ?? $item['candidate_id'] ?? $item['id'] ?? ''));
        $type = strtolower((string) ($item['item_type'] ?? $item['type'] ?? ''));
        $key = $id !== '' ? $id : $type;
        if ($key === '') {
            return null;
        }

        $seed = match (true) {
            str_contains($key, 'owner_runtime') || str_contains($key, 'runtime_execution') => $this->parent->factorySeed(
                'ap790_priority_owner_runtime_real_execution_bridge',
                'Materialize owner runtime real execution bridge backlog into AP-790 work',
                'The priority engine ranks owner-runtime real execution as the highest pending factory unlock, but it has no executable files attached. Materialize it through AP-786 owner-flow diagnostics and tests so the loop can keep improving real owner execution instead of stopping at empty candidate selection.',
                'app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/OwnerFlow/Ap786OwnerFlowExecutor.php',
                'OwnerFlow/Ap786OwnerFlowExecutorTest.php',
                'atlas_dev',
                'bug',
            ),
            str_contains($key, 'continuous_24h') || str_contains($key, '24h_scheduler') || str_contains($key, 'scheduler') => $this->parent->factorySeed(
                'ap790_priority_continuous_24h_scheduler',
                'Materialize continuous 24h scheduler backlog into AP-790 work',
                'The priority engine ranks continuous 24h scheduler reliability as a pending factory unlock, but the backlog item has no executable files attached. Materialize it through Reliable24hLoopRunnerService so blocked, merged and recovered cycles remain observable and bounded.',
                'app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/Reliable24hLoopRunnerService.php',
                'Reliable24hLoopRunnerServiceTest.php',
                'atlas_dev',
                'bug',
            ),
            str_contains($key, 'product_mode') || str_contains($key, 'controls') || str_contains($key, 'receipt') => $this->parent->factorySeed(
                'ap790_priority_product_mode_controls_receipts',
                'Materialize Product Mode controls and receipts backlog into AP-790 work',
                'The priority engine ranks Product Mode controls and receipts as the next operator-safety unlock, but the backlog item has no executable files attached. Materialize it through ProductModeOperationalControlReceiptService so pause, kill-switch and autonomy decisions remain receipt-backed before longer unattended runs.',
                'app/Services/Ai/SoftwareCompanyStewardship/ProductMode/ProductModeOperationalControlReceiptService.php',
                'ProductModeOperationalControlReceiptServiceTest.php',
                'atlas_dev',
                'bug',
            ),
            str_contains($key, 'provider_routing') || str_contains($key, 'provider_optimization') || str_contains($key, 'atlas_decide') => $this->parent->factorySeed(
                'ap789_provider_routing_authority_bridge',
                'Materialize provider routing authority bridge into AP-790 work',
                'The priority engine ranks provider routing only after owner runtime, scheduler and Product Mode controls are real. Materialize the next safe step through ForgeLiveAuthorityBootstrapService so AP-790 can move toward AtlasDecide/Forge authority without direct provider routing, fake topology or unsandboxed mutation.',
                'app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/ForgeLiveAuthorityBootstrapService.php',
                'ForgeLiveAuthorityBootstrapServiceTest.php',
                'atlas_dev',
                'bug',
            ),
            str_contains($key, 'senior_loop') || str_contains($key, 'failed_gate') || str_contains($key, 'repair_after_authority') => $this->parent->factorySeed(
                'ap786_owner_senior_loop_repair_after_authority_blocker',
                'Materialize owner senior loop repair after authority blocker',
                'The AP-790 loop reached a real owner runtime blocker: owner_runtime_senior_loop_execution_not_passed. Materialize a repair in Ap786OwnerFlowExecutor so senior-loop failures become more actionable and the loop can keep advancing without hiding failed provider/verification attempts.',
                'app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/OwnerFlow/Ap786OwnerFlowExecutor.php',
                'OwnerFlow/Ap786OwnerFlowExecutorTest.php',
                'atlas_dev',
                'bug',
            ),
            str_contains($key, 'deep_scan') || str_contains($key, 'candidate_discovery') => $this->parent->factorySeed(
                'ap790_priority_terminal_backlog_replenish_deep_scan',
                'Replenish deep-scan candidate discovery after terminal starvation',
                'The 24h loop consumed merge-queue replenishment and still found no executable work. Materialize deeper AP-748 candidate discovery so AP-790 can keep surfacing fresh Atlas Dev and Forge runtime bottlenecks instead of stopping at no_candidate_with_allowed_files.',
                'app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AreaFocusDeepFindingEngineService.php',
                'AreaFocusDeepFindingEngineServiceTest.php',
                'atlas_dev',
                'bug',
            ),
            str_contains($key, 'priority_backlog') || str_contains($key, 'priority_engine') => $this->parent->factorySeed(
                'ap790_priority_terminal_backlog_replenish_priority_backlog',
                'Replenish priority backlog generation after terminal starvation',
                'The 24h loop consumed merge-queue and deep-scan replenishment without finding executable work. Materialize AP-785 priority backlog generation so AP-790 can keep producing high-return runtime candidates instead of exhausting the factory queue.',
                'app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/StewardshipPriorityEngineService.php',
                'StewardshipPriorityEngineServiceTest.php',
                'atlas_dev',
                'bug',
            ),
            str_contains($key, 'terminal_backlog_replenish') || str_contains($key, 'merge_queue') => $this->parent->factorySeed(
                'ap790_priority_terminal_backlog_replenish_merge_queue',
                'Replenish merge queue executable after terminal starvation',
                'The 24h loop exhausted AP-786 terminal unlock ladder candidates. Materialize merge-queue replenishment so AP-790 can keep advancing with bounded Stewardship merge work instead of stopping at no_candidate_with_allowed_files.',
                'app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/StewardshipMergeQueueService.php',
                'StewardshipMergeQueueServiceTest.php',
                'atlas_dev',
                'bug',
            ),
            default => null,
        };

        if ($seed === null) {
            return null;
        }

        $seed['origin_type'] = 'priority_backlog_materialized';
        $seed['priority_source'] = [
            'schema_version' => 'atlas.software_company_stewardship.priority_backlog_source.v1',
            'item_id' => $id,
            'item_type' => $type,
            'lane' => (string) ($item['lane'] ?? ''),
            'final_priority_score' => $item['final_priority_score'] ?? null,
        ];
        $seed['evidence_refs'][] = 'ap785_priority_backlog:'.$key;
        $seed['spec_seed']['evidence_refs'][] = 'ap785_priority_backlog:'.$key;
        $seed['spec_seed']['acceptance'][] = 'The loop can select this priority-backed candidate when scanned findings and static seeds are exhausted.';

        return $seed;
    }

    /**
     * Terminal-locked starvation recovery means the loop has already tried to
     * fix empty selection and the owner runtime could not finish it. The next
     * professional move is to replenish the candidate factory itself through a
     * small ordered ladder, not to keep selecting the same exhausted recovery.
     *
     * @param  list<array<string,string>>  $rejections
     * @return list<array<string,mixed>>
     */
    public function factoryMaxTerminalBacklogUnlockCandidates(array $rejections): array
    {
        $context = $this->starvationExhaustionStateContext($rejections);
        $stateHash = $context['state_hash'];
        $terminalReasons = $this->terminalBacklogRejectionReasons($rejections);
        $reasonCount = count($terminalReasons);
        $detailSuffix = ' Terminal backlog state hash: '.$stateHash.'. Rejection reason count: '.$reasonCount.'.';

        $candidates = [
            $this->parent->factorySeed(
                'ap790_terminal_backlog_unlock_'.$stateHash,
                'Unlock AP-790 terminal candidate starvation · '.$stateHash,
                'The 24h loop reached terminal-locked starvation recovery. Add bounded selection/backlog replenishment behavior so AP-786 can continue to a fresh, high-impact executable candidate instead of stopping at no_candidate_with_allowed_files.'.$detailSuffix,
                'app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AutonomousEvolutionSessionService.php',
                'AutonomousEvolutionSessionServiceTest.php',
                'atlas_dev',
                'bug',
            ),
            $this->parent->factorySeed(
                'ap748_terminal_backlog_discovery_'.$stateHash,
                'Replenish AP-748 runtime candidate discovery after terminal starvation · '.$stateHash,
                'The 24h loop exhausted AP-786 static and priority-backed candidates. Improve AP-748 deep finding discovery so factory_max scans surface fresh runtime bottlenecks in Atlas Dev and Forge instead of leaving AP-790 without executable work.'.$detailSuffix,
                'app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AreaFocusDeepFindingEngineService.php',
                'AreaFocusDeepFindingEngineServiceTest.php',
                'atlas_dev',
                'bug',
            ),
            $this->parent->factorySeed(
                'ap785_terminal_backlog_rebalance_'.$stateHash,
                'Rebalance AP-785 priority backlog after terminal starvation · '.$stateHash,
                'The 24h loop has no executable high-impact candidate after locks and terminal blockers. Improve AP-785 priority backlog materialization so owner runtime, Forge authority, scheduler, merge and provider-routing unlocks stay available as concrete executable candidates.'.$detailSuffix,
                'app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/StewardshipPriorityEngineService.php',
                'StewardshipPriorityEngineServiceTest.php',
                'atlas_dev',
                'bug',
            ),
        ];

        foreach ($candidates as $index => $candidate) {
            $candidates[$index]['origin_type'] = 'ap790_terminal_backlog_unlock';
            $candidates[$index]['terminal_backlog_state_hash'] = $stateHash;
            $candidates[$index]['terminal_backlog_rejection_reasons'] = $terminalReasons;
            $candidates[$index]['terminal_backlog_rejected_ids'] = $context['rejected_ids'];
            $candidates[$index]['spec_seed']['state_hash'] = $stateHash;
            $candidates[$index]['spec_seed']['acceptance'][] = 'AP-790 no longer stops at no_candidate_with_allowed_files for this terminal backlog state.';
        }

        return $candidates;
    }
    /** @param array<string,mixed> $finding */
    public function isFactoryMaxTerminalBacklogUnlockFinding(array $finding): bool
    {
        if ((string) ($finding['origin_type'] ?? '') === 'ap790_terminal_backlog_unlock') {
            return true;
        }

        $findingId = (string) ($finding['finding_id'] ?? '');

        return str_starts_with($findingId, 'factory_max_ap790_terminal_backlog_unlock_')
            || str_starts_with($findingId, 'factory_max_ap748_terminal_backlog_discovery_')
            || str_starts_with($findingId, 'factory_max_ap785_terminal_backlog_rebalance_');
    }

    /** @param array<string,mixed> $finding */
    public function isForgeAuthorityReadinessCandidate(array $finding): bool
    {
        $originType = (string) ($finding['origin_type'] ?? '');
        $findingId = (string) ($finding['finding_id'] ?? '');

        return str_starts_with($originType, 'ap789_')
            || str_starts_with($findingId, 'factory_max_ap789_');
    }

    /**
     * AP-748 is read-only by design, so structural findings arrive as
     * proposal-only. The 24h factory loop may still execute the narrow subset that
     * is already safe: in-focus Atlas Dev missing-test findings over factory
     * runtime files with an explicit expected test path.
     *
     * @param  array<string,mixed>  $finding
     * @return array<string,mixed>
     */
    public function promoteSafeFactoryFinding(array $finding, string $scopeProfile): array
    {
        $finding = $this->promoteStructuralRuntimeGapFinding($finding, $scopeProfile);
        if ($scopeProfile !== AutonomousEvolutionSessionService::SCOPE_FACTORY_MAX || $this->parent->findingAllowsAutonomousExecution($finding)) {
            return $finding;
        }
        if (! $this->isSafeFactoryStructuralFinding($finding)) {
            return $finding;
        }

        $allowedFiles = $this->parent->allowedFiles($finding);
        $testsRequired = $this->parent->testsRequiredForFinding($finding, $allowedFiles);
        $title = trim((string) ($finding['title'] ?? ''));

        $finding['auto_execution_allowed'] = true;
        $finding['operator_review_required'] = false;
        $finding['autonomous_execution_reason'] = 'factory_max_safe_structural_missing_test';
        $finding['proposed_next_action'] = $this->safeFactoryNextAction($title, $allowedFiles, $testsRequired);

        $specSeed = is_array($finding['spec_seed'] ?? null) ? $finding['spec_seed'] : [];
        $specSeed['proposal_only'] = false;
        $specSeed['operator_review_required'] = false;
        $specSeed['tests_required'] = $testsRequired;
        $specSeed['acceptance'] = array_values(array_filter([
            $title !== '' ? 'The owner runtime implements the selected missing-test finding: '.$title.'.' : '',
            $testsRequired !== [] ? 'The focused test command passes: php artisan test '.$testsRequired[0].'.' : '',
            'The implementation changes only the selected runtime/test allowed_files.',
        ], static fn (string $line): bool => $line !== ''));
        $finding['spec_seed'] = $specSeed;

        return $finding;
    }

    /**
     * AP-790: ingest high-impact AAEOS runtime gap matrix rows (partial_runtime /
     * spec_runtime_gap) as executable factory-max candidates before routine
     * missing-test maintenance.
     *
     * @param  array<string,mixed>  $finding
     * @return array<string,mixed>
     */
    public function promoteStructuralRuntimeGapFinding(array $finding, string $scopeProfile): array
    {
        if ($scopeProfile !== AutonomousEvolutionSessionService::SCOPE_FACTORY_MAX || ! $this->isStructuralRuntimeGapFinding($finding)) {
            return $finding;
        }

        $allowedFiles = $this->parent->allowedFiles($finding);
        $testsRequired = $this->parent->testsRequiredForFinding($finding, $allowedFiles);
        $title = trim((string) ($finding['title'] ?? ''));
        $gapKind = $this->structuralRuntimeGapKind($finding);

        $finding['auto_execution_allowed'] = true;
        $finding['operator_review_required'] = false;
        $finding['autonomous_execution_reason'] = 'factory_max_structural_runtime_gap_matrix';
        $finding['proposed_next_action'] = sprintf(
            'Close the AAEOS runtime gap matrix %s finding "%s": implement the bounded runtime/test change in allowed_files and prove it with the focused test.',
            $gapKind,
            $title !== '' ? $title : 'structural runtime gap',
        );

        $specSeed = is_array($finding['spec_seed'] ?? null) ? $finding['spec_seed'] : [];
        $specSeed['proposal_only'] = false;
        $specSeed['operator_review_required'] = false;
        $specSeed['gap_kind'] = $gapKind;
        $specSeed['tests_required'] = $testsRequired;
        $specSeed['acceptance'] = array_values(array_filter([
            $title !== '' ? 'The owner runtime closes the structural runtime gap: '.$title.'.' : '',
            $testsRequired !== [] ? 'The focused test command passes: php artisan test '.$testsRequired[0].'.' : '',
            'AP-790 consumed this partial_runtime/spec_runtime_gap backlog item before routine maintenance.',
        ], static fn (string $line): bool => $line !== ''));
        $finding['spec_seed'] = $specSeed;

        return $finding;
    }

    /**
     * @param  list<array<string,mixed>>  $findings
     */
    public function scanHasStructuralRuntimeGapBacklog(array $findings): bool
    {
        foreach ($findings as $finding) {
            if (is_array($finding) && $this->isStructuralRuntimeGapFinding($finding)) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string,mixed> $finding */
    public function isStructuralRuntimeGapFinding(array $finding): bool
    {
        return in_array($this->structuralRuntimeGapKind($finding), AutonomousEvolutionSessionService::FACTORY_MAX_STRUCTURAL_RUNTIME_GAP_KINDS, true);
    }

    /** @param array<string,mixed> $finding */
    public function structuralRuntimeGapKind(array $finding): string
    {
        $gapKind = strtolower(trim((string) (
            data_get($finding, 'spec_seed.gap_kind')
            ?? data_get($finding, 'gap_kind')
            ?? ''
        )));
        if ($gapKind !== '') {
            return $gapKind;
        }

        if (strtolower((string) ($finding['origin'] ?? '')) !== 'runtime_gap_matrix') {
            return '';
        }

        return strtolower(trim((string) ($finding['origin_type'] ?? '')));
    }

    /** @param array<string,mixed> $finding */
    public function isSafeFactoryStructuralFinding(array $finding): bool
    {
        if ((string) ($finding['origin'] ?? '') !== 'structural_ap717') {
            return false;
        }
        if (! in_array(strtolower((string) ($finding['origin_type'] ?? '')), AutonomousEvolutionSessionService::FACTORY_MAX_SAFE_STRUCTURAL_ORIGIN_TYPES, true)) {
            return false;
        }
        if ((bool) ($finding['in_focus'] ?? false) !== true || $this->parent->owner($finding) !== 'atlas_dev') {
            return false;
        }
        if (! in_array(strtolower((string) ($finding['severity'] ?? '')), ['low', 'medium'], true)) {
            return false;
        }
        if (AreaFocusStringListNormalizer::preserveNonBlankStrings($finding['affected_docs'] ?? []) !== []) {
            return false;
        }

        $allowedFiles = $this->parent->allowedFiles($finding);
        $testsRequired = $this->parent->testsRequiredForFinding($finding, $allowedFiles);

        return $allowedFiles !== []
            && $testsRequired !== []
            && $this->parent->touchesFactoryRuntime($allowedFiles);
    }

    /**
     * @param  list<string>  $allowedFiles
     * @param  list<string>  $testsRequired
     */
    public function safeFactoryNextAction(string $title, array $allowedFiles, array $testsRequired): string
    {
        $target = $allowedFiles[0] ?? 'selected runtime';
        $test = $testsRequired[0] ?? 'focused test';

        return sprintf(
            'Implement the safe AP-717 missing-test finding "%s": add or harden %s for %s, keep the diff inside allowed_files, and prove it with php artisan test %s.',
            $title !== '' ? $title : 'missing test',
            $test,
            $target,
            $test,
        );
    }
}
