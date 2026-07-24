<?php

namespace App\Services\Ai\Programming\Frontend;

use App\Services\Ai\Mission\MissionCanonicalHash;

final class AtlasFrontendScenarioMatrixService
{
    public const SCHEMA_VERSION = 'atlas.frontend.scenario_matrix.v1';

    private const MAX_SCENARIOS = 96;

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function compile(array $input): array
    {
        $task = trim((string) ($input['task'] ?? ''));
        $workspace = trim((string) ($input['workspace'] ?? ''));
        $frontendAppScope = AtlasFrontendAppScope::fromRequestedApp($workspace, $input['frontend_app'] ?? null, [
            'reject_raw_absolute' => false,
        ]);
        $surface = AtlasFrontendSurface::fromInput($input);
        $taskSpec = app(AtlasFrontendTaskSpecCompilerService::class)->compile([
            'task' => $task,
            'workspace' => $workspace,
            'surface' => $surface,
            'acceptance' => (bool) ($input['acceptance_criteria'] ?? $input['acceptance'] ?? false),
            'asset_context' => (bool) ($input['asset_context'] ?? false),
            'company_profile' => (bool) ($input['company_profile_ready'] ?? false),
            'prototype' => (bool) ($input['prototype'] ?? false),
            'live' => (bool) ($input['live'] ?? false),
            'routes' => $input['routes'] ?? [],
            'hints' => $input['hints'] ?? [],
        ]);

        $routes = array_values(array_filter((array) ($taskSpec['routes'] ?? []), 'is_string'));
        $viewports = array_values(array_filter((array) ($taskSpec['viewports'] ?? []), 'is_string'));
        $states = array_values(array_filter((array) ($taskSpec['states'] ?? []), 'is_string'));
        $scenarios = $this->scenarios($routes, $viewports, $states);
        $blockers = array_values(array_unique(array_merge(
            $this->blockers($taskSpec, $scenarios),
            (array) ($frontendAppScope['blockers'] ?? []),
        )));
        $warnings = count($routes) * count($viewports) * count($states) > self::MAX_SCENARIOS
            ? ['scenario_matrix_truncated']
            : [];

        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $blockers === [] ? 'ready' : 'blocked',
            'source' => self::class,
            'matrix_type' => 'route_viewport_state_visual_verification_matrix',
            'task_hash' => $task !== '' ? hash('sha256', $task) : null,
            'workspace_hash' => $workspace !== '' ? hash('sha256', $workspace) : null,
            'frontend_app_scope' => $frontendAppScope,
            'task_spec_hash' => $taskSpec['task_spec_hash'] ?? null,
            'task_spec_status' => $taskSpec['status'] ?? 'unknown',
            'coverage' => [
                'route_count' => count($routes),
                'viewport_count' => count($viewports),
                'state_count' => count($states),
                'scenario_count' => count($scenarios),
                'max_scenarios' => self::MAX_SCENARIOS,
            ],
            'required_artifacts_per_scenario' => [
                'screenshot_or_video_ref',
                'console_result',
                'a11y_result_or_reason',
                'responsive_fit_result',
                'text_overlap_result',
                'state_assertion',
            ],
            'scenarios' => $scenarios,
            'claim_policy' => [
                'visual_done_requires_scenario_matrix_evidence' => true,
                'scenario_matrix_is_not_completion_evidence' => true,
                'raw_customer_source_returned' => false,
                'world_best_claim_allowed' => false,
            ],
            'blockers' => $blockers,
            'warnings' => $warnings,
        ];
        $payload['scenario_matrix_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * @param  array<int,string>  $routes
     * @param  array<int,string>  $viewports
     * @param  array<int,string>  $states
     * @return array<int,array<string,mixed>>
     */
    private function scenarios(array $routes, array $viewports, array $states): array
    {
        $scenarios = [];
        foreach ($routes as $route) {
            foreach ($viewports as $viewport) {
                foreach ($states as $state) {
                    $id = $route.'|'.$viewport.'|'.$state;
                    $scenarios[] = [
                        'id_hash' => hash('sha256', $id),
                        'route' => $route,
                        'viewport' => $viewport,
                        'state' => $state,
                        'required_checks' => [
                            'screenshot_or_video_ref',
                            'console_clean',
                            'a11y_or_reason',
                            'responsive_fit',
                            'text_overlap',
                            'state_assertion',
                        ],
                    ];
                    if (count($scenarios) >= self::MAX_SCENARIOS) {
                        return $scenarios;
                    }
                }
            }
        }

        return $scenarios;
    }

    /**
     * @param  array<string,mixed>  $taskSpec
     * @param  array<int,array<string,mixed>>  $scenarios
     * @return array<int,string>
     */
    private function blockers(array $taskSpec, array $scenarios): array
    {
        $blockers = [];
        if (($taskSpec['status'] ?? null) !== 'ready') {
            $blockers[] = 'task_spec_not_ready';
        }
        if ($scenarios === []) {
            $blockers[] = 'scenario_matrix_empty';
        }

        return array_values(array_unique($blockers));
    }
}
