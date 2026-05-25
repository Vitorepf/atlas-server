<?php

namespace App\Services\Ai\Programming\Forge\Intelligence;

use App\Models\AiForgeMultiAgentSchedule;
use App\Models\AiForgeWorkPacket;
use App\Models\AiForgeWorkPacketExecutionCycle;
use App\Models\AiForgeWorkPacketWorkcellRoute;
use App\Services\Ai\Mission\MissionCanonicalHash;
use App\Services\Ai\Programming\Frontend\AtlasFrontendCompanyRepoOnboardingService;
use App\Services\Ai\Programming\Frontend\AtlasFrontendDesignRuntimeService;
use App\Services\Ai\Programming\Frontend\AtlasFrontendEnterpriseBootstrapService;
use App\Services\Ai\Programming\Frontend\AtlasFrontendExecutionGateService;
use App\Services\Ai\Programming\Frontend\AtlasFrontendExecutionRunbookService;
use App\Services\Ai\Programming\Frontend\AtlasFrontendProviderInstructionPacketService;
use App\Services\Ai\Programming\Frontend\AtlasFrontendSelectedWorkspaceService;
use Illuminate\Support\Str;

final class ForgeSpecialistWorkcellRouterService
{
    public const SCHEMA_VERSION = 'atlas.forge.specialist_workcell_route.v1';

    /**
     * @return array<string,mixed>
     */
    public function route(AiForgeWorkPacket $packet): array
    {
        $text = strtolower((string) $packet->title.' '.(string) $packet->objective.' '.(string) $packet->scope);
        $workspace = $this->workspaceFromPacket($packet);
        $frontendApp = $this->frontendAppFromPacket($packet, $workspace);
        $route = 'implementation';
        if (str_contains($text, 'test') || str_contains($text, 'teste') || str_contains($text, 'qa')) {
            $route = 'qa_test';
        } elseif (str_contains($text, 'debug') || str_contains($text, 'bug') || str_contains($text, 'repair') || str_contains($text, 'corrig')) {
            $route = 'repair_debug';
        } elseif (str_contains($text, 'doc') || str_contains($text, 'cartografia')) {
            $route = 'documentation';
        } elseif (str_contains($text, 'frontend') || str_contains($text, 'mobile') || str_contains($text, 'ui')) {
            $route = 'surface_ui';
        } elseif (str_contains($text, 'security') || str_contains($text, 'segur')) {
            $route = 'security_review';
        } elseif (str_contains($text, 'migration') || str_contains($text, 'schema') || str_contains($text, 'database')) {
            $route = 'database_migration';
        } elseif (str_contains($text, 'architecture') || str_contains($text, 'arquitet')) {
            $route = 'architecture';
        }

        $atlasFrontendRuntime = $route === 'surface_ui'
            ? app(AtlasFrontendDesignRuntimeService::class)->contract([
                'task' => trim($text),
                'surface' => 'atlas_forge',
                'workspace' => $workspace ?? '',
            ])
            : null;
        $frontendInput = [
            'task' => trim($text),
            'surface' => 'atlas_forge',
            'workspace' => $workspace ?? '',
            'frontend_app' => $frontendApp ?? '',
            'acceptance_criteria' => $this->hasPacketItems($packet->acceptance_criteria),
            'test_plan' => $this->hasPacketItems($packet->suggested_tests),
            'visual_quality_plan' => $this->hasPacketItems($packet->required_evidence) || str_contains($text, 'visual') || str_contains($text, 'screenshot'),
            'evidence_plan' => $this->hasPacketItems($packet->required_evidence),
            'senior_design_review' => in_array((string) $packet->risk_band, ['high', 'critical'], true),
        ];
        $atlasFrontendSelectedWorkspace = $route === 'surface_ui'
            ? app(AtlasFrontendSelectedWorkspaceService::class)->resolve($frontendInput + [
                'selection_source' => 'atlas_forge_work_packet',
            ])
            : null;
        $frontendWorkspaceSelected = ($atlasFrontendSelectedWorkspace['status'] ?? null) === 'selected';
        $atlasFrontendGate = $route === 'surface_ui'
            ? app(AtlasFrontendExecutionGateService::class)->evaluate($frontendInput)
            : null;
        $atlasFrontendEnterpriseBootstrap = $route === 'surface_ui' && $frontendWorkspaceSelected
            ? app(AtlasFrontendEnterpriseBootstrapService::class)->run($frontendInput)
            : null;
        $atlasFrontendCompanyRepoOnboarding = $route === 'surface_ui' && $frontendWorkspaceSelected
            ? app(AtlasFrontendCompanyRepoOnboardingService::class)->run($frontendInput + [
                'provider' => 'forge_provider_neutral',
            ])
            : null;
        $atlasFrontendExecutionRunbook = $route === 'surface_ui' && $frontendWorkspaceSelected
            ? app(AtlasFrontendExecutionRunbookService::class)->compile($frontendInput + [
                'write_evidence_kit' => false,
            ])
            : null;
        $atlasFrontendProviderInstructionPacket = $route === 'surface_ui' && $frontendWorkspaceSelected
            ? app(AtlasFrontendProviderInstructionPacketService::class)->compile($frontendInput + [
                'provider' => 'forge_provider_neutral',
                'write_evidence_kit' => false,
            ])
            : null;

        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'packet_id' => (string) $packet->packet_id,
            'workcell' => $route,
            'agent_profile' => 'forge_'.$route,
            'parallelizable' => in_array($route, ['documentation', 'qa_test', 'security_review'], true),
            'requires_human_review' => in_array((string) $packet->risk_band, ['high', 'critical'], true),
            'route_reasons' => ['objective_keyword_match', 'risk_band:'.(string) $packet->risk_band],
        ];
        if ($atlasFrontendRuntime !== null) {
            $payload['atlas_frontend_runtime'] = $atlasFrontendRuntime;
            $payload['atlas_frontend_pre_execution_gate'] = $atlasFrontendGate;
            $payload['atlas_frontend_enterprise_operating_contract'] = [
                'schema_version' => 'atlas.forge.frontend_enterprise_operating_contract.v1',
                'workspace_mode' => $atlasFrontendSelectedWorkspace['workspace_mode'] ?? 'generic_or_missing_workspace',
                'selected_workspace_schema' => AtlasFrontendSelectedWorkspaceService::SCHEMA_VERSION,
                'enterprise_bootstrap_schema' => AtlasFrontendEnterpriseBootstrapService::SCHEMA_VERSION,
                'company_repo_onboarding_schema' => AtlasFrontendCompanyRepoOnboardingService::SCHEMA_VERSION,
                'execution_runbook_schema' => AtlasFrontendExecutionRunbookService::SCHEMA_VERSION,
                'provider_instruction_packet_schema' => AtlasFrontendProviderInstructionPacketService::SCHEMA_VERSION,
                'selected_workspace_status' => $atlasFrontendSelectedWorkspace['status'] ?? 'not_evaluated',
                'selected_workspace_dispatch_readiness_status' => data_get($atlasFrontendSelectedWorkspace, 'dispatch_readiness.status', 'not_evaluated'),
                'runtime_projection_allowed' => (bool) data_get($atlasFrontendSelectedWorkspace, 'dispatch_readiness.runtime_projection_allowed', false),
                'runtime_projection_status' => data_get($atlasFrontendSelectedWorkspace, 'frontend_runtime_projection.status', 'not_evaluated'),
                'frontend_app_candidate_status' => data_get($atlasFrontendSelectedWorkspace, 'frontend_app_candidates.status', 'not_evaluated'),
                'frontend_app_candidate_count' => (int) data_get($atlasFrontendSelectedWorkspace, 'frontend_app_candidates.candidate_count', 0),
                'frontend_app_primary_candidate_ref' => data_get($atlasFrontendSelectedWorkspace, 'frontend_app_candidates.primary_candidate_ref'),
                'frontend_app_candidate_confirmation_required' => (bool) data_get($atlasFrontendSelectedWorkspace, 'frontend_app_candidates.operator_decision.required', false),
                'frontend_app_scope_status' => data_get($atlasFrontendExecutionRunbook, 'frontend_app_scope.status', 'not_evaluated'),
                'frontend_app_scope_hash' => data_get($atlasFrontendExecutionRunbook, 'frontend_app_scope.relative_name_hash'),
                'onboarding_frontend_app_scope_status' => data_get($atlasFrontendCompanyRepoOnboarding, 'frontend_app_scope.status', 'not_evaluated'),
                'onboarding_frontend_app_scope_hash' => data_get($atlasFrontendCompanyRepoOnboarding, 'frontend_app_scope.relative_name_hash'),
                'operator_start_panel_status' => data_get($atlasFrontendSelectedWorkspace, 'operator_start_panel.status', 'not_evaluated'),
                'operator_primary_action' => data_get($atlasFrontendSelectedWorkspace, 'operator_start_panel.primary_action'),
                'next_best_action' => data_get($atlasFrontendSelectedWorkspace, 'next_best_action.id'),
                'next_best_action_priority' => data_get($atlasFrontendSelectedWorkspace, 'next_best_action.priority'),
                'task_binding_status' => data_get($atlasFrontendSelectedWorkspace, 'task_binding.status', 'not_evaluated'),
                'task_bound' => (bool) data_get($atlasFrontendSelectedWorkspace, 'task_binding.task_present', false),
                'onboarding_status' => $atlasFrontendCompanyRepoOnboarding['status'] ?? 'not_evaluated',
                'bootstrap_status' => $atlasFrontendEnterpriseBootstrap['status'] ?? 'not_evaluated',
                'runbook_status' => $atlasFrontendExecutionRunbook['status'] ?? 'not_evaluated',
                'provider_packet_status' => $atlasFrontendProviderInstructionPacket['status'] ?? 'not_evaluated',
                'selected_workspace_provider_dispatch_allowed' => (bool) data_get($atlasFrontendSelectedWorkspace, 'dispatch_readiness.provider_dispatch_allowed', false),
                'provider_dispatch_allowed' => (bool) data_get($atlasFrontendEnterpriseBootstrap, 'readiness.provider_dispatch_allowed') && ($atlasFrontendCompanyRepoOnboarding['status'] ?? null) === 'ready_for_operator_execution' && ($atlasFrontendExecutionRunbook['status'] ?? null) === 'ready' && ($atlasFrontendProviderInstructionPacket['status'] ?? null) === 'ready',
                'premium_frontend_claim_allowed' => (bool) data_get($atlasFrontendEnterpriseBootstrap, 'readiness.premium_frontend_claim_allowed') && ($atlasFrontendExecutionRunbook['status'] ?? null) === 'ready',
                'world_best_claim_allowed' => false,
                'hash_refs' => [
                    'selected_workspace_hash' => $atlasFrontendSelectedWorkspace['selected_workspace_hash'] ?? null,
                    'runtime_projection_hash' => data_get($atlasFrontendSelectedWorkspace, 'frontend_runtime_projection.runtime_projection_hash'),
                    'enterprise_bootstrap_hash' => $atlasFrontendEnterpriseBootstrap['enterprise_bootstrap_hash'] ?? null,
                    'company_repo_onboarding_hash' => $atlasFrontendCompanyRepoOnboarding['onboarding_hash'] ?? null,
                    'runbook_hash' => $atlasFrontendExecutionRunbook['runbook_hash'] ?? null,
                    'provider_instruction_packet_hash' => $atlasFrontendProviderInstructionPacket['provider_instruction_packet_hash'] ?? null,
                ],
                'claim_policy' => [
                    'forge_surface_ui_packets_use_enterprise_bootstrap_and_runbook_when_workspace_is_known' => true,
                    'runbook_is_not_execution_evidence' => true,
                    'completion_requires_run_certification_handoff_and_outcome' => true,
                    'raw_customer_source_returned' => false,
                ],
            ];
            $payload['atlas_frontend_selected_workspace'] = $atlasFrontendSelectedWorkspace;
            $payload['atlas_frontend_company_repo_onboarding'] = $atlasFrontendCompanyRepoOnboarding;
            $payload['atlas_frontend_enterprise_bootstrap'] = $atlasFrontendEnterpriseBootstrap;
            $payload['atlas_frontend_execution_runbook'] = $atlasFrontendExecutionRunbook;
            $payload['atlas_frontend_provider_instruction_packet'] = $atlasFrontendProviderInstructionPacket;
            $payload['route_reasons'][] = 'atlas_frontend_runtime_contract_attached';
            $payload['route_reasons'][] = 'atlas_frontend_pre_execution_gate_attached';
            $payload['route_reasons'][] = 'atlas_frontend_enterprise_operating_contract_attached';
            $payload['route_reasons'][] = 'atlas_frontend_selected_workspace_attached';
            $payload['route_reasons'][] = 'atlas_frontend_company_repo_onboarding_attached';
            $payload['route_reasons'][] = 'atlas_frontend_provider_instruction_packet_attached';
        }
        $payload['route_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * @param  array<string,mixed>  $route
     */
    public function persistRoute(
        AiForgeWorkPacket $packet,
        array $route,
        ?AiForgeWorkPacketExecutionCycle $cycle = null,
        ?AiForgeMultiAgentSchedule $schedule = null,
    ): AiForgeWorkPacketWorkcellRoute {
        $payload = [
            'schema_version' => 'atlas.forge.work_packet_workcell_route.v1',
            'intake_id' => $packet->intake_id,
            'work_packet_id' => $packet->id,
            'work_packet_canonical_id' => (string) $packet->packet_id,
            'execution_cycle_id' => $cycle?->id,
            'multi_agent_schedule_id' => $schedule?->id,
            'workcell' => (string) ($route['workcell'] ?? 'implementation'),
            'agent_profile' => (string) ($route['agent_profile'] ?? 'forge_implementation'),
            'parallelizable' => (bool) ($route['parallelizable'] ?? false),
            'requires_human_review' => (bool) ($route['requires_human_review'] ?? false),
            'route_reasons' => array_values((array) ($route['route_reasons'] ?? [])),
            'ownership_paths' => array_values((array) ($packet->expected_files ?? [])),
            'status' => $schedule !== null ? 'scheduled' : 'planned',
        ];
        $payload['route_hash'] = MissionCanonicalHash::sha256($payload);

        return AiForgeWorkPacketWorkcellRoute::query()->updateOrCreate(
            [
                'execution_cycle_id' => $cycle?->id,
                'work_packet_canonical_id' => (string) $packet->packet_id,
            ],
            [
                'schema_version' => (string) $payload['schema_version'],
                'uuid' => (string) Str::uuid(),
                'intake_id' => $payload['intake_id'],
                'work_packet_id' => $payload['work_packet_id'],
                'multi_agent_schedule_id' => $payload['multi_agent_schedule_id'],
                'workcell' => $payload['workcell'],
                'agent_profile' => $payload['agent_profile'],
                'parallelizable' => $payload['parallelizable'],
                'requires_human_review' => $payload['requires_human_review'],
                'route_reasons' => $payload['route_reasons'],
                'ownership_paths' => $payload['ownership_paths'],
                'status' => $payload['status'],
                'route_hash' => $payload['route_hash'],
            ],
        );
    }

    private function workspaceFromPacket(AiForgeWorkPacket $packet): ?string
    {
        $candidates = [
            data_get($packet, 'intake.workspace_execution_gate.execution_context.workspace_path'),
            data_get($packet, 'intake.workspace_execution_gate.execution_context.workspace'),
            data_get($packet, 'intake.workspace_slug'),
        ];

        foreach ($candidates as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '' && is_dir(trim($candidate))) {
                return (string) (realpath(trim($candidate)) ?: trim($candidate));
            }
        }

        foreach ((array) ($packet->expected_files ?? []) as $file) {
            if (! is_string($file) || trim($file) === '') {
                continue;
            }
            $path = trim($file);
            if (str_starts_with($path, DIRECTORY_SEPARATOR)) {
                $canonicalPath = $this->canonicalMacPath(str_replace('\\', '/', $path));
                if (preg_match('#^(.+)/(apps|packages)/[^/]+(?:/|$)#', $canonicalPath, $matches) === 1 && $this->workspaceLooksProjectScoped($matches[1])) {
                    return (string) (realpath($matches[1]) ?: $matches[1]);
                }

                $directory = is_dir($path) ? $path : dirname($path);
                while ($directory !== DIRECTORY_SEPARATOR && $directory !== '.') {
                    if ($this->workspaceLooksProjectScoped($directory)) {
                        return (string) (realpath($directory) ?: $directory);
                    }
                    $directory = dirname($directory);
                }
            }
        }

        return null;
    }

    private function workspaceLooksProjectScoped(string $workspace): bool
    {
        $workspace = realpath($workspace) ?: $workspace;
        if (! is_dir($workspace)) {
            return false;
        }

        foreach (['.git', 'composer.json', 'package.json', 'pnpm-workspace.yaml', 'artisan', 'pyproject.toml', 'go.mod', 'Package.swift'] as $marker) {
            if (file_exists($workspace.DIRECTORY_SEPARATOR.$marker)) {
                return true;
            }
        }

        return false;
    }

    private function frontendAppFromPacket(AiForgeWorkPacket $packet, ?string $workspace): ?string
    {
        if ($workspace === null || trim($workspace) === '') {
            return null;
        }

        $workspace = rtrim((string) (realpath($workspace) ?: $workspace), DIRECTORY_SEPARATOR);
        foreach ((array) ($packet->expected_files ?? []) as $file) {
            if (! is_string($file) || trim($file) === '') {
                continue;
            }

            $path = $this->canonicalMacPath(str_replace('\\', '/', trim($file)));
            $workspacePrefix = $this->canonicalMacPath(str_replace('\\', '/', $workspace)).'/';
            if (! str_starts_with($path, $workspacePrefix)) {
                continue;
            }

            $relative = substr($path, strlen($workspacePrefix));
            if (preg_match('#^(apps|packages)/([^/]+)/#', $relative, $matches) === 1) {
                return $matches[1].'/'.$matches[2];
            }
        }

        return null;
    }

    private function canonicalMacPath(string $path): string
    {
        return str_starts_with($path, '/private/var/')
            ? substr($path, strlen('/private'))
            : $path;
    }

    private function hasPacketItems(mixed $items): bool
    {
        return array_values(array_filter((array) $items, fn (mixed $item): bool => is_string($item) && trim($item) !== '')) !== [];
    }
}
