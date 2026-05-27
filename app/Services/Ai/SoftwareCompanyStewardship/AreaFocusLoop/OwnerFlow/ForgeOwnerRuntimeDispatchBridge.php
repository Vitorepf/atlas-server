<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\OwnerFlow;

/**
 * AP-787 · Forge Owner Runtime Dispatch Bridge.
 *
 * Builds the allowlisted Atlas Forge owner-runtime command that AP-786 runs
 * through AP-759 inside the AP-756 worktree, so `owner=forge` flows through the
 * real Atlas Forge/Obra path — never the direct provider driver router.
 *
 * Honesty rules (no false positives):
 *  - It requires a REAL, operator/loop-supplied governed Obra UUID. It never
 *    fabricates one; a missing/invalid Obra blocks with `forge_obra_required` /
 *    `forge_obra_invalid`.
 *  - Real Forge execution requires a live provider topology and a live Forge
 *    decision; missing ones block with `forge_live_topology_required` /
 *    `forge_live_decision_required` BEFORE any execution claim.
 *  - `atlas:forge:runtime-dispatch` only prepares a governed dispatch plan
 *    (`--execution-mode=prepare_dispatch_plan`, never calls a provider): it is
 *    returned with `plan_only=true`, so the caller must classify a successful run
 *    with no real changed files as PLANNED, never completed.
 *  - `atlas:forge:provider-invoke --mode=execute` is a provider command and
 *    additionally requires explicit provider-execution + budget authorization;
 *    missing ones block with `forge_provider_authorization_required` /
 *    `forge_budget_approval_required`.
 *
 * What counts as Forge execution vs not is documented in
 * docs/ap/AP-787-forge-owner-runtime-dispatch-bridge-contract.md.
 */
final class ForgeOwnerRuntimeDispatchBridge implements ForgeOwnerRuntimeDispatchPlanner
{
    public const CONTRACT_SCHEMA = 'atlas.software_company_stewardship.ap787_forge_owner_runtime_dispatch.v1';

    public const KIND_RUNTIME_DISPATCH = 'forge_runtime_dispatch';

    public const KIND_PARALLEL_DURABLE = 'forge_parallel_durable';

    public const KIND_PROVIDER_INVOKE = 'forge_provider_invoke';

    /** Plan-only dispatch kinds never count as a completed runtime result. */
    private const PLAN_ONLY_KINDS = [self::KIND_RUNTIME_DISPATCH];

    /** @var list<string> */
    private const CANONICAL_ROLES = ['primary_builder', 'critical_reviewer', 'context_scout', 'repair_agent', 'local_tool_runner'];

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function plan(array $input): array
    {
        $finding = is_array($input['finding'] ?? null) ? $input['finding'] : [];

        // 1. Real governed Obra — never fabricated.
        $obraId = trim((string) ($input['forge_obra'] ?? $input['obra_id'] ?? data_get($finding, 'forge_obra_id', '')));
        if ($obraId === '') {
            return $this->blocked('forge_obra_required', 'AP-787 requires a real governed Obra UUID (forge_obra/obra_id) for owner=forge. It does not fabricate an Obra; create or supply a governed Obra before dispatch.');
        }
        if (! $this->isValidObraId($obraId)) {
            return $this->blocked('forge_obra_invalid', 'forge_obra must be a valid Obra UUID; AP-787 refuses fake or malformed Obra identifiers.', ['obra_id' => $obraId]);
        }

        // 2. Live provider topology — runtime-dispatch is fail-closed without it.
        if (! $this->topologyLive($input)) {
            return $this->blocked('forge_live_topology_required', 'AP-787 requires a live Forge provider topology (forge_live_topology.status=live) before owner runtime dispatch.', ['obra_id' => $obraId]);
        }

        // 3. Live Forge decision — execution must be governed, not auto-granted.
        $decision = $this->liveDecision($input);
        if ($decision === []) {
            return $this->blocked('forge_live_decision_required', 'AP-787 requires a live Forge decision receipt (forge_live_decision with decision + operator_actor) before dispatching the Forge owner runtime.', ['obra_id' => $obraId]);
        }

        $role = $this->role($input);
        $mode = strtolower(trim((string) ($input['forge_dispatch_mode'] ?? self::KIND_RUNTIME_DISPATCH))) ?: self::KIND_RUNTIME_DISPATCH;

        return match ($mode) {
            self::KIND_PROVIDER_INVOKE => $this->providerInvoke($input, $obraId, $role, $decision),
            self::KIND_PARALLEL_DURABLE => $this->parallelDurable($input, $obraId, $role, $decision),
            default => $this->runtimeDispatch($obraId, $role, $decision),
        };
    }

    /**
     * Canonical, governed dispatch plan. Allowlisted in AP-759, not a provider
     * command, but plan-only: it prepares a dispatch plan and never executes a
     * provider, so the caller must report PLANNED, never completed.
     *
     * @param  array<string,mixed>  $decision
     * @return array<string,mixed>
     */
    private function runtimeDispatch(string $obraId, string $role, array $decision): array
    {
        return $this->ready(self::KIND_RUNTIME_DISPATCH, $obraId, $role, $decision, true, [
            PHP_BINARY, 'artisan', 'atlas:forge:runtime-dispatch', '--obra='.$obraId, '--role='.$role, '--json', '--strict',
        ], []);
    }

    /**
     * @param  array<string,mixed>  $input
     * @param  array<string,mixed>  $decision
     * @return array<string,mixed>
     */
    private function parallelDurable(array $input, string $obraId, string $role, array $decision): array
    {
        $tickets = $this->jsonArg($input['forge_tickets'] ?? null);
        $agents = $this->jsonArg($input['forge_agents'] ?? null);
        if ($tickets === '' || $agents === '') {
            return $this->blocked('forge_parallel_durable_inputs_required', 'atlas:forge:parallel-durable requires forge_tickets and forge_agents JSON inputs.', ['obra_id' => $obraId]);
        }

        return $this->ready(self::KIND_PARALLEL_DURABLE, $obraId, $role, $decision, false, [
            PHP_BINARY, 'artisan', 'atlas:forge:parallel-durable', '--tickets='.$tickets, '--agents='.$agents, '--json',
        ], []);
    }

    /**
     * Provider-backed execution. AP-759 treats this as a PROVIDER_COMMAND, so it
     * additionally requires explicit provider-execution and budget authorization.
     *
     * @param  array<string,mixed>  $input
     * @param  array<string,mixed>  $decision
     * @return array<string,mixed>
     */
    private function providerInvoke(array $input, string $obraId, string $role, array $decision): array
    {
        if ((bool) ($input['forge_provider_authorization'] ?? false) !== true) {
            return $this->blocked('forge_provider_authorization_required', 'atlas:forge:provider-invoke --mode=execute requires explicit forge_provider_authorization before AP-759 will run it.', ['obra_id' => $obraId]);
        }
        if ((bool) ($input['forge_budget_approved'] ?? false) !== true) {
            return $this->blocked('forge_budget_approval_required', 'atlas:forge:provider-invoke --mode=execute requires explicit forge_budget_approved before AP-759 will run it.', ['obra_id' => $obraId]);
        }

        return $this->ready(self::KIND_PROVIDER_INVOKE, $obraId, $role, $decision, false, [
            PHP_BINARY, 'artisan', 'atlas:forge:provider-invoke', '--obra='.$obraId, '--mode=execute',
            '--confirm-provider-call', '--confirm-budget', '--confirm-runtime-dispatch', '--json',
        ], [
            'provider_execution_authorized' => true,
            'budget_approved' => true,
        ]);
    }

    /**
     * @param  array<string,mixed>  $decision
     * @param  list<string>  $command
     * @param  array<string,mixed>  $receiptExtra
     * @return array<string,mixed>
     */
    private function ready(string $kind, string $obraId, string $role, array $decision, bool $planOnly, array $command, array $receiptExtra): array
    {
        return [
            'schema_version' => self::CONTRACT_SCHEMA,
            'ap_contract' => 'AP-787',
            'ok' => true,
            'dispatch_kind' => $kind,
            'obra_id' => $obraId,
            'role' => $role,
            'plan_only' => $planOnly || in_array($kind, self::PLAN_ONLY_KINDS, true),
            'command' => $command,
            'receipt_extra' => $receiptExtra,
            'forge_decision_id' => (string) ($decision['decision_id'] ?? $decision['id'] ?? ''),
            'forge_decision_actor' => (string) ($decision['operator_actor'] ?? $decision['actor'] ?? ''),
            'provider_router_used' => false,
            'requires_real_changed_files_for_completion' => true,
            'note' => $planOnly || in_array($kind, self::PLAN_ONLY_KINDS, true)
                ? 'runtime-dispatch prepares a governed plan only; a successful run with no real changed files is PLANNED, never completed.'
                : 'Execution-capable Forge command; completion still requires a real owner runtime result with allowed changed files.',
        ];
    }

    /**
     * @param  array<string,mixed>  $extra
     * @return array<string,mixed>
     */
    private function blocked(string $blocker, string $detail, array $extra = []): array
    {
        return [
            'schema_version' => self::CONTRACT_SCHEMA,
            'ap_contract' => 'AP-787',
            'ok' => false,
            'blocker' => $blocker,
            'detail' => $detail,
            'provider_router_used' => false,
        ] + $extra;
    }

    private function isValidObraId(string $obraId): bool
    {
        // Real Obra ids are UUIDs (optionally prefixed). Reject obvious fakes.
        if (preg_match('/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/i', $obraId) !== 1) {
            return false;
        }
        $lower = strtolower($obraId);

        return ! str_contains($lower, 'fake')
            && ! str_contains($lower, 'placeholder')
            && $lower !== '00000000-0000-0000-0000-000000000000';
    }

    /**
     * @param  array<string,mixed>  $input
     */
    private function topologyLive(array $input): bool
    {
        $topology = $input['forge_live_topology'] ?? null;
        if (is_array($topology)) {
            return (string) ($topology['status'] ?? '') === 'live'
                || (bool) ($topology['live'] ?? false) === true;
        }

        return false;
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    private function liveDecision(array $input): array
    {
        $decision = $input['forge_live_decision'] ?? null;
        if (! is_array($decision)) {
            return [];
        }
        $hasDecision = trim((string) ($decision['decision'] ?? '')) !== '';
        $hasActor = trim((string) ($decision['operator_actor'] ?? $decision['actor'] ?? '')) !== '';

        return ($hasDecision && $hasActor) ? $decision : [];
    }

    /**
     * @param  array<string,mixed>  $input
     */
    private function role(array $input): string
    {
        $role = strtolower(trim((string) ($input['forge_role'] ?? 'primary_builder')));

        return in_array($role, self::CANONICAL_ROLES, true) ? $role : 'primary_builder';
    }

    private function jsonArg(mixed $value): string
    {
        if (is_string($value) && trim($value) !== '') {
            return trim($value);
        }
        if (is_array($value) && $value !== []) {
            return (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        return '';
    }
}
