<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\AtlasForge\AtlasForgeParallelDurableCoordinatorService;
use App\Services\Ai\Mission\MissionCanonicalHash;
use App\Services\Ai\Programming\AtlasDevRuntimeService;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use InvalidArgumentException;

/**
 * Area Focus Loop · Branch Sandbox Preflight + Governed Handoff (AP-726).
 *
 * Atlas Software Company Stewardship Stack é stack/capability family dentro do
 * Atlas Autonomous Software Company Runtime, não OS novo.
 *
 * Pure transformer. After an operator accept (AP-724) of an emitted work order
 * (AP-719) that passes the safety gates (AP-723), it prepares a
 * branch-metadata-only, dry-run sandbox plan plus a Dev/Forge handoff packet.
 *
 * Hard guarantees: it creates NO branch and NO worktree, touches NO target code,
 * and NEVER merges, deploys, pushes, accesses secrets, makes a destructive
 * change, invokes a provider, dispatches Dev/Forge or executes the work order.
 * Materializing the branch requires an explicit branch-creation receipt in a
 * future slice (`branch_creation_receipt_required = true`).
 */
class AreaFocusBranchSandboxPreflightService
{
    public const REPORT_SCHEMA = 'atlas.software_company_stewardship.area_focus_branch_sandbox_preflight.v1';

    public const HANDOFF_SCHEMA = 'atlas.software_company_stewardship.area_focus_handoff_packet.v1';

    public const MODE = 'dry_run_preflight';

    public const STATUS_READY = 'ready';

    public const STATUS_BLOCKED = 'blocked';

    public const BLOCK_DECISION_NOT_ACCEPT = 'decision_not_accept';

    public const BLOCK_DECISION_ALREADY_EXECUTED = 'decision_already_executed';

    public const BLOCK_WORK_ORDER_MISMATCH = 'decision_work_order_mismatch';

    public const BLOCK_ROUTE_NOT_EXECUTABLE = 'route_not_executable';

    public const BLOCK_WORK_ORDER_BLOCKED = 'work_order_blocked';

    public const BLOCK_SAFETY_GATE_BLOCKED = 'safety_gate_blocked';

    /** Routes that may receive a branch sandbox (executable lanes). */
    private const EXECUTABLE_ROUTES = [
        AreaFocusDevForgeRouterService::ROUTE_ATLAS_DEV,
        AreaFocusDevForgeRouterService::ROUTE_FORGE,
    ];

    public function __construct(
        private readonly AreaFocusGateEvaluatorService $gates,
    ) {}

    /**
     * Prepare a branch sandbox preflight + handoff plan from an operator accept.
     *
     * Required input:
     *   - operator_decision: an AP-724 receipt (decision=accept, executed=false)
     *   - work_order:        an AP-719 work order (route atlas_dev|forge, emitted)
     * Optional:
     *   - gate_report:   an AP-723 report override (else evaluated via the gate
     *                    evaluator using `area_contract` + a derived run)
     *   - area_contract: forwarded to the gate evaluator when no override
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function project(array $input): array
    {
        $decision = $this->requireArray($input, 'operator_decision');
        $workOrder = $this->requireArray($input, 'work_order');

        $areaId = (string) ($workOrder['area_id'] ?? $decision['area_id'] ?? 'agentic_engineering_os');
        $route = (string) ($workOrder['route'] ?? '');

        $gateReport = is_array($input['gate_report'] ?? null)
            ? $input['gate_report']
            : $this->gates->evaluate([
                'area_id' => $areaId,
                'area_contract' => is_array($input['area_contract'] ?? null) ? $input['area_contract'] : [],
                'run' => $this->deriveGateRun($workOrder),
            ]);

        $preconditions = $this->preconditions($decision, $workOrder, $gateReport, $route);
        $failed = array_values(array_filter($preconditions, static fn (array $c): bool => $c['status'] !== 'pass'));

        if ($failed !== []) {
            return $this->blocked($areaId, $route, $preconditions, $failed[0], $gateReport);
        }

        $branchPlan = $this->branchPlan($areaId, $route, $workOrder, $decision);
        $handoff = $this->handoffPacket($areaId, $route, $workOrder, $decision);

        $payload = [
            'schema_version' => self::REPORT_SCHEMA,
            'ap_contract' => 'AP-726',
            'status' => self::STATUS_READY,
            'mode' => self::MODE,
            'area_id' => $areaId,
            'stack_family' => 'atlas_software_company_stewardship_stack',
            'runtime_home' => 'atlas_autonomous_software_company_runtime',
            'stewardship_stack_note' => 'Atlas Software Company Stewardship Stack is a stack/capability family inside the Atlas Autonomous Software Company Runtime, not a new OS.',
            'source_refs' => [
                'decision_id' => (string) ($decision['decision_id'] ?? ''),
                'decision_hash' => (string) ($decision['decision_hash'] ?? ''),
                'work_order_id' => (string) ($workOrder['work_order_id'] ?? ''),
                'work_order_hash' => (string) ($workOrder['work_order_hash'] ?? ''),
                'finding_hash' => (string) ($decision['finding_hash'] ?? ''),
                'gate_report_decision' => (string) ($gateReport['decision'] ?? ''),
            ],
            'preconditions' => $preconditions,
            'branch_plan' => $branchPlan,
            'handoff_packet' => $handoff,
            'safety' => $this->safety($gateReport),
            'governance' => $this->governance(),
            'claim_policy' => $this->claimPolicy(),
            'next_actions' => $this->nextActions($route),
        ];
        $payload['preflight_hash'] = 'sha256:'.MissionCanonicalHash::sha256($payload);
        $payload['generated_at'] = (new DateTimeImmutable('now', new DateTimeZone('UTC')))
            ->format(DateTimeInterface::ATOM);

        return $payload;
    }

    // ---------- preconditions ----------

    /**
     * @param  array<string,mixed>  $decision
     * @param  array<string,mixed>  $workOrder
     * @param  array<string,mixed>  $gateReport
     * @return list<array<string,mixed>>
     */
    private function preconditions(array $decision, array $workOrder, array $gateReport, string $route): array
    {
        $decisionValue = strtolower((string) ($decision['decision'] ?? ''));
        $executed = (bool) ($decision['executed'] ?? false);
        $woId = (string) ($workOrder['work_order_id'] ?? '');
        $decisionWoId = (string) ($decision['work_order_id'] ?? '');
        $status = (string) ($workOrder['status'] ?? '');
        $gateDecision = (string) ($gateReport['decision'] ?? '');

        return [
            $this->check(
                self::BLOCK_DECISION_NOT_ACCEPT,
                $decisionValue === AreaFocusOperatorDecisionService::DECISION_ACCEPT,
                "operator decision is '{$decisionValue}'; only an accept prepares a branch sandbox.",
            ),
            $this->check(
                self::BLOCK_DECISION_ALREADY_EXECUTED,
                $executed === false,
                'operator decision must not be marked executed (a preflight precedes any execution).',
            ),
            $this->check(
                self::BLOCK_WORK_ORDER_MISMATCH,
                $decisionWoId === '' || $woId === '' || $decisionWoId === $woId,
                "decision work_order_id '{$decisionWoId}' must match work order '{$woId}'.",
            ),
            $this->check(
                self::BLOCK_ROUTE_NOT_EXECUTABLE,
                in_array($route, self::EXECUTABLE_ROUTES, true),
                "route '{$route}' is not an executable lane (only atlas_dev/forge get a branch sandbox).",
            ),
            $this->check(
                self::BLOCK_WORK_ORDER_BLOCKED,
                $status === AreaFocusDevForgeRouterService::WO_EMITTED,
                "work order status is '{$status}'; only an emitted work order can be handed off.",
            ),
            $this->check(
                self::BLOCK_SAFETY_GATE_BLOCKED,
                $gateDecision !== AreaFocusGateEvaluatorService::DECISION_BLOCK,
                "safety gate decision is '{$gateDecision}'; a block stops the handoff.",
            ),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function check(string $name, bool $passed, string $detail): array
    {
        return [
            'check' => $name,
            'status' => $passed ? 'pass' : 'fail',
            'detail' => $detail,
        ];
    }

    // ---------- plan builders ----------

    /**
     * @param  array<string,mixed>  $workOrder
     * @param  array<string,mixed>  $decision
     * @return array<string,mixed>
     */
    private function branchPlan(string $areaId, string $route, array $workOrder, array $decision): array
    {
        $shortHash = substr(hash('sha256', implode('|', [
            'area_focus_branch_sandbox',
            $areaId,
            (string) ($workOrder['work_order_hash'] ?? ''),
            (string) ($decision['decision_id'] ?? ''),
        ])), 0, 12);

        $areaSlug = $this->slug($areaId);
        $branchName = "atlas/area-focus/{$areaSlug}/{$route}/{$shortHash}";

        return [
            'branch_name' => $branchName,
            'base_ref_plan' => 'main',
            'base_ref_confirmed' => false,
            'worktree_path_plan' => ".atlas/worktrees/area-focus/{$areaSlug}/{$shortHash}",
            'naming_convention' => 'atlas/area-focus/{area}/{route}/{short_hash}',
            'branch_created' => false,
            'worktree_created' => false,
            'target_code_touched' => false,
            'branch_creation_receipt_required' => true,
            'isolation_policy' => [
                'sandbox_only' => true,
                'no_push' => true,
                'no_merge' => true,
                'no_deploy' => true,
                'no_secrets' => true,
                'no_destructive_change' => true,
                'no_force_operations' => true,
                'branch_prefix_locked' => 'atlas/area-focus/',
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $workOrder
     * @param  array<string,mixed>  $decision
     * @return array<string,mixed>
     */
    private function handoffPacket(string $areaId, string $route, array $workOrder, array $decision): array
    {
        return [
            'schema_version' => self::HANDOFF_SCHEMA,
            'area_id' => $areaId,
            'route' => $route,
            'target_owner' => $route === AreaFocusDevForgeRouterService::ROUTE_FORGE ? 'forge' : 'atlas_dev',
            'target_owner_service' => $route === AreaFocusDevForgeRouterService::ROUTE_FORGE
                ? AtlasForgeParallelDurableCoordinatorService::class
                : AtlasDevRuntimeService::class,
            'work_order_id' => (string) ($workOrder['work_order_id'] ?? ''),
            'work_order_hash' => (string) ($workOrder['work_order_hash'] ?? ''),
            'finding_hash' => (string) ($decision['finding_hash'] ?? ''),
            'decision_id' => (string) ($decision['decision_id'] ?? ''),
            'decision_hash' => (string) ($decision['decision_hash'] ?? ''),
            'title' => (string) ($workOrder['title'] ?? ''),
            'risk_level' => (string) ($workOrder['risk_level'] ?? $decision['risk_level'] ?? 'medium'),
            'evidence_refs' => array_values((array) ($workOrder['evidence_refs'] ?? [])),
            'recommended_action' => (string) ($workOrder['recommended_action'] ?? ''),
            'required_validations' => ['build', 'tests', 'docs-health', 'architecture-validate', 'evidence_pack'],
            'scope_constraints' => [
                'branch_metadata_only' => true,
                'target_code_untouched' => true,
                'operator_review_required_before_execution' => true,
            ],
            'dispatched' => false,
            'execution_performed' => false,
        ];
    }

    /**
     * @param  array<string,mixed>  $gateReport
     * @return array<string,mixed>
     */
    private function safety(array $gateReport): array
    {
        return [
            'gate_decision' => (string) ($gateReport['decision'] ?? ''),
            'blocked_when' => array_values((array) ($gateReport['blocked_when'] ?? [])),
            'warnings' => array_values((array) ($gateReport['warnings'] ?? [])),
            'gate_report_schema' => (string) ($gateReport['schema_version'] ?? ''),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function governance(): array
    {
        return [
            'mode' => 'max_governed',
            'enforced_autonomy_tier' => 'tier_3_branch_sandbox',
            'execution_enabled' => false,
            'no_merge_without_operator' => true,
            'no_deploy_without_operator' => true,
            'no_push' => true,
            'no_secrets' => true,
            'no_destructive_change' => true,
            'branch_isolation_required' => true,
            'kill_switch_required' => true,
            'evidence_pack_required' => true,
            'branch_creation_receipt_required' => true,
        ];
    }

    /**
     * @return array<string,bool|string>
     */
    private function claimPolicy(): array
    {
        return [
            'read_only_plan' => true,
            'branch_created' => false,
            'worktree_created' => false,
            'target_code_touched' => false,
            'merges' => false,
            'deploys' => false,
            'pushes_external' => false,
            'touches_secrets' => false,
            'destructive_change' => false,
            'provider_invoked' => false,
            'dispatched_to_dev_or_forge' => false,
            'execution_performed' => false,
            'auto_approved' => false,
            'parallel_runtime_created' => false,
            'new_os_created' => false,
            'mutates_target_repo' => false,
        ];
    }

    /**
     * @return list<string>
     */
    private function nextActions(string $route): array
    {
        $owner = $route === AreaFocusDevForgeRouterService::ROUTE_FORGE ? 'Forge' : 'Atlas Dev';

        return [
            'Operator reviews the branch plan and handoff packet.',
            "Issue an explicit branch-creation receipt to materialize the {$owner} sandbox (future slice).",
            'No branch, worktree or code change exists yet; this is a dry-run preflight.',
        ];
    }

    // ---------- blocked ----------

    /**
     * @param  list<array<string,mixed>>  $preconditions
     * @param  array<string,mixed>  $firstFail
     * @param  array<string,mixed>  $gateReport
     * @return array<string,mixed>
     */
    private function blocked(string $areaId, string $route, array $preconditions, array $firstFail, array $gateReport): array
    {
        $payload = [
            'schema_version' => self::REPORT_SCHEMA,
            'ap_contract' => 'AP-726',
            'status' => self::STATUS_BLOCKED,
            'mode' => self::MODE,
            'area_id' => $areaId,
            'reason' => (string) ($firstFail['check'] ?? 'precondition_failed'),
            'detail' => (string) ($firstFail['detail'] ?? ''),
            'preconditions' => $preconditions,
            'safety' => $this->safety($gateReport),
            'branch_plan' => null,
            'handoff_packet' => null,
            'claim_policy' => $this->claimPolicy(),
        ];
        $payload['preflight_hash'] = 'sha256:'.MissionCanonicalHash::sha256($payload);
        $payload['generated_at'] = (new DateTimeImmutable('now', new DateTimeZone('UTC')))
            ->format(DateTimeInterface::ATOM);

        return $payload;
    }

    // ---------- helpers ----------

    /**
     * @param  array<string,mixed>  $workOrder
     * @return array<string,mixed>
     */
    private function deriveGateRun(array $workOrder): array
    {
        // A branch-metadata-only handoff requests nothing unsafe.
        return [
            'requested_actions' => [],
            'kill_switch_engaged' => false,
            'evidence_pack' => ['present' => (array) ($workOrder['evidence_refs'] ?? []) !== []],
            'operator_inbox' => ['present' => true],
            'target' => 'atlas',
        ];
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    private function requireArray(array $input, string $key): array
    {
        if (! isset($input[$key]) || ! is_array($input[$key]) || $input[$key] === []) {
            throw new InvalidArgumentException("{$key} is required and must be a non-empty array.");
        }

        return $input[$key];
    }

    private function slug(string $value): string
    {
        $slug = strtolower(trim($value));
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?? $slug;

        return trim($slug, '-') ?: 'area';
    }
}
