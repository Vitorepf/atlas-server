<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming;

use App\Models\AtlasProject;
use App\Services\Ai\AtlasDecideService;
use Illuminate\Support\Str;

/**
 * Atlas Forge Runtime Dispatcher.
 *
 * Prepares a governed runtime dispatch plan for the Atlas Forge Continuum OS.
 *
 *   Atlas Code -> Obra -> Fast Path -> Atlas Decide -> Decision Receipt
 *   -> Provider Topology -> Governed Dispatcher (this service)
 *   -> Child Decision Receipt on fallback -> Execution Projection -> Cockpit UI
 *
 * Hard rules enforced:
 *   - `static_policy` topologies cannot run runtime dispatch.
 *   - dispatch requires `live_atlas_decide` Decision Receipt with id+hash.
 *   - `runtime_dispatch_allowed=true` is mandatory.
 *   - role must exist in topology with provider/model populated.
 *   - `provider_capacity_exhausted` is a terminal blocker.
 *   - provider failure rerouting requires a child Decision Receipt before
 *     dispatch can resume.
 *   - dispatch NEVER calls an external provider, NEVER spends tokens, NEVER
 *     promotes completion claim, NEVER bypasses review/completion gate.
 *
 * Schema: atlas.forge.runtime_dispatch_plan.v1
 * Projection schema: atlas.forge.runtime_dispatch_projection.v1
 * Doc: docs/engineering-knowledge-base/atlas-forge-provider-topology-and-fallback-v1.md
 */
class AtlasForgeRuntimeDispatchService
{
    public const SCHEMA_VERSION = 'atlas.forge.runtime_dispatch_plan.v1';
    public const PROJECTION_SCHEMA_VERSION = 'atlas.forge.runtime_dispatch_projection.v1';

    public const STATUS_DISPATCH_PLANNED = 'dispatch_planned';
    public const STATUS_BLOCKED = 'blocked';
    public const STATUS_FALLBACK_CHILD_RECEIPT_REQUIRED = 'fallback_child_receipt_required';
    public const STATUS_CAPACITY_EXHAUSTED = 'provider_capacity_exhausted';

    public const BLOCKER_OBRA_REQUIRED = 'obra_required';
    public const BLOCKER_OBRA_NOT_FOUND = 'obra_not_found';
    public const BLOCKER_TOPOLOGY_MISSING = 'provider_topology_missing';
    public const BLOCKER_LIVE_DECIDE_REQUIRED = 'live_decide_receipt_required';
    public const BLOCKER_DECISION_RECEIPT_REQUIRED = 'decision_receipt_required';
    public const BLOCKER_RUNTIME_DISPATCH_NOT_ALLOWED = 'runtime_dispatch_not_allowed';
    public const BLOCKER_ROLE_INVALID = 'role_invalid';
    public const BLOCKER_ROLE_MISSING_PROVIDER = 'role_missing_provider_or_model';
    public const BLOCKER_FALLBACK_CHILD_RECEIPT_REQUIRED = 'fallback_child_receipt_required';
    public const BLOCKER_CAPACITY_EXHAUSTED = 'provider_capacity_exhausted';

    public function __construct(
        private readonly AtlasForgeProviderTopologyService $topology,
        private readonly AtlasForgeProviderFallbackPolicyService $fallbackPolicy,
        private readonly AtlasDecideService $decide,
    ) {}

    /**
     * Prepare a runtime dispatch plan. NEVER calls an external provider.
     *
     * @param  array<string,mixed>  $options  obra_id, role, simulate_provider_failure, create_child_receipt, fast_path_run_id, strict
     * @return array<string,mixed>
     */
    public function dispatch(array $options = []): array
    {
        $obraId = $this->stringOrNull($options['obra_id'] ?? null);
        $requestedRole = $this->stringOrNull($options['role'] ?? null) ?? AtlasForgeProviderTopologyService::ROLE_PRIMARY_BUILDER;
        $simulateFailure = $this->stringOrNull($options['simulate_provider_failure'] ?? null);
        $createChildReceipt = (bool) ($options['create_child_receipt'] ?? false);
        $fastPathRunId = $this->stringOrNull($options['fast_path_run_id'] ?? null);
        $executionMode = $this->stringOrNull($options['execution_mode'] ?? null) ?? 'prepare_dispatch_plan';

        $dispatchId = $this->stringOrNull($options['dispatch_id'] ?? null) ?? 'dispatch_'.(string) Str::ulid();
        $generatedAt = now()->toIso8601String();
        $blockers = [];
        $childReceiptId = null;
        $childReceiptHash = null;
        $fallbackEventId = null;
        $fallbackFailureType = null;

        if ($obraId === null) {
            return $this->finalize(
                dispatchId: $dispatchId,
                generatedAt: $generatedAt,
                status: self::STATUS_BLOCKED,
                obraId: null,
                topology: null,
                role: $requestedRole,
                provider: null,
                model: null,
                fastPathRunId: $fastPathRunId,
                executionMode: $executionMode,
                blockers: [self::BLOCKER_OBRA_REQUIRED],
                fallbackEventId: null,
                fallbackFailureType: null,
                childReceiptId: null,
                childReceiptHash: null,
                project: null,
            );
        }

        $project = AtlasProject::query()->whereKey($obraId)->first();
        if ($project === null) {
            return $this->finalize(
                dispatchId: $dispatchId,
                generatedAt: $generatedAt,
                status: self::STATUS_BLOCKED,
                obraId: $obraId,
                topology: null,
                role: $requestedRole,
                provider: null,
                model: null,
                fastPathRunId: $fastPathRunId,
                executionMode: $executionMode,
                blockers: [self::BLOCKER_OBRA_NOT_FOUND],
                fallbackEventId: null,
                fallbackFailureType: null,
                childReceiptId: null,
                childReceiptHash: null,
                project: null,
            );
        }

        $topology = $this->topology->topology([
            'obra_id' => $obraId,
            'fast_path_run_id' => $fastPathRunId,
        ]);

        $decisionSource = $this->stringOrNull($topology['decision_source'] ?? null);
        $decisionReceiptId = $this->stringOrNull($topology['decision_receipt_id'] ?? null);
        $decisionReceiptHash = $this->stringOrNull($topology['decision_receipt_hash'] ?? null);
        $runtimeDispatchAllowed = (bool) ($topology['runtime_dispatch_allowed'] ?? false);
        $providerTopologyId = $this->stringOrNull($topology['provider_topology_id'] ?? null);

        // Honest checks before considering simulate_provider_failure.
        if ($decisionSource === null || $decisionSource === 'static_policy') {
            $blockers[] = self::BLOCKER_LIVE_DECIDE_REQUIRED;
        }
        if ($decisionReceiptId === null || $decisionReceiptHash === null) {
            $blockers[] = self::BLOCKER_DECISION_RECEIPT_REQUIRED;
        }

        foreach ((array) ($topology['blockers'] ?? []) as $existingBlocker) {
            $existingBlocker = (string) $existingBlocker;
            if ($existingBlocker !== '' && ! in_array($existingBlocker, $blockers, true)) {
                $blockers[] = $existingBlocker;
            }
        }

        $roleEntry = $this->findRole($topology, $requestedRole);
        if ($roleEntry === null) {
            $blockers[] = self::BLOCKER_ROLE_INVALID;
        } elseif (! is_string($roleEntry['provider'] ?? null) || ! is_string($roleEntry['model'] ?? null)) {
            $blockers[] = self::BLOCKER_ROLE_MISSING_PROVIDER;
        }

        if ($runtimeDispatchAllowed === false && ! in_array(self::BLOCKER_LIVE_DECIDE_REQUIRED, $blockers, true)
            && ! in_array(self::BLOCKER_DECISION_RECEIPT_REQUIRED, $blockers, true)) {
            $blockers[] = self::BLOCKER_RUNTIME_DISPATCH_NOT_ALLOWED;
        }

        // simulate_provider_failure path — governed fallback / child receipt.
        if ($simulateFailure !== null && $roleEntry !== null) {
            $classification = $this->fallbackPolicy->classify(
                failure: [
                    'type' => $simulateFailure,
                    'role' => $requestedRole,
                    'provider' => $roleEntry['provider'] ?? null,
                    'model' => $roleEntry['model'] ?? null,
                    'reason' => 'Simulated by atlas:forge:runtime-dispatch --simulate-provider-failure='.$simulateFailure,
                ],
                topology: $topology,
            );
            $event = $classification['event'] ?? [];
            $fallbackEventId = $this->stringOrNull($event['event_id'] ?? null);
            $fallbackFailureType = $this->stringOrNull($event['failure_type'] ?? null);

            $action = (string) ($classification['action'] ?? '');
            if ($action === AtlasForgeProviderFallbackPolicyService::ACTION_BLOCK) {
                $blocker = (string) ($classification['blocker'] ?? AtlasForgeProviderFallbackPolicyService::BLOCKER_CAPACITY_EXHAUSTED);
                if (! in_array($blocker, $blockers, true)) {
                    $blockers[] = $blocker;
                }
            } elseif ($action === AtlasForgeProviderFallbackPolicyService::ACTION_REROUTE) {
                // Reroute requires child Decision Receipt; without --create-child-receipt the dispatcher fails closed.
                if ($createChildReceipt && $decisionReceiptId !== null) {
                    $child = $this->createChildReceipt(
                        project: $project,
                        parentReceiptId: $decisionReceiptId,
                        parentReceiptHash: $decisionReceiptHash,
                        parentTopologyId: $providerTopologyId,
                        fallbackEvent: $event,
                        classification: $classification,
                    );
                    $childReceiptId = (string) ($child['receipt_id'] ?? '');
                    $childReceiptHash = (string) ($child['receipt_hash'] ?? '');
                    // After child receipt persists, the topology was rewritten; reload.
                    $topology = $this->topology->topology(['obra_id' => $obraId, 'fast_path_run_id' => $fastPathRunId]);
                    $runtimeDispatchAllowed = (bool) ($topology['runtime_dispatch_allowed'] ?? false);
                    $decisionReceiptId = $this->stringOrNull($topology['decision_receipt_id'] ?? null);
                    $decisionReceiptHash = $this->stringOrNull($topology['decision_receipt_hash'] ?? null);
                    $decisionSource = $this->stringOrNull($topology['decision_source'] ?? null);
                    $providerTopologyId = $this->stringOrNull($topology['provider_topology_id'] ?? null);

                    // Reroute may have repositioned the requested role; the dispatcher
                    // promotes the selected fallback as the new effective role.
                    $selectedFallback = $classification['selected_fallback'] ?? null;
                    if (is_array($selectedFallback)) {
                        $requestedRole = (string) ($selectedFallback['role'] ?? $requestedRole);
                        $roleEntry = $this->findRole($topology, $requestedRole) ?? $roleEntry;
                    }

                    // Remove the obsolete "fallback_child_receipt_required" blocker if present
                    // since the child receipt has been generated.
                    $blockers = array_values(array_filter(
                        $blockers,
                        static fn (string $b): bool => $b !== self::BLOCKER_FALLBACK_CHILD_RECEIPT_REQUIRED,
                    ));
                    if ($runtimeDispatchAllowed === false && ! in_array(self::BLOCKER_RUNTIME_DISPATCH_NOT_ALLOWED, $blockers, true)) {
                        $blockers[] = self::BLOCKER_RUNTIME_DISPATCH_NOT_ALLOWED;
                    }
                } else {
                    if (! in_array(self::BLOCKER_FALLBACK_CHILD_RECEIPT_REQUIRED, $blockers, true)) {
                        $blockers[] = self::BLOCKER_FALLBACK_CHILD_RECEIPT_REQUIRED;
                    }
                }
            }
        }

        if (in_array(AtlasForgeProviderFallbackPolicyService::BLOCKER_CAPACITY_EXHAUSTED, $blockers, true)) {
            $status = self::STATUS_CAPACITY_EXHAUSTED;
        } elseif (in_array(self::BLOCKER_FALLBACK_CHILD_RECEIPT_REQUIRED, $blockers, true) && count($blockers) === 1) {
            $status = self::STATUS_FALLBACK_CHILD_RECEIPT_REQUIRED;
        } elseif ($blockers !== []) {
            $status = self::STATUS_BLOCKED;
        } else {
            $status = self::STATUS_DISPATCH_PLANNED;
        }

        return $this->finalize(
            dispatchId: $dispatchId,
            generatedAt: $generatedAt,
            status: $status,
            obraId: $obraId,
            topology: $topology,
            role: $requestedRole,
            provider: is_array($roleEntry) ? ($roleEntry['provider'] ?? null) : null,
            model: is_array($roleEntry) ? ($roleEntry['model'] ?? null) : null,
            fastPathRunId: $fastPathRunId,
            executionMode: $executionMode,
            blockers: $blockers,
            fallbackEventId: $fallbackEventId,
            fallbackFailureType: $fallbackFailureType,
            childReceiptId: $childReceiptId,
            childReceiptHash: $childReceiptHash,
            project: $project,
        );
    }

    /**
     * Read latest dispatch plan persisted on the Obra (state projection).
     *
     * @return array<string,mixed>|null
     */
    public function latest(AtlasProject $project): ?array
    {
        $value = data_get($project->metadata, 'latest_atlas_forge_runtime_dispatch');

        return is_array($value) ? $value : null;
    }

    /**
     * @param  array<string,mixed>  $topology
     * @return array<string,mixed>|null
     */
    private function findRole(array $topology, string $role): ?array
    {
        foreach ((array) ($topology['roles'] ?? []) as $entry) {
            if (! is_array($entry)) {
                continue;
            }
            if (($entry['role'] ?? null) === $role) {
                return $entry;
            }
        }

        return null;
    }

    /**
     * @param  array<string,mixed>  $fallbackEvent
     * @param  array<string,mixed>  $classification
     * @return array<string,mixed>
     */
    private function createChildReceipt(
        AtlasProject $project,
        string $parentReceiptId,
        ?string $parentReceiptHash,
        ?string $parentTopologyId,
        array $fallbackEvent,
        array $classification,
    ): array {
        $selectedFallback = $classification['selected_fallback'] ?? [];
        $requestedProvider = is_array($selectedFallback)
            ? (string) ($selectedFallback['provider'] ?? 'claude_cli')
            : 'claude_cli';
        $requestedModel = is_array($selectedFallback)
            ? (string) ($selectedFallback['model'] ?? 'selected-by-decide')
            : 'selected-by-decide';
        $requestedRole = is_array($selectedFallback)
            ? (string) ($selectedFallback['role'] ?? AtlasForgeProviderTopologyService::ROLE_CRITICAL_REVIEWER)
            : AtlasForgeProviderTopologyService::ROLE_CRITICAL_REVIEWER;

        $decision = $this->decide->operationalDecision([
            'source_type' => 'app',
            'input_text' => 'Atlas Forge child Decision Receipt: provider fallback reroute.',
            'payload' => [
                'surface_id' => 'atlas_code',
                'app_surface' => 'atlas_code',
                'routing_domain' => 'programming',
                'programming_flow' => 'programming.forge',
                'atlas_workflow_mode' => 'forge',
                'obra_id' => (string) $project->getKey(),
                'parent_decision_receipt_id' => $parentReceiptId,
                'parent_decision_receipt_hash' => $parentReceiptHash,
                'parent_provider_topology_id' => $parentTopologyId,
                'fallback_event_id' => $fallbackEvent['event_id'] ?? null,
                'fallback_failure_type' => $fallbackEvent['failure_type'] ?? null,
                'fallback_action' => $fallbackEvent['action'] ?? null,
                'requested_role' => $requestedRole,
                'requested_provider' => $requestedProvider,
                'requested_model' => $requestedModel,
                'child_receipt_reason' => 'provider_fallback_reroute',
            ],
        ], $requestedProvider, $requestedModel)->toArray();

        $receiptV2 = is_array($decision['receipt_v2'] ?? null) ? $decision['receipt_v2'] : [];
        $childReceiptId = (string) ($receiptV2['receipt_id'] ?? '');
        $childReceiptHash = (string) ($receiptV2['receipt_hash'] ?? '');

        // Persist the child receipt history without erasing parent evidence.
        $project->refresh();
        $metadata = is_array($project->metadata) ? $project->metadata : [];
        $childSummary = [
            'schema_version' => 'atlas.forge.child_decision_receipt.v1',
            'child_decision_receipt_id' => $childReceiptId,
            'child_decision_receipt_hash' => $childReceiptHash,
            'parent_decision_receipt_id' => $parentReceiptId,
            'parent_decision_receipt_hash' => $parentReceiptHash,
            'parent_provider_topology_id' => $parentTopologyId,
            'fallback_event_id' => $fallbackEvent['event_id'] ?? null,
            'fallback_failure_type' => $fallbackEvent['failure_type'] ?? null,
            'requested_role' => $requestedRole,
            'requested_provider' => $requestedProvider,
            'requested_model' => $requestedModel,
            'reason' => 'provider_fallback_reroute',
            'recorded_at' => now()->toIso8601String(),
        ];
        $metadata['latest_atlas_forge_child_decision_receipt'] = $childSummary;
        $history = array_values((array) ($metadata['atlas_forge_child_decision_receipt_history'] ?? []));
        array_unshift($history, $childSummary);
        $metadata['atlas_forge_child_decision_receipt_history'] = array_slice($history, 0, 25);
        $project->forceFill(['metadata' => $metadata])->save();

        return [
            'receipt_id' => $childReceiptId,
            'receipt_hash' => $childReceiptHash,
            'receipt_v2' => $receiptV2,
        ];
    }

    /**
     * @param  array<string,mixed>|null  $topology
     * @param  array<int,string>  $blockers
     * @return array<string,mixed>
     */
    private function finalize(
        string $dispatchId,
        string $generatedAt,
        string $status,
        ?string $obraId,
        ?array $topology,
        string $role,
        ?string $provider,
        ?string $model,
        ?string $fastPathRunId,
        string $executionMode,
        array $blockers,
        ?string $fallbackEventId,
        ?string $fallbackFailureType,
        ?string $childReceiptId,
        ?string $childReceiptHash,
        ?AtlasProject $project,
    ): array {
        $blockers = array_values(array_unique($blockers));
        $decisionReceiptId = $topology !== null ? $this->stringOrNull($topology['decision_receipt_id'] ?? null) : null;
        $decisionReceiptHash = $topology !== null ? $this->stringOrNull($topology['decision_receipt_hash'] ?? null) : null;
        $decisionSource = $topology !== null ? $this->stringOrNull($topology['decision_source'] ?? null) : null;
        $providerTopologyId = $topology !== null ? $this->stringOrNull($topology['provider_topology_id'] ?? null) : null;
        $qualityGates = $topology !== null && is_array($topology['quality_gates'] ?? null)
            ? array_values((array) $topology['quality_gates'])
            : [];

        $nextAction = match ($status) {
            self::STATUS_DISPATCH_PLANNED => 'register_dispatch_or_request_provider_invocation_with_operator_approval',
            self::STATUS_FALLBACK_CHILD_RECEIPT_REQUIRED => 'rerun_with_create_child_receipt_to_generate_child_decision_receipt',
            self::STATUS_CAPACITY_EXHAUSTED => 'wait_for_provider_capacity_or_change_strategy',
            default => $this->nextActionForBlockers($blockers),
        };

        $plan = [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $status,
            'obra_id' => $obraId,
            'obra_present' => $project !== null,
            'dispatch_id' => $dispatchId,
            'fast_path_run_id' => $fastPathRunId,
            'decision_receipt_id' => $decisionReceiptId,
            'decision_receipt_hash' => $decisionReceiptHash,
            'child_decision_receipt_id' => $childReceiptId,
            'child_decision_receipt_hash' => $childReceiptHash,
            'provider_topology_id' => $providerTopologyId,
            'decision_source' => $decisionSource,
            'role' => $role,
            'provider' => $provider,
            'model' => $model,
            'runtime_dispatch_allowed' => $status === self::STATUS_DISPATCH_PLANNED,
            'execution_mode' => $executionMode,
            'fallback_event_id' => $fallbackEventId,
            'fallback_failure_type' => $fallbackFailureType,
            'external_provider_call' => false,
            'provider_invocation_planned' => false,
            'requires_provider_approval' => true,
            'quality_gates' => $qualityGates,
            'review_completion_gate_preserved' => true,
            'completion_claim_promoted' => false,
            'evidence_refs' => [
                'docs/engineering-knowledge-base/atlas-forge-continuum-os.md',
                'docs/engineering-knowledge-base/atlas-forge-provider-topology-and-fallback-v1.md',
                'docs/engineering-knowledge-base/system-graph/atlas-decide.md',
            ],
            'blockers' => $blockers,
            'next_action' => $nextAction,
            'generated_at' => $generatedAt,
            'recorded_at' => now()->toIso8601String(),
            'note' => 'Atlas Forge Runtime Dispatcher: nenhum provider externo foi chamado; dispatch precisa de Decision Receipt live + topologia governada; fallback exige child receipt.',
            'separated_from' => 'external_rivals_certification',
        ];

        if ($project !== null) {
            $this->persistDispatchProjection($project, $plan);
        }

        return $plan;
    }

    /**
     * @param  array<int,string>  $blockers
     */
    private function nextActionForBlockers(array $blockers): string
    {
        return match (true) {
            in_array(self::BLOCKER_OBRA_REQUIRED, $blockers, true) => 'bind_obra_to_atlas_code_forge',
            in_array(self::BLOCKER_OBRA_NOT_FOUND, $blockers, true) => 'select_existing_obra',
            in_array(self::BLOCKER_LIVE_DECIDE_REQUIRED, $blockers, true) => 'run_atlas_decide_for_forge_to_generate_live_decision_receipt',
            in_array(self::BLOCKER_DECISION_RECEIPT_REQUIRED, $blockers, true) => 'run_atlas_decide_for_forge_to_generate_live_decision_receipt',
            in_array(self::BLOCKER_RUNTIME_DISPATCH_NOT_ALLOWED, $blockers, true) => 'repair_decision_receipt_or_quality_gates_before_dispatch',
            in_array(self::BLOCKER_ROLE_INVALID, $blockers, true) => 'request_dispatch_for_canonical_role',
            in_array(self::BLOCKER_ROLE_MISSING_PROVIDER, $blockers, true) => 'wait_for_atlas_decide_to_populate_role',
            in_array(AtlasForgeProviderFallbackPolicyService::BLOCKER_CAPACITY_EXHAUSTED, $blockers, true) => 'wait_for_provider_capacity_or_change_strategy',
            default => 'resolve_remaining_blockers',
        };
    }

    /**
     * @param  array<string,mixed>  $plan
     */
    private function persistDispatchProjection(AtlasProject $project, array $plan): void
    {
        $project->refresh();
        $metadata = is_array($project->metadata) ? $project->metadata : [];
        $projection = [
            'schema_version' => self::PROJECTION_SCHEMA_VERSION,
            'dispatch_id' => $plan['dispatch_id'] ?? null,
            'status' => $plan['status'] ?? null,
            'role' => $plan['role'] ?? null,
            'provider' => $plan['provider'] ?? null,
            'model' => $plan['model'] ?? null,
            'decision_receipt_id' => $plan['decision_receipt_id'] ?? null,
            'decision_receipt_hash' => $plan['decision_receipt_hash'] ?? null,
            'child_decision_receipt_id' => $plan['child_decision_receipt_id'] ?? null,
            'child_decision_receipt_hash' => $plan['child_decision_receipt_hash'] ?? null,
            'fallback_event_id' => $plan['fallback_event_id'] ?? null,
            'fallback_failure_type' => $plan['fallback_failure_type'] ?? null,
            'runtime_dispatch_allowed' => $plan['runtime_dispatch_allowed'] ?? false,
            'external_provider_call' => false,
            'provider_invocation_planned' => false,
            'blockers' => $plan['blockers'] ?? [],
            'next_action' => $plan['next_action'] ?? null,
            'recorded_at' => $plan['recorded_at'] ?? now()->toIso8601String(),
        ];

        $metadata['latest_atlas_forge_runtime_dispatch'] = $plan;
        $history = array_values((array) ($metadata['atlas_forge_runtime_dispatch_history'] ?? []));
        array_unshift($history, $projection);
        $metadata['atlas_forge_runtime_dispatch_history'] = array_slice($history, 0, 25);

        $project->forceFill(['metadata' => $metadata])->save();
    }

    private function stringOrNull(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
