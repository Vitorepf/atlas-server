<?php

namespace App\Services\Ai\Programming\Frontend;

use App\Services\Ai\Mission\MissionCanonicalHash;

final class AtlasFrontendWorkspaceRuntimeProjectionService
{
    public const SCHEMA_VERSION = 'atlas.frontend.workspace_runtime_projection.v1';

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function project(array $input): array
    {
        $task = trim((string) ($input['task'] ?? ''));
        $workspace = rtrim(trim((string) ($input['workspace'] ?? '')), DIRECTORY_SEPARATOR);
        $frontendApp = trim((string) ($input['frontend_app'] ?? ''));
        $provider = trim((string) ($input['provider'] ?? 'provider_neutral')) ?: 'provider_neutral';

        $selected = app(AtlasFrontendSelectedWorkspaceService::class)->resolve([
            'task' => $task,
            'workspace' => $workspace,
            'frontend_app' => $frontendApp,
            'selection_source' => (string) ($input['selection_source'] ?? 'atlas_code'),
        ]);

        $invalidFrontendApp = data_get($selected, 'frontend_app_candidates.status') === 'requested_frontend_app_subscope_invalid';
        $selectedReady = ($selected['status'] ?? null) === 'selected' && ! $invalidFrontendApp;

        $runtimeInput = $this->runtimeInput($input, $task, $workspace, $frontendApp, $provider);
        $gauntlet = $selectedReady ? app(AtlasFrontendGauntletService::class)->run($runtimeInput) : null;
        $onboarding = $selectedReady ? app(AtlasFrontendCompanyRepoOnboardingService::class)->run($runtimeInput + [
            'write' => false,
            'write_docs' => false,
        ]) : null;
        $workOrder = $selectedReady ? app(AtlasFrontendWorkOrderService::class)->compile($runtimeInput) : null;
        $providerPacket = $selectedReady ? app(AtlasFrontendProviderInstructionPacketService::class)->compile($runtimeInput) : null;

        $frontendAppScope = $this->frontendAppScope($selected, $gauntlet, $providerPacket);
        $lanes = [
            $this->lane('selected_workspace', $selected['status'] ?? 'missing', $selected['selected_workspace_hash'] ?? null, $selectedReady ? [] : (array) ($selected['blockers'] ?? ['selected_workspace_missing'])),
            $this->lane('gauntlet', $gauntlet['status'] ?? 'not_evaluated', $gauntlet['gauntlet_hash'] ?? null, (array) ($gauntlet['required_next_actions'] ?? [])),
            $this->lane('onboarding_read_only', $onboarding['status'] ?? 'not_evaluated', $onboarding['onboarding_hash'] ?? null, (array) ($onboarding['required_next_actions'] ?? [])),
            $this->lane('work_order', $workOrder['status'] ?? 'not_evaluated', $workOrder['work_order_hash'] ?? null, (array) ($workOrder['required_next_actions'] ?? [])),
            $this->lane('provider_instruction_packet', $providerPacket['status'] ?? 'not_evaluated', $providerPacket['provider_instruction_packet_hash'] ?? null, (array) ($providerPacket['required_next_actions'] ?? [])),
        ];

        $providerPacketReady = ($providerPacket['status'] ?? null) === 'ready';
        $blockers = $this->blockers($selectedReady, $invalidFrontendApp, $lanes, $providerPacket);
        $status = ! $selectedReady ? 'blocked' : ($providerPacketReady ? 'ready_for_provider_dispatch' : 'needs_context');
        $actions = $this->requiredNextActions($selected, $lanes, $providerPacketReady);

        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $status,
            'surface' => 'atlas_code_frontend_runtime_projection',
            'projection_type' => 'read_only_selected_repo_frontend_operator_cockpit',
            'source' => self::class,
            'target_provider' => $provider,
            'task_hash' => $task !== '' ? hash('sha256', $task) : null,
            'workspace_hash' => $workspace !== '' ? hash('sha256', $workspace) : null,
            'frontend_app_scope' => $frontendAppScope,
            'selected_workspace_summary' => [
                'schema_version' => 'atlas.frontend.workspace_runtime_projection.selected_workspace_summary.v1',
                'status' => $selected['status'] ?? 'missing',
                'hash' => $selected['selected_workspace_hash'] ?? null,
                'dispatch_readiness_status' => data_get($selected, 'dispatch_readiness.status'),
                'runtime_projection_status' => data_get($selected, 'frontend_runtime_projection.status'),
                'runtime_projection_hash' => data_get($selected, 'frontend_runtime_projection.runtime_projection_hash'),
                'next_best_action_id' => data_get($selected, 'next_best_action.id'),
                'frontend_app_candidate_status' => data_get($selected, 'frontend_app_candidates.status'),
            ],
            'runtime_lanes' => $lanes,
            'operator_cockpit' => [
                'schema_version' => 'atlas.frontend.workspace_runtime_projection.operator_cockpit.v1',
                'primary_action' => $providerPacketReady
                    ? 'dispatch_provider_with_provider_instruction_packet'
                    : (string) data_get($selected, 'next_best_action.id', 'complete_frontend_context'),
                'provider_dispatch_ready' => $providerPacketReady,
                'provider_dispatch_performed' => false,
                'world_best_claim_allowed' => false,
                'completion_claim_allowed' => false,
                'required_next_actions' => $actions,
                'disabled_claims' => [
                    'delivery_done_until_run_certification',
                    'public_distribution_until_publication_receipt',
                    'world_best_until_external_rival_replay_receipts',
                ],
            ],
            'hash_refs' => [
                'selected_workspace_hash' => $selected['selected_workspace_hash'] ?? null,
                'gauntlet_hash' => $gauntlet['gauntlet_hash'] ?? null,
                'onboarding_hash' => $onboarding['onboarding_hash'] ?? null,
                'work_order_hash' => $workOrder['work_order_hash'] ?? null,
                'provider_instruction_packet_hash' => $providerPacket['provider_instruction_packet_hash'] ?? null,
            ],
            'claim_policy' => [
                'runtime_projection_is_not_execution_evidence' => true,
                'read_only_projection_does_not_write_workspace' => true,
                'provider_dispatch_ready_requires_provider_packet_ready' => true,
                'provider_dispatch_performed_by_this_endpoint' => false,
                'selected_repository_remains_primary_workspace' => true,
                'frontend_app_is_subscope_only' => true,
                'space_runtime_required' => false,
                'raw_workspace_path_returned' => false,
                'raw_customer_source_returned' => false,
                'world_best_claim_allowed' => false,
            ],
            'blockers' => $blockers,
            'warnings' => [],
        ];
        $payload['workspace_runtime_projection_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    private function runtimeInput(array $input, string $task, string $workspace, string $frontendApp, string $provider): array
    {
        return [
            'task' => $task,
            'workspace' => $workspace,
            'frontend_app' => $frontendApp,
            'provider' => $provider,
            'surface' => (string) ($input['surface'] ?? 'programming.frontend'),
            'acceptance_criteria' => (bool) ($input['acceptance_criteria'] ?? false),
            'test_plan' => (bool) ($input['test_plan'] ?? false),
            'visual_quality_plan' => (bool) ($input['visual_quality_plan'] ?? false),
            'evidence_plan' => (bool) ($input['evidence_plan'] ?? false),
            'senior_design_review' => (bool) ($input['senior_design_review'] ?? false),
            'asset_context' => (bool) ($input['asset_context'] ?? false),
            'prototype' => (bool) ($input['prototype'] ?? false),
            'live' => (bool) ($input['live'] ?? false),
            'company_profile_ready' => (bool) ($input['company_profile_ready'] ?? false),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function lane(string $id, mixed $status, mixed $hash, array $nextActions): array
    {
        return [
            'id' => $id,
            'status' => is_string($status) ? $status : 'not_evaluated',
            'hash' => is_string($hash) ? $hash : null,
            'next_action_count' => count($nextActions),
            'next_action_ids' => array_values(array_slice(array_filter($nextActions, 'is_string'), 0, 12)),
        ];
    }

    /**
     * @param  array<int,array<string,mixed>>  $lanes
     * @param  array<string,mixed>|null  $providerPacket
     * @return array<int,string>
     */
    private function blockers(bool $selectedReady, bool $invalidFrontendApp, array $lanes, ?array $providerPacket): array
    {
        if (! $selectedReady) {
            return [$invalidFrontendApp ? 'requested_frontend_app_subscope_not_found' : 'selected_repository_workspace_required'];
        }

        $blockers = [];
        foreach ($lanes as $lane) {
            if (in_array($lane['status'] ?? null, ['blocked', 'missing', 'not_project_scoped'], true)) {
                $blockers[] = 'lane_blocked_'.$lane['id'];
            }
        }
        foreach ((array) ($providerPacket['blockers'] ?? []) as $blocker) {
            if (is_string($blocker)) {
                $blockers[] = 'provider_packet_'.$blocker;
            }
        }

        return array_values(array_unique($blockers));
    }

    /**
     * @param  array<string,mixed>  $selected
     * @param  array<int,array<string,mixed>>  $lanes
     * @return array<int,string>
     */
    private function requiredNextActions(array $selected, array $lanes, bool $providerPacketReady): array
    {
        if ($providerPacketReady) {
            return ['dispatch_provider_with_provider_instruction_packet_then_collect_certification_evidence'];
        }

        $actions = [(string) data_get($selected, 'next_best_action.id', 'complete_frontend_context')];
        foreach ($lanes as $lane) {
            array_push($actions, ...array_values(array_filter((array) ($lane['next_action_ids'] ?? []), 'is_string')));
        }

        return array_values(array_unique(array_filter($actions)));
    }

    /**
     * @param  array<string,mixed>|null  $gauntlet
     * @param  array<string,mixed>|null  $providerPacket
     * @return array<string,mixed>
     */
    private function frontendAppScope(array $selected, ?array $gauntlet, ?array $providerPacket): array
    {
        $scope = data_get($providerPacket, 'frontend_app_scope');
        if (! is_array($scope)) {
            $scope = data_get($gauntlet, 'frontend_app_scope');
        }
        if (! is_array($scope)) {
            $scope = data_get($selected, 'confirmed_frontend_app_scope');
        }

        return [
            'status' => (string) ($scope['status'] ?? 'repo_root_or_unconfirmed'),
            'relative_name' => $scope['relative_name'] ?? null,
            'relative_name_hash' => $scope['relative_name_hash'] ?? null,
            'selected_repository_remains_primary_workspace' => true,
            'frontend_app_is_subscope_only' => true,
            'space_runtime_required' => false,
        ];
    }
}
