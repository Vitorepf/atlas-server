<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\AtlasForge\AtlasForgeParallelDurableCoordinatorService;
use App\Services\Ai\Mission\MissionCanonicalHash;

/**
 * Area Focus Loop · Forge Handoff Packet Builder (AP-729).
 *
 * Atlas Software Company Stewardship Stack é stack/capability family dentro do
 * Atlas Autonomous Software Company Runtime, não OS novo.
 *
 * Converts a long-horizon / cross-system Area Focus work order (route=forge,
 * AP-719) into a Forge/Obra handoff packet, gated by an AP-724 operator accept
 * receipt and an AP-720 evidence pack. The obra candidate targets the REAL Forge
 * runtime (AtlasForgeParallelDurableCoordinatorService,
 * `atlas.forge.parallel_durable.v1`); this builder reuses that contract and
 * creates NO parallel Forge.
 *
 * Hard guarantees: it NEVER executes Forge, NEVER spawns agents, NEVER creates a
 * branch/worktree, NEVER merges/deploys/pushes/accesses secrets and NEVER
 * dispatches. The packet is a declaration consumed by Forge under explicit
 * operator authority in a future slice.
 */
class AreaFocusForgeHandoffBuilderService
{
    public const HANDOFF_SCHEMA = 'atlas.software_company_stewardship.area_focus_forge_handoff.v1';

    public const OBRA_CANDIDATE_SCHEMA = 'atlas.software_company_stewardship.area_focus_forge_obra_candidate.v1';

    /** Real Forge runtime this packet targets (reused, never reimplemented). */
    public const FORGE_TARGET_RUNTIME = AtlasForgeParallelDurableCoordinatorService::SCHEMA_VERSION;

    public const STATUS_READY = 'ready_for_forge_handoff';

    public const STATUS_BLOCKED = 'blocked';

    public const ROUTE_FORGE = 'forge';

    public const BLOCK_WORK_ORDER_REQUIRED = 'work_order_required';

    public const BLOCK_ROUTE_NOT_FORGE = 'route_not_forge';

    public const BLOCK_MISSING_OPERATOR_ACCEPT = 'missing_operator_accept';

    public const BLOCK_MISSING_EVIDENCE = 'missing_evidence';

    public const BLOCK_MISSING_SCOPE = 'missing_scope';

    public const BLOCK_UNSAFE_REQUEST = 'unsafe_request';

    /** Actions that must never be requested through a governed handoff. */
    private const UNSAFE_ACTIONS = ['merge', 'deploy', 'push', 'secrets', 'secret', 'destructive', 'destructive_change', 'force_push'];

    /**
     * Build a Forge handoff packet from a route=forge work order.
     *
     * `$input`:
     *   - work_order:       array  AP-719 work order (route=forge)  [required]
     *   - operator_receipt: array  AP-724 receipt (decision=accept) [required]
     *   - evidence_pack:    array  AP-720 evidence pack             [required]
     *   - scope:            array|list  allowed paths / area scope  [required non-empty]
     *   - area_id:          string
     *   - dependency_graph: array  optional
     *   - requested_actions: list  optional (any unsafe action blocks)
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function build(array $input): array
    {
        $workOrder = is_array($input['work_order'] ?? null) ? $input['work_order'] : null;
        if ($workOrder === null) {
            return $this->blocked(self::BLOCK_WORK_ORDER_REQUIRED, 'A route=forge work order is required.', $input);
        }

        $route = (string) ($workOrder['route'] ?? '');
        if ($route !== self::ROUTE_FORGE) {
            return $this->blocked(self::BLOCK_ROUTE_NOT_FORGE, "Work order route is '{$route}'; Forge handoff requires route=forge.", $input);
        }

        // Unsafe-request gate first (a destructive request is never honored).
        if (($unsafe = $this->unsafeRequested($input, $workOrder)) !== null) {
            return $this->blocked(self::BLOCK_UNSAFE_REQUEST, "Unsafe action requested: {$unsafe}.", $input);
        }

        $receipt = is_array($input['operator_receipt'] ?? null) ? $input['operator_receipt'] : null;
        if (! $this->isValidAcceptReceipt($receipt, $workOrder)) {
            return $this->blocked(self::BLOCK_MISSING_OPERATOR_ACCEPT, 'A matching AP-724 operator accept receipt is required.', $input);
        }

        $evidencePack = is_array($input['evidence_pack'] ?? null) ? $input['evidence_pack'] : null;
        $evidenceRefs = $this->evidenceRefs($evidencePack, $workOrder, $receipt);
        if ($evidencePack === null || $evidenceRefs === []) {
            return $this->blocked(self::BLOCK_MISSING_EVIDENCE, 'An AP-720 evidence pack with at least one evidence ref is required.', $input);
        }

        $scope = $this->normalizeScope($input, $workOrder);
        if (($scope['allowed_paths'] ?? []) === []) {
            return $this->blocked(self::BLOCK_MISSING_SCOPE, 'A non-empty scope (allowed_paths) is required.', $input);
        }

        $areaId = (string) ($input['area_id'] ?? ($workOrder['area_id'] ?? 'agentic_engineering_os'));
        $objective = $this->objective($workOrder);
        $constraints = $this->constraints();
        $riskPolicy = $this->riskPolicy($workOrder);
        $expectedAgents = $this->expectedAgents($workOrder);
        $acceptanceGates = $this->acceptanceGates();
        $rollbackPlan = $this->rollbackPlan();
        $operatorDecisionRefs = [$this->operatorDecisionRef($receipt)];

        $obraCandidate = [
            'schema_version' => self::OBRA_CANDIDATE_SCHEMA,
            'obra_kind' => 'long_horizon_cross_system',
            'target_runtime' => self::FORGE_TARGET_RUNTIME,
            'target_coordinator' => AtlasForgeParallelDurableCoordinatorService::class,
            'title' => (string) ($workOrder['title'] ?? 'Area Focus Forge obra'),
            'objective' => $objective,
            'scope' => $scope,
            'constraints' => $constraints,
            'risk_policy' => $riskPolicy,
            'expected_agents' => $expectedAgents,
            'source_work_order_id' => (string) ($workOrder['work_order_id'] ?? ''),
            'source_work_order_hash' => (string) ($workOrder['work_order_hash'] ?? ''),
            'dependency_graph' => is_array($input['dependency_graph'] ?? null) ? $input['dependency_graph'] : null,
            'agents_spawned' => false,
            'forge_executed' => false,
        ];

        $handoffId = 'affh_'.substr(hash('sha256', implode('|', [
            'area_focus_forge_handoff',
            $areaId,
            (string) ($workOrder['work_order_hash'] ?? ''),
            (string) ($receipt['decision_id'] ?? ''),
        ])), 0, 16);

        $payload = [
            'schema_version' => self::HANDOFF_SCHEMA,
            'status' => self::STATUS_READY,
            'ap_contract' => 'AP-729',
            'handoff_id' => $handoffId,
            'area_id' => $areaId,
            'stewardship_stack' => $this->stewardshipStack(),
            'target_runtime' => self::FORGE_TARGET_RUNTIME,
            'obra_candidate' => $obraCandidate,
            'objective' => $objective,
            'scope' => $scope,
            'constraints' => $constraints,
            'risk_policy' => $riskPolicy,
            'expected_agents' => $expectedAgents,
            'evidence_refs' => $evidenceRefs,
            'acceptance_gates' => $acceptanceGates,
            'rollback_plan' => $rollbackPlan,
            'operator_decision_refs' => $operatorDecisionRefs,
            'reused_owners' => $this->reusedOwners(),
            'claim_policy' => $this->claimPolicy(),
        ];
        $payload['handoff_hash'] = 'sha256:'.MissionCanonicalHash::sha256($payload);
        $payload['generated_at'] = AreaFocusUtcClock::atomNow();

        return $payload;
    }

    // ---------- gates ----------

    /**
     * @param  array<string,mixed>|null  $receipt
     * @param  array<string,mixed>  $workOrder
     */
    private function isValidAcceptReceipt(?array $receipt, array $workOrder): bool
    {
        if ($receipt === null) {
            return false;
        }
        if ((string) ($receipt['decision'] ?? '') !== AreaFocusOperatorDecisionService::DECISION_ACCEPT) {
            return false;
        }
        // If the receipt names a work order, it must match this one.
        $receiptWoId = trim((string) ($receipt['work_order_id'] ?? ''));
        $woId = trim((string) ($workOrder['work_order_id'] ?? ''));
        if ($receiptWoId !== '' && $woId !== '' && $receiptWoId !== $woId) {
            return false;
        }

        return true;
    }

    /**
     * @param  array<string,mixed>  $input
     * @param  array<string,mixed>  $workOrder
     */
    private function unsafeRequested(array $input, array $workOrder): ?string
    {
        $requested = array_map(
            static fn ($a): string => strtolower(trim((string) $a)),
            is_array($input['requested_actions'] ?? null) ? $input['requested_actions'] : []
        );
        foreach ($requested as $action) {
            if (in_array($action, self::UNSAFE_ACTIONS, true)) {
                return $action;
            }
        }
        // Defensive: a work order or input that explicitly flags an unsafe op.
        foreach (['requires_merge', 'requires_deploy', 'requires_push', 'requires_secrets', 'destructive'] as $flag) {
            if (($input[$flag] ?? false) === true || ($workOrder[$flag] ?? false) === true) {
                return $flag;
            }
        }

        return null;
    }

    // ---------- packet sections ----------

    /**
     * @param  array<string,mixed>  $workOrder
     */
    private function objective(array $workOrder): string
    {
        $title = (string) ($workOrder['title'] ?? 'long-horizon area finding');
        $action = (string) ($workOrder['recommended_action'] ?? '');

        return $action !== ''
            ? "Resolve as a governed Forge obra: {$title}. {$action}"
            : "Resolve as a governed Forge obra: {$title}.";
    }

    /**
     * @param  array<string,mixed>  $input
     * @param  array<string,mixed>  $workOrder
     * @return array<string,mixed>
     */
    private function normalizeScope(array $input, array $workOrder): array
    {
        $paths = [];
        $raw = $input['scope'] ?? null;
        if (is_array($raw)) {
            $candidate = array_is_list($raw) ? $raw : ($raw['allowed_paths'] ?? $raw['paths'] ?? []);
            $paths = AreaFocusStringListNormalizer::trimmedUniqueStrings((array) $candidate);
        }

        // Fall back to work-order-declared paths if no explicit scope given.
        if ($paths === []) {
            $paths = AreaFocusStringListNormalizer::trimmedUniqueStrings((array) ($workOrder['affected_paths'] ?? []));
        }

        return [
            'allowed_paths' => $paths,
            'blast_radius' => (string) ($workOrder['blast_radius'] ?? 'unknown'),
            'write_scope_limited_to_allowed_paths' => true,
            'forbidden_paths' => ['.env', 'secrets/**', 'deploy/**'],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function constraints(): array
    {
        return [
            'mode' => 'max_governed',
            'no_merge_without_operator' => true,
            'no_deploy_without_operator' => true,
            'no_push_external' => true,
            'no_secrets_access' => true,
            'no_destructive_change' => true,
            'branch_isolation_required' => true,
            'operator_review_required' => true,
        ];
    }

    /**
     * @param  array<string,mixed>  $workOrder
     * @return array<string,mixed>
     */
    private function riskPolicy(array $workOrder): array
    {
        return [
            'risk_level' => (string) ($workOrder['risk_level'] ?? ($workOrder['severity'] ?? 'medium')),
            'requires_operator' => true,
            'high_risk_requires_operator' => true,
            'sensitive_classes_stay_local' => ['sensitive', 'secret', 'cyber'],
        ];
    }

    /**
     * Declared (NOT spawned) Forge agent roles for a long-horizon obra.
     *
     * @param  array<string,mixed>  $workOrder
     * @return list<array<string,mixed>>
     */
    private function expectedAgents(array $workOrder): array
    {
        return [
            ['role' => 'architect', 'count' => 1, 'spawned' => false],
            ['role' => 'implementer', 'count' => 1, 'spawned' => false],
            ['role' => 'reviewer', 'count' => 1, 'spawned' => false],
            ['role' => 'qa', 'count' => 1, 'spawned' => false],
        ];
    }

    /**
     * @return list<string>
     */
    private function acceptanceGates(): array
    {
        return [
            'operator_accept_receipt_present',
            'evidence_pack_complete',
            'branch_isolation_at_obra_creation',
            'tests_green_before_merge_proposal',
            'docs_health_green',
            'architecture_validate_green',
            'no_merge_without_operator',
            'no_deploy_without_operator',
        ];
    }

    private function rollbackPlan(): string
    {
        return 'The Forge obra runs in an isolated worktree created under a future slice; aborting discards the worktree with zero effect on the target repo. No canonical change is merged, deployed or pushed without explicit operator approval.';
    }

    /**
     * @param  array<string,mixed>|null  $evidencePack
     * @param  array<string,mixed>  $workOrder
     * @param  array<string,mixed>  $receipt
     * @return list<string>
     */
    private function evidenceRefs(?array $evidencePack, array $workOrder, array $receipt): array
    {
        $refs = [];
        if ($evidencePack !== null) {
            foreach (['pack_id', 'pack_hash', 'cycle_id'] as $k) {
                $v = (string) ($evidencePack[$k] ?? '');
                if ($v !== '') {
                    $refs[] = 'evidence_pack:'.$k.':'.$v;
                }
            }
        }
        foreach ((array) ($workOrder['evidence_refs'] ?? []) as $r) {
            if (is_string($r) && $r !== '') {
                $refs[] = 'work_order:'.$r;
            }
        }
        $decisionHash = (string) ($receipt['decision_hash'] ?? '');
        if ($decisionHash !== '') {
            $refs[] = 'operator_decision:'.$decisionHash;
        }

        return AreaFocusStringListNormalizer::uniqueStringValues($refs);
    }

    /**
     * @param  array<string,mixed>  $receipt
     * @return array<string,mixed>
     */
    private function operatorDecisionRef(array $receipt): array
    {
        return [
            'decision_id' => (string) ($receipt['decision_id'] ?? ''),
            'decision_hash' => (string) ($receipt['decision_hash'] ?? ''),
            'decision' => (string) ($receipt['decision'] ?? ''),
            'operator_actor' => (string) ($receipt['operator_actor'] ?? ''),
        ];
    }

    // ---------- metadata ----------

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    private function blocked(string $reason, string $detail, array $input): array
    {
        $payload = [
            'schema_version' => self::HANDOFF_SCHEMA,
            'status' => self::STATUS_BLOCKED,
            'ap_contract' => 'AP-729',
            'reason' => $reason,
            'detail' => $detail,
            'area_id' => (string) ($input['area_id'] ?? 'agentic_engineering_os'),
            'stewardship_stack' => $this->stewardshipStack(),
            'obra_candidate' => null,
            'reused_owners' => $this->reusedOwners(),
            'claim_policy' => $this->claimPolicy(),
        ];
        $payload['handoff_hash'] = 'sha256:'.MissionCanonicalHash::sha256($payload);
        $payload['generated_at'] = AreaFocusUtcClock::atomNow();

        return $payload;
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
            'aps' => ['AP-712', 'AP-715', 'AP-719', 'AP-720', 'AP-724', 'AP-729'],
            'forge_target' => self::FORGE_TARGET_RUNTIME,
            'parallel_forge_created' => false,
            'new_os_created' => false,
        ];
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    private function reusedOwners(): array
    {
        return [
            'work_order_router' => ['ap' => 'AP-719', 'contract' => 'atlas.software_company_stewardship.area_work_order.v1'],
            'operator_receipt' => ['ap' => 'AP-724', 'owner_service' => AreaFocusOperatorDecisionService::class],
            'evidence_pack' => ['ap' => 'AP-720', 'contract' => 'atlas.software_company_stewardship.area_focus_evidence_pack.v1'],
            'forge_runtime' => ['owner_service' => AtlasForgeParallelDurableCoordinatorService::class, 'contract' => self::FORGE_TARGET_RUNTIME],
        ];
    }

    /**
     * @return array<string,bool>
     */
    private function claimPolicy(): array
    {
        return [
            'read_only' => true,
            'forge_executed' => false,
            'agents_spawned' => false,
            'branch_created' => false,
            'dispatched' => false,
            'mutates_target_repo' => false,
            'merge_performed' => false,
            'deploy_performed' => false,
            'push_performed' => false,
            'secrets_accessed' => false,
            'destructive_change' => false,
            'provider_invoked' => false,
            'parallel_forge_created' => false,
            'new_os_created' => false,
            'operator_review_required' => true,
        ];
    }
}
