<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\Mission\MissionCanonicalHash;
use App\Services\Ai\NightShift\AtlasNightShiftAreaFocusContractRegistry;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Throwable;

/**
 * Area Focus Loop · Branch Sandbox Preflight + Governed Dev/Forge Handoff (AP-726).
 *
 * Atlas Software Company Stewardship Stack é stack/capability family dentro do
 * Atlas Autonomous Software Company Runtime, não OS novo.
 *
 * Advances the loop from read-only operational to a PREFLIGHT branch sandbox
 * plan + governed handoff packet. For each operator-accepted work order (AP-719
 * routed, AP-724 accept receipt) it prepares branch METADATA ONLY and a handoff
 * packet routed to Atlas Dev (small/local) or Forge (long-horizon).
 *
 * Hard boundary — it PREPARES, it never EXECUTES:
 *   - mode is always `preflight_dry_run`: branch metadata only;
 *   - NEVER creates a branch/worktree (`branch_created = false`), applies a fix,
 *     alters finding target code, dispatches Dev/Forge, merges, deploys, pushes
 *     externally or accesses secrets;
 *   - a handoff is `ready_for_handoff` ONLY with an explicit operator `accept`
 *     receipt; otherwise `awaiting_operator_approval`;
 *   - if the AP-723 safety gates return `block` (or cannot be verified), the
 *     whole preflight is blocked and no handoff/branch plan is prepared.
 *
 * Inputs are supplied via `$input` (decoupled from the upstream owners on
 * purpose), keeping the projection deterministic and side-effect free.
 */
class AreaFocusBranchSandboxHandoffService
{
    public const REPORT_SCHEMA = 'atlas.software_company_stewardship.area_focus_branch_sandbox_handoff.v1';

    public const BRANCH_PLAN_SCHEMA = 'atlas.software_company_stewardship.area_focus_branch_sandbox_plan.v1';

    public const HANDOFF_SCHEMA = 'atlas.software_company_stewardship.area_focus_handoff.v1';

    public const MODE = 'preflight_dry_run';

    public const STATUS_READY = 'ready';

    public const STATUS_PARTIAL = 'partial';

    public const STATUS_BLOCKED = 'blocked';

    public const DEFAULT_AREA_ID = 'agentic_engineering_os';

    // Handoff statuses.
    public const HO_READY = 'ready_for_handoff';

    public const HO_AWAITING = 'awaiting_operator_approval';

    public const HO_REJECTED = 'rejected_by_operator';

    public const HO_DEFERRED = 'deferred_by_operator';

    public const HO_CHANGES = 'changes_requested';

    public const HO_SDE = 'routed_to_self_directed_evolution';

    public const HO_OPERATOR_REVIEW = 'not_handoffable_operator_review';

    /** Routes that can be handed off to a Dev/Forge execution owner. */
    private const HANDOFFABLE_ROUTES = ['atlas_dev', 'forge'];

    /** route -> Dev/Forge target owner. */
    private const ROUTE_OWNER = [
        'atlas_dev' => 'atlas_dev',
        'forge' => 'forge',
    ];

    public function __construct(
        private readonly AtlasNightShiftAreaFocusContractRegistry $registry,
        private readonly AreaFocusGateEvaluatorService $gateEvaluator,
    ) {}

    /**
     * Preflight the branch sandbox + Dev/Forge handoff for a set of work orders.
     *
     * `$input`:
     *   - area_id:           string  default agentic_engineering_os
     *   - work_orders:       list    AP-719 work orders (or work_order_plan)
     *   - work_order_plan:   array   full AP-719 plan (work_orders extracted)
     *   - operator_receipts: list    AP-724 operator decision receipts
     *   - gate_report:       array   AP-723 gate report override (test seam)
     *   - contract:          array   area contract override (test seam)
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function preflight(array $input = []): array
    {
        $areaId = trim((string) ($input['area_id'] ?? self::DEFAULT_AREA_ID)) ?: self::DEFAULT_AREA_ID;

        $contract = is_array($input['contract'] ?? null) ? $input['contract'] : $this->registry->resolve($areaId);
        if ($contract === null) {
            return $this->blocked($areaId, 'area_not_registered', "Area '{$areaId}' is not registered; no handoff can be prepared.");
        }

        $workOrders = $this->resolveWorkOrders($input);
        if ($workOrders === []) {
            return $this->blocked($areaId, 'work_orders_required', 'preflight() requires AP-719 work_orders (or work_order_plan).');
        }

        // ---- AP-723 safety gates precondition ----
        $gate = $this->evaluateGates($areaId, $contract, $workOrders, $input);
        if (($gate['decision'] ?? 'block') === 'block') {
            return $this->blocked($areaId, 'safety_gate_blocked', 'AP-723 safety gates blocked the preflight; no handoff prepared.', [
                'gate_decision' => (string) ($gate['decision'] ?? 'block'),
                'blocking_gates' => $gate['blocking_gates'] ?? [],
            ]);
        }

        $receiptIndex = $this->indexReceipts(is_array($input['operator_receipts'] ?? null) ? $input['operator_receipts'] : []);

        $handoffs = [];
        foreach ($this->sortWorkOrders($workOrders) as $wo) {
            $handoffs[] = $this->buildHandoff($areaId, $contract, $wo, $receiptIndex);
        }

        $counts = $this->counts($handoffs);
        $status = $counts[self::HO_READY] > 0 && $counts[self::HO_AWAITING] === 0
            ? self::STATUS_READY
            : self::STATUS_PARTIAL;

        $payload = [
            'schema_version' => self::REPORT_SCHEMA,
            'status' => $status,
            'ap_contract' => 'AP-726',
            'mode' => self::MODE,
            'area_id' => $areaId,
            'stewardship_stack' => $this->stewardshipStack(),
            'gate_decision' => (string) ($gate['decision'] ?? 'unknown'),
            'handoff_count' => count($handoffs),
            'counts' => $counts,
            'handoffs' => $handoffs,
            'reused_owners' => $this->reusedOwners(),
            'claim_policy' => $this->claimPolicy(),
        ];
        $payload['report_hash'] = 'sha256:'.MissionCanonicalHash::sha256($this->identity($areaId, $gate, $handoffs));
        $payload['generated_at'] = $this->now();

        return $payload;
    }

    // ---------- handoff construction ----------

    /**
     * @param  array<string,mixed>  $contract
     * @param  array<string,mixed>  $wo
     * @param  array<string,array<string,mixed>>  $receiptIndex
     * @return array<string,mixed>
     */
    private function buildHandoff(string $areaId, array $contract, array $wo, array $receiptIndex): array
    {
        $route = (string) ($wo['route'] ?? 'operator_review');
        $sourceRef = (string) ($wo['source_ref'] ?? '');
        $workOrderId = (string) ($wo['work_order_id'] ?? '');

        $base = [
            'schema_version' => self::HANDOFF_SCHEMA,
            'area_id' => $areaId,
            'work_order_id' => $workOrderId,
            'source_ref' => $sourceRef,
            'route' => $route,
            'title' => (string) ($wo['title'] ?? ''),
            'risk_level' => (string) ($wo['risk_level'] ?? ($wo['severity'] ?? 'medium')),
            'evidence_refs' => array_values(array_filter((array) ($wo['evidence_refs'] ?? []), 'is_string')),
        ];

        // Non-Dev/Forge routes are never branch-sandbox handoffs.
        if (! in_array($route, self::HANDOFFABLE_ROUTES, true)) {
            return $this->finalizeHandoff($base + [
                'target_owner' => $route === 'self_directed_evolution' ? 'self_directed_evolution' : 'operator',
                'handoff_status' => $route === 'self_directed_evolution' ? self::HO_SDE : self::HO_OPERATOR_REVIEW,
                'operator_receipt' => null,
                'branch_plan' => null,
                'next_action' => $route === 'self_directed_evolution'
                    ? 'Route to Self-Directed Evolution for a proposal-only spec draft before any branch.'
                    : 'Operator review required before any work; not a Dev/Forge handoff.',
            ]);
        }

        $receipt = $this->matchReceipt($receiptIndex, $sourceRef, $workOrderId);
        $decision = $receipt !== null ? (string) ($receipt['decision'] ?? '') : null;

        $status = match ($decision) {
            'accept' => self::HO_READY,
            'reject' => self::HO_REJECTED,
            'defer' => self::HO_DEFERRED,
            'request_changes' => self::HO_CHANGES,
            default => self::HO_AWAITING,
        };

        // A branch sandbox plan is prepared ONLY for an operator-accepted handoff.
        $branchPlan = $status === self::HO_READY
            ? $this->branchPlan($areaId, $contract, $wo)
            : null;

        return $this->finalizeHandoff($base + [
            'target_owner' => self::ROUTE_OWNER[$route],
            'handoff_status' => $status,
            'operator_receipt' => $receipt !== null ? [
                'decision' => $decision,
                'decision_id' => (string) ($receipt['decision_id'] ?? ''),
                'decision_hash' => (string) ($receipt['decision_hash'] ?? ''),
                'operator_actor' => (string) ($receipt['operator_actor'] ?? ''),
            ] : null,
            'branch_plan' => $branchPlan,
            'next_action' => match ($status) {
                self::HO_READY => "Operator releases this handoff to {$route} under review; the real worktree is created in a future slice. Nothing executes here.",
                self::HO_AWAITING => 'Awaiting an explicit operator accept receipt (AP-724) before a branch plan is prepared.',
                self::HO_REJECTED => 'Operator rejected; close the work order with no action.',
                self::HO_DEFERRED => 'Operator deferred; re-review next Area Focus cycle.',
                self::HO_CHANGES => 'Operator requested changes; return to spec draft revision.',
                default => 'Operator review required.',
            },
        ]);
    }

    /**
     * Branch metadata ONLY — nothing is created on disk or in git.
     *
     * @param  array<string,mixed>  $contract
     * @param  array<string,mixed>  $wo
     * @return array<string,mixed>
     */
    private function branchPlan(string $areaId, array $contract, array $wo): array
    {
        $sourceRef = (string) ($wo['source_ref'] ?? '');
        $route = (string) ($wo['route'] ?? '');
        $short = substr(preg_replace('/[^a-f0-9]/', '', strtolower($sourceRef)) ?: 'unknown', 0, 12);
        $branchName = sprintf('area-focus/%s/%s/%s', $this->slug($areaId), $this->slug($route), $short);

        $repoScope = is_array($contract['repo_scope'] ?? null) ? $contract['repo_scope'] : [];

        return [
            'schema_version' => self::BRANCH_PLAN_SCHEMA,
            'mode' => self::MODE,
            'proposed_branch_name' => $branchName,
            'proposed_base_ref' => 'main',
            'proposed_worktree_path' => 'storage/atlas/software_company_stewardship/area_focus_worktrees/'.$short,
            'isolation' => 'worktree_isolated',
            'repo' => $repoScope['repos'][0] ?? 'atlas-server',
            'allowed_paths' => array_values((array) ($repoScope['allowed_paths'] ?? [])),
            'forbidden_paths' => array_values((array) ($repoScope['forbidden_paths'] ?? [])),
            'rollback_plan' => 'Discard the (not-yet-created) worktree; no commit, no merge, no push. The target repo is never mutated by this slice.',
            // Hard preflight guarantees.
            'branch_created' => false,
            'worktree_created' => false,
            'fix_applied' => false,
            'target_code_modified' => false,
        ];
    }

    // ---------- safety gates ----------

    /**
     * Evaluate the AP-723 safety gates as a precondition. A `gate_report`
     * override short-circuits for tests. If the evaluator is unavailable, the
     * preflight fails safe (treated as block).
     *
     * @param  array<string,mixed>  $contract
     * @param  list<array<string,mixed>>  $workOrders
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    private function evaluateGates(string $areaId, array $contract, array $workOrders, array $input): array
    {
        if (is_array($input['gate_report'] ?? null)) {
            return $input['gate_report'];
        }

        // A metadata-only preflight opens NO work: it consumes zero budget and
        // zero WIP (actual consumption is enforced on operator release, a future
        // slice, and already by AP-719). Feeding real work-order counts here would
        // be a false WIP/budget block.
        $run = [
            // We request NO mutation — these keep the hard gates green.
            'requested' => ['secrets' => false, 'merge' => false, 'deploy' => false, 'destructive_change' => false],
            'budget_used' => ['dev' => 0, 'forge' => 0],
            'wip_used' => 0,
            'kill_switch_engaged' => false,
            'owner_docs_present' => $this->ownerDocsPresent($contract),
            'evidence_pack' => ['present' => true, 'required' => true, 'complete' => true],
            'operator_inbox' => ['present' => true],
            'target_repos' => array_values((array) (($contract['repo_scope']['repos'] ?? ['atlas-server']))),
        ];

        try {
            $report = $this->gateEvaluator->evaluate(['area_id' => $areaId, 'area_contract' => $contract, 'run' => $run]);
            $blocking = [];
            foreach ((array) ($report['gates'] ?? []) as $g) {
                if (is_array($g) && (string) ($g['status'] ?? '') === 'block') {
                    $blocking[] = (string) ($g['id'] ?? $g['gate'] ?? 'unknown');
                }
            }

            return [
                'decision' => (string) ($report['decision'] ?? 'block'),
                'blocking_gates' => $blocking,
                'gate_report_hash' => (string) ($report['report_hash'] ?? ''),
            ];
        } catch (Throwable $e) {
            // Fail safe: if gates cannot be verified, do not prepare handoffs.
            return ['decision' => 'block', 'blocking_gates' => ['gate_evaluation_unavailable'], 'detail' => $e->getMessage()];
        }
    }

    // ---------- receipts ----------

    /**
     * @param  list<array<string,mixed>>  $receipts
     * @return array<string,list<array<string,mixed>>>
     */
    private function indexReceipts(array $receipts): array
    {
        $index = [];
        foreach ($receipts as $receipt) {
            if (! is_array($receipt)) {
                continue;
            }
            $findingHash = (string) ($receipt['finding_hash'] ?? '');
            if ($findingHash === '') {
                continue;
            }
            $index[$findingHash][] = $receipt;
        }

        return $index;
    }

    /**
     * @param  array<string,list<array<string,mixed>>>  $index
     * @return array<string,mixed>|null
     */
    private function matchReceipt(array $index, string $sourceRef, string $workOrderId): ?array
    {
        $candidates = $index[$sourceRef] ?? [];
        if ($candidates === []) {
            return null;
        }
        // Prefer a receipt whose work_order_id matches; else deterministic by decision_hash.
        usort($candidates, static function (array $a, array $b) use ($workOrderId): int {
            $aw = ((string) ($a['work_order_id'] ?? '') === $workOrderId) ? 0 : 1;
            $bw = ((string) ($b['work_order_id'] ?? '') === $workOrderId) ? 0 : 1;

            return [$aw, (string) ($a['decision_hash'] ?? '')] <=> [$bw, (string) ($b['decision_hash'] ?? '')];
        });

        return $candidates[0];
    }

    // ---------- helpers ----------

    /**
     * @param  array<string,mixed>  $input
     * @return list<array<string,mixed>>
     */
    private function resolveWorkOrders(array $input): array
    {
        $raw = $input['work_orders'] ?? null;
        if (! is_array($raw) && is_array($input['work_order_plan'] ?? null)) {
            $raw = $input['work_order_plan']['work_orders'] ?? null;
        }
        if (! is_array($raw)) {
            return [];
        }

        return array_values(array_filter($raw, 'is_array'));
    }

    /**
     * @param  array<string,mixed>  $handoff
     * @return array<string,mixed>
     */
    private function finalizeHandoff(array $handoff): array
    {
        $raw = hash('sha256', implode('|', [
            (string) ($handoff['area_id'] ?? ''),
            (string) ($handoff['work_order_id'] ?? ''),
            (string) ($handoff['source_ref'] ?? ''),
            (string) ($handoff['target_owner'] ?? ''),
            (string) ($handoff['handoff_status'] ?? ''),
        ]));
        $handoff['handoff_id'] = 'afho_'.substr($raw, 0, 16);
        $handoff['handoff_hash'] = 'sha256:'.$raw;
        $handoff['dispatched'] = false;
        $handoff['execution_performed'] = false;
        $handoff['requires_operator_review'] = true;

        return $handoff;
    }

    /**
     * @param  list<array<string,mixed>>  $handoffs
     * @return array<string,int>
     */
    private function counts(array $handoffs): array
    {
        $counts = [
            self::HO_READY => 0,
            self::HO_AWAITING => 0,
            self::HO_REJECTED => 0,
            self::HO_DEFERRED => 0,
            self::HO_CHANGES => 0,
            self::HO_SDE => 0,
            self::HO_OPERATOR_REVIEW => 0,
        ];
        foreach ($handoffs as $h) {
            $status = (string) ($h['handoff_status'] ?? '');
            $counts[$status] = ($counts[$status] ?? 0) + 1;
        }

        return $counts;
    }

    /**
     * @param  list<array<string,mixed>>  $workOrders
     * @return list<array<string,mixed>>
     */
    private function sortWorkOrders(array $workOrders): array
    {
        usort($workOrders, static fn (array $a, array $b): int => ((string) ($a['source_ref'] ?? '')) <=> ((string) ($b['source_ref'] ?? '')));

        return array_values($workOrders);
    }

    /**
     * @param  array<string,mixed>  $gate
     * @param  list<array<string,mixed>>  $handoffs
     * @return array<string,mixed>
     */
    private function identity(string $areaId, array $gate, array $handoffs): array
    {
        $hoIdentity = array_map(static fn (array $h): array => [
            'handoff_hash' => (string) ($h['handoff_hash'] ?? ''),
            'handoff_status' => (string) ($h['handoff_status'] ?? ''),
            'branch' => (string) ($h['branch_plan']['proposed_branch_name'] ?? ''),
        ], $handoffs);

        return [
            'schema_version' => self::REPORT_SCHEMA,
            'area_id' => $areaId,
            'gate_decision' => (string) ($gate['decision'] ?? ''),
            'handoffs' => $hoIdentity,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function stewardshipStack(): array
    {
        return [
            'umbrella' => 'Atlas Software Company Stewardship Stack',
            'level_name' => 'Area Focus Loop',
            'parent_runtime' => 'Atlas Autonomous Software Company Runtime',
            'canonical_statement' => 'Atlas Software Company Stewardship Stack é stack/capability family dentro do Atlas Autonomous Software Company Runtime, não OS novo.',
            'aps' => ['AP-712', 'AP-715', 'AP-719', 'AP-723', 'AP-724', 'AP-726'],
            'new_os_created' => false,
            'parallel_runtime_created' => false,
        ];
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    private function reusedOwners(): array
    {
        return [
            'work_order_router' => ['ap' => 'AP-719', 'owner_service' => AreaFocusDevForgeRouterService::class, 'role' => 'work orders to hand off (consumed)'],
            'operator_decision' => ['ap' => 'AP-724', 'owner_service' => AreaFocusOperatorDecisionService::class, 'role' => 'accept receipt gates ready_for_handoff'],
            'safety_gates' => ['ap' => 'AP-723', 'owner_service' => AreaFocusGateEvaluatorService::class, 'role' => 'block precondition'],
            'contract_registry' => ['ap' => 'AP-712', 'owner_service' => AtlasNightShiftAreaFocusContractRegistry::class, 'role' => 'branch scope (allowed/forbidden paths)'],
        ];
    }

    /**
     * @return array<string,bool|string>
     */
    private function claimPolicy(): array
    {
        return [
            'mode' => self::MODE,
            'read_only' => true,
            'preflight_only' => true,
            'branch_created' => false,
            'worktree_created' => false,
            'fix_applied' => false,
            'target_code_modified' => false,
            'work_dispatched' => false,
            'execution_performed' => false,
            'provider_invoked' => false,
            'merge_performed' => false,
            'deploy_performed' => false,
            'pushed_external' => false,
            'secret_access' => false,
            'destructive_change' => false,
            'writes_state' => false,
            'parallel_runtime_created' => false,
            'new_os_created' => false,
            'requires_operator_accept_receipt' => true,
            'operator_review_required' => true,
        ];
    }

    /**
     * @param  array<string,mixed>  $extra
     * @return array<string,mixed>
     */
    private function blocked(string $areaId, string $reason, string $detail, array $extra = []): array
    {
        $payload = [
            'schema_version' => self::REPORT_SCHEMA,
            'status' => self::STATUS_BLOCKED,
            'ap_contract' => 'AP-726',
            'mode' => self::MODE,
            'area_id' => $areaId,
            'reason' => $reason,
            'detail' => $detail,
            'stewardship_stack' => $this->stewardshipStack(),
            'handoff_count' => 0,
            'handoffs' => [],
            'reused_owners' => $this->reusedOwners(),
            'claim_policy' => $this->claimPolicy(),
        ] + $extra;
        $payload['report_hash'] = 'sha256:'.MissionCanonicalHash::sha256([
            'schema_version' => self::REPORT_SCHEMA,
            'area_id' => $areaId,
            'reason' => $reason,
            'extra' => $extra,
        ]);
        $payload['generated_at'] = $this->now();

        return $payload;
    }

    /**
     * Probe each contract owner doc for presence (read-only is_file), so the
     * AP-723 owner-docs gate can be satisfied without the caller supplying it.
     *
     * @param  array<string,mixed>  $contract
     * @return array<string,bool>
     */
    private function ownerDocsPresent(array $contract): array
    {
        $base = function_exists('base_path') ? base_path() : getcwd();
        $present = [];
        foreach ((array) ($contract['area_owner_docs'] ?? ($contract['owner_docs'] ?? [])) as $doc) {
            if (is_string($doc) && $doc !== '') {
                $present[$doc] = is_file(rtrim((string) $base, '/').'/'.ltrim($doc, '/'));
            }
        }

        return $present;
    }

    private function slug(string $value): string
    {
        $slug = preg_replace('/[^a-z0-9]+/', '-', strtolower($value)) ?? '';

        return trim($slug, '-') ?: 'unknown';
    }

    private function now(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DateTimeInterface::ATOM);
    }
}
