<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming;

use App\Services\Ai\Kernel\Domain\AtlasAiDomainCatalogService;
use App\Services\Ai\Surface\DomainCatalogSurfaceSelectionService;
use App\Services\Ai\Surface\SurfaceAdapterRegistry;
use Illuminate\Support\Str;

/**
 * Atlas Forge Runtime Certification.
 *
 * Prova replayable da cadeia canonica:
 *   Atlas Code -> Obra -> Forge Workspace -> programming.forge
 *   -> Atlas Decide / Decision Receipt -> Governance -> Evidence.
 *
 * Schema: atlas.forge_runtime_certification.v1
 * Doc: docs/engineering-knowledge-base/atlas-forge-runtime-certification-one-shot.md
 */
class AtlasForgeRuntimeCertificationService
{
    public const SCHEMA_VERSION = 'atlas.forge_runtime_certification.v1';
    public const FORGE_WORKSPACE_BINDING_SCHEMA = 'atlas.forge_workspace_binding.v1';
    public const FORGE_WORKSPACE_BLOCKER_SCHEMA = 'atlas.forge_workspace_blocker.v1';

    public function __construct(
        private readonly DomainCatalogSurfaceSelectionService $domainSelection,
        private readonly SurfaceAdapterRegistry $surfaces,
        private readonly AtlasAiDomainCatalogService $catalog,
    ) {}

    /**
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    public function certify(array $options = []): array
    {
        $obraId = $this->normalizeObraId($options['obra_id'] ?? null);
        $stages = [];
        $blockers = [];

        $surfaceStage = $this->stageSurfaceAdapter();
        $stages[] = $surfaceStage;
        if ($surfaceStage['status'] !== 'passed') {
            $blockers[] = $surfaceStage['blocker'] ?? 'surface_adapter_invalid';
        }

        $payloadStage = $this->stageObraBindingPayload($obraId);
        $stages[] = $payloadStage;
        $payload = $payloadStage['payload'];
        if ($payloadStage['status'] === 'blocked') {
            $blockers[] = $payloadStage['blocker'] ?? 'obra_binding_failed';
        }

        $selectionStage = $this->stageDomainCatalogSelection($payload);
        $stages[] = $selectionStage;
        if ($selectionStage['status'] !== 'passed') {
            $blockers[] = $selectionStage['blocker'] ?? 'domain_catalog_selection_failed';
        }

        $governanceStage = $this->stageGovernanceRoute($selectionStage['selection']);
        $stages[] = $governanceStage;
        if ($governanceStage['status'] !== 'passed') {
            $blockers[] = $governanceStage['blocker'] ?? 'governance_route_unverified';
        }

        $evidenceStage = $this->stageEvidencePolicy($selectionStage['selection'], $obraId);
        $stages[] = $evidenceStage;
        if ($evidenceStage['status'] === 'blocked') {
            $blockers[] = $evidenceStage['blocker'] ?? 'evidence_policy_missing';
        }

        $forgeCoreStatus = $this->resolveForgeCoreStatus($stages, $obraId);

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'generated_at' => now()->toIso8601String(),
            'forge_core_status' => $forgeCoreStatus,
            'external_rivals_status' => 'blocked_requires_operator_approval',
            'external_rivals_reason' => 'Bateria externa Rivals exige autorizacao operador e custo provider; mantida isolada do Forge core conforme atlas-forge-runtime-certification-one-shot.md.',
            'e2e_command' => 'php artisan atlas:forge:runtime-certify --json',
            'inputs' => [
                'surface_id' => 'atlas_code',
                'requested_flow_id' => 'programming.forge',
                'routing_task' => 'forge',
                'obra_id' => $obraId,
                'obra_provided' => $obraId !== null,
            ],
            'stages' => $stages,
            'evidence_paths' => $this->evidencePaths(),
            'remaining_blockers' => array_values(array_unique($blockers)),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function stageSurfaceAdapter(): array
    {
        try {
            $adapter = $this->surfaces->get('atlas_code');
        } catch (\Throwable $e) {
            return [
                'name' => 'surface_adapter_resolution',
                'status' => 'blocked',
                'blocker' => 'atlas_code_surface_not_registered',
                'reason' => $e->getMessage(),
            ];
        }

        $hints = method_exists($adapter, 'supportedDomainFlowHints')
            ? $adapter->supportedDomainFlowHints()
            : [];

        $supportedFlows = is_array($hints['supported_flow_ids'] ?? null)
            ? $hints['supported_flow_ids']
            : [];

        $defaultFlow = is_string($hints['default_flow_id'] ?? null)
            ? $hints['default_flow_id']
            : null;

        $forgeOnly = $supportedFlows === ['programming.forge'] && $defaultFlow === 'programming.forge';

        return [
            'name' => 'surface_adapter_resolution',
            'status' => $forgeOnly ? 'passed' : 'blocked',
            'blocker' => $forgeOnly ? null : 'atlas_code_not_forge_only',
            'surface_id' => $adapter->surfaceId(),
            'default_flow_id' => $defaultFlow,
            'supported_flow_ids' => $supportedFlows,
            'capabilities' => $adapter->supportedCapabilities(),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function stageObraBindingPayload(?string $obraId): array
    {
        $basePayload = [
            'app_surface' => 'atlas_code',
            'surface_id' => 'atlas_code',
            'atlas_mode' => 'forge',
            'current_mode' => 'forge',
            'atlas_workflow_mode' => 'forge',
            'domain_id' => 'programming',
            'flow_id' => 'programming.forge',
            'routing_domain' => 'programming',
            'routing_task' => 'forge',
            'programming_profile' => 'forge',
            'programming_flow' => 'programming.forge',
            'requires_obra' => true,
        ];

        if ($obraId === null) {
            $payload = $basePayload;
            $payload['forge_workspace_blocker'] = [
                'schema_version' => self::FORGE_WORKSPACE_BLOCKER_SCHEMA,
                'reason' => 'missing_obra_binding',
                'requires_obra' => true,
                'surface_id' => 'atlas_code',
                'flow_id' => 'programming.forge',
                'remediation' => 'Selecione ou crie uma Obra antes de despachar Forge.',
            ];

            return [
                'name' => 'obra_binding_payload',
                'status' => 'blocked',
                'blocker' => 'missing_obra_binding',
                'reason' => 'Atlas Code Forge precisa de Obra vinculada para construir payload canonico.',
                'payload' => $payload,
            ];
        }

        $payload = $basePayload;
        $payload['obra_id'] = $obraId;
        $payload['work_id'] = $obraId;
        $payload['project_id'] = $obraId;
        $payload['forge_workspace'] = [
            'schema_version' => self::FORGE_WORKSPACE_BINDING_SCHEMA,
            'workspace_kind' => 'obras_shared_workspace',
            'specialization' => 'forge_workspace',
            'obra_id' => $obraId,
            'source' => 'atlas_code',
        ];

        $contractOk = $payload['surface_id'] === 'atlas_code'
            && $payload['flow_id'] === 'programming.forge'
            && $payload['routing_task'] === 'forge'
            && $payload['requires_obra'] === true
            && $payload['obra_id'] === $obraId
            && $payload['forge_workspace']['obra_id'] === $obraId
            && $payload['forge_workspace']['workspace_kind'] === 'obras_shared_workspace';

        return [
            'name' => 'obra_binding_payload',
            'status' => $contractOk ? 'passed' : 'blocked',
            'blocker' => $contractOk ? null : 'obra_binding_contract_invalid',
            'contract' => [
                'requires_obra' => $payload['requires_obra'],
                'obra_id' => $payload['obra_id'],
                'forge_workspace.workspace_kind' => $payload['forge_workspace']['workspace_kind'],
                'forge_workspace.obra_id' => $payload['forge_workspace']['obra_id'],
            ],
            'payload' => $payload,
        ];
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function stageDomainCatalogSelection(array $payload): array
    {
        $selection = $this->domainSelection->select([
            'surface_id' => $payload['surface_id'] ?? 'atlas_code',
            'mode' => $payload['atlas_mode'] ?? null,
            'task' => $payload['routing_task'] ?? null,
            'domain_id' => $payload['domain_id'] ?? null,
            'flow_id' => $payload['flow_id'] ?? null,
            'routing_domain' => $payload['routing_domain'] ?? null,
        ]);

        $status = (string) ($selection['status'] ?? 'unresolved');
        $flowId = (string) data_get($selection, 'flow.id', '');
        $domainId = (string) data_get($selection, 'domain.id', '');

        $ok = $status === 'ok' && $flowId === 'programming.forge' && $domainId === 'programming';

        return [
            'name' => 'domain_catalog_selection',
            'status' => $ok ? 'passed' : 'blocked',
            'blocker' => $ok ? null : 'domain_catalog_did_not_resolve_programming_forge',
            'selection' => $selection,
            'flow_id' => $flowId,
            'domain_id' => $domainId,
        ];
    }

    /**
     * @param  array<string,mixed>  $selection
     * @return array<string,mixed>
     */
    private function stageGovernanceRoute(array $selection): array
    {
        if (($selection['status'] ?? null) !== 'ok') {
            return [
                'name' => 'governance_route',
                'status' => 'blocked',
                'blocker' => 'selection_not_ok',
            ];
        }

        $flow = is_array($selection['flow'] ?? null) ? $selection['flow'] : [];
        $domain = is_array($selection['domain'] ?? null) ? $selection['domain'] : [];

        $autonomy = (string) ($flow['autonomy'] ?? '');
        $runtime = (string) ($flow['runtime'] ?? '');
        $orchestratorMaturity = (string) ($flow['orchestrator_maturity'] ?? '');
        $destructiveRequiresApproval = (bool) ($flow['destructive_requires_approval'] ?? false);
        $onboardingReady = (string) data_get($selection, 'safety.onboarding_status', 'unknown') === 'ready';

        $contractOk = $autonomy !== ''
            && $runtime !== ''
            && $orchestratorMaturity !== ''
            && $destructiveRequiresApproval === true
            && $onboardingReady === true;

        return [
            'name' => 'governance_route',
            'status' => $contractOk ? 'passed' : 'blocked',
            'blocker' => $contractOk ? null : 'governance_contract_incomplete',
            'flow_runtime' => $runtime,
            'flow_autonomy' => $autonomy,
            'flow_orchestrator_maturity' => $orchestratorMaturity,
            'destructive_requires_approval' => $destructiveRequiresApproval,
            'onboarding_ready' => $onboardingReady,
            'domain_default_flow' => (string) ($domain['default_flow'] ?? ''),
        ];
    }

    /**
     * @param  array<string,mixed>  $selection
     * @return array<string,mixed>
     */
    private function stageEvidencePolicy(array $selection, ?string $obraId): array
    {
        $evidenceRequired = [
            'docs/engineering-knowledge-base/atlas-programming-forge-flow.md',
            'docs/engineering-knowledge-base/atlas-desktop-code-surface.md',
            'docs/engineering-knowledge-base/atlas-code-scor-1-implementation-contract.md',
            'docs/engineering-knowledge-base/obras/shared-workspace-and-forge.md',
            'docs/engineering-knowledge-base/atlas-forge-runtime-certification-one-shot.md',
        ];

        $repoRoot = base_path();
        $missing = [];
        foreach ($evidenceRequired as $path) {
            if (! is_file($repoRoot.DIRECTORY_SEPARATOR.$path)) {
                $missing[] = $path;
            }
        }

        $hasObra = $obraId !== null;

        return [
            'name' => 'evidence_policy',
            'status' => $missing === [] ? ($hasObra ? 'passed' : 'degraded') : 'blocked',
            'blocker' => $missing === [] ? null : 'canonical_docs_missing',
            'missing_docs' => $missing,
            'evidence_required' => $evidenceRequired,
            'evidence_capture' => $hasObra
                ? 'Run AtlasCodeContractTest::test_atlas_code_can_send_intent_for_work_through_ai_interactions for a live obra-bound trace.'
                : 'Provide --obra=<id> to capture WorkStateSnapshot evidence from /atlas-code/works/{id}/state.',
        ];
    }

    /**
     * @return array<int,string>
     */
    private function evidencePaths(): array
    {
        return [
            'tests/Feature/AtlasCodeContractTest.php',
            'tests/Feature/AiAtlasDecideContractTest.php',
            'tests/Unit/Ai/Surface/SurfaceAdaptersTest.php',
            'tests/Unit/Ai/Surface/DomainCatalogSurfaceSelectionServiceTest.php',
            'tests/Unit/Ai/Kernel/Pipeline/KernelPipelinePlanGuardTest.php',
            'tests/Feature/Ai/SurfaceDomainCatalogInteractionApiTest.php',
            'tests/Feature/Ai/Programming/AtlasForgeRuntimeCertificationTest.php',
            'app/Services/Ai/Surface/Adapters/AtlasCodeSurfaceAdapter.php',
            'app/Http/Controllers/AiInteractionController.php',
            'app/Services/Ai/Programming/AtlasForgeRuntimeCertificationService.php',
            'app/Console/Commands/AtlasForgeRuntimeCertifyCommand.php',
        ];
    }

    /**
     * @param  array<int,array<string,mixed>>  $stages
     */
    private function resolveForgeCoreStatus(array $stages, ?string $obraId): string
    {
        $statuses = array_map(static fn (array $stage): string => (string) ($stage['status'] ?? 'blocked'), $stages);

        if (in_array('blocked', $statuses, true)) {
            return $obraId === null
                ? 'blocked_missing_obra_binding'
                : 'blocked';
        }

        if (in_array('degraded', $statuses, true)) {
            return 'degraded';
        }

        return 'passed';
    }

    private function normalizeObraId(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);
        if ($value === '') {
            return null;
        }

        return Str::isUuid($value) ? $value : $value;
    }
}
