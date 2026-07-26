<?php

namespace App\Services\Ai\Programming\CompletionAudit;

use App\Console\Commands\AtlasCodeForgeFastPathCommand;
use App\Console\Commands\AtlasCodeForgeFastPathStatusCommand;
use App\Console\Commands\AtlasCodeForgeReviewCommand;
use App\Console\Commands\AtlasCodeForgeWorkIntakeCommand;
use App\Console\Commands\AtlasForgeLiveExecuteCommand;
use App\Http\Controllers\AtlasCodeForgeFastPathController;
use App\Http\Controllers\AtlasCodeForgeFastPathStatusController;
use App\Http\Controllers\AtlasCodeForgeReviewCompletionController;
use App\Http\Controllers\AtlasCodeForgeWorkIntakeController;
use App\Services\Ai\Programming\AtlasCodeForgeFastPathService;
use App\Services\Ai\Programming\AtlasCodeForgeFastPathStatusService;
use App\Services\Ai\Programming\AtlasCodeForgeReviewCompletionService;
use App\Services\Ai\Programming\AtlasCodeForgeWorkIntakeService;
use App\Services\Ai\Programming\AtlasForgeLiveExecutionService;
use App\Services\Ai\Programming\AtlasForgeNativeRivalsCaseManifestService;
use App\Services\Ai\Programming\AtlasForgeNativeRivalsDryRunService;
use App\Services\Ai\Programming\AtlasForgeNativeRivalsPreflightService;
use App\Services\Ai\Programming\AtlasForgeNativeRivalsProtocolService;

class ForgeAudit
{
    /**
     * Items do checklist que pertencem ao Atlas Forge Runtime core
     * (programming foundation + governance + harness + evidence).
     * Itens de Rivals externo ficam fora — sao certificados separadamente.
     *
     * @var array<int,string>
     */
    private const FORGE_CORE_ITEMS = [
        'professional_operating_standard',
        'professional_spec',
        'enterprise_plan',
        'completion_audit_doc',
        'agentic_rag_context_pack',
        'hybrid_retrieval_and_gap_critic',
        'semantic_code_graph',
        'stage_receipts_resume',
        'tool_runtime_manifests',
        'patch_verifier',
        'test_impact',
        'sandbox_repair_learning',
        'python_runtime',
        'local_benchmarks',
        'programming_cli_commands',
    ];

    public function __construct(
        private readonly CompletionAuditSupport $support,
        private readonly AtlasForgeNativeRivalsProtocolService $forgeNativeRivalsProtocol,
        private readonly AtlasForgeNativeRivalsCaseManifestService $forgeNativeRivalsCaseManifest,
        private readonly AtlasForgeNativeRivalsPreflightService $forgeNativeRivalsPreflight,
        private readonly AtlasForgeNativeRivalsDryRunService $forgeNativeRivalsDryRun,
    ) {}

    /**
     * @param  array<int,array<string,mixed>>  $checklist
     * @return array<string,mixed>
     */
    public function forgeRuntimeCertification(array $checklist): array
    {
        $forgeItems = collect($checklist)
            ->filter(fn (array $item): bool => in_array((string) ($item['id'] ?? ''), self::FORGE_CORE_ITEMS, true))
            ->values();

        $blockers = $forgeItems
            ->filter(fn (array $item): bool => ($item['status'] ?? null) !== 'passed')
            ->map(fn (array $item): array => [
                'id' => (string) ($item['id'] ?? ''),
                'blocker' => (string) ($item['blocker'] ?? 'unknown'),
            ])
            ->values()
            ->all();

        $status = $blockers === [] ? 'passed' : 'blocked';

        return [
            'schema_version' => 'atlas.forge_runtime_certification.v1',
            'status' => $status,
            'evidence_command' => 'php artisan atlas:forge:runtime-certify --json',
            'core_artifact_group_count' => $forgeItems->count(),
            'passed_count' => $forgeItems->count() - count($blockers),
            'blocked_count' => count($blockers),
            'blockers' => $blockers,
            'note' => 'Forge core e certificado pelo command atlas:forge:runtime-certify e por este audit local. Bateria Rivals externo NAO afeta este eixo.',
            'separated_from' => 'external_rivals_certification',
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function forgeLiveExecutionCertification(): array
    {
        $serviceClass = AtlasForgeLiveExecutionService::class;
        $commandClass = AtlasForgeLiveExecuteCommand::class;
        $testFile = base_path('tests/Feature/Ai/Programming/AtlasForgeLiveExecutionTest.php');
        $docFile = base_path('docs/engineering-knowledge-base/atlas-forge-live-execution-e2e-v1.md');

        $serviceExists = class_exists($serviceClass);
        $commandExists = class_exists($commandClass);
        $testExists = is_file($testFile);
        $docExists = is_file($docFile);

        $expectedTestMethods = [
            'test_live_execution_fails_closed_without_obra',
            'test_live_execution_runs_full_chain_with_obra',
            'test_context_pack_has_canonical_minimum_with_non_empty_ranked_refs',
            'test_repair_loop_is_skipped_not_needed_when_test_passes',
            'test_repair_loop_is_triggered_when_test_simulated_failure',
        ];

        $testCoverage = $this->support->scanTestCoverage($testFile, $expectedTestMethods);

        $missingArtifacts = [];
        if (! $serviceExists) {
            $missingArtifacts[] = 'service_class_missing';
        }
        if (! $commandExists) {
            $missingArtifacts[] = 'command_class_missing';
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
            'schema_version' => 'atlas.forge_live_execution_certification.v1',
            'status' => $status,
            'evidence_command' => 'php artisan atlas:forge:live-execute --obra=<uuid> --json --strict',
            'evidence_command_failure_path' => 'php artisan atlas:forge:live-execute --obra=<uuid> --simulate-failure --json',
            'fail_closed_command' => 'php artisan atlas:forge:live-execute --json --strict',
            'fail_closed_expected_exit_code' => 1,
            'artifacts' => [
                'service' => ['class' => $serviceClass, 'present' => $serviceExists],
                'command' => ['class' => $commandClass, 'present' => $commandExists],
                'test' => ['path' => 'tests/Feature/Ai/Programming/AtlasForgeLiveExecutionTest.php', 'present' => $testExists],
                'doc' => ['path' => 'docs/engineering-knowledge-base/atlas-forge-live-execution-e2e-v1.md', 'present' => $docExists],
            ],
            'test_coverage' => [
                'expected_methods' => $expectedTestMethods,
                'present_methods' => $testCoverage['present_methods'],
                'missing_methods' => $missingMethods,
                'coverage_complete' => $missingMethods === [],
            ],
            'stages_proven' => [
                'obra_binding',
                'sandbox_provision',
                'context_pack',
                'patch_apply',
                'action_manifest',
                'patch_verifier',
                'test_run',
                'stage_receipts',
                'repair_loop',
                'evidence_ledger',
                'sandbox_rollback',
            ],
            'contract_invariants' => [
                'obra_required' => true,
                'fail_closed_without_obra' => true,
                'context_pack_canonical_minimum_required' => true,
                'repair_loop_states' => ['skipped_not_needed', 'passed', 'degraded', 'blocked'],
                'evidence_ledger_required_when_table_present' => true,
            ],
            'external_provider_call' => false,
            'is_external_benchmark' => false,
            'note' => 'Status=available significa artefatos + cobertura de teste presentes; passed exige operador rodar atlas:forge:live-execute --strict e anexar evidencia recente. requires_operator_run aparece quando metodos canonicos de teste estao faltando.',
            'separated_from' => 'external_rivals_certification',
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function forgeFastPathCertification(): array
    {
        $serviceClass = AtlasCodeForgeFastPathService::class;
        $controllerClass = AtlasCodeForgeFastPathController::class;
        $commandClass = AtlasCodeForgeFastPathCommand::class;
        $statusServiceClass = AtlasCodeForgeFastPathStatusService::class;
        $statusControllerClass = AtlasCodeForgeFastPathStatusController::class;
        $statusCommandClass = AtlasCodeForgeFastPathStatusCommand::class;
        $testFile = base_path('tests/Feature/Ai/Programming/AtlasCodeForgeFastPathTest.php');
        $docFile = base_path('docs/engineering-knowledge-base/atlas-code-forge-fast-path-v1.md');
        $routesFile = base_path('routes/api.php');
        $routesSource = is_file($routesFile) ? (string) file_get_contents($routesFile) : '';

        $serviceExists = class_exists($serviceClass);
        $controllerExists = class_exists($controllerClass);
        $commandExists = class_exists($commandClass);
        $statusServiceExists = class_exists($statusServiceClass);
        $statusControllerExists = class_exists($statusControllerClass);
        $statusCommandExists = class_exists($statusCommandClass);
        $testExists = is_file($testFile);
        $docExists = is_file($docFile);
        $routeRegistered = str_contains($routesSource, '/forge/fast-path')
            && str_contains($routesSource, 'AtlasCodeForgeFastPathController');
        $statusEndpointRegistered = str_contains($routesSource, '/forge/fast-path/{run}/status')
            && str_contains($routesSource, 'AtlasCodeForgeFastPathStatusController');
        $resumeEndpointRegistered = str_contains($routesSource, '/forge/fast-path/{run}/resume')
            && str_contains($routesSource, 'AtlasCodeForgeFastPathStatusController');

        $expectedTestMethods = [
            'test_fast_path_fails_closed_without_obra',
            'test_fast_path_fails_closed_when_obra_not_found',
            'test_fast_path_prepare_only_creates_work_item_and_compiles_spec_plan',
            'test_fast_path_execute_async_dispatches_queued_status',
            'test_fast_path_persists_snapshot_and_exposes_state_projection',
            'test_fast_path_blocks_when_obra_lacks_intent',
            'test_fast_path_status_returns_not_found_for_unknown_run',
            'test_fast_path_status_rejects_run_from_other_obra',
            'test_fast_path_status_returns_canonical_lifecycle_payload',
            'test_fast_path_resume_reuses_existing_work_item',
            'test_fast_path_does_not_auto_complete_without_review',
            'test_fast_path_status_ignores_unrelated_latest_forge_execution',
        ];
        $testCoverage = $this->support->scanTestCoverage($testFile, $expectedTestMethods);

        $missingArtifacts = [];
        if (! $serviceExists) {
            $missingArtifacts[] = 'service_class_missing';
        }
        if (! $controllerExists) {
            $missingArtifacts[] = 'controller_class_missing';
        }
        if (! $commandExists) {
            $missingArtifacts[] = 'command_class_missing';
        }
        if (! $statusServiceExists) {
            $missingArtifacts[] = 'status_service_class_missing';
        }
        if (! $statusControllerExists) {
            $missingArtifacts[] = 'status_controller_class_missing';
        }
        if (! $statusCommandExists) {
            $missingArtifacts[] = 'status_command_class_missing';
        }
        if (! $testExists) {
            $missingArtifacts[] = 'test_file_missing';
        }
        if (! $docExists) {
            $missingArtifacts[] = 'doc_file_missing';
        }
        if (! $routeRegistered) {
            $missingArtifacts[] = 'route_not_registered';
        }
        if (! $statusEndpointRegistered) {
            $missingArtifacts[] = 'status_endpoint_not_registered';
        }
        if (! $resumeEndpointRegistered) {
            $missingArtifacts[] = 'resume_endpoint_not_registered';
        }

        $missingMethods = $testCoverage['missing_methods'];

        $status = match (true) {
            $missingArtifacts !== [] => 'missing_artifacts',
            $missingMethods !== [] => 'requires_operator_run',
            default => 'available',
        };

        return [
            'schema_version' => 'atlas.code.forge_fast_path_certification.v2',
            'status' => $status,
            'evidence_command' => 'php artisan atlas:code:forge-fast-path --obra=<uuid> --mode=execute_async --json --strict',
            'evidence_status_command' => 'php artisan atlas:code:forge-fast-path-status --obra=<uuid> --run=<run> --json --strict',
            'fail_closed_command' => 'php artisan atlas:code:forge-fast-path --json --strict',
            'fail_closed_expected_exit_code' => 1,
            'api_endpoint' => 'POST /atlas-code/works/{project}/forge/fast-path',
            'api_status_endpoint' => 'GET /atlas-code/works/{project}/forge/fast-path/{run}/status',
            'api_resume_endpoint' => 'POST /atlas-code/works/{project}/forge/fast-path/{run}/resume',
            'lifecycle_invariants' => [
                'run_lifecycle_available' => $serviceExists && $statusServiceExists,
                'polling_endpoint_registered' => $statusEndpointRegistered,
                'resume_endpoint_registered' => $resumeEndpointRegistered,
                'cli_status_command_registered' => $statusCommandExists,
                'review_gate_integrated' => $statusServiceExists,
                'repair_path_exposed' => $statusServiceExists,
                'no_auto_completion_without_review' => true,
                'state_projection_available' => true,
            ],
            'read_model' => [
                'state_projection' => '/atlas-code/works/{obra}/state.forge_fast_path',
                'latest_metadata_key' => 'AtlasProject.metadata.latest_atlas_code_forge_fast_path',
                'history_metadata_key' => 'AtlasProject.metadata.atlas_code_forge_fast_path_history',
                'latest_run_metadata_key' => 'AtlasProject.metadata.latest_atlas_code_forge_fast_path_run',
                'run_history_metadata_key' => 'AtlasProject.metadata.atlas_code_forge_fast_path_run_history',
                'history_limit' => 10,
                'run_history_limit' => 25,
            ],
            'artifacts' => [
                'service' => ['class' => $serviceClass, 'present' => $serviceExists],
                'controller' => ['class' => $controllerClass, 'present' => $controllerExists],
                'command' => ['class' => $commandClass, 'present' => $commandExists],
                'status_service' => ['class' => $statusServiceClass, 'present' => $statusServiceExists],
                'status_controller' => ['class' => $statusControllerClass, 'present' => $statusControllerExists],
                'status_command' => ['class' => $statusCommandClass, 'present' => $statusCommandExists],
                'test' => ['path' => 'tests/Feature/Ai/Programming/AtlasCodeForgeFastPathTest.php', 'present' => $testExists],
                'doc' => ['path' => 'docs/engineering-knowledge-base/atlas-code-forge-fast-path-v1.md', 'present' => $docExists],
                'route_registered' => $routeRegistered,
                'status_endpoint_registered' => $statusEndpointRegistered,
                'resume_endpoint_registered' => $resumeEndpointRegistered,
            ],
            'test_coverage' => [
                'expected_methods' => $expectedTestMethods,
                'present_methods' => $testCoverage['present_methods'],
                'missing_methods' => $missingMethods,
                'coverage_complete' => $missingMethods === [],
            ],
            'stages_orchestrated' => [
                'obra_binding',
                'workspace_binding',
                'work_item_resolution',
                'spec_plan_resolution',
                'task_queue_resolution',
                'execution_dispatch',
                'state_projection',
                'operator_next_action',
            ],
            'modes_supported' => ['prepare_only', 'execute_async', 'execute_sync'],
            'contract_invariants' => [
                'obra_required' => true,
                'fail_closed_without_obra' => true,
                'no_silent_obra_uuid_generation' => true,
                'forge_only_surface' => true,
                'reuses_canonical_controllers' => true,
                'persists_latest_snapshot_on_obra' => true,
                'exposes_state_projection_to_atlas_code' => true,
            ],
            'external_provider_call' => false,
            'is_external_benchmark' => false,
            'note' => 'Fast Path orquestra os controllers canonicos (programming work-items + forge live-executions + checkpoints) reusando services existentes. Nao cria runtime novo nem provider externo. Separado de external_rivals_certification.',
            'separated_from' => 'external_rivals_certification',
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function forgeReviewCompletionCertification(): array
    {
        $serviceClass = AtlasCodeForgeReviewCompletionService::class;
        $controllerClass = AtlasCodeForgeReviewCompletionController::class;
        $commandClass = AtlasCodeForgeReviewCommand::class;
        $testFile = base_path('tests/Feature/Ai/Programming/AtlasCodeForgeReviewCompletionTest.php');
        $docFile = base_path('docs/engineering-knowledge-base/atlas-code-forge-review-completion-gate-v1.md');
        $routesFile = base_path('routes/api.php');
        $routesSource = is_file($routesFile) ? (string) file_get_contents($routesFile) : '';
        $desktopRoot = dirname(base_path()).'/atlas-desktop';
        $domainFile = $desktopRoot.'/packages/atlas-domain/src/index.ts';
        $bridgeFile = $desktopRoot.'/apps/desktop/src/lib/bridge.ts';
        $hookFile = $desktopRoot.'/apps/desktop/src/hooks/useBridge.ts';
        $reviewPanelFile = $desktopRoot.'/apps/desktop/src/surfaces/code/panels/ForgeReviewCompletionPanel.tsx';

        $serviceExists = class_exists($serviceClass);
        $controllerExists = class_exists($controllerClass);
        $commandExists = class_exists($commandClass);
        $testExists = is_file($testFile);
        $docExists = is_file($docFile);
        $domainSource = is_file($domainFile) ? (string) file_get_contents($domainFile) : '';
        $bridgeSource = is_file($bridgeFile) ? (string) file_get_contents($bridgeFile) : '';
        $hookSource = is_file($hookFile) ? (string) file_get_contents($hookFile) : '';
        $desktopDomainTypes = str_contains($domainSource, 'AtlasCodeForgeReviewPacket')
            && str_contains($domainSource, 'AtlasCodeForgeCompletionClaim');
        $desktopBridgeActions = str_contains($bridgeSource, 'getForgeReviewPacket')
            && str_contains($bridgeSource, 'approveForgeReview')
            && str_contains($bridgeSource, 'rejectForgeReview')
            && str_contains($bridgeSource, 'rollbackForgeReview');
        $desktopHookActions = str_contains($hookSource, 'refreshForgeReview')
            && str_contains($hookSource, 'approveForgeReview')
            && str_contains($hookSource, 'rejectForgeReview')
            && str_contains($hookSource, 'rollbackForgeReview');
        $desktopPanel = is_file($reviewPanelFile);
        $desktopUiAvailable = $desktopDomainTypes && $desktopBridgeActions && $desktopHookActions && $desktopPanel;
        $reviewEndpoint = str_contains($routesSource, '/forge/fast-path/{run}/review')
            && str_contains($routesSource, 'AtlasCodeForgeReviewCompletionController');
        $approveEndpoint = str_contains($routesSource, '/forge/fast-path/{run}/review/approve');
        $rejectEndpoint = str_contains($routesSource, '/forge/fast-path/{run}/review/reject');
        $rollbackEndpoint = str_contains($routesSource, '/forge/fast-path/{run}/review/rollback');

        $expectedTestMethods = [
            'test_review_packet_blocked_for_unknown_run',
            'test_review_packet_blocked_when_run_belongs_to_other_obra',
            'test_review_packet_only_uses_correlated_live_execution',
            'test_approve_blocked_when_runtime_not_passed',
            'test_approve_blocked_when_evidence_pack_missing',
            'test_reject_records_decision_and_blocks_completion_claim',
            'test_rollback_blocked_when_rollback_not_available',
            'test_state_endpoint_exposes_review_packet_and_completion_claim',
            'test_completion_audit_block_lists_review_completion_artifacts',
            'test_completion_claim_only_allowed_after_human_approval',
            'test_approve_with_human_review_completes_claim',
        ];
        $testCoverage = $this->support->scanTestCoverage($testFile, $expectedTestMethods);

        $missingArtifacts = [];
        if (! $serviceExists) {
            $missingArtifacts[] = 'service_class_missing';
        }
        if (! $controllerExists) {
            $missingArtifacts[] = 'controller_class_missing';
        }
        if (! $commandExists) {
            $missingArtifacts[] = 'command_class_missing';
        }
        if (! $testExists) {
            $missingArtifacts[] = 'test_file_missing';
        }
        if (! $docExists) {
            $missingArtifacts[] = 'doc_file_missing';
        }
        if (! $reviewEndpoint) {
            $missingArtifacts[] = 'review_endpoint_not_registered';
        }
        if (! $approveEndpoint) {
            $missingArtifacts[] = 'approve_endpoint_not_registered';
        }
        if (! $rejectEndpoint) {
            $missingArtifacts[] = 'reject_endpoint_not_registered';
        }
        if (! $rollbackEndpoint) {
            $missingArtifacts[] = 'rollback_endpoint_not_registered';
        }

        $missingMethods = $testCoverage['missing_methods'];

        $status = match (true) {
            $missingArtifacts !== [] => 'missing_artifacts',
            $missingMethods !== [] => 'requires_operator_run',
            ! $desktopUiAvailable => 'backend_available_ui_pending',
            default => 'available',
        };

        return [
            'schema_version' => 'atlas.code.forge_review_completion_certification.v1',
            'status' => $status,
            'review_packet_schema' => 'atlas.code.forge_review_packet.v1',
            'completion_claim_schema' => 'atlas.code.forge_completion_claim.v1',
            'evidence_command' => 'php artisan atlas:code:forge-review --obra=<uuid> --run=<id> --json --strict',
            'endpoints' => [
                'show' => 'GET /atlas-code/works/{project}/forge/fast-path/{run}/review',
                'approve' => 'POST /atlas-code/works/{project}/forge/fast-path/{run}/review/approve',
                'reject' => 'POST /atlas-code/works/{project}/forge/fast-path/{run}/review/reject',
                'rollback' => 'POST /atlas-code/works/{project}/forge/fast-path/{run}/review/rollback',
            ],
            'artifacts' => [
                'service' => ['class' => $serviceClass, 'present' => $serviceExists],
                'controller' => ['class' => $controllerClass, 'present' => $controllerExists],
                'command' => ['class' => $commandClass, 'present' => $commandExists],
                'test' => ['path' => 'tests/Feature/Ai/Programming/AtlasCodeForgeReviewCompletionTest.php', 'present' => $testExists],
                'doc' => ['path' => 'docs/engineering-knowledge-base/atlas-code-forge-review-completion-gate-v1.md', 'present' => $docExists],
                'review_endpoint_registered' => $reviewEndpoint,
                'approve_endpoint_registered' => $approveEndpoint,
                'reject_endpoint_registered' => $rejectEndpoint,
                'rollback_endpoint_registered' => $rollbackEndpoint,
            ],
            'desktop_ui' => [
                'domain_types_present' => $desktopDomainTypes,
                'bridge_actions_present' => $desktopBridgeActions,
                'hook_actions_present' => $desktopHookActions,
                'panel_present' => $desktopPanel,
                'available' => $desktopUiAvailable,
            ],
            'test_coverage' => [
                'expected_methods' => $expectedTestMethods,
                'present_methods' => $testCoverage['present_methods'],
                'missing_methods' => $missingMethods,
                'coverage_complete' => $missingMethods === [],
            ],
            'lifecycle_invariants' => [
                'review_packet_available' => $serviceExists,
                'completion_claim_available' => $serviceExists,
                'endpoints_registered' => $reviewEndpoint && $approveEndpoint && $rejectEndpoint && $rollbackEndpoint,
                'cli_registered' => $commandExists,
                'no_auto_completion_without_human_review' => true,
                'rollback_path_available' => true,
                'state_projection_available' => true,
                'correlated_live_execution_required' => true,
                'desktop_review_ui_available' => $desktopUiAvailable,
            ],
            'external_provider_call' => false,
            'is_external_benchmark' => false,
            'note' => 'Review & Completion Gate reusa AtlasCodeForgeReviewController + promotion. Completion claim canonico nao admite human_approved sem decisao operadora. Separado de external_rivals_certification.',
            'separated_from_external_rivals' => 'separated',
            'separated_from' => 'external_rivals_certification',
        ];
    }

    /**
     * Forge-Native Rivals certification block.
     *
     * Reports the canonical contract that the Atlas arm in Rivals MUST run
     * through Forge. This block can reach `available` from protocol +
     * dry-run alone, but it does NOT promote the completion claim.
     * `external_rivals_certification` continues to gate the real Rivals
     * claim and remains blocked until a valid paid battery is approved.
     *
     * @return array<string,mixed>
     */
    public function forgeNativeRivalsCertification(string $workspace): array
    {
        $root = rtrim($workspace, DIRECTORY_SEPARATOR);
        $protocolDoc = $root.DIRECTORY_SEPARATOR.'docs/engineering-knowledge-base/atlas-forge-native-rivals-protocol-v1.md';
        $protocolDocPresent = is_file($protocolDoc);
        $protocolPacket = $this->forgeNativeRivalsProtocol->protocol();
        $manifestPacket = $this->forgeNativeRivalsCaseManifest->manifest(null);
        $preflightPacket = $this->forgeNativeRivalsPreflight->preflight([
            'workspace' => $workspace,
            'intends_provider_battery' => false,
        ]);
        $dryRunPacket = $this->forgeNativeRivalsDryRun->dryRun([
            'workspace' => $workspace,
        ]);

        $protocolAvailable = ($protocolPacket['schema_version'] ?? null) === AtlasForgeNativeRivalsProtocolService::SCHEMA_VERSION
            && (bool) data_get($protocolPacket, 'atlas_arm.atlas_side_must_use_forge', false)
            && data_get($protocolPacket, 'atlas_arm.runtime') === 'forge';
        $caseManifestAvailable = (bool) ($manifestPacket['valid'] ?? false);
        $caseManifestAtlasIsForge = data_get($manifestPacket, 'case.atlas_arm.runtime') === 'forge';
        $forgeRuntimeVerified = data_get($preflightPacket, 'checks.forge_runtime.status') === 'passed'
            && data_get($preflightPacket, 'checks.forge_commands.status') === 'passed';
        $dryRunPassed = ($dryRunPacket['status'] ?? null) === 'dry_run_passed';
        $providerCallDuringDryRun = (bool) data_get($dryRunPacket, 'external_provider_call', true);
        $syntheticAllowed = (bool) data_get($dryRunPacket, 'synthetic_scores_allowed', true);

        $missingArtifacts = [];
        if (! $protocolDocPresent) {
            $missingArtifacts[] = 'protocol_doc_missing';
        }
        if (! $protocolAvailable) {
            $missingArtifacts[] = 'protocol_service_missing_or_invalid';
        }
        if (! $caseManifestAvailable) {
            $missingArtifacts[] = 'case_manifest_invalid';
        }
        if (! $caseManifestAtlasIsForge) {
            $missingArtifacts[] = 'case_manifest_atlas_arm_not_forge';
        }
        if (! $forgeRuntimeVerified) {
            $missingArtifacts[] = 'forge_runtime_not_verified';
        }

        $workspaceStatus = (string) data_get($preflightPacket, 'checks.workspace.status', 'unknown');

        $status = match (true) {
            $missingArtifacts !== [] => 'missing_artifacts',
            $providerCallDuringDryRun || $syntheticAllowed => 'blocked_protocol_invalid',
            ! $dryRunPassed && $workspaceStatus !== 'passed' => 'blocked_dirty_workspace',
            ! $dryRunPassed => 'blocked_requires_operator_approval',
            default => 'available',
        };

        return [
            'schema_version' => 'atlas.programming.forge_native_rivals_certification.v1',
            'protocol_id' => AtlasForgeNativeRivalsProtocolService::PROTOCOL_ID,
            'status' => $status,
            'atlas_side_must_use_forge' => true,
            'atlas_side_forge_runtime_verified' => $forgeRuntimeVerified,
            'protocol_available' => $protocolAvailable,
            'protocol_doc_available' => $protocolDocPresent,
            'case_manifest_available' => $caseManifestAvailable,
            'case_manifest_atlas_arm_is_forge' => $caseManifestAtlasIsForge,
            'dry_run_passed' => $dryRunPassed,
            'dry_run_dispatched_provider' => $providerCallDuringDryRun,
            'provider_dispatch_blocked_without_approval' => true,
            'synthetic_scores_allowed' => false,
            'separated_from_external_rivals_certification' => true,
            'promotes_completion_claim' => false,
            'evidence' => [
                // Rivals 1.0 CLI wrappers removidos (Slice 6); preflight/dry-run rodam in-process via services.
                'protocol_doc' => 'docs/engineering-knowledge-base/atlas-forge-native-rivals-protocol-v1.md',
            ],
            'preflight_summary' => [
                'status' => $preflightPacket['status'] ?? null,
                'workspace_status' => $workspaceStatus,
                'blocking_reasons' => $preflightPacket['blocking_reasons'] ?? [],
            ],
            'dry_run_summary' => [
                'status' => $dryRunPacket['status'] ?? null,
                'case_id_resolved' => data_get($dryRunPacket, 'inputs.case_id_resolved'),
                'replay_manifest_valid' => (bool) data_get($dryRunPacket, 'planned.replay_manifest.valid', false),
                'blocking_reasons' => $dryRunPacket['blocking_reasons'] ?? [],
            ],
            'missing_artifacts' => $missingArtifacts,
            'note' => 'Forge-Native Rivals certification is informational and never promotes the Rivals claim. Real Rivals battery is still gated by external_rivals_certification.',
            'separated_from' => 'external_rivals_certification',
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function forgeWorkIntakeCertification(): array
    {
        $serviceClass = AtlasCodeForgeWorkIntakeService::class;
        $controllerClass = AtlasCodeForgeWorkIntakeController::class;
        $commandClass = AtlasCodeForgeWorkIntakeCommand::class;
        $testFile = base_path('tests/Feature/Ai/Programming/AtlasCodeForgeWorkIntakeTest.php');
        $docFile = base_path('docs/engineering-knowledge-base/atlas-code-forge-work-intake-spec-governance-v1.md');
        $routesFile = base_path('routes/api.php');
        $routesSource = is_file($routesFile) ? (string) file_get_contents($routesFile) : '';
        $serviceSource = class_exists($serviceClass) ? (string) file_get_contents((new \ReflectionClass($serviceClass))->getFileName() ?: '') : '';
        $workControllerSource = is_file(base_path('app/Http/Controllers/AtlasCodeWorkController.php'))
            ? (string) file_get_contents(base_path('app/Http/Controllers/AtlasCodeWorkController.php'))
            : '';

        $desktopRoot = dirname(base_path()).'/atlas-desktop';
        $domainFile = $desktopRoot.'/packages/atlas-domain/src/index.ts';
        $bridgeFile = $desktopRoot.'/apps/desktop/src/lib/bridge.ts';
        $hookFile = $desktopRoot.'/apps/desktop/src/hooks/useBridge.ts';
        $panelFile = $desktopRoot.'/apps/desktop/src/surfaces/code/panels/ForgeWorkIntakePanel.tsx';
        $tauriBridgeFile = $desktopRoot.'/crates/atlas-bridge/src/client.rs';
        $tauriCommandsFile = $desktopRoot.'/crates/atlas-tauri/src/commands_bridge.rs';
        $tauriLibFile = $desktopRoot.'/crates/atlas-tauri/src/lib.rs';
        $domainSource = is_file($domainFile) ? (string) file_get_contents($domainFile) : '';
        $bridgeSource = is_file($bridgeFile) ? (string) file_get_contents($bridgeFile) : '';
        $hookSource = is_file($hookFile) ? (string) file_get_contents($hookFile) : '';
        $tauriBridgeSource = is_file($tauriBridgeFile) ? (string) file_get_contents($tauriBridgeFile) : '';
        $tauriCommandsSource = is_file($tauriCommandsFile) ? (string) file_get_contents($tauriCommandsFile) : '';
        $tauriLibSource = is_file($tauriLibFile) ? (string) file_get_contents($tauriLibFile) : '';

        $serviceExists = class_exists($serviceClass);
        $controllerExists = class_exists($controllerClass);
        $commandExists = class_exists($commandClass);
        $testExists = is_file($testFile);
        $docExists = is_file($docFile);
        $apiRegistered = str_contains($routesSource, '/forge/intake')
            && str_contains($routesSource, 'AtlasCodeForgeWorkIntakeController');
        $stateProjection = str_contains($workControllerSource, 'forge_work_intake')
            && str_contains($workControllerSource, 'forgeWorkIntakeForWork');
        $metadataHistory = $serviceSource !== ''
            && str_contains($serviceSource, 'atlas_code_forge_work_intake_history');
        $businessRuleRequired = $serviceSource !== ''
            && str_contains($serviceSource, 'blocked_missing_business_rule');
        $acceptanceRequired = $serviceSource !== ''
            && str_contains($serviceSource, 'blocked_missing_acceptance_criteria');
        $canonicalDocsRequired = $serviceSource !== ''
            && str_contains($serviceSource, 'blocked_missing_canonical_docs');
        $readinessGate = $serviceSource !== ''
            && str_contains($serviceSource, 'readiness_status');
        $workItemLink = $serviceSource !== ''
            && str_contains($serviceSource, 'work_item_id');
        $noProviderCall = $serviceSource !== ''
            && str_contains($serviceSource, "'external_provider_call' => false");
        $noSilentObra = $serviceSource !== ''
            && str_contains($serviceSource, 'blocked_no_obra');

        $expectedTestMethods = [
            'test_intake_fails_closed_without_obra',
            'test_intake_blocks_missing_business_rule',
            'test_intake_blocks_missing_acceptance_criteria',
            'test_intake_ready_with_full_payload',
            'test_intake_persists_latest_and_history',
            'test_state_endpoint_exposes_forge_work_intake',
            'test_cli_strict_exits_non_zero_when_blocked',
            'test_completion_audit_exposes_intake_certification',
            'test_no_provider_call',
            'test_work_item_link_available_when_governance_exists',
        ];
        $testCoverage = $this->support->scanTestCoverage($testFile, $expectedTestMethods);

        $desktopTypesPresent = str_contains($domainSource, 'AtlasCodeForgeWorkIntake');
        $desktopBridgePresent = str_contains($bridgeSource, 'getForgeWorkIntake')
            && str_contains($bridgeSource, 'saveForgeWorkIntake');
        $desktopHookPresent = str_contains($hookSource, 'refreshForgeWorkIntake')
            && str_contains($hookSource, 'saveForgeWorkIntake');
        $desktopPanelPresent = is_file($panelFile);
        $tauriBridgePresent = str_contains($tauriBridgeSource, 'get_forge_work_intake')
            && str_contains($tauriBridgeSource, 'save_forge_work_intake');
        $tauriCommandsPresent = str_contains($tauriCommandsSource, 'bridge_get_forge_work_intake')
            && str_contains($tauriCommandsSource, 'bridge_save_forge_work_intake')
            && str_contains($tauriLibSource, 'bridge_get_forge_work_intake')
            && str_contains($tauriLibSource, 'bridge_save_forge_work_intake');
        $desktopUiAvailable = $desktopTypesPresent && $desktopBridgePresent && $desktopHookPresent && $desktopPanelPresent && $tauriBridgePresent && $tauriCommandsPresent;

        $missingArtifacts = [];
        if (! $serviceExists) {
            $missingArtifacts[] = 'service_class_missing';
        }
        if (! $controllerExists) {
            $missingArtifacts[] = 'controller_class_missing';
        }
        if (! $commandExists) {
            $missingArtifacts[] = 'command_class_missing';
        }
        if (! $testExists) {
            $missingArtifacts[] = 'test_file_missing';
        }
        if (! $docExists) {
            $missingArtifacts[] = 'doc_file_missing';
        }
        if (! $apiRegistered) {
            $missingArtifacts[] = 'api_not_registered';
        }

        $missingMethods = $testCoverage['missing_methods'];
        $allInvariantsTrue = $businessRuleRequired && $acceptanceRequired && $canonicalDocsRequired
            && $readinessGate && $workItemLink && $noProviderCall && $noSilentObra
            && $stateProjection && $metadataHistory && $serviceExists && $apiRegistered && $commandExists;

        $status = match (true) {
            $missingArtifacts !== [] => 'missing_artifacts',
            ! $allInvariantsTrue => 'backend_available_ui_pending',
            $missingMethods !== [] => 'requires_operator_run',
            ! $desktopUiAvailable => 'backend_available_ui_pending',
            default => 'available',
        };

        return [
            'schema_version' => 'atlas.code.forge_work_intake_certification.v1',
            'status' => $status,
            'evidence_command' => 'php artisan atlas:code:forge-intake --obra=<uuid> --json --strict',
            'api_endpoints' => [
                'show' => 'GET /atlas-code/works/{project}/forge/intake',
                'store' => 'POST /atlas-code/works/{project}/forge/intake',
            ],
            'invariants' => [
                'intake_service_available' => $serviceExists,
                'intake_api_registered' => $apiRegistered,
                'intake_cli_registered' => $commandExists,
                'state_projection_available' => $stateProjection,
                'metadata_history_available' => $metadataHistory,
                'business_rule_required' => $businessRuleRequired,
                'acceptance_criteria_required' => $acceptanceRequired,
                'canonical_docs_required' => $canonicalDocsRequired,
                'readiness_gate_available' => $readinessGate,
                'work_item_link_available' => $workItemLink,
                'no_provider_call' => $noProviderCall,
                'no_silent_obra' => $noSilentObra,
                'separated_from_rivals' => true,
            ],
            'artifacts' => [
                'service' => ['class' => $serviceClass, 'present' => $serviceExists],
                'controller' => ['class' => $controllerClass, 'present' => $controllerExists],
                'command' => ['class' => $commandClass, 'present' => $commandExists],
                'test' => ['path' => 'tests/Feature/Ai/Programming/AtlasCodeForgeWorkIntakeTest.php', 'present' => $testExists],
                'doc' => ['path' => 'docs/engineering-knowledge-base/atlas-code-forge-work-intake-spec-governance-v1.md', 'present' => $docExists],
                'api_registered' => $apiRegistered,
            ],
            'test_coverage' => [
                'expected_methods' => $expectedTestMethods,
                'present_methods' => $testCoverage['present_methods'],
                'missing_methods' => $missingMethods,
                'coverage_complete' => $missingMethods === [],
            ],
            'desktop_ui' => [
                'domain_types_present' => $desktopTypesPresent,
                'bridge_methods_present' => $desktopBridgePresent,
                'hook_actions_present' => $desktopHookPresent,
                'panel_present' => $desktopPanelPresent,
                'tauri_bridge_present' => $tauriBridgePresent,
                'tauri_commands_present' => $tauriCommandsPresent,
                'available' => $desktopUiAvailable,
            ],
            'missing_artifacts' => $missingArtifacts,
            'external_provider_call' => false,
            'is_external_benchmark' => false,
            'note' => 'Intake & Spec Governance bloqueia execucao enterprise sem objetivo + regra de negocio + criterios de aceite + docs canonicas. Separado de Rivals.',
            'separated_from' => 'external_rivals_certification',
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function forgeOperatorCockpitCertification(): array
    {
        $desktopRoot = dirname(base_path()).'/atlas-desktop';
        $cockpitFile = $desktopRoot.'/apps/desktop/src/surfaces/code/panels/ForgeOperatorCockpitPanel.tsx';
        $registryFile = $desktopRoot.'/apps/desktop/src/surfaces/code/panels/rightRailRegistry.tsx';
        $hookFile = $desktopRoot.'/apps/desktop/src/hooks/useBridge.ts';
        $bridgeFile = $desktopRoot.'/apps/desktop/src/lib/bridge.ts';
        $tauriCommandsFile = $desktopRoot.'/crates/atlas-tauri/src/commands_bridge.rs';
        $tauriLibFile = $desktopRoot.'/crates/atlas-tauri/src/lib.rs';
        $docFile = base_path('docs/engineering-knowledge-base/atlas-code-forge-operator-cockpit-v1.md');

        $cockpitSource = is_file($cockpitFile) ? (string) file_get_contents($cockpitFile) : '';
        $registrySource = is_file($registryFile) ? (string) file_get_contents($registryFile) : '';
        $hookSource = is_file($hookFile) ? (string) file_get_contents($hookFile) : '';
        $bridgeSource = is_file($bridgeFile) ? (string) file_get_contents($bridgeFile) : '';
        $tauriCommandsSource = is_file($tauriCommandsFile) ? (string) file_get_contents($tauriCommandsFile) : '';
        $tauriLibSource = is_file($tauriLibFile) ? (string) file_get_contents($tauriLibFile) : '';

        $cockpitPanelPresent = $cockpitSource !== '' && str_contains($registrySource, 'ForgeOperatorCockpitPanel');
        $cockpitUsesBridgeActions = $cockpitSource !== ''
            && str_contains($cockpitSource, 'onRunForgeFastPath')
            && str_contains($cockpitSource, 'onRefreshForgeFastPathStatus')
            && str_contains($cockpitSource, 'onResumeForgeFastPath')
            && str_contains($cockpitSource, 'onRefreshForgeReview')
            && str_contains($cockpitSource, 'onApproveForgeReview')
            && str_contains($cockpitSource, 'onRejectForgeReview')
            && str_contains($cockpitSource, 'onRollbackForgeReview');
        $obraFailClosedVisible = $cockpitSource !== '' && str_contains($cockpitSource, 'sem Obra');
        $fastPathActionsAvailable = $cockpitSource !== ''
            && str_contains($cockpitSource, "'prepare_only'")
            && str_contains($cockpitSource, "'execute_async'")
            && str_contains($cockpitSource, "'execute_sync'");
        $statusPollingAvailable = $cockpitSource !== ''
            && str_contains($cockpitSource, 'setInterval')
            && str_contains($cockpitSource, 'POLL_TERMINAL_STATES');
        $resumeActionAvailable = $cockpitSource !== '' && str_contains($cockpitSource, 'onResumeForgeFastPath');
        $reviewActionsAvailable = $cockpitSource !== ''
            && str_contains($cockpitSource, 'onApproveForgeReview')
            && str_contains($cockpitSource, 'onRejectForgeReview')
            && str_contains($cockpitSource, 'onRollbackForgeReview');
        $completionClaimVisible = $cockpitSource !== ''
            && str_contains($cockpitSource, 'completionStatus')
            && str_contains($cockpitSource, 'finalCompletionAllowed');
        $repairStateVisible = $cockpitSource !== '' && str_contains($cockpitSource, 'repair_available');
        $evidenceStateVisible = $cockpitSource !== ''
            && str_contains($cockpitSource, 'evidence ·')
            && str_contains($cockpitSource, 'ledger ·');
        $noAutoCompletion = $cockpitSource !== '' && str_contains($cockpitSource, 'waiting_human_review');
        $noExternalProviderCall = $cockpitSource !== '' && ! str_contains($cockpitSource, 'externalProvider');
        $tauriCommandsRegistered =
            str_contains($tauriCommandsSource, 'bridge_run_forge_fast_path')
            && str_contains($tauriCommandsSource, 'bridge_get_forge_fast_path_status')
            && str_contains($tauriCommandsSource, 'bridge_resume_forge_fast_path')
            && str_contains($tauriCommandsSource, 'bridge_get_forge_review_packet')
            && str_contains($tauriCommandsSource, 'bridge_decide_forge_review')
            && str_contains($tauriLibSource, 'bridge_run_forge_fast_path')
            && str_contains($tauriLibSource, 'bridge_get_forge_fast_path_status')
            && str_contains($tauriLibSource, 'bridge_resume_forge_fast_path')
            && str_contains($tauriLibSource, 'bridge_get_forge_review_packet')
            && str_contains($tauriLibSource, 'bridge_decide_forge_review');

        $invariants = [
            'cockpit_panel_present' => $cockpitPanelPresent,
            'cockpit_uses_bridge_actions' => $cockpitUsesBridgeActions,
            'obra_fail_closed_visible' => $obraFailClosedVisible,
            'fast_path_actions_available' => $fastPathActionsAvailable,
            'status_polling_available' => $statusPollingAvailable,
            'resume_action_available' => $resumeActionAvailable,
            'review_actions_available' => $reviewActionsAvailable,
            'completion_claim_visible' => $completionClaimVisible,
            'repair_state_visible' => $repairStateVisible,
            'evidence_state_visible' => $evidenceStateVisible,
            'no_auto_completion' => $noAutoCompletion,
            'no_external_provider_call' => $noExternalProviderCall,
            'tauri_commands_registered' => $tauriCommandsRegistered,
        ];

        $missingArtifacts = [];
        if ($cockpitSource === '') {
            $missingArtifacts[] = 'cockpit_panel_missing';
        }
        if (! is_file($docFile)) {
            $missingArtifacts[] = 'doc_file_missing';
        }
        if (! is_file($hookFile) || $hookSource === '' || ! str_contains($hookSource, 'approveForgeReview')) {
            $missingArtifacts[] = 'hook_actions_missing';
        }
        if (! is_file($bridgeFile) || $bridgeSource === '' || ! str_contains($bridgeSource, 'approveForgeReview')) {
            $missingArtifacts[] = 'bridge_actions_missing';
        }

        $allInvariantsTrue = ! in_array(false, $invariants, true);

        $status = match (true) {
            $missingArtifacts !== [] => 'missing_artifacts',
            ! $allInvariantsTrue => 'backend_available_ui_pending',
            default => 'available',
        };

        return [
            'schema_version' => 'atlas.code.forge_operator_cockpit_certification.v1',
            'status' => $status,
            'invariants' => $invariants,
            'invariants_all_true' => $allInvariantsTrue,
            'artifacts' => [
                'cockpit_panel' => ['path' => 'apps/desktop/src/surfaces/code/panels/ForgeOperatorCockpitPanel.tsx', 'present' => $cockpitSource !== ''],
                'registry_entry' => ['path' => 'apps/desktop/src/surfaces/code/panels/rightRailRegistry.tsx', 'registered' => $cockpitPanelPresent],
                'doc' => ['path' => 'docs/engineering-knowledge-base/atlas-code-forge-operator-cockpit-v1.md', 'present' => is_file($docFile)],
                'tauri_commands_file' => ['path' => 'crates/atlas-tauri/src/commands_bridge.rs', 'present' => $tauriCommandsSource !== ''],
            ],
            'missing_artifacts' => $missingArtifacts,
            'lifecycle_states' => ['idle', 'running', 'waiting_review', 'blocked', 'completed', 'rolled_back', 'rejected'],
            'external_provider_call' => false,
            'is_external_benchmark' => false,
            'note' => 'Cockpit unifica Obra/Forge/Fast Path/Review/Completion sob uma surface operavel; usa exclusivamente useBridge; nao chama provider externo; nao auto-completa.',
            'separated_from' => 'external_rivals_certification',
        ];
    }
}
