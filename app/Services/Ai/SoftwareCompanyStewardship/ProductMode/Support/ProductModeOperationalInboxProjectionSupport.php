<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\ProductMode\Support;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\FirstFullCycleOrchestratorService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\StewardshipBranchSystemCertificationService;
use App\Services\Ai\SoftwareCompanyStewardship\ContinuousStewardship\ContinuousStewardshipDayReadinessService;
use App\Services\Ai\SoftwareCompanyStewardship\ContinuousStewardship\ContinuousStewardshipDayStartService;
use App\Services\Ai\SoftwareCompanyStewardship\ContinuousStewardship\ContinuousStewardshipRunnerService;
use App\Services\Ai\SoftwareCompanyStewardship\StewardshipStringListNormalizer;

/**
 * Pure projection helpers for Product Mode operational inbox (AP-754 family).
 *
 * Extracted from ProductModeOperationalInboxReadModelService private pure methods:
 * item mappers, counters, blockers/next-actions, claim policy, hash identity,
 * AP-786 cycle shaping. No I/O, no DI, no time side effects, no provider calls.
 *
 * Caller remains responsible for source loading (readiness/runner/…), first-cycle
 * replay I/O, finalize hash + generated_at timestamps.
 */
final class ProductModeOperationalInboxProjectionSupport
{
    public const ITEM_SCHEMA = 'atlas.software_company.stewardship_operational_inbox_item.v1';

    /** AP-786 owner-flow chain that must be the authority for provider execution. */
    public const AP786_OWNER_FLOW_CHAIN = [
        'AP-747' => 'area_focus_dev_forge_release',
        'AP-756' => 'branch_sandbox_materializer',
        'AP-757' => 'owner_release_outcome_bridge',
        'AP-749' => 'owner_specific_dev_forge_queue_consumption_gate',
        'AP-758' => 'owner_flow_execution_authority',
        'AP-759' => 'owner_sandbox_runtime_runner',
        'AP-750' => 'owner_runtime_result_bridge',
    ];

    /**
     * @param  array<string,mixed>  $readiness
     * @return list<array<string,mixed>>
     */
    public static function itemsFromReadiness(string $areaId, string $portfolioId, array $readiness): array
    {
        $items = [];
        $status = (string) ($readiness['status'] ?? '');
        $blockers = array_values(array_filter((array) ($readiness['blockers'] ?? []), 'is_string'));

        if ($status === ContinuousStewardshipDayReadinessService::STATUS_BLOCKED) {
            $items[] = self::item(
                kind: 'continuous_24h_readiness_blocked',
                bucket: 'alert',
                cycleState: 'blocked',
                title: '24h stewardship loop is not ready to start',
                summary: $blockers === [] ? 'AP-777 readiness gate is blocked.' : 'Blockers: '.implode(', ', $blockers),
                areaId: $areaId,
                portfolioId: $portfolioId,
                sourceAp: 'AP-777',
                dedupeKey: 'stewardship:ap777:blocked:'.$areaId,
                payload: ['readiness' => $readiness],
            );
            foreach ($blockers as $blocker) {
                if (str_contains($blocker, 'kill_switch')) {
                    $items[] = self::item(
                        kind: 'kill_switch_active',
                        bucket: 'alert',
                        cycleState: 'blocked',
                        title: 'Kill switch is active',
                        summary: 'Continuous stewardship cannot run until the kill switch is cleared by the operator.',
                        areaId: $areaId,
                        portfolioId: $portfolioId,
                        sourceAp: 'AP-777',
                        dedupeKey: 'stewardship:kill_switch:'.$areaId.':'.$blocker,
                        payload: ['blocker' => $blocker],
                    );
                }
                if (str_contains($blocker, 'rate') || str_contains($blocker, 'budget')) {
                    $items[] = self::item(
                        kind: 'rate_limited_or_budget_blocked',
                        bucket: 'alert',
                        cycleState: 'rate_limited',
                        title: 'Runner budget or rate policy blocks admission',
                        summary: $blocker,
                        areaId: $areaId,
                        portfolioId: $portfolioId,
                        sourceAp: 'AP-766',
                        dedupeKey: 'stewardship:rate_budget:'.$areaId.':'.$blocker,
                        payload: ['blocker' => $blocker],
                    );
                }
            }
        } elseif ($status === ContinuousStewardshipDayReadinessService::STATUS_READY) {
            $items[] = self::item(
                kind: 'continuous_24h_readiness_ready',
                bucket: 'recommendation',
                cycleState: 'ready',
                title: '24h stewardship loop is ready to start',
                summary: 'AP-777 reports all required proofs for an operator-observed 24h run.',
                areaId: $areaId,
                portfolioId: $portfolioId,
                sourceAp: 'AP-777',
                dedupeKey: 'stewardship:ap777:ready:'.$areaId,
                payload: [
                    'operator_start_command' => (string) ($readiness['operator_start_command'] ?? ''),
                ],
            );
        }

        return $items;
    }

    /**
     * @param  array<string,mixed>  $runner
     * @return list<array<string,mixed>>
     */
    public static function itemsFromRunner(string $areaId, string $portfolioId, array $runner): array
    {
        $items = [];
        $kill = (array) ($runner['kill_switch_status'] ?? []);
        if ((bool) ($kill['global_active'] ?? $kill['active'] ?? false)) {
            $items[] = self::item(
                kind: 'kill_switch_active',
                bucket: 'alert',
                cycleState: 'blocked',
                title: 'Continuous runner kill switch is active',
                summary: (string) ($kill['detail'] ?? 'Global or area kill switch blocks runner admission.'),
                areaId: $areaId,
                portfolioId: $portfolioId,
                sourceAp: 'AP-766',
                dedupeKey: 'stewardship:ap766:kill_switch:'.$areaId,
                payload: ['kill_switch_status' => $kill],
            );
        }

        $budget = (array) ($runner['budget_status'] ?? []);
        if (($budget['status'] ?? '') === 'exhausted' || ($runner['status'] ?? '') === ContinuousStewardshipRunnerService::STATUS_BUDGET_EXHAUSTED) {
            $items[] = self::item(
                kind: 'rate_limited',
                bucket: 'alert',
                cycleState: 'rate_limited',
                title: 'Daily runner budget exhausted',
                summary: 'No more AP-766 ticks are admitted until the budget resets or policy changes.',
                areaId: $areaId,
                portfolioId: $portfolioId,
                sourceAp: 'AP-766',
                dedupeKey: 'stewardship:ap766:budget_exhausted:'.$areaId,
                payload: ['budget_status' => $budget],
            );
        }

        $lastRun = is_array($runner['last_run'] ?? null) ? $runner['last_run'] : null;
        if ($lastRun !== null && (bool) ($lastRun['tick_admitted'] ?? false)) {
            $items[] = self::item(
                kind: 'continuous_runner_tick_executed',
                bucket: 'insight',
                cycleState: 'executed',
                title: 'Continuous runner executed a tick',
                summary: 'Last runner receipt: '.(string) ($lastRun['status'] ?? 'ran').' · tick '.(string) ($lastRun['tick_status'] ?? ''),
                areaId: $areaId,
                portfolioId: $portfolioId,
                sourceAp: 'AP-766',
                dedupeKey: 'stewardship:ap766:executed:'.(string) ($lastRun['runner_run_id'] ?? ''),
                payload: ['last_run' => $lastRun],
            );
        } elseif (($runner['status'] ?? '') === ContinuousStewardshipRunnerService::STATUS_BLOCKED) {
            $items[] = self::item(
                kind: 'continuous_runner_blocked',
                bucket: 'alert',
                cycleState: 'blocked',
                title: 'Continuous runner is blocked',
                summary: implode(', ', array_values(array_filter((array) ($runner['blockers'] ?? []), 'is_string'))),
                areaId: $areaId,
                portfolioId: $portfolioId,
                sourceAp: 'AP-766',
                dedupeKey: 'stewardship:ap766:blocked:'.$areaId,
                payload: ['runner_status' => $runner],
            );
        }

        return $items;
    }

    /**
     * @param  array<string,mixed>  $branchCert
     * @return list<array<string,mixed>>
     */
    public static function itemsFromBranchCert(string $areaId, string $portfolioId, array $branchCert): array
    {
        if (($branchCert['status'] ?? '') === StewardshipBranchSystemCertificationService::STATUS_CERTIFIED) {
            return [];
        }

        return [
            self::item(
                kind: 'branch_review_required',
                bucket: 'recommendation',
                cycleState: 'blocked',
                title: 'Branch system certification required before merge queue',
                summary: 'AP-776 is not certified: '.implode(', ', array_values(array_filter((array) ($branchCert['blockers'] ?? []), 'is_string'))),
                areaId: $areaId,
                portfolioId: $portfolioId,
                sourceAp: 'AP-776',
                dedupeKey: 'stewardship:ap776:not_certified:'.$areaId,
                payload: ['branch_system_certification' => $branchCert],
            ),
        ];
    }

    /**
     * @param  array<string,mixed>  $controls
     * @return list<array<string,mixed>>
     */
    public static function itemsFromControls(string $areaId, string $portfolioId, array $controls): array
    {
        $items = [];
        $safety = (array) ($controls['safety_controls'] ?? []);
        if ((bool) ($safety['kill_switch_active'] ?? false)) {
            $items[] = self::item(
                kind: 'product_mode_kill_switch_active',
                bucket: 'alert',
                cycleState: 'blocked',
                title: 'Product Mode kill switch is active',
                summary: 'Night Shift Product Mode safety control blocks stewardship admission.',
                areaId: $areaId,
                portfolioId: $portfolioId,
                sourceAp: 'AP-754',
                dedupeKey: 'stewardship:ap754:kill_switch:'.$areaId,
                payload: ['safety_controls' => $safety],
            );
        }
        if ((bool) ($safety['rate_limited'] ?? false)) {
            $items[] = self::item(
                kind: 'product_mode_rate_limited',
                bucket: 'alert',
                cycleState: 'rate_limited',
                title: 'Product Mode is rate limited',
                summary: 'Operator policy is throttling Product Mode cycles.',
                areaId: $areaId,
                portfolioId: $portfolioId,
                sourceAp: 'AP-754',
                dedupeKey: 'stewardship:ap754:rate_limited:'.$areaId,
                payload: ['safety_controls' => $safety],
            );
        }

        $branchReview = (array) ($controls['branch_review_center'] ?? []);
        $pending = (int) ($branchReview['pending_review_count'] ?? 0);
        if ($pending > 0) {
            $items[] = self::item(
                kind: 'branch_review_required',
                bucket: 'recommendation',
                cycleState: 'ready',
                title: 'Branches pending operator review',
                summary: $pending.' branch(es) need review before merge or deploy decisions.',
                areaId: $areaId,
                portfolioId: $portfolioId,
                sourceAp: 'AP-754',
                dedupeKey: 'stewardship:ap754:branch_review:'.$areaId,
                payload: ['branch_review_center' => $branchReview],
            );
        }

        return $items;
    }

    /**
     * Pure first-cycle items. Pass already-loaded stages from firstCycle->replay()
     * so I/O stays in the service orchestrator.
     *
     * @param  array<string,mixed>  $cycles  shape: { cycles: list<array> }
     * @param  array<string,mixed>|null  $replayStages  shape: { stages: map } or null when no replay
     * @return list<array<string,mixed>>
     */
    public static function itemsFromFirstCycles(string $areaId, string $portfolioId, array $cycles, ?array $replayStages = null): array
    {
        $items = [];
        $list = array_values(array_filter((array) ($cycles['cycles'] ?? []), 'is_array'));
        if ($list === []) {
            return [];
        }

        $latest = $list[array_key_last($list)];
        $final = (string) ($latest['final_status'] ?? '');
        if ($final === FirstFullCycleOrchestratorService::STATUS_CYCLE_CLOSED || $final === FirstFullCycleOrchestratorService::STATUS_EXECUTED_TO_GATE) {
            $items[] = self::item(
                kind: 'first_full_cycle_executed',
                bucket: 'insight',
                cycleState: 'executed',
                title: 'First full stewardship cycle recorded',
                summary: 'AP-768 cycle '.$final.' · '.(string) ($latest['cycle_id'] ?? ''),
                areaId: $areaId,
                portfolioId: $portfolioId,
                sourceAp: 'AP-768',
                dedupeKey: 'stewardship:ap768:cycle:'.(string) ($latest['cycle_id'] ?? ''),
                payload: ['cycle' => $latest],
            );
        }

        if (is_array($replayStages)) {
            foreach ((array) ($replayStages['stages'] ?? []) as $stageName => $stage) {
                if (! is_array($stage) || ($stage['status'] ?? '') !== FirstFullCycleOrchestratorService::STAGE_DEFERRED) {
                    continue;
                }
                $ap = (string) ($stage['ap_contract'] ?? '');
                if (! str_contains($ap, '767') && ! str_contains((string) $stageName, 'dev_forge')) {
                    continue;
                }
                $items[] = self::item(
                    kind: 'dev_forge_deferred',
                    bucket: 'insight',
                    cycleState: 'deferred',
                    title: 'Dev/Forge execution deferred by governance',
                    summary: (string) ($stage['detail'] ?? 'AP-767 stage deferred; operator must supply execution_result.'),
                    areaId: $areaId,
                    portfolioId: $portfolioId,
                    sourceAp: 'AP-767',
                    dedupeKey: 'stewardship:ap767:deferred:'.(string) ($latest['cycle_id'] ?? '').':'.$stageName,
                    payload: ['stage' => $stage],
                );
            }
        }

        return $items;
    }

    /**
     * @param  array<string,mixed>  $runtimeEvents
     * @return list<array<string,mixed>>
     */
    public static function itemsFromRuntimeEvents(string $areaId, string $portfolioId, array $runtimeEvents): array
    {
        $items = [];
        foreach (array_slice(array_reverse(array_values(array_filter((array) ($runtimeEvents['events'] ?? []), 'is_array'))), 0, 5) as $event) {
            $items[] = self::item(
                kind: 'runtime_result_evidence_emitted',
                bucket: 'insight',
                cycleState: 'executed',
                title: 'Runtime result visible in Product Mode',
                summary: 'Owner '.(string) ($event['owner'] ?? '').' · status '.(string) ($event['result_status'] ?? ''),
                areaId: $areaId,
                portfolioId: $portfolioId,
                sourceAp: 'AP-765',
                dedupeKey: 'stewardship:ap765:event:'.(string) ($event['event_id'] ?? ''),
                payload: ['event' => $event],
            );
        }

        return $items;
    }

    /**
     * @param  array<string,mixed>  $source
     * @return list<array<string,mixed>>
     */
    public static function itemsFromAutonomousEvolution(string $areaId, string $portfolioId, array $source): array
    {
        $items = [];
        $sessions = array_values(array_filter((array) ($source['sessions'] ?? []), 'is_array'));
        foreach (array_slice(array_reverse($sessions), 0, 3) as $session) {
            $sessionId = (string) ($session['session_id'] ?? '');
            $claim = is_array($session['claim_policy'] ?? null) ? $session['claim_policy'] : [];
            $directAllowed = (bool) ($claim['direct_provider_driver_allowed'] ?? false);
            $robustRequired = (bool) ($claim['requires_robust_obra_forge_quality_flow'] ?? true);

            foreach (array_values(array_filter((array) ($session['cycles'] ?? []), 'is_array')) as $cycle) {
                $item = self::autonomousCycleItem($areaId, $portfolioId, $sessionId, $cycle, $directAllowed, $robustRequired);
                if ($item !== null) {
                    $items[] = $item;
                }
            }
        }

        return $items;
    }

    /**
     * @param  array<string,mixed>  $cycle
     * @return array<string,mixed>|null
     */
    public static function autonomousCycleItem(
        string $areaId,
        string $portfolioId,
        string $sessionId,
        array $cycle,
        bool $directAllowed,
        bool $robustRequired,
    ): ?array {
        $finalStatus = (string) ($cycle['final_status'] ?? '');
        if ($finalStatus === 'dry_run_planned' || $finalStatus === '') {
            return null;
        }

        $finding = is_array($cycle['selected_finding'] ?? null) ? $cycle['selected_finding'] : [];
        $cycleId = (string) ($cycle['cycle_id'] ?? '');
        $title = (string) ($finding['title'] ?? '') ?: 'AP-786 autonomous cycle';
        $blockers = array_values(array_filter((array) ($cycle['blockers'] ?? []), 'is_string'));
        $gate = is_array($cycle['flow_integrity_gate'] ?? null) ? $cycle['flow_integrity_gate'] : [];
        $branchRef = (string) ($cycle['branch_ref'] ?? '');
        $sandboxId = (string) ($cycle['sandbox_id'] ?? '');
        $merged = (bool) ($cycle['merge_performed'] ?? false);

        $blockedByGate = (string) ($gate['blocked_reason'] ?? '') === 'full_atlas_forge_flow_required';
        $isBlockedFake = $finalStatus === 'blocked' && (in_array('full_atlas_forge_flow_required', $blockers, true) || $blockedByGate);
        $isBlocked = $finalStatus === 'blocked';
        $fullOwnerFlow = ! $isBlocked;

        if ($isBlockedFake) {
            $kind = 'autonomous_cycle_blocked_fake_flow';
            $bucket = 'alert';
            $cycleState = 'blocked';
            $itemTitle = 'AP-786 cycle blocked — full Atlas Forge owner-flow required';
            $summary = 'No provider execution allowed without the AP-747→AP-750 owner-flow as authority. This is the anti-fake gate, not a failure to hide.';
        } elseif ($isBlocked) {
            $kind = 'autonomous_cycle_blocked';
            $bucket = 'alert';
            $cycleState = 'blocked';
            $itemTitle = 'AP-786 cycle blocked: '.$title;
            $summary = $blockers === [] ? 'Cycle stopped before completion.' : 'Blockers: '.implode(', ', $blockers);
        } elseif ($merged) {
            $kind = 'autonomous_cycle_merged';
            $bucket = 'insight';
            $cycleState = 'executed';
            $itemTitle = 'AP-786 cycle merged: '.$title;
            $summary = 'Branch '.$branchRef.' ff-merged to main under AP-769/AP-774 governance.';
        } else {
            $kind = 'autonomous_cycle_review_required';
            $bucket = 'approval';
            $cycleState = 'executed';
            $itemTitle = 'AP-786 cycle ready for review: '.$title;
            $summary = 'Branch '.$branchRef.' is isolated and inbox was emitted before any merge attempt. Operator decision required.';
        }

        return self::item(
            kind: $kind,
            bucket: $bucket,
            cycleState: $cycleState,
            title: $itemTitle,
            summary: $summary,
            areaId: $areaId,
            portfolioId: $portfolioId,
            sourceAp: 'AP-786',
            dedupeKey: 'stewardship:ap786:cycle:'.$cycleId,
            payload: [
                'ap786_cycle' => [
                    'session_id' => $sessionId,
                    'cycle_id' => $cycleId,
                    'final_status' => $finalStatus,
                    'selected_finding' => [
                        'finding_id' => (string) ($finding['finding_id'] ?? ''),
                        'title' => $title,
                        'kind' => (string) ($finding['kind'] ?? ''),
                        'severity' => (string) ($finding['severity'] ?? ''),
                    ],
                    'why_this_matters' => (string) ($finding['why_it_matters'] ?? '') ?: 'Selected by the AP-785 priority engine for the largest real, robust advancement.',
                    'branch_ref' => $branchRef,
                    'sandbox_id' => $sandboxId,
                    'worktree_hash' => self::shortHash((string) ($cycle['worktree_path'] ?? '') ?: $cycleId),
                    'worktree_path' => (string) ($cycle['worktree_path'] ?? ''),
                    'owner_flow_stages' => self::ownerFlowStages($gate, $isBlocked),
                    'changed_files' => array_values(array_filter((array) ($cycle['changed_files'] ?? []), 'is_string')),
                    'tests_validation' => self::cycleValidation($cycle),
                    'evidence' => [
                        'result_bridge_id' => (string) ($cycle['result_bridge_id'] ?? ''),
                        'inbox_item_id' => $cycle['inbox_item_id'] ?? null,
                        'inbox_emitted_before_merge_attempt' => (bool) ($cycle['inbox_emitted_before_merge_attempt'] ?? false),
                    ],
                    'merge_governance_status' => (string) data_get($cycle, 'merge_governance.status', $isBlocked ? 'not_evaluated_blocked' : 'pending'),
                    'merged_to_main' => $merged,
                    'rollback_instruction' => self::rollbackInstruction($branchRef, $sandboxId, $merged, $isBlocked),
                    'next_operator_action' => self::cycleNextOperatorAction($finalStatus, $branchRef, $sandboxId, $isBlockedFake, $blockers),
                    'anti_fake_proof' => [
                        'direct_provider_driver_allowed' => $directAllowed,
                        'full_owner_flow' => $fullOwnerFlow,
                        'robust_contract' => $isBlocked ? false : $robustRequired,
                    ],
                    'blockers' => $blockers,
                ],
            ],
        );
    }

    /**
     * @param  array<string,mixed>  $gate
     * @return list<array<string,mixed>>
     */
    public static function ownerFlowStages(array $gate, bool $blocked): array
    {
        $chain = self::AP786_OWNER_FLOW_CHAIN;
        $required = array_values(array_filter((array) ($gate['required_chain'] ?? []), 'is_string'));

        $stages = [];
        foreach ($chain as $ap => $role) {
            $stages[] = [
                'ap' => $ap,
                'role' => $role,
                'status' => $blocked ? 'required_not_satisfied' : 'owned',
            ];
        }

        foreach ($required as $ap) {
            if (! array_key_exists($ap, $chain)) {
                $stages[] = ['ap' => $ap, 'role' => 'owner_flow_stage', 'status' => $blocked ? 'required_not_satisfied' : 'owned'];
            }
        }

        return $stages;
    }

    /**
     * @param  array<string,mixed>  $cycle
     * @return array<string,mixed>
     */
    public static function cycleValidation(array $cycle): array
    {
        $validation = is_array($cycle['validation'] ?? null) ? $cycle['validation'] : [];
        $passed = array_key_exists('passed', $validation)
            ? (bool) $validation['passed']
            : ((string) ($validation['status'] ?? '') === 'passed');
        $status = (string) ($validation['status'] ?? '');
        if ($status === '') {
            $status = $validation === [] ? 'not_run' : ($passed ? 'passed' : 'recorded');
        }
        $commands = (array) ($validation['commands'] ?? $cycle['validation_commands'] ?? []);

        return [
            'status' => $status,
            'passed' => $passed,
            'commands' => array_values(array_filter($commands, 'is_string')),
            'detail' => $validation,
        ];
    }

    public static function rollbackInstruction(string $branchRef, string $sandboxId, bool $merged, bool $blocked): string
    {
        if ($blocked) {
            return 'No repository changes were made (blocked before execution). Nothing to roll back.';
        }
        if ($merged) {
            return 'Revert the ff-merge on main: `git revert -m 1 <merge_head>`; then delete branch `'.$branchRef.'` (sandbox '.$sandboxId.').';
        }

        return 'Discard the isolated branch only (no main impact): `git branch -D '.$branchRef.'` (sandbox '.$sandboxId.').';
    }

    /**
     * @param  list<string>  $blockers
     */
    public static function cycleNextOperatorAction(
        string $finalStatus,
        string $branchRef,
        string $sandboxId,
        bool $isBlockedFake,
        array $blockers,
    ): string {
        if ($isBlockedFake) {
            return 'Do NOT use --allow-direct-provider-driver for real factory work. Wire the full AP-747→AP-750 owner-flow as execution authority, then re-run. This cycle made no autonomous-Forge claim.';
        }
        if ($finalStatus === 'blocked') {
            return 'Resolve blockers ('.(implode(', ', $blockers) ?: 'see cycle').') and re-run the AP-786 cycle.';
        }
        if ($finalStatus === 'cycle_completed') {
            return 'Review the merged commit on main; the next loop tick can run another AP-786 cycle.';
        }

        return 'Review branch `'.$branchRef.'` (sandbox '.$sandboxId.'); approve an AP-769/AP-774 ff-only merge or reject.';
    }

    public static function shortHash(string $value): string
    {
        return substr(hash('sha256', $value), 0, 12);
    }

    /**
     * @param  list<array<string,mixed>>  $items
     * @return array<string,int>
     */
    public static function counters(array $items): array
    {
        $counters = [
            'approvals' => 0,
            'recommendations' => 0,
            'insights' => 0,
            'alerts' => 0,
            'blocked' => 0,
            'executed' => 0,
            'deferred' => 0,
        ];

        foreach ($items as $item) {
            $bucket = (string) ($item['bucket'] ?? '');
            if ($bucket === 'approval') {
                $counters['approvals']++;
            } elseif ($bucket === 'recommendation') {
                $counters['recommendations']++;
            } elseif ($bucket === 'insight') {
                $counters['insights']++;
            } elseif ($bucket === 'alert') {
                $counters['alerts']++;
            }
            $state = (string) ($item['cycle_state'] ?? '');
            if ($state === 'blocked') {
                $counters['blocked']++;
            } elseif ($state === 'executed') {
                $counters['executed']++;
            } elseif ($state === 'deferred') {
                $counters['deferred']++;
            }
        }

        return $counters;
    }

    /**
     * @param  array<string,mixed>|null  $runner
     * @param  array<string,mixed>|null  $dayStarts
     * @param  array<string,mixed>|null  $firstCycles
     * @param  array<string,mixed>|null  $bridgeRecords
     * @param  array<string,mixed>|null  $receipts
     * @return list<array<string,mixed>>
     */
    public static function latestReceipts(
        ?array $runner,
        ?array $dayStarts,
        ?array $firstCycles,
        ?array $bridgeRecords,
        ?array $receipts,
    ): array {
        $out = [];

        if (is_array($runner['last_run'] ?? null)) {
            $out[] = ['source' => 'ap_766_runner', 'receipt' => $runner['last_run']];
        }

        $starts = array_values(array_filter((array) ($dayStarts['records'] ?? []), 'is_array'));
        if ($starts !== []) {
            $out[] = ['source' => 'ap_778_day_start', 'receipt' => $starts[array_key_last($starts)]];
            $last = $starts[array_key_last($starts)];
            if (($last['final_status'] ?? '') === ContinuousStewardshipDayStartService::STATUS_FIRST_TICK_EXECUTED) {
                $out[] = [
                    'source' => 'ap_778_first_tick',
                    'receipt' => [
                        'final_status' => ContinuousStewardshipDayStartService::STATUS_FIRST_TICK_EXECUTED,
                        'start_receipt_id' => (string) ($last['start_receipt_id'] ?? ''),
                        'recorded_at' => (string) ($last['recorded_at'] ?? ''),
                    ],
                ];
            }
        }

        $cycles = array_values(array_filter((array) ($firstCycles['cycles'] ?? []), 'is_array'));
        if ($cycles !== []) {
            $out[] = ['source' => 'ap_768_first_cycle', 'receipt' => $cycles[array_key_last($cycles)]];
        }

        $bridges = array_values(array_filter((array) ($bridgeRecords['records'] ?? []), 'is_array'));
        if ($bridges !== []) {
            $out[] = ['source' => 'ap_765_runtime_bridge', 'receipt' => $bridges[array_key_last($bridges)]];
        }

        foreach (array_slice(array_reverse(array_values(array_filter((array) ($receipts['receipts'] ?? []), 'is_array'))), 0, 3) as $receipt) {
            $out[] = ['source' => 'ap_755_control_receipt', 'receipt' => $receipt];
        }

        return array_slice($out, 0, 12);
    }

    /**
     * @param  array<string,mixed>|null  $readiness
     * @param  array<string,mixed>|null  $runner
     * @param  array<string,mixed>|null  $controls
     * @param  array<string,mixed>|null  $branchCert
     * @return list<string>
     */
    public static function collectBlockers(?array $readiness, ?array $runner, ?array $controls, ?array $branchCert): array
    {
        $blockers = [];
        foreach ([$readiness, $runner, $controls, $branchCert] as $section) {
            if (! is_array($section)) {
                continue;
            }
            foreach ((array) ($section['blockers'] ?? []) as $blocker) {
                if (is_string($blocker) && $blocker !== '') {
                    $blockers[] = $blocker;
                }
            }
        }

        return StewardshipStringListNormalizer::uniqueStrings($blockers);
    }

    /**
     * @param  array<string,mixed>|null  $readiness
     * @param  array<string,mixed>|null  $runner
     * @param  array<string,mixed>|null  $controls
     * @return list<string>
     */
    public static function collectNextActions(?array $readiness, ?array $runner, ?array $controls): array
    {
        $actions = [];
        foreach ([$readiness, $runner, $controls] as $section) {
            if (! is_array($section)) {
                continue;
            }
            foreach ((array) ($section['next_actions'] ?? []) as $action) {
                if (is_string($action) && $action !== '') {
                    $actions[] = $action;
                }
            }
        }

        return StewardshipStringListNormalizer::uniqueStrings($actions);
    }

    /**
     * @param  list<string>  $failedSources
     * @return array<string,mixed>
     */
    public static function emptyState(bool $degraded, array $failedSources): array
    {
        return [
            'honest' => true,
            'reason' => $degraded ? 'degraded_with_no_items' : 'no_stewardship_loop_activity_recorded',
            'message' => $degraded
                ? 'Sources failed before any inbox items could be projected; check degraded_state.failed_sources.'
                : 'No stewardship loop ticks, cycles, runtime results or control receipts are recorded yet. This is not a loading failure.',
            'failed_sources' => $failedSources,
            'loading' => false,
        ];
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    public static function item(
        string $kind,
        string $bucket,
        string $cycleState,
        string $title,
        string $summary,
        string $areaId,
        string $portfolioId,
        string $sourceAp,
        string $dedupeKey,
        array $payload = [],
    ): array {
        return [
            'schema_version' => self::ITEM_SCHEMA,
            'kind' => $kind,
            'bucket' => $bucket,
            'cycle_state' => $cycleState,
            'title' => $title,
            'summary' => $summary,
            'area_id' => $areaId,
            'portfolio_id' => $portfolioId,
            'source_ap_contract' => $sourceAp,
            'dedupe_key' => $dedupeKey,
            'review_required' => true,
            'inbox_status' => 'projected',
            'payload' => $payload,
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $items
     * @return list<array<string,mixed>>
     */
    public static function dedupeItems(array $items): array
    {
        $seen = [];
        $out = [];
        foreach ($items as $item) {
            $key = (string) ($item['dedupe_key'] ?? '');
            if ($key === '' || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = $item;
        }

        return $out;
    }

    /**
     * @return array<string,bool>
     */
    public static function claimPolicy(): array
    {
        return [
            'read_only' => true,
            'writes_repo' => false,
            'invokes_provider' => false,
            'invokes_dev' => false,
            'invokes_forge' => false,
            'installs_scheduler' => false,
            'starts_loop' => false,
            'fabricates_receipts' => false,
        ];
    }

    /**
     * Stable identity payload used for projection_hash (no timestamps).
     *
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    public static function hashIdentity(array $payload): array
    {
        $items = [];
        foreach ((array) ($payload['items'] ?? []) as $item) {
            if (! is_array($item)) {
                continue;
            }
            $items[] = [
                'dedupe_key' => (string) ($item['dedupe_key'] ?? ''),
                'kind' => (string) ($item['kind'] ?? ''),
                'cycle_state' => (string) ($item['cycle_state'] ?? ''),
            ];
        }

        return [
            'schema_version' => (string) ($payload['schema_version'] ?? ''),
            'status' => (string) ($payload['status'] ?? ''),
            'area_id' => (string) ($payload['area_id'] ?? ''),
            'portfolio_id' => (string) ($payload['portfolio_id'] ?? ''),
            'item_count' => (int) ($payload['item_count'] ?? 0),
            'counters' => (array) ($payload['counters'] ?? []),
            'items' => $items,
            'failed_sources' => (array) data_get($payload, 'degraded_state.failed_sources', []),
        ];
    }

    public static function slug(string $value, string $fallback = 'agentic_engineering_os'): string
    {
        $slug = strtolower(trim($value));
        $slug = preg_replace('/[^a-z0-9_\-]+/', '_', $slug) ?: 'default';

        return trim($slug, '_') ?: $fallback;
    }
}
