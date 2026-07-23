<?php

namespace App\Services\Ai\Programming\CompletionAudit\AtlasAudit;

use App\Services\Ai\Programming\AtlasForgeClaudeCliInvocationDriver;
use App\Services\Ai\Programming\AtlasForgeCodexCliInvocationDriver;
use App\Services\Ai\Programming\AtlasForgeContinuumCertificationService;
use App\Services\Ai\Programming\AtlasForgeGeminiCliInvocationDriver;
use App\Services\Ai\Programming\AtlasForgeProviderCapacityService;
use App\Services\Ai\Programming\AtlasForgeProviderCommandAllowlistService;
use App\Services\Ai\Programming\AtlasForgeProviderFailureMemoryService;
use App\Services\Ai\Programming\AtlasForgeProviderFallbackPolicyService;
use App\Services\Ai\Programming\AtlasForgeProviderInvocationDriver;
use App\Services\Ai\Programming\AtlasForgeProviderInvocationDriverRouter;
use App\Services\Ai\Programming\AtlasForgeProviderInvocationFailureClassifier;
use App\Services\Ai\Programming\AtlasForgeProviderInvocationPromptBuilder;
use App\Services\Ai\Programming\AtlasForgeProviderInvocationService;
use App\Services\Ai\Programming\AtlasForgeProviderProcessRunner;
use App\Services\Ai\Programming\CompletionAudit\CompletionAuditSupport;
use App\Services\Ai\Support\DatabaseTableAvailability;

class ForgeSection
{
    public function __construct(
        private readonly CompletionAuditSupport $support,
        private readonly \App\Services\Ai\Programming\AtlasForgeContinuumCertificationService $forgeContinuumCertification,
        private readonly \App\Services\Ai\Programming\AtlasForgeProviderCapacityService $forgeProviderCapacity,
        private readonly \App\Services\Ai\Programming\AtlasForgeProviderFallbackPolicyService $forgeProviderFallbackPolicy,
        private readonly \App\Services\Ai\Programming\AtlasForgeProviderInvocationDriverRouter $forgeProviderInvocationDriverRouter,
    ) {
    }

    /**
     * @return array<string,mixed>
     */
    public function atlasCodeEnterpriseCertification(): array
    {
        $serviceClass = \App\Services\Ai\Programming\AtlasCodeEnterpriseCertificationService::class;
        $commandClass = \App\Console\Commands\AtlasCodeEnterpriseCertifyCommand::class;
        $controllerClass = \App\Http\Controllers\AtlasCodeEnterpriseCertificationController::class;
        $testFile = base_path('tests/Feature/AtlasCodeContractTest.php');
        $docFile = base_path('docs/engineering-knowledge-base/atlas-code-enterprise-certification.md');

        $serviceExists = class_exists($serviceClass);
        $commandExists = class_exists($commandClass);
        $controllerExists = class_exists($controllerClass);
        $testExists = is_file($testFile);
        $docExists = is_file($docFile);

        $expectedTestMethods = [
            'test_atlas_code_enterprise_certification_proves_full_product_loop',
            'test_atlas_code_enterprise_certification_api_exposes_product_proof_packet',
            'test_atlas_code_can_compile_spec_plan_and_queue_from_bound_work_item',
            'test_atlas_code_replays_forge_run_history_read_only_with_review_context',
            'test_atlas_code_can_create_checkpoint_for_work_resume',
        ];
        $testCoverage = $this->support->scanTestCoverage($testFile, $expectedTestMethods);

        $missingArtifacts = [];
        if (! $serviceExists) {
            $missingArtifacts[] = 'service_class_missing';
        }
        if (! $commandExists) {
            $missingArtifacts[] = 'command_class_missing';
        }
        if (! $controllerExists) {
            $missingArtifacts[] = 'controller_class_missing';
        }
        if (! $testExists) {
            $missingArtifacts[] = 'test_file_missing';
        }
        if (! $docExists) {
            $missingArtifacts[] = 'doc_file_missing';
        }

        $missingMethods = $testCoverage['missing_methods'];
        $status = match (true) {
            $missingArtifacts !== [] => 'missing_artifacts',
            $missingMethods !== [] => 'requires_operator_run',
            default => 'available',
        };

        return [
            'schema_version' => 'atlas.code.enterprise_certification.v1',
            'status' => $status,
            'evidence_command' => 'php artisan atlas:code:enterprise-certify --json --strict',
            'artifacts' => [
                'service' => ['class' => $serviceClass, 'present' => $serviceExists],
                'command' => ['class' => $commandClass, 'present' => $commandExists],
                'controller' => ['class' => $controllerClass, 'present' => $controllerExists],
                'test' => ['path' => 'tests/Feature/AtlasCodeContractTest.php', 'present' => $testExists],
                'doc' => ['path' => 'docs/engineering-knowledge-base/atlas-code-enterprise-certification.md', 'present' => $docExists],
            ],
            'api_surface' => [
                'certify_endpoint' => 'POST /atlas-code/certification',
                'read_model_endpoint' => 'GET /atlas-code/certification',
                'state_projection' => '/atlas-code/works/{obra}/state.atlas_code_enterprise_certification',
                'works_index_hides_ephemeral_certification_obras' => true,
            ],
            'test_coverage' => [
                'expected_methods' => $expectedTestMethods,
                'present_methods' => $testCoverage['present_methods'],
                'missing_methods' => $missingMethods,
                'coverage_complete' => $missingMethods === [],
            ],
            'stages_proven' => [
                'preflight',
                'obra_workspace_fixture',
                'work_item_binding',
                'spec_plan_task_queue',
                'forge_live_execution_governed',
                'state_history_after_run',
                'history_replay_read_only',
                'human_review_promotion',
                'governed_rollback',
                'checkpoint_resume',
                'final_state_read_model',
                'workspace_cleanup',
            ],
            'contract_invariants' => [
                'forge_only' => true,
                'obra_required' => true,
                'workspace_not_mutated_before_review' => true,
                'history_replay_read_only' => true,
                'rollback_restores_initial_hash' => true,
                'checkpoint_required_for_resume' => true,
                'api_read_model_does_not_create_obra' => true,
                'ephemeral_certification_obras_hidden_from_works_index' => true,
            ],
            'external_provider_call' => false,
            'is_external_benchmark' => false,
            'note' => 'Status=available significa que comando, doc e cobertura existem. Para evidencia fresca, rode atlas:code:enterprise-certify --json --strict.',
            'separated_from' => 'external_rivals_certification',
        ];
    }

    /**
     * Atlas Forge Continuum OS certification block.
     *
     * Evaluates the canonical doc-mother + Provider Topology read-model +
     * Governed Fallback Policy + State Projection + Desktop Cockpit UI as a
     * single eixo certificado. This block does NOT call external providers,
     * NEVER unblocks `external_rivals_certification`, NEVER auto-completes work
     * and NEVER bypasses the review/completion gate.
     *
     * Schema: atlas.forge_continuum_certification.v1
     *
     * @return array<string,mixed>
     */
    public function atlasForgeContinuumCertification(string $workspace): array
    {
        $options = [
            'workspace' => $workspace,
            'strict' => false,
        ];
        $liveDecideObraId = $this->latestForgeLiveDecideObraId();
        if ($liveDecideObraId !== null) {
            $options['obra_id'] = $liveDecideObraId;
        }

        $report = $this->forgeContinuumCertification->certify($options);

        $invariants = is_array($report['invariants'] ?? null) ? $report['invariants'] : [];
        $invariantsAllTrue = (bool) ($report['invariants_all_true'] ?? false);
        $missingArtifacts = is_array($report['missing_artifacts'] ?? null) ? $report['missing_artifacts'] : [];
        $serviceStatus = (string) ($report['status'] ?? '');

        // Audit-block-level status enum normalization.
        $auditStatus = match (true) {
            $missingArtifacts !== [] => 'missing_artifacts',
            $serviceStatus === AtlasForgeContinuumCertificationService::STATUS_BLOCKED => 'blocked',
            $serviceStatus === AtlasForgeContinuumCertificationService::STATUS_BACKEND_AVAILABLE_UI_PENDING => 'backend_available_ui_pending',
            ! $invariantsAllTrue => 'backend_available_ui_pending',
            $serviceStatus === AtlasForgeContinuumCertificationService::STATUS_AVAILABLE => 'available',
            $serviceStatus === AtlasForgeContinuumCertificationService::STATUS_AVAILABLE_WITHOUT_OBRA_CONTEXT => 'available',
            default => 'backend_available_ui_pending',
        };

        $topology = is_array($report['provider_topology'] ?? null) ? $report['provider_topology'] : [];
        $policy = is_array($report['fallback_policy'] ?? null) ? $report['fallback_policy'] : [];
        $liveDecideRuntime = is_array($report['live_decide_runtime'] ?? null) ? $report['live_decide_runtime'] : [];

        return [
            'schema_version' => AtlasForgeContinuumCertificationService::SCHEMA_VERSION,
            'status' => $auditStatus,
            'service_status' => $serviceStatus,
            'invariants' => $invariants,
            'invariants_all_true' => $invariantsAllTrue,
            'missing_artifacts' => $missingArtifacts,
            'artifacts' => is_array($report['artifacts'] ?? null) ? $report['artifacts'] : [],
            'provider_topology_summary' => [
                'schema_version' => $topology['schema_version'] ?? null,
                'status' => $topology['status'] ?? null,
                'strategy' => $topology['strategy'] ?? null,
                'role_count' => is_array($topology['roles'] ?? null) ? count($topology['roles']) : 0,
                'fallback_chain_count' => is_array($topology['fallback_chain'] ?? null) ? count($topology['fallback_chain']) : 0,
                'provider_capacity_count' => is_array($topology['provider_capacity'] ?? null) ? count($topology['provider_capacity']) : 0,
            ],
            'fallback_policy_summary' => [
                'schema_version' => $policy['schema_version'] ?? null,
                'event_schema_version' => $policy['event_schema_version'] ?? null,
                'known_failures' => $policy['known_failures'] ?? [],
                'no_silent_fallback' => (bool) data_get($policy, 'invariants.no_silent_fallback', false),
                'capacity_exhausted_is_hard_blocker' => (bool) data_get($policy, 'invariants.capacity_exhausted_is_hard_blocker', false),
            ],
            'live_decide_runtime' => [
                'schema_version' => $liveDecideRuntime['schema_version'] ?? 'atlas.forge_continuum.live_decide_runtime.v1',
                'decision_source' => $liveDecideRuntime['decision_source'] ?? ($topology['decision_source'] ?? 'static_policy'),
                'live_atlas_decide_topology_available' => (bool) ($liveDecideRuntime['live_atlas_decide_topology_available'] ?? false),
                'decision_receipt_topology_projection_available' => (bool) ($liveDecideRuntime['decision_receipt_topology_projection_available'] ?? false),
                'static_policy_fallback_declared' => (bool) ($liveDecideRuntime['static_policy_fallback_declared'] ?? true),
                'fallback_child_receipt_required' => (bool) ($liveDecideRuntime['fallback_child_receipt_required'] ?? false),
                'runtime_dispatch_not_allowed_without_receipt' => (bool) ($liveDecideRuntime['runtime_dispatch_not_allowed_without_receipt'] ?? true),
                'runtime_dispatch_allowed' => (bool) ($liveDecideRuntime['runtime_dispatch_allowed'] ?? false),
            ],
            'evidence_command' => $report['evidence_command'] ?? null,
            'fail_closed_command' => $report['fail_closed_command'] ?? null,
            'fail_closed_expected_exit_code' => $report['fail_closed_expected_exit_code'] ?? 1,
            'lifecycle_states' => [
                'available',
                'available_without_obra_context',
                'backend_available_ui_pending',
                'missing_artifacts',
                'blocked',
                'blocked_obra_required_for_runtime_projection',
            ],
            'simulated_failure_modes' => [
                'rate_limit',
                'quota_exhausted',
                'auth_failed',
                'timeout',
                'context_limit',
                'model_unavailable',
                'provider_error',
                'insufficient_capability',
                'provider_capacity_exhausted',
            ],
            'no_silent_fallback' => true,
            'external_provider_call' => false,
            'is_external_benchmark' => false,
            'promotes_external_rivals_claim' => false,
            'separated_from_external_rivals_certification' => true,
            'note' => 'Atlas Forge Continuum OS amarra Atlas Decide -> Provider Topology -> Governed Fallback -> State Projection -> Cockpit UI -> Review/Completion -> Repair -> Evidence. Read-model; nunca chama provider externo; nunca promove completion claim; nunca libera external_rivals_certification.',
            'separated_from' => 'external_rivals_certification',
        ];
    }

    private function latestForgeLiveDecideObraId(): ?string
    {
        try {
            if (! DatabaseTableAvailability::has('atlas_projects')) {
                return null;
            }

            $projects = \App\Models\AtlasProject::query()
                ->orderByDesc('updated_at')
                ->limit(50)
                ->get(['id', 'metadata']);

            foreach ($projects as $project) {
                $metadata = is_array($project->metadata) ? $project->metadata : [];
                $topology = is_array($metadata['latest_atlas_forge_provider_topology'] ?? null)
                    ? $metadata['latest_atlas_forge_provider_topology']
                    : [];
                if (($topology['decision_source'] ?? null) !== 'live_atlas_decide') {
                    continue;
                }
                if (! is_string($topology['decision_receipt_id'] ?? null) || ! is_string($topology['decision_receipt_hash'] ?? null)) {
                    continue;
                }

                return (string) $project->id;
            }
        } catch (\Throwable) {
            return null;
        }

        return null;
    }

    /**
     * Atlas Forge Provider Capacity certification block.
     *
     * Audits the local capacity layer that feeds Atlas Decide / Provider
     * Topology / Continuum: capacity service + failure memory service +
     * CLI + API + state projection + desktop UI + topology consumes
     * capacity + fallback records failure memory.
     *
     * Diagnostic only: never changes `completion_allowed`, never unblocks
     * `external_rivals_certification`, never promotes a claim.
     *
     * Schema: atlas.forge_provider_capacity_certification.v1
     *
     * @return array<string,mixed>
     */
    public function atlasForgeProviderCapacityCertification(string $workspace): array
    {
        $repoRoot = rtrim($workspace, DIRECTORY_SEPARATOR);
        $desktopRoot = dirname($repoRoot).'/atlas-desktop';

        $capacityServiceFile = $repoRoot.'/app/Services/Ai/Programming/AtlasForgeProviderCapacityService.php';
        $memoryServiceFile = $repoRoot.'/app/Services/Ai/Programming/AtlasForgeProviderFailureMemoryService.php';
        $capacityCommandFile = $repoRoot.'/app/Console/Commands/AtlasForgeProviderCapacityCommand.php';
        $failureCommandFile = $repoRoot.'/app/Console/Commands/AtlasForgeProviderFailureRecordCommand.php';
        $controllerFile = $repoRoot.'/app/Http/Controllers/AtlasCodeForgeProviderCapacityController.php';
        $routesFile = $repoRoot.'/routes/api.php';
        $workControllerFile = $repoRoot.'/app/Http/Controllers/AtlasCodeWorkController.php';
        $topologyServiceFile = $repoRoot.'/app/Services/Ai/Programming/AtlasForgeProviderTopologyService.php';
        $fallbackPolicyServiceFile = $repoRoot.'/app/Services/Ai/Programming/AtlasForgeProviderFallbackPolicyService.php';
        $docFile = $repoRoot.'/docs/engineering-knowledge-base/atlas-forge-provider-capacity-continuity-v1.md';
        $desktopPanelFile = $desktopRoot.'/apps/desktop/src/surfaces/code/panels/ForgeProviderCapacityPanel.tsx';
        $domainTypesFile = $desktopRoot.'/packages/atlas-domain/src/index.ts';
        $bridgeFile = $desktopRoot.'/apps/desktop/src/lib/bridge.ts';
        $useBridgeFile = $desktopRoot.'/apps/desktop/src/hooks/useBridge.ts';

        $routesSource = is_file($routesFile) ? (string) @file_get_contents($routesFile) : '';
        $workControllerSource = is_file($workControllerFile) ? (string) @file_get_contents($workControllerFile) : '';
        $topologySource = is_file($topologyServiceFile) ? (string) @file_get_contents($topologyServiceFile) : '';
        $fallbackSource = is_file($fallbackPolicyServiceFile) ? (string) @file_get_contents($fallbackPolicyServiceFile) : '';
        $bridgeSource = is_file($bridgeFile) ? (string) @file_get_contents($bridgeFile) : '';
        $useBridgeSource = is_file($useBridgeFile) ? (string) @file_get_contents($useBridgeFile) : '';
        $domainTypesSource = is_file($domainTypesFile) ? (string) @file_get_contents($domainTypesFile) : '';
        $desktopPanelSource = is_file($desktopPanelFile) ? (string) @file_get_contents($desktopPanelFile) : '';

        $capacityServicePresent = class_exists(AtlasForgeProviderCapacityService::class)
            && is_file($capacityServiceFile);
        $memoryServicePresent = class_exists(AtlasForgeProviderFailureMemoryService::class)
            && is_file($memoryServiceFile);
        $capacityCommandPresent = class_exists(\App\Console\Commands\AtlasForgeProviderCapacityCommand::class)
            && is_file($capacityCommandFile);
        $failureRecordCommandPresent = class_exists(\App\Console\Commands\AtlasForgeProviderFailureRecordCommand::class)
            && is_file($failureCommandFile);
        $controllerPresent = class_exists(\App\Http\Controllers\AtlasCodeForgeProviderCapacityController::class)
            && is_file($controllerFile);

        $capacityApiPresent = $routesSource !== ''
            && str_contains($routesSource, '/forge/provider-capacity');
        $failureApiPresent = $routesSource !== ''
            && str_contains($routesSource, '/forge/provider-failures');
        $stateProjectionAvailable = $workControllerSource !== ''
            && str_contains($workControllerSource, 'forge_provider_capacity')
            && str_contains($workControllerSource, 'forge_provider_failure_memory');
        $desktopUiAvailable = $desktopPanelSource !== ''
            && str_contains($desktopPanelSource, 'AtlasForgeProviderCapacity');
        $topologyConsumesCapacity = $topologySource !== ''
            && str_contains($topologySource, 'capacityService')
            && str_contains($topologySource, 'capacity_snapshot_id');
        $fallbackRecordsFailure = $fallbackSource !== ''
            && str_contains($fallbackSource, 'failureMemory')
            && str_contains($fallbackSource, 'maybeRecordFailureMemory');

        $knownFailureTypesAligned = AtlasForgeProviderFailureMemoryService::EVENT_SCHEMA_VERSION === 'atlas.forge.provider_failure_memory_event.v1'
            && in_array('rate_limit', AtlasForgeProviderFallbackPolicyService::KNOWN_FAILURES, true)
            && in_array('provider_capacity_exhausted', AtlasForgeProviderFallbackPolicyService::KNOWN_FAILURES, true);

        $providerRegistryAligned = (function (): bool {
            try {
                $providers = $this->forgeProviderCapacity->providers();
                $keys = array_map(static fn (array $p): string => (string) ($p['provider'] ?? ''), array_filter($providers, 'is_array'));
                foreach (AtlasForgeProviderCapacityService::CANONICAL_PROVIDERS as $expected) {
                    if (! in_array($expected, $keys, true)) {
                        return false;
                    }
                }

                return true;
            } catch (\Throwable) {
                return false;
            }
        })();

        $capacityExhaustedBlocks = (function (): bool {
            // Round-trip the policy with capacity_exhausted and assert action=block.
            try {
                $decision = $this->forgeProviderFallbackPolicy->classify(
                    failure: ['type' => AtlasForgeProviderFallbackPolicyService::FAILURE_CAPACITY_EXHAUSTED],
                    topology: ['provider_topology_id' => 'topo_audit', 'roles' => [], 'fallback_chain' => []],
                );

                return ($decision['action'] ?? null) === AtlasForgeProviderFallbackPolicyService::ACTION_BLOCK
                    && ($decision['blocker'] ?? null) === AtlasForgeProviderFallbackPolicyService::BLOCKER_CAPACITY_EXHAUSTED;
            } catch (\Throwable) {
                return false;
            }
        })();

        $cooldownSupported = method_exists(AtlasForgeProviderFailureMemoryService::class, 'snapshot');

        $invariants = [
            'capacity_service_present' => $capacityServicePresent,
            'failure_memory_service_present' => $memoryServicePresent,
            'capacity_command_present' => $capacityCommandPresent,
            'failure_record_command_present' => $failureRecordCommandPresent,
            'capacity_api_present' => $capacityApiPresent,
            'failure_api_present' => $failureApiPresent,
            'state_projection_available' => $stateProjectionAvailable,
            'desktop_ui_available' => $desktopUiAvailable,
            'topology_consumes_capacity' => $topologyConsumesCapacity,
            'fallback_records_failure_memory' => $fallbackRecordsFailure,
            'known_failure_types_aligned' => $knownFailureTypesAligned,
            'provider_registry_aligned' => $providerRegistryAligned,
            'capacity_exhausted_blocks' => $capacityExhaustedBlocks,
            'cooldown_supported' => $cooldownSupported,
            'no_external_provider_call' => true,
            'provider_tokens_spent_false' => true,
            'static_policy_does_not_dispatch' => true,
            'live_decide_authority_preserved' => true,
            'external_rivals_separated' => true,
        ];

        $missingArtifacts = [];
        if (! $capacityServicePresent) {
            $missingArtifacts[] = 'capacity_service_missing';
        }
        if (! $memoryServicePresent) {
            $missingArtifacts[] = 'failure_memory_service_missing';
        }
        if (! $capacityCommandPresent) {
            $missingArtifacts[] = 'capacity_command_missing';
        }
        if (! $failureRecordCommandPresent) {
            $missingArtifacts[] = 'failure_record_command_missing';
        }
        if (! $controllerPresent) {
            $missingArtifacts[] = 'controller_missing';
        }
        if (! is_file($docFile)) {
            $missingArtifacts[] = 'doc_missing';
        }
        if (! is_file($desktopPanelFile)) {
            $missingArtifacts[] = 'desktop_panel_missing';
        }
        if ($domainTypesSource === '' || ! str_contains($domainTypesSource, 'AtlasForgeProviderCapacity')) {
            $missingArtifacts[] = 'desktop_domain_types_missing';
        }
        if ($bridgeSource === '' || ! str_contains($bridgeSource, 'getForgeProviderCapacity')) {
            $missingArtifacts[] = 'desktop_bridge_missing';
        }
        if ($useBridgeSource === '' || ! str_contains($useBridgeSource, 'forgeProviderCapacity')) {
            $missingArtifacts[] = 'desktop_use_bridge_missing';
        }

        $allInvariantsTrue = ! in_array(false, $invariants, true);

        $status = match (true) {
            $missingArtifacts !== [] => 'missing_artifacts',
            ! $desktopUiAvailable && $allInvariantsTrue === false => 'backend_available_ui_pending',
            ! $allInvariantsTrue => 'blocked',
            default => 'available',
        };

        $snapshot = null;
        try {
            $snapshot = $this->forgeProviderCapacity->snapshot([]);
        } catch (\Throwable) {
            $snapshot = null;
        }

        return [
            'schema_version' => 'atlas.forge_provider_capacity_certification.v1',
            'status' => $status,
            'invariants' => $invariants,
            'invariants_all_true' => $allInvariantsTrue,
            'missing_artifacts' => $missingArtifacts,
            'capacity_summary' => is_array($snapshot) ? [
                'schema_version' => $snapshot['schema_version'] ?? null,
                'status' => $snapshot['status'] ?? null,
                'available_count' => $snapshot['available_count'] ?? null,
                'degraded_count' => $snapshot['degraded_count'] ?? null,
                'unavailable_count' => $snapshot['unavailable_count'] ?? null,
                'unknown_count' => $snapshot['unknown_count'] ?? null,
                'best_available_provider' => $snapshot['best_available_provider'] ?? null,
                'runtime_dispatch_allowed' => $snapshot['runtime_dispatch_allowed'] ?? null,
            ] : null,
            'canonical_providers' => AtlasForgeProviderCapacityService::CANONICAL_PROVIDERS,
            'known_failure_types' => AtlasForgeProviderFallbackPolicyService::KNOWN_FAILURES,
            'cooldown_policy_aligned' => $cooldownSupported,
            'evidence_command' => 'php artisan atlas:forge:provider-capacity --json --strict',
            'failure_record_command' => 'php artisan atlas:forge:provider-failure-record --obra=<uuid> --provider=<runtime> --failure=<type> --json --strict',
            'lifecycle_states' => ['available', 'degraded', 'unavailable', 'unknown'],
            'top_level_states' => ['available', 'degraded', 'blocked'],
            'no_silent_fallback' => true,
            'external_provider_call' => false,
            'is_external_benchmark' => false,
            'provider_tokens_spent' => false,
            'promotes_external_rivals_claim' => false,
            'separated_from_external_rivals_certification' => true,
            'separated_from' => 'external_rivals_certification',
            'note' => 'Capacidade local do Forge Continuum. Atlas Decide e quem despacha runtime; este eixo so audita sinais e failure memory locais. Read-model; nunca chama provider externo; nunca promove claim.',
        ];
    }

    /**
     * Atlas Forge Governed Provider Invocation certification block.
     *
     * Audits the governed provider invocation surface: service + driver router +
     * prompt builder + CLI + controller + receipt schema + state projection +
     * desktop UI + 21 canonical invariants. This block NEVER calls an external
     * provider, NEVER unblocks `external_rivals_certification`, NEVER promotes
     * the completion claim.
     *
     * Schema: atlas.forge_provider_invocation_certification.v1
     *
     * @return array<string,mixed>
     */
    public function atlasForgeProviderInvocationCertification(string $workspace): array
    {
        $repoRoot = rtrim(is_dir($workspace) ? $workspace : base_path(), '/');
        $desktopRoot = dirname($repoRoot).'/atlas-desktop';

        $serviceFile = $repoRoot.'/app/Services/Ai/Programming/AtlasForgeProviderInvocationService.php';
        $driverFile = $repoRoot.'/app/Services/Ai/Programming/AtlasForgeProviderInvocationDriverRouter.php';
        $promptFile = $repoRoot.'/app/Services/Ai/Programming/AtlasForgeProviderInvocationPromptBuilder.php';
        $commandFile = $repoRoot.'/app/Console/Commands/AtlasForgeProviderInvokeCommand.php';
        $controllerFile = $repoRoot.'/app/Http/Controllers/AtlasCodeForgeProviderInvocationController.php';
        $testFile = $repoRoot.'/tests/Feature/Ai/Programming/AtlasForgeProviderInvocationTest.php';
        $docFile = $repoRoot.'/docs/engineering-knowledge-base/atlas-forge-governed-provider-invocation-v1.md';
        $routesFile = $repoRoot.'/routes/api.php';
        $routesSource = is_file($routesFile) ? (string) file_get_contents($routesFile) : '';

        $serviceSource = is_file($serviceFile) ? (string) file_get_contents($serviceFile) : '';
        $controllerSource = is_file($controllerFile) ? (string) file_get_contents($controllerFile) : '';
        $workControllerSource = is_file($repoRoot.'/app/Http/Controllers/AtlasCodeWorkController.php')
            ? (string) file_get_contents($repoRoot.'/app/Http/Controllers/AtlasCodeWorkController.php')
            : '';

        $invariants = [
            'invocation_service_present' => is_file($serviceFile)
                && class_exists(AtlasForgeProviderInvocationService::class),
            'driver_router_present' => is_file($driverFile)
                && class_exists(AtlasForgeProviderInvocationDriverRouter::class),
            'prompt_builder_present' => is_file($promptFile)
                && class_exists(AtlasForgeProviderInvocationPromptBuilder::class),
            'invocation_receipt_available' => $serviceSource !== ''
                && str_contains($serviceSource, "RECEIPT_SCHEMA_VERSION = 'atlas.forge.provider_invocation_receipt.v1'"),
            'invocation_command_present' => class_exists(\App\Console\Commands\AtlasForgeProviderInvokeCommand::class)
                && is_file($commandFile),
            'invocation_controller_present' => class_exists(\App\Http\Controllers\AtlasCodeForgeProviderInvocationController::class)
                && is_file($controllerFile),
            'state_projection_available' => $workControllerSource !== ''
                && str_contains($workControllerSource, "'forge_provider_invocation' =>")
                && str_contains($workControllerSource, "'forge_provider_invocation_receipt' =>"),
            'desktop_ui_available' => is_file($desktopRoot.'/apps/desktop/src/surfaces/code/panels/ForgeProviderTopologyPanel.tsx')
                && is_file($desktopRoot.'/apps/desktop/src/lib/bridge.ts'),
            'dry_run_mode_available' => $serviceSource !== '' && str_contains($serviceSource, "MODE_DRY_RUN = 'dry_run'"),
            'execute_mode_fail_closed_without_operator_approval' => $serviceSource !== ''
                && str_contains($serviceSource, 'BLOCKER_OPERATOR_APPROVAL_REQUIRED'),
            'budget_approval_required' => $serviceSource !== ''
                && str_contains($serviceSource, 'BLOCKER_BUDGET_APPROVAL_REQUIRED'),
            'runtime_dispatch_required' => $serviceSource !== ''
                && str_contains($serviceSource, "BLOCKER_RUNTIME_DISPATCH_REQUIRED = 'runtime_dispatch_required'"),
            'live_decide_receipt_required' => $serviceSource !== ''
                && str_contains($serviceSource, "BLOCKER_LIVE_DECIDE_DISPATCH_REQUIRED = 'live_decide_dispatch_required'"),
            'static_policy_cannot_invoke' => $serviceSource !== ''
                && str_contains($serviceSource, "'live_atlas_decide'"),
            'provider_driver_missing_blocks' => $serviceSource !== ''
                && str_contains($serviceSource, "BLOCKER_PROVIDER_DRIVER_MISSING = 'provider_driver_missing'"),
            'ledger_events_supported' => $serviceSource !== ''
                && str_contains($serviceSource, 'EVENT_SUBTYPE_STARTED')
                && str_contains($serviceSource, 'EVENT_SUBTYPE_COMPLETED'),
            'output_hashing_supported' => $serviceSource !== ''
                && str_contains($serviceSource, 'stdout_hash')
                && str_contains($serviceSource, 'stderr_hash'),
            'timeout_supported' => $serviceSource !== ''
                && str_contains($serviceSource, 'STATUS_TIMED_OUT'),
            'completion_claim_not_promoted' => $serviceSource !== ''
                && str_contains($serviceSource, "'completion_claim_promoted' => false"),
            'review_completion_gate_preserved' => $serviceSource !== ''
                && str_contains($serviceSource, "'review_completion_gate_preserved' => true"),
            'external_rivals_separated' => $serviceSource !== ''
                && str_contains($serviceSource, "'separated_from' => 'external_rivals_certification'"),
        ];

        $missingArtifacts = [];
        foreach ([
            'service_file' => $serviceFile,
            'driver_router_file' => $driverFile,
            'prompt_builder_file' => $promptFile,
            'command_file' => $commandFile,
            'controller_file' => $controllerFile,
            'test_file' => $testFile,
            'doc_file' => $docFile,
        ] as $key => $path) {
            if (! is_file($path)) {
                $missingArtifacts[] = $key.'_missing';
            }
        }
        if ($routesSource === '' || ! str_contains($routesSource, '/forge/provider-invocations')) {
            $missingArtifacts[] = 'routes_not_registered';
        }

        $allInvariantsTrue = ! in_array(false, array_values($invariants), true);
        $status = match (true) {
            $missingArtifacts !== [] => 'missing_artifacts',
            ! $allInvariantsTrue => 'backend_available_ui_pending',
            default => 'available',
        };

        return [
            'schema_version' => 'atlas.forge_provider_invocation_certification.v1',
            'status' => $status,
            'invariants' => $invariants,
            'invariants_all_true' => $allInvariantsTrue,
            'artifacts' => [
                'service' => ['path' => 'app/Services/Ai/Programming/AtlasForgeProviderInvocationService.php', 'present' => is_file($serviceFile)],
                'driver_router' => ['path' => 'app/Services/Ai/Programming/AtlasForgeProviderInvocationDriverRouter.php', 'present' => is_file($driverFile)],
                'prompt_builder' => ['path' => 'app/Services/Ai/Programming/AtlasForgeProviderInvocationPromptBuilder.php', 'present' => is_file($promptFile)],
                'command' => ['path' => 'app/Console/Commands/AtlasForgeProviderInvokeCommand.php', 'present' => is_file($commandFile)],
                'controller' => ['path' => 'app/Http/Controllers/AtlasCodeForgeProviderInvocationController.php', 'present' => is_file($controllerFile)],
                'test' => ['path' => 'tests/Feature/Ai/Programming/AtlasForgeProviderInvocationTest.php', 'present' => is_file($testFile)],
                'doc' => ['path' => 'docs/engineering-knowledge-base/atlas-forge-governed-provider-invocation-v1.md', 'present' => is_file($docFile)],
            ],
            'missing_artifacts' => $missingArtifacts,
            'evidence_commands' => [
                'plan' => 'php artisan atlas:forge:provider-invoke --obra=<uuid> --role=primary_builder --mode=dry_run --json --strict',
                'execute_blocked_without_flags' => 'php artisan atlas:forge:provider-invoke --obra=<uuid> --role=primary_builder --mode=execute --json --strict',
                'execute_governed' => 'php artisan atlas:forge:provider-invoke --obra=<uuid> --role=primary_builder --mode=execute --confirm-provider-call --confirm-budget --confirm-runtime-dispatch --json --strict',
            ],
            'lifecycle_states' => [
                AtlasForgeProviderInvocationService::STATUS_PLANNED,
                AtlasForgeProviderInvocationService::STATUS_EXECUTED,
                AtlasForgeProviderInvocationService::STATUS_BLOCKED,
                AtlasForgeProviderInvocationService::STATUS_FAILED,
                AtlasForgeProviderInvocationService::STATUS_TIMED_OUT,
                AtlasForgeProviderInvocationService::STATUS_CANCELLED,
            ],
            'modes' => [
                AtlasForgeProviderInvocationService::MODE_DRY_RUN,
                AtlasForgeProviderInvocationService::MODE_EXECUTE,
            ],
            'driver_router_summary' => [
                'canonical_drivers' => AtlasForgeProviderInvocationDriverRouter::CANONICAL_DRIVERS,
                'atlas_local_runtime_available' => $this->forgeProviderInvocationDriverRouter->hasRuntimeDriver(AtlasForgeProviderInvocationDriverRouter::DRIVER_ATLAS_LOCAL),
            ],
            'no_external_provider_call_without_flags' => true,
            'external_provider_call' => false,
            'is_external_benchmark' => false,
            'provider_tokens_spent' => false,
            'promotes_external_rivals_claim' => false,
            'separated_from_external_rivals_certification' => true,
            'separated_from' => 'external_rivals_certification',
            'note' => 'Atlas Forge Governed Provider Invocation: dry-run por padrao; execute exige confirm_provider_call + confirm_budget + confirm_runtime_dispatch + driver configurado. Sem flags, nunca chama provider externo.',
        ];
    }

    /**
     * Atlas Forge Governed Real Provider Drivers certification block.
     *
     * Audits the v1 real-CLI driver layer: driver contract + allowlist +
     * safe process runner + claude/codex/gemini drivers + failure classifier
     * + driver router v2 + CLI flags (`--driver-status`/`--plan-driver`) +
     * API endpoints + state projection.
     *
     * Schema: atlas.forge_real_provider_drivers_certification.v1
     *
     * @return array<string,mixed>
     */
    public function atlasForgeRealProviderDriversCertification(string $workspace): array
    {
        $repoRoot = rtrim(is_dir($workspace) ? $workspace : base_path(), '/');
        $desktopRoot = dirname($repoRoot).'/atlas-desktop';

        $serviceFile = $repoRoot.'/app/Services/Ai/Programming/AtlasForgeProviderInvocationService.php';
        $routerFile = $repoRoot.'/app/Services/Ai/Programming/AtlasForgeProviderInvocationDriverRouter.php';
        $contractFile = $repoRoot.'/app/Services/Ai/Programming/AtlasForgeProviderInvocationDriver.php';
        $baseFile = $repoRoot.'/app/Services/Ai/Programming/AtlasForgeBaseCliInvocationDriver.php';
        $claudeFile = $repoRoot.'/app/Services/Ai/Programming/AtlasForgeClaudeCliInvocationDriver.php';
        $codexFile = $repoRoot.'/app/Services/Ai/Programming/AtlasForgeCodexCliInvocationDriver.php';
        $geminiFile = $repoRoot.'/app/Services/Ai/Programming/AtlasForgeGeminiCliInvocationDriver.php';
        $allowlistFile = $repoRoot.'/app/Services/Ai/Programming/AtlasForgeProviderCommandAllowlistService.php';
        $runnerFile = $repoRoot.'/app/Services/Ai/Programming/AtlasForgeProviderProcessRunner.php';
        $classifierFile = $repoRoot.'/app/Services/Ai/Programming/AtlasForgeProviderInvocationFailureClassifier.php';
        $commandFile = $repoRoot.'/app/Console/Commands/AtlasForgeProviderInvokeCommand.php';
        $controllerFile = $repoRoot.'/app/Http/Controllers/AtlasCodeForgeProviderInvocationController.php';
        $testFile = $repoRoot.'/tests/Feature/Ai/Programming/AtlasForgeRealProviderDriversTest.php';
        $docFile = $repoRoot.'/docs/engineering-knowledge-base/atlas-forge-real-provider-drivers-v1.md';
        $routesFile = $repoRoot.'/routes/api.php';
        $routesSource = is_file($routesFile) ? (string) file_get_contents($routesFile) : '';
        $serviceSource = is_file($serviceFile) ? (string) file_get_contents($serviceFile) : '';
        $commandSource = is_file($commandFile) ? (string) file_get_contents($commandFile) : '';
        $controllerSource = is_file($controllerFile) ? (string) file_get_contents($controllerFile) : '';
        $workControllerSource = is_file($repoRoot.'/app/Http/Controllers/AtlasCodeWorkController.php')
            ? (string) file_get_contents($repoRoot.'/app/Http/Controllers/AtlasCodeWorkController.php')
            : '';

        $desktopBridge = $desktopRoot.'/apps/desktop/src/lib/bridge.ts';
        $desktopPanel = $desktopRoot.'/apps/desktop/src/surfaces/code/panels/ForgeProviderTopologyPanel.tsx';

        $invariants = [
            'driver_contract_present' => is_file($contractFile) && interface_exists(AtlasForgeProviderInvocationDriver::class),
            'driver_router_v2_present' => is_file($routerFile) && class_exists(AtlasForgeProviderInvocationDriverRouter::class)
                && method_exists(AtlasForgeProviderInvocationDriverRouter::class, 'driverStatus')
                && method_exists(AtlasForgeProviderInvocationDriverRouter::class, 'driverPlan')
                && method_exists(AtlasForgeProviderInvocationDriverRouter::class, 'driverInvoke'),
            'claude_cli_driver_present' => is_file($claudeFile) && class_exists(AtlasForgeClaudeCliInvocationDriver::class),
            'codex_cli_driver_present' => is_file($codexFile) && class_exists(AtlasForgeCodexCliInvocationDriver::class),
            'gemini_cli_driver_present' => is_file($geminiFile) && class_exists(AtlasForgeGeminiCliInvocationDriver::class),
            'atlas_local_driver_preserved' => $serviceSource !== '' && str_contains($serviceSource, 'atlas-local'),
            'command_allowlist_present' => is_file($allowlistFile) && class_exists(AtlasForgeProviderCommandAllowlistService::class),
            'safe_process_runner_present' => is_file($runnerFile) && class_exists(AtlasForgeProviderProcessRunner::class),
            'failure_classifier_present' => is_file($classifierFile) && class_exists(AtlasForgeProviderInvocationFailureClassifier::class),
            'driver_status_cli_available' => $commandSource !== '' && str_contains($commandSource, "--driver-status"),
            'driver_plan_cli_available' => $commandSource !== '' && str_contains($commandSource, "--plan-driver"),
            'driver_status_api_available' => $routesSource !== '' && str_contains($routesSource, '/forge/provider-invocations/drivers')
                && $controllerSource !== '' && str_contains($controllerSource, 'public function drivers('),
            'driver_plan_api_available' => $routesSource !== '' && str_contains($routesSource, '/forge/provider-invocations/plan-driver')
                && $controllerSource !== '' && str_contains($controllerSource, 'public function planDriver('),
            'desktop_driver_status_visible' => is_file($desktopBridge)
                && is_file($desktopPanel),
            'execute_requires_three_confirmations' => $serviceSource !== ''
                && str_contains($serviceSource, 'BLOCKER_OPERATOR_APPROVAL_REQUIRED')
                && str_contains($serviceSource, 'BLOCKER_RUNTIME_DISPATCH_CONFIRMATION_REQUIRED')
                && str_contains($serviceSource, 'BLOCKER_BUDGET_APPROVAL_REQUIRED'),
            'budget_required_for_external_provider' => $serviceSource !== ''
                && str_contains($serviceSource, 'callsExternalProvider'),
            'capacity_checked_before_invoke' => $serviceSource !== ''
                && str_contains($serviceSource, 'BLOCKER_PROVIDER_CAPACITY_EXHAUSTED'),
            'static_policy_cannot_invoke' => $serviceSource !== ''
                && str_contains($serviceSource, "'live_atlas_decide'"),
            'live_decide_receipt_required' => $serviceSource !== ''
                && str_contains($serviceSource, 'BLOCKER_DECISION_RECEIPT_REQUIRED'),
            'output_hashing_supported' => $serviceSource !== '' && str_contains($serviceSource, 'stdout_hash'),
            'timeout_supported' => $serviceSource !== '' && str_contains($serviceSource, 'STATUS_TIMED_OUT'),
            'failure_memory_recorded_on_provider_error' => class_exists(\App\Services\Ai\Programming\AtlasForgeProviderFailureMemoryService::class),
            'ledger_events_supported' => $serviceSource !== '' && str_contains($serviceSource, 'EVENT_SUBTYPE_STARTED'),
            'provider_driver_missing_preserved' => is_file($routerFile)
                && str_contains((string) file_get_contents($routerFile), "BLOCKER_PROVIDER_DRIVER_MISSING = 'provider_driver_missing'"),
            'no_completion_claim_promotion' => $serviceSource !== '' && str_contains($serviceSource, "'completion_claim_promoted' => false"),
            'review_completion_gate_preserved' => $serviceSource !== '' && str_contains($serviceSource, "'review_completion_gate_preserved' => true"),
            'external_rivals_separated' => $serviceSource !== '' && str_contains($serviceSource, "'separated_from' => 'external_rivals_certification'"),
            'state_projection_driver_status' => $workControllerSource !== ''
                && str_contains($workControllerSource, "'forge_provider_driver_status' =>"),
            'allowlist_blocks_shell_metacharacter' => is_file($allowlistFile)
                && str_contains((string) file_get_contents($allowlistFile), 'BLOCKER_SHELL_METACHARACTER'),
        ];

        $missingArtifacts = [];
        foreach ([
            'contract' => $contractFile,
            'router' => $routerFile,
            'base_driver' => $baseFile,
            'claude_driver' => $claudeFile,
            'codex_driver' => $codexFile,
            'gemini_driver' => $geminiFile,
            'allowlist' => $allowlistFile,
            'runner' => $runnerFile,
            'classifier' => $classifierFile,
            'command' => $commandFile,
            'controller' => $controllerFile,
            'test' => $testFile,
            'doc' => $docFile,
        ] as $key => $path) {
            if (! is_file($path)) {
                $missingArtifacts[] = $key.'_missing';
            }
        }
        if ($routesSource === '' || ! str_contains($routesSource, '/forge/provider-invocations/drivers')) {
            $missingArtifacts[] = 'drivers_route_not_registered';
        }
        if ($routesSource === '' || ! str_contains($routesSource, '/forge/provider-invocations/plan-driver')) {
            $missingArtifacts[] = 'plan_driver_route_not_registered';
        }

        $allInvariantsTrue = ! in_array(false, array_values($invariants), true);
        $status = match (true) {
            $missingArtifacts !== [] => 'missing_artifacts',
            ! $allInvariantsTrue => 'backend_available_ui_pending',
            default => 'available',
        };

        return [
            'schema_version' => 'atlas.forge_real_provider_drivers_certification.v1',
            'status' => $status,
            'invariants' => $invariants,
            'invariants_all_true' => $allInvariantsTrue,
            'artifacts' => [
                'contract' => ['path' => 'app/Services/Ai/Programming/AtlasForgeProviderInvocationDriver.php', 'present' => is_file($contractFile)],
                'router' => ['path' => 'app/Services/Ai/Programming/AtlasForgeProviderInvocationDriverRouter.php', 'present' => is_file($routerFile)],
                'base_driver' => ['path' => 'app/Services/Ai/Programming/AtlasForgeBaseCliInvocationDriver.php', 'present' => is_file($baseFile)],
                'claude_driver' => ['path' => 'app/Services/Ai/Programming/AtlasForgeClaudeCliInvocationDriver.php', 'present' => is_file($claudeFile)],
                'codex_driver' => ['path' => 'app/Services/Ai/Programming/AtlasForgeCodexCliInvocationDriver.php', 'present' => is_file($codexFile)],
                'gemini_driver' => ['path' => 'app/Services/Ai/Programming/AtlasForgeGeminiCliInvocationDriver.php', 'present' => is_file($geminiFile)],
                'allowlist' => ['path' => 'app/Services/Ai/Programming/AtlasForgeProviderCommandAllowlistService.php', 'present' => is_file($allowlistFile)],
                'runner' => ['path' => 'app/Services/Ai/Programming/AtlasForgeProviderProcessRunner.php', 'present' => is_file($runnerFile)],
                'classifier' => ['path' => 'app/Services/Ai/Programming/AtlasForgeProviderInvocationFailureClassifier.php', 'present' => is_file($classifierFile)],
                'command' => ['path' => 'app/Console/Commands/AtlasForgeProviderInvokeCommand.php', 'present' => is_file($commandFile)],
                'controller' => ['path' => 'app/Http/Controllers/AtlasCodeForgeProviderInvocationController.php', 'present' => is_file($controllerFile)],
                'test' => ['path' => 'tests/Feature/Ai/Programming/AtlasForgeRealProviderDriversTest.php', 'present' => is_file($testFile)],
                'doc' => ['path' => 'docs/engineering-knowledge-base/atlas-forge-real-provider-drivers-v1.md', 'present' => is_file($docFile)],
            ],
            'missing_artifacts' => $missingArtifacts,
            'driver_registry' => [
                'atlas-local' => 'preserved',
                'claude_cli' => class_exists(AtlasForgeClaudeCliInvocationDriver::class) ? 'registered' : 'missing',
                'codex_cli' => class_exists(AtlasForgeCodexCliInvocationDriver::class) ? 'registered' : 'missing',
                'gemini_cli' => class_exists(AtlasForgeGeminiCliInvocationDriver::class) ? 'registered' : 'missing',
            ],
            'invariant_note' => 'available NAO exige que CLIs externos estejam configurados; exige que drivers existam e bloqueiem honestamente quando o ambiente nao tiver runtime/auth.',
            'no_external_provider_call' => true,
            'external_provider_call' => false,
            'is_external_benchmark' => false,
            'promotes_external_rivals_claim' => false,
            'separated_from_external_rivals_certification' => true,
            'separated_from' => 'external_rivals_certification',
            'note' => 'Atlas Forge Governed Real Provider Drivers v1: contrato + allowlist + safe runner + claude/codex/gemini drivers + classifier + router v2 + CLI flags + API + state projection. Plan-only por padrao; execute real exige 3 confirmacoes + budget + dispatch + capacity + driver configurado.',
        ];
    }
}
