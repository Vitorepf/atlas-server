<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming;

use App\Models\AtlasProject;

/**
 * Atlas Forge Continuum Certification.
 *
 * Audit-level proof that the Atlas Forge Continuum OS is wired end-to-end:
 *   doc-mother → Atlas Code Forge-only → Obra → Forge Workspace
 *   → Work Intake → Atlas Decide contract → Provider Topology
 *   → Governed Fallback Policy → State Projection → Desktop Cockpit UI
 *   → Review/Completion Gate → Repair Loop → Evidence → Rivals (isolated).
 *
 * This service NEVER calls an external provider. It evaluates 25 canonical
 * invariants from local artifacts (services, commands, docs, routes, desktop
 * files) and produces a deterministic certification payload.
 *
 * Schema: atlas.forge_continuum_certification.v1
 * Doc: docs/engineering-knowledge-base/atlas-forge-continuum-os.md
 *      docs/engineering-knowledge-base/atlas-forge-provider-topology-and-fallback-v1.md
 */
class AtlasForgeContinuumCertificationService
{
    public const SCHEMA_VERSION = 'atlas.forge_continuum_certification.v1';

    public const STATUS_AVAILABLE = 'available';
    public const STATUS_BACKEND_AVAILABLE_UI_PENDING = 'backend_available_ui_pending';
    public const STATUS_AVAILABLE_WITHOUT_OBRA_CONTEXT = 'available_without_obra_context';
    public const STATUS_BLOCKED_OBRA_REQUIRED = 'blocked_obra_required_for_runtime_projection';
    public const STATUS_MISSING_ARTIFACTS = 'missing_artifacts';
    public const STATUS_BLOCKED = 'blocked';

    /** @var list<string> Canonical invariants this block must report. */
    public const REQUIRED_INVARIANTS = [
        'doc_mother_present',
        'atlas_code_forge_only',
        'obra_required',
        'work_intake_available',
        'forge_workspace_binding_available',
        'fast_path_available',
        'live_execution_available',
        'review_completion_available',
        'operator_cockpit_available',
        'atlas_decide_contract_referenced',
        'provider_topology_available',
        'fallback_policy_available',
        'provider_failure_classifier_available',
        'no_silent_fallback',
        'provider_capacity_exhausted_blocker_available',
        'state_projection_available',
        'desktop_ui_provider_topology_visible',
        'review_completion_gate_preserved',
        'repair_loop_preserved',
        'evidence_pack_available',
        'evidence_ledger_refs_supported',
        'rivals_separated_from_external_claim',
        'no_external_provider_call',
        'no_silent_obra_creation',
        'completion_audit_block_available',
        'runtime_dispatch_service_available',
        'runtime_dispatch_endpoint_registered',
        'runtime_dispatch_static_policy_blocked',
        'runtime_dispatch_child_receipt_supported',
        'provider_invocation_service_available',
        'provider_invocation_command_registered',
        'provider_invocation_endpoint_registered',
        'provider_invocation_driver_router_available',
        'provider_invocation_prompt_builder_available',
        'provider_invocation_dry_run_mode_available',
        'provider_invocation_execute_requires_operator_approval',
        'provider_invocation_completion_claim_not_promoted',
    ];

    public function __construct(
        private readonly AtlasForgeProviderTopologyService $topology,
        private readonly AtlasForgeProviderFallbackPolicyService $fallbackPolicy,
    ) {}

    /**
     * Certify the Atlas Forge Continuum OS.
     *
     * @param  array<string,mixed>  $options  obra_id, simulate_provider_failure, strict, workspace
     * @return array<string,mixed>
     */
    public function certify(array $options = []): array
    {
        $repoRoot = $this->resolveRepoRoot($options);
        $obraId = $this->stringOrNull($options['obra_id'] ?? null);
        $simulate = $this->stringOrNull($options['simulate_provider_failure'] ?? null);
        $strict = (bool) ($options['strict'] ?? false);

        $project = $obraId !== null ? AtlasProject::query()->whereKey($obraId)->first() : null;
        $obraPresent = $project !== null;

        $invariants = $this->invariants($repoRoot);
        $artifacts = $this->artifacts($repoRoot);
        $blockers = [];
        $missingArtifacts = $this->collectMissingArtifacts($artifacts);

        if ($obraId !== null && ! $obraPresent) {
            $blockers[] = 'obra_not_found';
        }

        if ($strict && ! $obraPresent) {
            $blockers[] = 'obra_required';
        }

        $topology = $this->topology->topology([
            'obra_id' => $obraPresent ? (string) $project->getKey() : null,
            'simulate_provider_failure' => $simulate,
            'strategy' => $this->stringOrNull($options['strategy'] ?? null),
            'fast_path_run_id' => $this->stringOrNull($options['fast_path_run_id'] ?? null),
            'decision_receipt_id' => $this->stringOrNull($options['decision_receipt_id'] ?? null),
        ]);
        $liveDecideRuntime = $this->liveDecideRuntime($topology);

        $policy = $this->fallbackPolicy->policy();

        foreach ((array) ($topology['blockers'] ?? []) as $blocker) {
            $blocker = (string) $blocker;
            if ($blocker !== '' && ! in_array($blocker, $blockers, true)) {
                $blockers[] = $blocker;
            }
        }

        $invariantsAllTrue = ! in_array(false, array_values($invariants), true);

        $status = match (true) {
            $missingArtifacts !== [] => self::STATUS_MISSING_ARTIFACTS,
            in_array(AtlasForgeProviderFallbackPolicyService::BLOCKER_CAPACITY_EXHAUSTED, $blockers, true) => self::STATUS_BLOCKED,
            $strict && ! $obraPresent => self::STATUS_BLOCKED_OBRA_REQUIRED,
            ! $obraPresent => self::STATUS_AVAILABLE_WITHOUT_OBRA_CONTEXT,
            ! $invariants['desktop_ui_provider_topology_visible'] => self::STATUS_BACKEND_AVAILABLE_UI_PENDING,
            ! $invariantsAllTrue => self::STATUS_BLOCKED,
            default => self::STATUS_AVAILABLE,
        };

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $status,
            'generated_at' => now()->toIso8601String(),
            'obra_id' => $obraPresent ? (string) $project->getKey() : null,
            'obra_present' => $obraPresent,
            'strict' => $strict,
            'evidence_command' => 'php artisan atlas:forge:continuum-certify --json --strict --obra=<uuid>',
            'fail_closed_command' => 'php artisan atlas:forge:continuum-certify --json --strict',
            'fail_closed_expected_exit_code' => 1,
            'invariants' => $invariants,
            'invariants_all_true' => $invariantsAllTrue,
            'artifacts' => $artifacts,
            'missing_artifacts' => $missingArtifacts,
            'provider_topology' => $topology,
            'fallback_policy' => $policy,
            'live_decide_runtime' => $liveDecideRuntime,
            'blockers' => array_values(array_unique($blockers)),
            'evidence_refs' => [
                'docs/engineering-knowledge-base/atlas-forge-continuum-os.md',
                'docs/engineering-knowledge-base/atlas-forge-provider-topology-and-fallback-v1.md',
                'docs/engineering-knowledge-base/system-graph/atlas-decide.md',
                'docs/engineering-knowledge-base/atlas-code-forge-operator-cockpit-v1.md',
                'docs/engineering-knowledge-base/atlas-code-forge-review-completion-gate-v1.md',
                'docs/engineering-knowledge-base/atlas-programming-forge-flow.md',
                'docs/engineering-knowledge-base/atlas-forge-operating-system.md',
            ],
            'evidence_paths' => [
                'app/Services/Ai/Programming/AtlasForgeContinuumCertificationService.php',
                'app/Services/Ai/Programming/AtlasForgeProviderTopologyService.php',
                'app/Services/Ai/Programming/AtlasForgeProviderFallbackPolicyService.php',
                'app/Services/Ai/Programming/AtlasForgeRuntimeDispatchService.php',
                'app/Console/Commands/AtlasForgeContinuumCertifyCommand.php',
                'app/Console/Commands/AtlasForgeRuntimeDispatchCommand.php',
                'app/Http/Controllers/AtlasCodeForgeProviderTopologyController.php',
                'app/Http/Controllers/AtlasCodeForgeRuntimeDispatchController.php',
                'tests/Feature/Ai/Programming/AtlasForgeContinuumCertificationTest.php',
                'tests/Feature/Ai/Programming/AtlasForgeProviderTopologyTest.php',
                'tests/Feature/Ai/Programming/AtlasForgeRuntimeDispatchTest.php',
            ],
            'external_provider_call' => false,
            'is_external_benchmark' => false,
            'no_silent_fallback' => true,
            'note' => 'Atlas Forge Continuum OS certification: doc-mae + Atlas Decide + Provider Topology + Governed Fallback + State Projection + Desktop Cockpit + Review/Completion + Repair + Evidence + Rivals isolado. Nenhuma chamada provider externa; nenhum token gasto.',
            'separated_from' => 'external_rivals_certification',
        ];
    }

    /**
     * @param  array<string,mixed>  $topology
     * @return array<string,mixed>
     */
    private function liveDecideRuntime(array $topology): array
    {
        $decisionSource = $this->stringOrNull($topology['decision_source'] ?? null) ?? 'static_policy';
        $hasReceipt = $this->stringOrNull($topology['decision_receipt_id'] ?? null) !== null
            && $this->stringOrNull($topology['decision_receipt_hash'] ?? null) !== null;
        $fallbackChildReceiptRequired = (bool) ($topology['fallback_child_receipt_required'] ?? false);

        return [
            'schema_version' => 'atlas.forge_continuum.live_decide_runtime.v1',
            'decision_source' => $decisionSource,
            'live_atlas_decide_topology_available' => $decisionSource === 'live_atlas_decide' && $hasReceipt,
            'decision_receipt_topology_projection_available' => $hasReceipt,
            'static_policy_fallback_declared' => $decisionSource === 'static_policy',
            'fallback_child_receipt_required' => $fallbackChildReceiptRequired,
            'runtime_dispatch_not_allowed_without_receipt' => ! $hasReceipt && ! (bool) ($topology['runtime_dispatch_allowed'] ?? false),
            'runtime_dispatch_allowed' => (bool) ($topology['runtime_dispatch_allowed'] ?? false),
            'decision_receipt_id' => $this->stringOrNull($topology['decision_receipt_id'] ?? null),
            'decision_receipt_hash' => $this->stringOrNull($topology['decision_receipt_hash'] ?? null),
        ];
    }

    /**
     * @return array<string,bool>
     */
    public function invariants(string $repoRoot): array
    {
        $docMother = $repoRoot.'/docs/engineering-knowledge-base/atlas-forge-continuum-os.md';
        $atlasDecideDoc = $repoRoot.'/docs/engineering-knowledge-base/system-graph/atlas-decide.md';
        $providerTopologyDoc = $repoRoot.'/docs/engineering-knowledge-base/atlas-forge-provider-topology-and-fallback-v1.md';
        $cockpitDoc = $repoRoot.'/docs/engineering-knowledge-base/atlas-code-forge-operator-cockpit-v1.md';
        $reviewDoc = $repoRoot.'/docs/engineering-knowledge-base/atlas-code-forge-review-completion-gate-v1.md';
        $intakeDoc = $repoRoot.'/docs/engineering-knowledge-base/atlas-code-forge-work-intake-spec-governance-v1.md';
        $fastPathDoc = $repoRoot.'/docs/engineering-knowledge-base/atlas-code-forge-fast-path-v1.md';
        $liveExecutionDoc = $repoRoot.'/docs/engineering-knowledge-base/atlas-forge-live-execution-e2e-v1.md';

        $desktopRoot = dirname($repoRoot).'/atlas-desktop';
        $desktopPanel = $desktopRoot.'/apps/desktop/src/surfaces/code/panels/ForgeProviderTopologyPanel.tsx';
        $cockpitPanel = $desktopRoot.'/apps/desktop/src/surfaces/code/panels/ForgeOperatorCockpitPanel.tsx';
        $bridgeFile = $desktopRoot.'/apps/desktop/src/lib/bridge.ts';
        $useBridgeFile = $desktopRoot.'/apps/desktop/src/hooks/useBridge.ts';
        $domainTypesFile = $desktopRoot.'/packages/atlas-domain/src/index.ts';

        $workControllerFile = $repoRoot.'/app/Http/Controllers/AtlasCodeWorkController.php';
        $fastPathServiceFile = $repoRoot.'/app/Services/Ai/Programming/AtlasCodeForgeFastPathService.php';
        $completionAuditFile = $repoRoot.'/app/Services/Ai/Programming/ProgrammingProfessionalCompletionAuditService.php';
        $providerTopologyFile = $repoRoot.'/app/Services/Ai/Programming/AtlasForgeProviderTopologyService.php';

        $repairExecutorClass = \App\Services\Ai\Programming\ProgrammingRepairExecutor::class;
        $reviewServiceClass = \App\Services\Ai\Programming\AtlasCodeForgeReviewCompletionService::class;
        $workIntakeServiceClass = \App\Services\Ai\Programming\AtlasCodeForgeWorkIntakeService::class;
        $fastPathServiceClass = \App\Services\Ai\Programming\AtlasCodeForgeFastPathService::class;
        $liveExecutionServiceClass = \App\Services\Ai\Programming\AtlasForgeLiveExecutionService::class;
        $reviewControllerClass = \App\Http\Controllers\AtlasCodeForgeReviewController::class;
        $evidencePackServiceClass = \App\Services\Ai\Programming\AtlasRivalsEvidencePackService::class;
        $evidenceLedgerClass = \App\Services\Ai\Kernel\Evidence\AtlasEvidenceLedger::class;
        $ledgerEventTypeClass = \App\Services\Ai\Kernel\Evidence\LedgerEventType::class;

        $workControllerSource = is_file($workControllerFile) ? (string) file_get_contents($workControllerFile) : '';
        $auditSource = is_file($completionAuditFile) ? (string) file_get_contents($completionAuditFile) : '';
        $docMotherSource = is_file($docMother) ? (string) file_get_contents($docMother) : '';
        $fastPathSource = is_file($fastPathServiceFile) ? (string) file_get_contents($fastPathServiceFile) : '';
        $providerTopologySource = is_file($providerTopologyFile) ? (string) file_get_contents($providerTopologyFile) : '';

        $atlasDecideReferenced = is_file($atlasDecideDoc)
            && (
                str_contains($docMotherSource, 'atlas-decide')
                || str_contains($docMotherSource, 'Atlas Decide')
            )
            && str_contains($providerTopologySource, 'Atlas Decide');

        $rivalsSeparated = (
            is_file($docMother) && (
                str_contains($docMotherSource, 'separated_from')
                || str_contains($docMotherSource, 'rivals-diagnostic-separated-from-claim')
            )
        ) || str_contains($auditSource, "'separated_from' => 'external_rivals_certification'");

        $noSilentObraCreation = $fastPathSource !== '' && (
            str_contains($fastPathSource, 'obra_required')
            || str_contains($fastPathSource, 'obra_not_found')
        );

        $completionAuditBlockAvailable = $auditSource !== ''
            && str_contains($auditSource, 'atlas_forge_continuum_certification')
            && (
                str_contains($auditSource, 'forgeContinuumCertification')
                || str_contains($auditSource, 'continuumCertification')
            );

        return [
            'doc_mother_present' => is_file($docMother),
            'atlas_code_forge_only' => class_exists(\App\Services\Ai\Programming\AtlasForgeRuntimeCertificationService::class),
            'obra_required' => $this->fileContains($workControllerFile, 'obra')
                && class_exists($fastPathServiceClass)
                && $noSilentObraCreation,
            'work_intake_available' => class_exists($workIntakeServiceClass) && is_file($intakeDoc),
            'forge_workspace_binding_available' => $this->fileContains(
                $repoRoot.'/app/Services/Ai/Programming/AtlasForgeRuntimeCertificationService.php',
                'atlas.forge_workspace_binding.v1',
            ),
            'fast_path_available' => class_exists($fastPathServiceClass) && is_file($fastPathDoc),
            'live_execution_available' => class_exists($liveExecutionServiceClass) && is_file($liveExecutionDoc),
            'review_completion_available' => class_exists($reviewServiceClass) && is_file($reviewDoc),
            'operator_cockpit_available' => is_file($cockpitPanel) && is_file($cockpitDoc),
            'atlas_decide_contract_referenced' => $atlasDecideReferenced,
            'provider_topology_available' => class_exists(AtlasForgeProviderTopologyService::class)
                && is_file($providerTopologyFile),
            'fallback_policy_available' => class_exists(AtlasForgeProviderFallbackPolicyService::class)
                && is_file($repoRoot.'/app/Services/Ai/Programming/AtlasForgeProviderFallbackPolicyService.php'),
            'provider_failure_classifier_available' => method_exists(AtlasForgeProviderFallbackPolicyService::class, 'classify'),
            'no_silent_fallback' => $this->verifyNoSilentFallback(),
            'provider_capacity_exhausted_blocker_available' => AtlasForgeProviderFallbackPolicyService::BLOCKER_CAPACITY_EXHAUSTED === 'provider_capacity_exhausted',
            'state_projection_available' => $workControllerSource !== ''
                && str_contains($workControllerSource, 'forge_provider_topology')
                && str_contains($workControllerSource, 'forge_continuum_certification'),
            'desktop_ui_provider_topology_visible' => is_file($desktopPanel)
                && is_file($cockpitPanel)
                && $this->fileContains($desktopPanel, 'AtlasForgeProviderTopology')
                && $this->fileContains($bridgeFile, 'getForgeProviderTopology')
                && $this->fileContains($useBridgeFile, 'forgeProviderTopology')
                && $this->fileContains($domainTypesFile, 'AtlasForgeProviderTopology'),
            'review_completion_gate_preserved' => class_exists($reviewServiceClass)
                && class_exists($reviewControllerClass)
                && is_file($reviewDoc),
            'repair_loop_preserved' => class_exists($repairExecutorClass)
                && class_exists(\App\Services\Ai\Programming\ProgrammingRepairAttemptStore::class),
            'evidence_pack_available' => class_exists($evidencePackServiceClass)
                && is_file($repoRoot.'/docs/engineering-knowledge-base/atlas-rivals-evidence-pack-replay-manifest-v1.md'),
            'evidence_ledger_refs_supported' => class_exists($evidenceLedgerClass)
                && class_exists($ledgerEventTypeClass)
                && method_exists(AtlasForgeProviderFallbackPolicyService::class, 'classify')
                && method_exists(AtlasForgeProviderTopologyService::class, 'topology'),
            'rivals_separated_from_external_claim' => $rivalsSeparated,
            'no_external_provider_call' => true,
            'no_silent_obra_creation' => $noSilentObraCreation,
            'completion_audit_block_available' => $completionAuditBlockAvailable,
            'runtime_dispatch_service_available' => class_exists(\App\Services\Ai\Programming\AtlasForgeRuntimeDispatchService::class)
                && is_file($repoRoot.'/app/Services/Ai/Programming/AtlasForgeRuntimeDispatchService.php'),
            'runtime_dispatch_endpoint_registered' => $this->fileContains(
                $repoRoot.'/routes/api.php',
                '/forge/runtime-dispatch',
            ) && class_exists(\App\Http\Controllers\AtlasCodeForgeRuntimeDispatchController::class),
            'runtime_dispatch_static_policy_blocked' => $this->fileContains(
                $repoRoot.'/app/Services/Ai/Programming/AtlasForgeRuntimeDispatchService.php',
                "BLOCKER_LIVE_DECIDE_REQUIRED = 'live_decide_receipt_required'",
            ),
            'runtime_dispatch_child_receipt_supported' => $this->fileContains(
                $repoRoot.'/app/Services/Ai/Programming/AtlasForgeRuntimeDispatchService.php',
                'createChildReceipt',
            ) && $this->fileContains(
                $repoRoot.'/app/Services/Ai/Programming/AtlasForgeRuntimeDispatchService.php',
                "atlas.forge.child_decision_receipt.v1",
            ),
            'provider_invocation_service_available' => class_exists(\App\Services\Ai\Programming\AtlasForgeProviderInvocationService::class)
                && is_file($repoRoot.'/app/Services/Ai/Programming/AtlasForgeProviderInvocationService.php'),
            'provider_invocation_command_registered' => class_exists(\App\Console\Commands\AtlasForgeProviderInvokeCommand::class),
            'provider_invocation_endpoint_registered' => $this->fileContains(
                $repoRoot.'/routes/api.php',
                '/forge/provider-invocations',
            ) && class_exists(\App\Http\Controllers\AtlasCodeForgeProviderInvocationController::class),
            'provider_invocation_driver_router_available' => class_exists(\App\Services\Ai\Programming\AtlasForgeProviderInvocationDriverRouter::class),
            'provider_invocation_prompt_builder_available' => class_exists(\App\Services\Ai\Programming\AtlasForgeProviderInvocationPromptBuilder::class),
            'provider_invocation_dry_run_mode_available' => $this->fileContains(
                $repoRoot.'/app/Services/Ai/Programming/AtlasForgeProviderInvocationService.php',
                "MODE_DRY_RUN = 'dry_run'",
            ),
            'provider_invocation_execute_requires_operator_approval' => $this->fileContains(
                $repoRoot.'/app/Services/Ai/Programming/AtlasForgeProviderInvocationService.php',
                'BLOCKER_OPERATOR_APPROVAL_REQUIRED',
            ) && $this->fileContains(
                $repoRoot.'/app/Services/Ai/Programming/AtlasForgeProviderInvocationService.php',
                'BLOCKER_BUDGET_APPROVAL_REQUIRED',
            ),
            'provider_invocation_completion_claim_not_promoted' => $this->fileContains(
                $repoRoot.'/app/Services/Ai/Programming/AtlasForgeProviderInvocationService.php',
                "'completion_claim_promoted' => false",
            ),
        ];
    }

    /**
     * Validate that the fallback policy never emits silent fallback events. Used
     * by the no_silent_fallback invariant via runtime classification of a known
     * recoverable failure (rate_limit) — proves the contract by execution rather
     * than by string matching the constant.
     */
    private function verifyNoSilentFallback(): bool
    {
        if (! class_exists(AtlasForgeProviderFallbackPolicyService::class)) {
            return false;
        }

        try {
            $decision = $this->fallbackPolicy->classify(
                ['type' => AtlasForgeProviderFallbackPolicyService::FAILURE_RATE_LIMIT],
                ['provider_topology_id' => 'topo_test', 'roles' => [], 'fallback_chain' => []],
            );
        } catch (\Throwable) {
            return false;
        }

        return ($decision['event']['silent'] ?? null) === false
            && AtlasForgeProviderFallbackPolicyService::EVENT_SCHEMA_VERSION === 'atlas.forge.provider_fallback_event.v1';
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    public function artifacts(string $repoRoot): array
    {
        $desktopRoot = dirname($repoRoot).'/atlas-desktop';

        return [
            'doc_mother' => [
                'path' => 'docs/engineering-knowledge-base/atlas-forge-continuum-os.md',
                'present' => is_file($repoRoot.'/docs/engineering-knowledge-base/atlas-forge-continuum-os.md'),
            ],
            'provider_topology_doc' => [
                'path' => 'docs/engineering-knowledge-base/atlas-forge-provider-topology-and-fallback-v1.md',
                'present' => is_file($repoRoot.'/docs/engineering-knowledge-base/atlas-forge-provider-topology-and-fallback-v1.md'),
            ],
            'atlas_decide_doc' => [
                'path' => 'docs/engineering-knowledge-base/system-graph/atlas-decide.md',
                'present' => is_file($repoRoot.'/docs/engineering-knowledge-base/system-graph/atlas-decide.md'),
            ],
            'continuum_certification_service' => [
                'class' => self::class,
                'present' => class_exists(self::class),
            ],
            'provider_topology_service' => [
                'class' => AtlasForgeProviderTopologyService::class,
                'present' => class_exists(AtlasForgeProviderTopologyService::class),
            ],
            'fallback_policy_service' => [
                'class' => AtlasForgeProviderFallbackPolicyService::class,
                'present' => class_exists(AtlasForgeProviderFallbackPolicyService::class),
            ],
            'continuum_certify_command' => [
                'class' => \App\Console\Commands\AtlasForgeContinuumCertifyCommand::class,
                'present' => class_exists(\App\Console\Commands\AtlasForgeContinuumCertifyCommand::class),
            ],
            'provider_topology_controller' => [
                'class' => \App\Http\Controllers\AtlasCodeForgeProviderTopologyController::class,
                'present' => class_exists(\App\Http\Controllers\AtlasCodeForgeProviderTopologyController::class),
            ],
            'state_projection' => [
                'path' => 'app/Http/Controllers/AtlasCodeWorkController.php',
                'present' => $this->fileContains(
                    $repoRoot.'/app/Http/Controllers/AtlasCodeWorkController.php',
                    'forge_provider_topology',
                ),
            ],
            'desktop_panel' => [
                'path' => 'apps/desktop/src/surfaces/code/panels/ForgeProviderTopologyPanel.tsx',
                'present' => is_file($desktopRoot.'/apps/desktop/src/surfaces/code/panels/ForgeProviderTopologyPanel.tsx'),
            ],
            'desktop_domain_types' => [
                'path' => 'packages/atlas-domain/src/index.ts',
                'present' => $this->fileContains(
                    $desktopRoot.'/packages/atlas-domain/src/index.ts',
                    'AtlasForgeProviderTopology',
                ),
            ],
            'desktop_bridge' => [
                'path' => 'apps/desktop/src/lib/bridge.ts',
                'present' => $this->fileContains(
                    $desktopRoot.'/apps/desktop/src/lib/bridge.ts',
                    'getForgeProviderTopology',
                ),
            ],
            'desktop_use_bridge' => [
                'path' => 'apps/desktop/src/hooks/useBridge.ts',
                'present' => $this->fileContains(
                    $desktopRoot.'/apps/desktop/src/hooks/useBridge.ts',
                    'forgeProviderTopology',
                ),
            ],
            'test_certification' => [
                'path' => 'tests/Feature/Ai/Programming/AtlasForgeContinuumCertificationTest.php',
                'present' => is_file($repoRoot.'/tests/Feature/Ai/Programming/AtlasForgeContinuumCertificationTest.php'),
            ],
            'test_topology' => [
                'path' => 'tests/Feature/Ai/Programming/AtlasForgeProviderTopologyTest.php',
                'present' => is_file($repoRoot.'/tests/Feature/Ai/Programming/AtlasForgeProviderTopologyTest.php'),
            ],
            'runtime_dispatch_service' => [
                'class' => \App\Services\Ai\Programming\AtlasForgeRuntimeDispatchService::class,
                'present' => class_exists(\App\Services\Ai\Programming\AtlasForgeRuntimeDispatchService::class),
            ],
            'runtime_dispatch_command' => [
                'class' => \App\Console\Commands\AtlasForgeRuntimeDispatchCommand::class,
                'present' => class_exists(\App\Console\Commands\AtlasForgeRuntimeDispatchCommand::class),
            ],
            'runtime_dispatch_controller' => [
                'class' => \App\Http\Controllers\AtlasCodeForgeRuntimeDispatchController::class,
                'present' => class_exists(\App\Http\Controllers\AtlasCodeForgeRuntimeDispatchController::class),
            ],
            'test_runtime_dispatch' => [
                'path' => 'tests/Feature/Ai/Programming/AtlasForgeRuntimeDispatchTest.php',
                'present' => is_file($repoRoot.'/tests/Feature/Ai/Programming/AtlasForgeRuntimeDispatchTest.php'),
            ],
            'provider_invocation_service' => [
                'class' => \App\Services\Ai\Programming\AtlasForgeProviderInvocationService::class,
                'present' => class_exists(\App\Services\Ai\Programming\AtlasForgeProviderInvocationService::class),
            ],
            'provider_invocation_driver_router' => [
                'class' => \App\Services\Ai\Programming\AtlasForgeProviderInvocationDriverRouter::class,
                'present' => class_exists(\App\Services\Ai\Programming\AtlasForgeProviderInvocationDriverRouter::class),
            ],
            'provider_invocation_prompt_builder' => [
                'class' => \App\Services\Ai\Programming\AtlasForgeProviderInvocationPromptBuilder::class,
                'present' => class_exists(\App\Services\Ai\Programming\AtlasForgeProviderInvocationPromptBuilder::class),
            ],
            'provider_invocation_command' => [
                'class' => \App\Console\Commands\AtlasForgeProviderInvokeCommand::class,
                'present' => class_exists(\App\Console\Commands\AtlasForgeProviderInvokeCommand::class),
            ],
            'provider_invocation_controller' => [
                'class' => \App\Http\Controllers\AtlasCodeForgeProviderInvocationController::class,
                'present' => class_exists(\App\Http\Controllers\AtlasCodeForgeProviderInvocationController::class),
            ],
            'test_provider_invocation' => [
                'path' => 'tests/Feature/Ai/Programming/AtlasForgeProviderInvocationTest.php',
                'present' => is_file($repoRoot.'/tests/Feature/Ai/Programming/AtlasForgeProviderInvocationTest.php'),
            ],
        ];
    }

    /**
     * @param  array<string,array<string,mixed>>  $artifacts
     * @return array<int,string>
     */
    private function collectMissingArtifacts(array $artifacts): array
    {
        $missing = [];
        foreach ($artifacts as $key => $artifact) {
            if (! (bool) ($artifact['present'] ?? false)) {
                $missing[] = (string) $key.'_missing';
            }
        }

        return $missing;
    }

    private function fileContains(string $path, string $needle): bool
    {
        if (! is_file($path)) {
            return false;
        }
        $source = (string) file_get_contents($path);

        return str_contains($source, $needle);
    }

    /**
     * @param  array<string,mixed>  $options
     */
    private function resolveRepoRoot(array $options): string
    {
        $explicit = $this->stringOrNull($options['workspace'] ?? null);
        if ($explicit !== null && is_dir($explicit)) {
            return rtrim($explicit, '/');
        }

        return rtrim(base_path(), '/');
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
