<?php

namespace App\Services\Ai\Decide;

use App\Models\AtlasProject;
use App\Services\Ai\Policy\AtlasAiPolicyService;
use App\Services\Ai\Programming\AtlasForgeProviderTopologyService;
use App\Services\Ai\Support\DatabaseTableAvailability;

/**
 * GOD-DEBULK D3: Forge Continuum provider-topology family relocated verbatim
 * from AtlasDecideService so the central Atlas Decide façade stays under 2000
 * LOC. Bodies are byte-identical to the pre-split service (only the three
 * façade-dispatched entries changed private->public); no routing logic changed.
 * The three leaf helpers (automaticModelSelectionMode, obraId, cleanString) are
 * verbatim copies kept local so this section is self-contained — the same
 * duplicated-leaf convention used by OpenBrainMcp/ReportTools under D3. No
 * scanner source-pin carries in this family (the model-selection pin tokens all
 * remain on the façade), so no pin relocation was needed here.
 */
class ForgeTopologySection
{
    use DecideProviderNormalization;

    private const COUNCIL_PROVIDER = 'claude_codex';

    public function __construct(
        private readonly AtlasAiPolicyService $policies,
    ) {}

    /**
     * @param  array<string,mixed>  $options
     * @param  array<string,mixed>  $policy
     * @param  array<string,mixed>  $plan
     */
    public function isForgeContinuumDecision(array $options, array $policy, array $plan): bool
    {
        $payload = is_array($options['payload'] ?? null) ? $options['payload'] : [];
        $surface = strtolower((string) (data_get($payload, 'surface_id') ?: data_get($payload, 'app_surface') ?: ($policy['surface'] ?? '')));
        $domain = strtolower((string) (($policy['domain'] ?? null) ?: data_get($policy, 'profile_context.domain') ?: data_get($payload, 'routing_domain') ?: data_get($plan, 'task_profile.routing_domain')));
        $flow = strtolower((string) (($policy['flow'] ?? null) ?: data_get($policy, 'profile_context.flow') ?: data_get($payload, 'programming_flow') ?: data_get($payload, 'routing_task') ?: data_get($plan, 'task_profile.route_mode')));
        $mode = strtolower((string) (data_get($payload, 'atlas_workflow_mode') ?: data_get($payload, 'mode') ?: ($options['mode'] ?? '')));

        return $surface === 'atlas_code'
            || $flow === 'programming.forge'
            || $flow === 'forge'
            || $mode === 'forge'
            || ($domain === 'programming' && (($plan['task_profile']['requires_code_execution'] ?? false) === true));
    }

    /**
     * @param  array<string,mixed>  $options
     * @param  array<string,mixed>  $policy
     * @param  array<string,mixed>  $plan
     * @param  array<string,mixed>  $receiptV2
     * @return array<string,mixed>
     */
    public function forgeProviderTopologyFromDecisionReceipt(
        array $options,
        array $policy,
        array $plan,
        array $receiptV2,
        string $selectedProvider,
        ?string $selectedModel,
        ?string $fallbackReason,
        ?string $manualProvider,
    ): array {
        $payload = is_array($options['payload'] ?? null) ? $options['payload'] : [];
        $obraId = $this->obraId($options, $payload);
        $receiptId = is_string($receiptV2['receipt_id'] ?? null) ? $receiptV2['receipt_id'] : null;
        $receiptHash = is_string($receiptV2['receipt_hash'] ?? null) ? $receiptV2['receipt_hash'] : null;
        $topologyId = 'topo_'.substr(hash('sha256', implode('|', array_filter([
            $receiptId,
            $receiptHash,
            $obraId,
            $selectedProvider,
            $selectedModel ?: 'selected-by-decide',
        ]))), 0, 26);
        $selectionMode = (string) data_get($receiptV2, 'provider_selection.selection_mode', $manualProvider !== null ? 'manual_override' : $this->automaticModelSelectionMode($policy));
        $qualityGates = array_values((array) data_get($plan, 'execution_graph.quality_gates', []));
        $runtimeDispatchAllowed = (bool) data_get($receiptV2, 'metadata.kernel_contracts.execution_allowed', false)
            && ! (bool) ($receiptV2['dry_run'] ?? false);
        $manualOverridePending = $manualProvider !== null;
        $fallbackChain = $this->forgeFallbackChainFromPolicy($policy, $selectedProvider, $selectedModel);
        $roles = $this->forgeRolesFromDecision(
            selectedProvider: $selectedProvider,
            selectedModel: $selectedModel,
            fallbackChain: $fallbackChain,
            fallbackReason: $fallbackReason,
            manualOverridePending: $manualOverridePending,
            qualityGates: $qualityGates,
        );

        return [
            'schema_version' => AtlasForgeProviderTopologyService::SCHEMA_VERSION,
            'status' => $runtimeDispatchAllowed ? 'available' : 'dispatch_receipt_required',
            'obra_id' => $obraId,
            'obra_present' => $obraId !== null,
            'fast_path_run_id' => $this->cleanString(data_get($payload, 'fast_path_run_id')),
            'decision_receipt_id' => $receiptId,
            'decision_receipt_hash' => $receiptHash,
            'receipt_schema_version' => is_string($receiptV2['schema_version'] ?? null) ? $receiptV2['schema_version'] : null,
            'provider_topology_id' => $topologyId,
            'generated_at' => now()->toIso8601String(),
            'strategy' => 'one_shot_enterprise_decide',
            'decision_source' => $manualOverridePending ? 'operator_override_pending' : 'live_atlas_decide',
            'roles' => $roles,
            'fallback_chain' => $fallbackChain,
            'provider_capacity' => $this->forgeProviderCapacityFromPolicy($policy),
            'blockers' => $runtimeDispatchAllowed ? [] : ['runtime_dispatch_receipt_required'],
            'last_fallback_event' => null,
            'fallback_child_receipt_required' => false,
            'runtime_dispatch_allowed' => $runtimeDispatchAllowed,
            'quality_gates' => $qualityGates,
            'budget_decision' => [
                'allowed' => (bool) data_get($receiptV2, 'budgets.budget_enabled', false) === false
                    || (bool) data_get($this->operationalDecisionBudgetPreview($policy, $selectedProvider), 'allowed', true),
                'source' => 'atlas_decide_receipt',
            ],
            'evidence_refs' => [
                'docs/engineering-knowledge-base/system-graph/atlas-decide.md',
                'docs/engineering-knowledge-base/system-graph/decision-receipt.md',
                'docs/engineering-knowledge-base/atlas-forge-provider-topology-and-fallback-v1.md',
            ],
            'next_action' => $runtimeDispatchAllowed ? 'dispatch_primary_builder_with_decision_receipt' : 'repair_decision_receipt_before_dispatch',
            'external_provider_call' => false,
            'is_read_model' => true,
            'note' => 'Provider Topology materializada a partir do Atlas Decide Decision Receipt; nenhum provider externo foi chamado.',
        ];
    }

    /**
     * @param  array<int,array<string,mixed>>  $fallbackChain
     * @param  array<int,string>  $qualityGates
     * @return array<int,array<string,mixed>>
     */
    private function forgeRolesFromDecision(
        string $selectedProvider,
        ?string $selectedModel,
        array $fallbackChain,
        ?string $fallbackReason,
        bool $manualOverridePending,
        array $qualityGates,
    ): array {
        $primaryStatus = $fallbackReason !== null ? 'fallback_selected' : 'selected';
        $qualityRole = $qualityGates !== [] ? 'quality_gated' : 'receipt_gated';

        return [
            [
                'role' => AtlasForgeProviderTopologyService::ROLE_PRIMARY_BUILDER,
                'provider' => $selectedProvider,
                'model' => $selectedModel ?: 'selected-by-decide',
                'status' => $manualOverridePending ? 'blocked' : $primaryStatus,
                'capability_reason' => $fallbackReason !== null
                    ? 'Atlas Decide selected provider after governed fallback: '.$fallbackReason
                    : 'Atlas Decide selected primary provider for programming.forge execution.',
                'risk_fit' => 'critical',
                'quality_role' => $qualityRole,
                'autonomy_level' => 'governed',
                'fallback_order' => 1,
                'requires_human_review' => true,
                'evidence_required' => true,
                'decision_source' => $manualOverridePending ? 'operator_override_pending' : 'live_atlas_decide',
            ],
            [
                'role' => AtlasForgeProviderTopologyService::ROLE_CRITICAL_REVIEWER,
                'provider' => $fallbackChain[0]['provider'] ?? 'codex_cli',
                'model' => $fallbackChain[0]['model'] ?? 'selected-by-decide',
                'status' => 'available',
                'capability_reason' => 'Critical reviewer role retained by Forge Continuum for review/tests/contracts.',
                'risk_fit' => 'high',
                'quality_role' => 'review',
                'autonomy_level' => 'review_only',
                'fallback_order' => 2,
                'requires_human_review' => true,
                'evidence_required' => true,
                'decision_source' => 'live_atlas_decide',
            ],
            [
                'role' => AtlasForgeProviderTopologyService::ROLE_CONTEXT_SCOUT,
                'provider' => $fallbackChain[1]['provider'] ?? 'gemini_cli',
                'model' => $fallbackChain[1]['model'] ?? 'selected-by-decide',
                'status' => 'available',
                'capability_reason' => 'Context scout provides long-context/doc/code-intelligence support.',
                'risk_fit' => 'medium',
                'quality_role' => 'context',
                'autonomy_level' => 'read_only',
                'fallback_order' => 3,
                'requires_human_review' => false,
                'evidence_required' => true,
                'decision_source' => 'live_atlas_decide',
            ],
            [
                'role' => AtlasForgeProviderTopologyService::ROLE_REPAIR_AGENT,
                'provider' => $fallbackChain[2]['provider'] ?? 'claude_cli',
                'model' => $fallbackChain[2]['model'] ?? 'selected-by-decide',
                'status' => 'available',
                'capability_reason' => 'Repair role handles failure packets and retest under governed scope.',
                'risk_fit' => 'medium',
                'quality_role' => 'repair',
                'autonomy_level' => 'governed',
                'fallback_order' => 4,
                'requires_human_review' => true,
                'evidence_required' => true,
                'decision_source' => 'live_atlas_decide',
            ],
            [
                'role' => AtlasForgeProviderTopologyService::ROLE_LOCAL_TOOL_RUNNER,
                'provider' => 'atlas-local',
                'model' => 'atlas-runtime',
                'status' => 'available',
                'capability_reason' => 'Local tests, lint, graph and evidence; never external provider dispatch.',
                'risk_fit' => 'low',
                'quality_role' => 'local_tools',
                'autonomy_level' => 'local_only',
                'fallback_order' => 5,
                'requires_human_review' => false,
                'evidence_required' => true,
                'decision_source' => 'live_atlas_decide',
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $policy
     * @return array<int,array<string,mixed>>
     */
    private function forgeFallbackChainFromPolicy(array $policy, string $selectedProvider, ?string $selectedModel): array
    {
        $fallbacks = array_values(array_filter((array) ($policy['fallback_order'] ?? []), fn (mixed $value): bool => is_string($value) && trim($value) !== ''));
        $fallbacks = array_values(array_unique(array_filter($fallbacks, fn (string $provider): bool => $provider !== $selectedProvider)));
        $fallbacks = $fallbacks !== [] ? $fallbacks : ['hermes_cli', 'minimax_m27_cli', 'codex_cli', 'gemini_cli', 'claude_cli'];
        $roles = [
            AtlasForgeProviderTopologyService::ROLE_CRITICAL_REVIEWER,
            AtlasForgeProviderTopologyService::ROLE_CONTEXT_SCOUT,
            AtlasForgeProviderTopologyService::ROLE_REPAIR_AGENT,
        ];

        return array_values(array_map(function (string $provider, int $idx) use ($roles, $selectedModel): array {
            return [
                'order' => $idx + 1,
                'role' => $roles[$idx] ?? AtlasForgeProviderTopologyService::ROLE_CRITICAL_REVIEWER,
                'provider' => $provider,
                'model' => $provider === 'atlas-local' ? 'atlas-runtime' : ($idx === 0 ? 'selected-by-decide' : ($selectedModel ?: 'selected-by-decide')),
                'capable' => true,
                'reason' => 'Atlas Decide fallback candidate preserved in Decision Receipt.',
            ];
        }, array_slice($fallbacks, 0, 3), array_keys(array_slice($fallbacks, 0, 3))));
    }

    /**
     * @param  array<string,mixed>  $policy
     * @return array<int,array<string,mixed>>
     */
    private function forgeProviderCapacityFromPolicy(array $policy): array
    {
        $providers = array_values(array_unique(array_merge(
            [(string) ($policy['default_provider'] ?? 'hermes_cli')],
            array_values(array_filter((array) ($policy['fallback_order'] ?? []), 'is_string')),
            ['atlas-local'],
        )));

        return array_map(fn (string $provider): array => [
            'provider' => $provider,
            'capacity_state' => $provider === 'atlas-local' ? 'available' : 'unknown',
            'quota_state' => $provider === 'atlas-local' ? 'not_applicable' : 'unknown',
            'rate_limit_state' => $provider === 'atlas-local' ? 'not_applicable' : 'unknown',
        ], $providers);
    }

    /**
     * @param  array<string,mixed>  $policy
     * @return array<string,mixed>
     */
    private function operationalDecisionBudgetPreview(array $policy, string $selectedProvider): array
    {
        return [
            'allowed' => $selectedProvider === self::COUNCIL_PROVIDER || $this->policies->budgetAllows($policy, $selectedProvider),
        ];
    }

    /**
     * @param  array<string,mixed>  $options
     * @param  array<string,mixed>  $topology
     * @param  array<string,mixed>  $receiptV2
     */
    public function persistForgeProviderTopologyOnObra(array $options, array $topology, array $receiptV2): void
    {
        $payload = is_array($options['payload'] ?? null) ? $options['payload'] : [];
        $obraId = $this->obraId($options, $payload);
        if ($obraId === null) {
            return;
        }

        if (! DatabaseTableAvailability::has('atlas_projects')) {
            return;
        }

        $project = AtlasProject::query()->whereKey($obraId)->first();
        if (! $project) {
            return;
        }

        $metadata = is_array($project->metadata) ? $project->metadata : [];
        $summary = [
            'schema_version' => 'atlas.forge.provider_topology_projection.v1',
            'decision_receipt_id' => $topology['decision_receipt_id'] ?? null,
            'decision_receipt_hash' => $topology['decision_receipt_hash'] ?? null,
            'provider_topology_id' => $topology['provider_topology_id'] ?? null,
            'decision_source' => $topology['decision_source'] ?? null,
            'selected_provider' => data_get($topology, 'roles.0.provider'),
            'selected_model' => data_get($topology, 'roles.0.model'),
            'fallback_chain' => $topology['fallback_chain'] ?? [],
            'last_fallback_event' => $topology['last_fallback_event'] ?? null,
            'runtime_dispatch_allowed' => $topology['runtime_dispatch_allowed'] ?? false,
            'recorded_at' => now()->toIso8601String(),
        ];
        $metadata['latest_atlas_forge_provider_topology'] = $topology;
        $metadata['latest_atlas_forge_decision_receipt'] = $receiptV2;
        $metadata['latest_atlas_forge_provider_topology_summary'] = $summary;
        $history = array_values((array) ($metadata['atlas_forge_provider_topology_history'] ?? []));
        array_unshift($history, $summary);
        $metadata['atlas_forge_provider_topology_history'] = array_slice($history, 0, 25);

        $project->forceFill(['metadata' => $metadata])->save();
    }
}
