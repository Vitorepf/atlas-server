<?php

namespace App\Services\Ai\Programming\Frontend;

use App\Services\Ai\Mission\MissionCanonicalHash;
use Illuminate\Support\Facades\File;
use RuntimeException;

final class AtlasFrontendSelectedWorkspaceService
{
    public const SCHEMA_VERSION = 'atlas.frontend.selected_workspace.v1';

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function resolve(array $input): array
    {
        $workspace = rtrim(trim((string) ($input['workspace'] ?? '')), DIRECTORY_SEPARATOR);
        $source = trim((string) ($input['selection_source'] ?? $input['surface'] ?? 'unknown')) ?: 'unknown';
        $task = trim((string) ($input['task'] ?? ''));
        $requestedFrontendApp = $this->requestedFrontendAppRelativeName($input['frontend_app'] ?? null);
        $resolved = $workspace !== '' ? (realpath($workspace) ?: $workspace) : '';
        $exists = $resolved !== '' && is_dir($resolved);
        $markers = $exists ? $this->markers($resolved) : [];
        $selected = $exists && $markers !== [];
        $operatingSummary = $selected ? $this->operatingSummary($resolved) : null;
        $frontendAppCandidates = $selected ? $this->frontendAppCandidates($resolved, $requestedFrontendApp) : $this->emptyFrontendAppCandidates();
        $confirmedFrontendApp = $this->confirmedFrontendApp($frontendAppCandidates);
        $dispatchReadiness = $this->dispatchReadiness($selected, $operatingSummary, $frontendAppCandidates);
        $capabilityReadiness = $this->capabilityReadiness($selected, $operatingSummary);
        $operatorStartPanel = $this->operatorStartPanel($selected, $operatingSummary, $dispatchReadiness, $capabilityReadiness);
        $taskBinding = $this->taskBinding($task, $selected);
        $nextBestAction = $this->nextBestAction($selected, $dispatchReadiness, $capabilityReadiness, $operatorStartPanel, $taskBinding, $frontendAppCandidates, $confirmedFrontendApp);
        $runtimeProjection = $this->runtimeProjection($selected, $dispatchReadiness, $taskBinding, $frontendAppCandidates, $confirmedFrontendApp);

        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $selected ? 'selected' : ($exists ? 'not_project_scoped' : 'missing'),
            'selection_type' => 'operator_selected_repository_workspace',
            'selection_source' => $source,
            'primary_entrypoint' => 'selected_repository',
            'portfolio_scan_required' => false,
            'parallel_project_runtime_required' => false,
            'workspace_mode' => $selected ? 'local_company_or_product_repo' : 'generic_or_missing_workspace',
            'workspace_label' => $exists ? basename($resolved) : null,
            'workspace_hash' => $resolved !== '' ? hash('sha256', $resolved) : null,
            'project_markers' => $markers,
            'repo_operating_summary' => $operatingSummary,
            'frontend_app_candidates' => $frontendAppCandidates,
            'confirmed_frontend_app_scope' => $confirmedFrontendApp !== null ? [
                'status' => 'subscope_selected',
                'relative_name' => $confirmedFrontendApp,
                'relative_name_hash' => hash('sha256', $confirmedFrontendApp),
                'claim_policy' => [
                    'selected_repository_remains_primary_workspace' => true,
                    'relative_subscope_is_not_workspace' => true,
                    'raw_absolute_paths_returned' => false,
                ],
            ] : [
                'status' => 'not_selected',
                'relative_name' => null,
                'relative_name_hash' => null,
            ],
            'dispatch_readiness' => $dispatchReadiness,
            'capability_readiness' => $capabilityReadiness,
            'operator_start_panel' => $operatorStartPanel,
            'task_binding' => $taskBinding,
            'next_best_action' => $nextBestAction,
            'frontend_runtime_projection' => $runtimeProjection,
            'attachable_runtime_components' => [
                'selected_workspace_contract',
                'enterprise_bootstrap',
                'company_repo_onboarding_read_only_projection',
                'execution_runbook_read_only_projection',
                'provider_instruction_packet_read_only_projection',
                'evidence_kit_explicit_prepare',
                'run_certification',
            ],
            'recommended_command_sequence' => $this->recommendedCommandSequence($selected, $confirmedFrontendApp),
            'forbidden_assumptions' => [
                'do_not_treat_parent_folder_scan_as_selected_repo',
                'do_not_require_parallel_project_runtime_before_frontend_dispatch',
                'do_not_write_onboarding_or_evidence_from_selection_check',
                'do_not_claim_delivery_from_workspace_selection',
            ],
            'readiness' => [
                'workspace_exists' => $exists,
                'project_scoped' => $selected,
                'atlas_frontend_can_attach_repo_runtime' => $selected,
                'atlas_frontend_runtime_projection_allowed' => (bool) ($dispatchReadiness['runtime_projection_allowed'] ?? false),
                'capability_readiness_status' => $capabilityReadiness['status'] ?? 'not_evaluated',
                'operator_start_panel_status' => $operatorStartPanel['status'] ?? 'not_evaluated',
                'next_best_action_id' => $nextBestAction['id'] ?? 'not_evaluated',
                'frontend_runtime_projection_status' => $runtimeProjection['status'] ?? 'not_evaluated',
                'frontend_app_candidate_status' => $frontendAppCandidates['status'] ?? 'not_evaluated',
                'frontend_app_candidate_count' => (int) ($frontendAppCandidates['candidate_count'] ?? 0),
                'frontend_app_candidate_invalid' => (bool) ($frontendAppCandidates['requested_candidate_invalid'] ?? false),
                'task_bound' => (bool) ($taskBinding['task_present'] ?? false),
                'repo_operating_summary_available' => $operatingSummary !== null,
                'portfolio_scan_is_optional_inventory_only' => true,
                'write_performed' => false,
            ],
            'claim_policy' => [
                'raw_workspace_path_returned' => false,
                'selected_repo_is_runtime_scope' => true,
                'portfolio_scan_is_not_primary_entrypoint' => true,
                'frontend_app_candidate_is_subscope_not_new_workspace' => true,
                'selected_repository_is_sufficient_for_frontend_dispatch' => true,
                'selection_check_is_not_delivery_evidence' => true,
                'provider_dispatch_requires_task_binding' => true,
            ],
            'required_next_actions' => $selected
                ? ['run_atlas_frontend_onboarding_or_dev_forge_projection_for_selected_repo']
                : [$exists ? 'choose_a_project_scoped_repository_not_parent_folder' : 'choose_existing_local_repository_workspace'],
            'blockers' => $selected ? [] : [$exists ? 'workspace_not_project_scoped' : 'workspace_missing'],
            'warnings' => [],
        ];
        $payload['selected_workspace_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * @return array<int,string>
     */
    private function markers(string $workspace): array
    {
        $markers = [];
        foreach (['.git', 'composer.json', 'package.json', 'pnpm-workspace.yaml', 'artisan', 'pyproject.toml', 'go.mod', 'Package.swift'] as $marker) {
            if (file_exists($workspace.DIRECTORY_SEPARATOR.$marker)) {
                $markers[] = $marker;
            }
        }

        return $markers;
    }

    /**
     * @return array<string,mixed>|null
     */
    private function operatingSummary(string $workspace): ?array
    {
        try {
            $intake = app(AtlasFrontendRepoIntakeService::class)->inspect($workspace);
        } catch (RuntimeException) {
            return null;
        }

        return [
            'schema_version' => 'atlas.frontend.selected_workspace.operating_summary.v1',
            'repo_intake_status' => $intake['status'] ?? 'unknown',
            'repo_intake_hash' => $intake['repo_intake_hash'] ?? null,
            'package_manager' => $intake['package_manager'] ?? 'unknown',
            'framework' => [
                'primary' => data_get($intake, 'framework.primary'),
                'default_url' => data_get($intake, 'framework.default_url'),
                'detected' => data_get($intake, 'framework.detected', []),
            ],
            'command_inventory' => [
                'test_command_count' => count((array) data_get($intake, 'repo_map.test_commands', [])),
                'build_command_count' => count((array) data_get($intake, 'repo_map.build_commands', [])),
                'quality_command_count' => count((array) data_get($intake, 'repo_map.quality_commands', [])),
            ],
            'design_context' => [
                'dossier_status' => data_get($intake, 'design_context.dossier_status', 'unknown'),
                'inventory_status' => data_get($intake, 'design_context.inventory_status', 'unknown'),
                'component_count' => data_get($intake, 'design_context.component_count', 0),
            ],
            'claim_policy' => [
                'operating_summary_is_not_completion_evidence' => true,
                'raw_source_returned' => false,
                'absolute_paths_returned' => false,
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function frontendAppCandidates(string $workspace, ?string $requestedFrontendApp): array
    {
        $candidates = [];
        foreach ($this->packageJsonPaths($workspace, 3, 40) as $packagePath) {
            $directory = dirname($packagePath);
            $relative = trim(str_replace('\\', '/', substr($directory, strlen($workspace))), '/');
            $relative = $relative !== '' ? $relative : '.';
            $package = $this->package($packagePath);
            $scripts = collect((array) ($package['scripts'] ?? []))
                ->filter(fn (mixed $command, mixed $name): bool => is_string($name) && is_string($command));
            $frameworks = $this->frameworkSignals($package, $scripts->all());
            $frontendScore = $this->frontendAppScore($relative, $frameworks, $scripts->all());

            if ($frontendScore <= 0) {
                continue;
            }

            $candidates[] = [
                'app_ref' => hash('sha256', $relative),
                'relative_name' => $relative,
                'selection_state' => $requestedFrontendApp === $relative ? 'frontend_app_candidate_selected' : 'frontend_app_candidate_not_selected',
                'score' => $frontendScore,
                'framework_signals' => $frameworks,
                'command_inventory' => [
                    'dev_script_present' => $scripts->keys()->contains(fn (string $name): bool => in_array($name, ['dev', 'start'], true) || str_contains($name, 'dev')),
                    'test_script_present' => $scripts->keys()->contains(fn (string $name): bool => str_contains($name, 'test')),
                    'build_script_present' => $scripts->keys()->contains(fn (string $name): bool => str_contains($name, 'build')),
                ],
            ];
        }

        $ranked = collect($candidates)
            ->sortByDesc(fn (array $candidate): int => (int) ($candidate['score'] ?? 0))
            ->values()
            ->all();
        $primary = $ranked[0] ?? null;
        $primaryRelative = is_array($primary) ? (string) ($primary['relative_name'] ?? '.') : null;
        $requestedCandidateSelected = $requestedFrontendApp !== null && collect($ranked)
            ->contains(fn (array $candidate): bool => ($candidate['relative_name'] ?? null) === $requestedFrontendApp);
        $requestedCandidateInvalid = $requestedFrontendApp !== null && ! $requestedCandidateSelected;
        $status = match (true) {
            $ranked === [] => 'no_frontend_app_candidates',
            $requestedCandidateInvalid => 'requested_frontend_app_subscope_invalid',
            $primaryRelative !== '.' => 'nested_frontend_app_candidate_recommended',
            default => 'root_frontend_app_candidate',
        };
        $confirmationRequired = $status === 'requested_frontend_app_subscope_invalid'
            || ($status === 'nested_frontend_app_candidate_recommended' && ! $requestedCandidateSelected);

        return [
            'schema_version' => 'atlas.frontend.selected_workspace.app_candidates.v1',
            'status' => $status,
            'candidate_count' => count($ranked),
            'monorepo_like' => count($ranked) > 1 || ($primaryRelative !== null && $primaryRelative !== '.'),
            'primary_candidate_ref' => is_array($primary) ? ($primary['app_ref'] ?? null) : null,
            'primary_candidate_relative_name' => $primaryRelative,
            'requested_candidate_relative_name_hash' => $requestedFrontendApp !== null ? hash('sha256', $requestedFrontendApp) : null,
            'requested_candidate_selected' => $requestedCandidateSelected,
            'requested_candidate_invalid' => $requestedCandidateInvalid,
            'candidates' => array_slice($ranked, 0, 10),
            'operator_decision' => [
                'required' => $confirmationRequired,
                'action' => match ($status) {
                    'requested_frontend_app_subscope_invalid' => 'choose_valid_frontend_app_subscope_inside_selected_repo',
                    'nested_frontend_app_candidate_recommended' => 'confirm_frontend_app_candidate_inside_selected_repo',
                    default => null,
                },
                'command' => $confirmationRequired
                    ? 'php artisan atlas:frontend:selected-workspace --task="<intent>" --workspace=<selected-repo> --frontend-app='.$primaryRelative.' --json --strict'
                    : null,
                'blockers' => $status === 'requested_frontend_app_subscope_invalid'
                    ? ['requested_frontend_app_subscope_not_found']
                    : [],
            ],
            'claim_policy' => [
                'selected_repository_remains_primary_workspace' => true,
                'frontend_app_candidate_is_subscope_not_new_project' => true,
                'raw_absolute_paths_returned' => false,
                'provider_dispatch_allowed' => false,
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function emptyFrontendAppCandidates(): array
    {
        return [
            'schema_version' => 'atlas.frontend.selected_workspace.app_candidates.v1',
            'status' => 'workspace_not_selected',
            'candidate_count' => 0,
            'monorepo_like' => false,
            'primary_candidate_ref' => null,
            'primary_candidate_relative_name' => null,
            'candidates' => [],
            'operator_decision' => [
                'required' => false,
                'action' => null,
                'command' => null,
            ],
            'claim_policy' => [
                'selected_repository_remains_primary_workspace' => true,
                'frontend_app_candidate_is_subscope_not_new_project' => true,
                'raw_absolute_paths_returned' => false,
                'provider_dispatch_allowed' => false,
            ],
        ];
    }

    /**
     * @return array<int,string>
     */
    private function packageJsonPaths(string $workspace, int $maxDepth, int $maxRepos): array
    {
        $found = [];
        $queue = [[$workspace, 0]];

        while ($queue !== [] && count($found) < $maxRepos) {
            [$directory, $depth] = array_shift($queue);
            if (! is_string($directory) || ! File::isDirectory($directory)) {
                continue;
            }

            $package = $directory.DIRECTORY_SEPARATOR.'package.json';
            if (File::isFile($package)) {
                $found[] = $package;
            }

            if ($depth >= $maxDepth) {
                continue;
            }

            foreach (File::directories($directory) as $child) {
                if (in_array(basename($child), ['.git', 'node_modules', 'vendor', 'storage', 'dist', 'build', '.next'], true)) {
                    continue;
                }
                $queue[] = [$child, $depth + 1];
            }
        }

        return $found;
    }

    /**
     * @return array<string,mixed>
     */
    private function package(string $packagePath): array
    {
        $decoded = json_decode((string) File::get($packagePath), true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param  array<string,mixed>  $package
     * @param  array<string,string>  $scripts
     * @return array<int,string>
     */
    private function frameworkSignals(array $package, array $scripts): array
    {
        $haystack = strtolower(json_encode([
            'dependencies' => $package['dependencies'] ?? [],
            'devDependencies' => $package['devDependencies'] ?? [],
            'scripts' => $scripts,
        ], JSON_THROW_ON_ERROR));

        return collect(['next', 'vite', 'nuxt', 'svelte', 'astro', 'react', 'vue', 'angular', 'tailwind'])
            ->filter(fn (string $signal): bool => str_contains($haystack, $signal))
            ->values()
            ->all();
    }

    /**
     * @param  array<int,string>  $frameworks
     * @param  array<string,string>  $scripts
     */
    private function frontendAppScore(string $relative, array $frameworks, array $scripts): int
    {
        $score = count($frameworks) * 15;
        $score += $relative === '.' ? 0 : 10;
        $score += preg_match('/(^|\/)(app|apps|web|frontend|client|site)(\/|$)/', $relative) ? 15 : 0;
        $score += collect(array_keys($scripts))->contains(fn (string $name): bool => in_array($name, ['dev', 'start'], true) || str_contains($name, 'dev')) ? 15 : 0;
        $score += collect(array_keys($scripts))->contains(fn (string $name): bool => str_contains($name, 'build')) ? 10 : 0;
        $score += collect(array_keys($scripts))->contains(fn (string $name): bool => str_contains($name, 'test')) ? 5 : 0;

        return min(100, $score);
    }

    /**
     * @param  array<string,mixed>|null  $operatingSummary
     * @return array<string,mixed>
     */
    private function dispatchReadiness(bool $selected, ?array $operatingSummary, array $frontendAppCandidates): array
    {
        $repoStatus = (string) ($operatingSummary['repo_intake_status'] ?? 'not_available');
        $invalidFrontendAppSubscope = ($frontendAppCandidates['status'] ?? null) === 'requested_frontend_app_subscope_invalid';
        $runtimeProjectionAllowed = $selected && ! $invalidFrontendAppSubscope && in_array($repoStatus, ['ready', 'warning'], true);
        $status = match (true) {
            ! $selected => 'blocked',
            $invalidFrontendAppSubscope => 'blocked',
            $runtimeProjectionAllowed => 'ready_for_runtime_projection',
            default => 'needs_context',
        };

        $blockers = [];
        if (! $selected) {
            $blockers[] = 'selected_repository_workspace_required';
        } elseif ($invalidFrontendAppSubscope) {
            $blockers[] = 'requested_frontend_app_subscope_not_found';
        } elseif (! $runtimeProjectionAllowed) {
            $blockers[] = 'repo_operating_map_not_ready';
        }

        return [
            'schema_version' => 'atlas.frontend.selected_workspace.dispatch_readiness.v1',
            'status' => $status,
            'runtime_projection_allowed' => $runtimeProjectionAllowed,
            'provider_dispatch_allowed' => false,
            'provider_dispatch_requires' => [
                'company_repo_onboarding_ready',
                'pre_execution_gate_passed',
                'provider_instruction_packet_ready',
                'execution_runbook_ready',
            ],
            'next_action' => match ($status) {
                'ready_for_runtime_projection' => 'attach_read_only_frontend_runtime_projection_then_run_onboarding_or_gate',
                'needs_context' => 'complete_repo_operating_map_design_context_or_package_manifest',
                'blocked' => $invalidFrontendAppSubscope ? 'choose_valid_frontend_app_subscope_inside_selected_repo' : 'select_existing_project_scoped_repository',
                default => 'select_existing_project_scoped_repository',
            },
            'blockers' => $blockers,
            'claim_policy' => [
                'selection_readiness_is_not_provider_dispatch' => true,
                'provider_dispatch_requires_gate_and_onboarding' => true,
                'raw_workspace_path_returned' => false,
            ],
        ];
    }

    /**
     * @param  array<string,mixed>|null  $operatingSummary
     * @return array<string,mixed>
     */
    private function capabilityReadiness(bool $selected, ?array $operatingSummary): array
    {
        $previewReady = $selected && data_get($operatingSummary, 'framework.default_url') !== null;
        $testReady = (int) data_get($operatingSummary, 'command_inventory.test_command_count', 0) > 0;
        $buildReady = (int) data_get($operatingSummary, 'command_inventory.build_command_count', 0) > 0;
        $qualityReady = (int) data_get($operatingSummary, 'command_inventory.quality_command_count', 0) > 0;
        $designReady = data_get($operatingSummary, 'design_context.dossier_status') === 'ready'
            && data_get($operatingSummary, 'design_context.inventory_status') !== 'blocked';
        $liveModeCandidate = $previewReady && data_get($operatingSummary, 'framework.primary') !== null;

        $capabilities = [
            $this->capability('local_preview', $previewReady, 'add_or_fix_frontend_dev_script_and_framework_adapter'),
            $this->capability('test_verification', $testReady, 'add_repo_native_frontend_test_command'),
            $this->capability('build_verification', $buildReady, 'add_repo_native_frontend_build_command'),
            $this->capability('quality_verification', $qualityReady, 'add_lint_typecheck_or_quality_command'),
            $this->capability('design_context', $designReady, 'complete_atlas_frontend_design_dossier'),
            $this->capability('live_mode_candidate', $liveModeCandidate, 'confirm_preview_url_and_browser_bridge_before_live_mode'),
            $this->capability('measured_evidence', false, 'run_evidence_kit_prepare_and_run_certify_after_execution'),
        ];
        $readyCount = count(array_filter($capabilities, fn (array $capability): bool => ($capability['status'] ?? null) === 'ready'));
        $runtimeCapable = $previewReady && $testReady && $buildReady && $qualityReady && $designReady;

        return [
            'schema_version' => 'atlas.frontend.selected_workspace.capability_readiness.v1',
            'status' => ! $selected ? 'blocked' : ($runtimeCapable ? 'runtime_capable' : 'partial'),
            'ready_capability_count' => $readyCount,
            'capability_count' => count($capabilities),
            'capabilities' => $capabilities,
            'claim_policy' => [
                'capability_readiness_is_not_delivery_evidence' => true,
                'measured_evidence_required_for_completion' => true,
                'raw_source_returned' => false,
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function capability(string $id, bool $ready, string $nextAction): array
    {
        return [
            'id' => $id,
            'status' => $ready ? 'ready' : 'missing',
            'next_action' => $ready ? null : $nextAction,
        ];
    }

    /**
     * @param  array<string,mixed>|null  $operatingSummary
     * @param  array<string,mixed>  $dispatchReadiness
     * @param  array<string,mixed>  $capabilityReadiness
     * @return array<string,mixed>
     */
    private function operatorStartPanel(bool $selected, ?array $operatingSummary, array $dispatchReadiness, array $capabilityReadiness): array
    {
        $capabilities = (array) ($capabilityReadiness['capabilities'] ?? []);
        $missingCapabilities = collect($capabilities)
            ->filter(fn (array $capability): bool => ($capability['status'] ?? null) !== 'ready')
            ->pluck('id')
            ->values()
            ->all();
        $runtimeProjectionAllowed = (bool) ($dispatchReadiness['runtime_projection_allowed'] ?? false);

        return [
            'schema_version' => 'atlas.frontend.selected_workspace.operator_start_panel.v1',
            'status' => ! $selected ? 'blocked' : ($runtimeProjectionAllowed ? 'ready_to_project_runtime' : 'needs_context'),
            'workspace_label' => $operatingSummary !== null ? 'selected_repo' : null,
            'headline' => ! $selected ? 'Select a repository to start Atlas Frontend.' : 'Selected repository is ready for Atlas Frontend inspection.',
            'badges' => array_values(array_filter([
                $this->badge('repo', $selected ? 'ready' : 'blocked'),
                $this->badge('framework', data_get($operatingSummary, 'framework.primary') !== null ? (string) data_get($operatingSummary, 'framework.primary') : 'unknown'),
                $this->badge('tests', (int) data_get($operatingSummary, 'command_inventory.test_command_count', 0) > 0 ? 'ready' : 'missing'),
                $this->badge('build', (int) data_get($operatingSummary, 'command_inventory.build_command_count', 0) > 0 ? 'ready' : 'missing'),
                $this->badge('design', $this->capabilityValue($capabilities, 'design_context')),
                $this->badge('evidence', $this->capabilityValue($capabilities, 'measured_evidence')),
            ])),
            'primary_action' => $runtimeProjectionAllowed
                ? 'open_frontend_runtime_projection'
                : ($selected ? 'complete_frontend_context' : 'choose_repository'),
            'disabled_actions' => $runtimeProjectionAllowed
                ? ['provider_dispatch_until_gate_onboarding_packet_and_runbook_pass']
                : ['provider_dispatch', 'world_best_claim', 'completion_claim'],
            'missing_capabilities' => $missingCapabilities,
            'claim_policy' => [
                'panel_is_navigation_not_evidence' => true,
                'provider_dispatch_requires_enterprise_operating_contract' => true,
                'raw_workspace_path_returned' => false,
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function taskBinding(string $task, bool $selected): array
    {
        $taskPresent = $task !== '';

        return [
            'schema_version' => 'atlas.frontend.selected_workspace.task_binding.v1',
            'status' => $taskPresent && $selected ? 'bound' : ($taskPresent ? 'pending_workspace' : 'missing_task'),
            'task_present' => $taskPresent,
            'task_hash' => $taskPresent ? hash('sha256', $task) : null,
            'required_for_provider_dispatch' => true,
            'next_action' => $taskPresent ? null : 'provide_frontend_task_or_user_intent_for_selected_repo',
            'claim_policy' => [
                'raw_task_text_returned' => false,
                'task_binding_is_not_acceptance_criteria' => true,
                'provider_dispatch_requires_task_binding' => true,
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $dispatchReadiness
     * @param  array<string,mixed>  $capabilityReadiness
     * @param  array<string,mixed>  $operatorStartPanel
     * @param  array<string,mixed>  $taskBinding
     * @return array<string,mixed>
     */
    private function nextBestAction(bool $selected, array $dispatchReadiness, array $capabilityReadiness, array $operatorStartPanel, array $taskBinding, array $frontendAppCandidates, ?string $confirmedFrontendApp): array
    {
        $taskBound = (bool) ($taskBinding['task_present'] ?? false) && ($taskBinding['status'] ?? null) === 'bound';
        $runtimeProjectionAllowed = (bool) ($dispatchReadiness['runtime_projection_allowed'] ?? false);
        $missingCapabilities = (array) ($operatorStartPanel['missing_capabilities'] ?? []);
        $missingBlockingCapabilities = array_values(array_diff($missingCapabilities, ['measured_evidence']));
        $frontendAppStatus = (string) ($frontendAppCandidates['status'] ?? 'not_evaluated');
        $frontendAppArg = $confirmedFrontendApp !== null ? ' --frontend-app='.$confirmedFrontendApp : '';

        if (! $selected) {
            return $this->action(
                'choose_repository',
                100,
                'Select a local project repository before Atlas Frontend attaches runtime.',
                'php artisan atlas:frontend:selected-workspace --workspace=<local-company-repo> --json --strict',
                false,
                ['selected_repository_workspace_required']
            );
        }

        if ($frontendAppStatus === 'requested_frontend_app_subscope_invalid') {
            return $this->action(
                'choose_valid_frontend_app_subscope_inside_selected_repo',
                95,
                'Choose a frontend app sub-scope that exists inside the selected repository before projecting Atlas Frontend runtime.',
                'php artisan atlas:frontend:selected-workspace --task="<intent>" --workspace=<selected-repo> --frontend-app='.(string) ($frontendAppCandidates['primary_candidate_relative_name'] ?? '<frontend-app-relative-name>').' --json --strict',
                false,
                ['requested_frontend_app_subscope_not_found']
            );
        }

        if (! $taskBound) {
            return $this->action(
                'bind_task_to_selected_repository',
                90,
                'Bind the operator intent to the selected repository before provider dispatch.',
                'php artisan atlas:frontend:selected-workspace --task="<intent>" --workspace=<local-company-repo> --json --strict',
                false,
                ['frontend_task_or_user_intent_required']
            );
        }

        if (($frontendAppCandidates['operator_decision']['required'] ?? false) === true) {
            return $this->action(
                'confirm_frontend_app_candidate_inside_selected_repo',
                85,
                'Confirm the frontend app sub-scope inside the selected repository before projecting Atlas Frontend runtime.',
                'php artisan atlas:frontend:selected-workspace --task="<intent>" --workspace=<selected-repo> --frontend-app='.(string) ($frontendAppCandidates['primary_candidate_relative_name'] ?? '<frontend-app-relative-name>').' --json --strict',
                false,
                ['frontend_app_candidate_confirmation_required']
            );
        }

        if ($missingBlockingCapabilities !== []) {
            return $this->action(
                'complete_frontend_context',
                80,
                'Complete the repo operating map and design context before runtime projection.',
                'php artisan atlas:frontend:onboard --task="<intent>" --workspace=<local-company-repo>'.$frontendAppArg.' --json --strict',
                true,
                $missingBlockingCapabilities
            );
        }

        if ($runtimeProjectionAllowed) {
            return $this->action(
                'open_frontend_runtime_projection',
                70,
                'Open the Atlas Frontend runtime projection for the selected repo, then run onboarding, gate, provider packet, runbook, evidence kit and certification before dispatch or completion claims.',
                'php artisan atlas:frontend:gauntlet --task="<intent>" --workspace=<local-company-repo>'.$frontendAppArg.' --json --strict',
                false,
                ['provider_dispatch_still_requires_gate_onboarding_packet_runbook_and_evidence']
            );
        }

        return $this->action(
            'repair_repo_operating_map',
            60,
            'Repair package, framework, command or design context detection before Atlas Frontend can project runtime.',
            'php artisan atlas:frontend:enterprise-bootstrap --task="<intent>" --workspace=<local-company-repo> --json --strict',
            false,
            (array) ($dispatchReadiness['blockers'] ?? ['repo_operating_map_not_ready'])
        );
    }

    private function requestedFrontendAppRelativeName(mixed $frontendApp): ?string
    {
        if (! is_string($frontendApp) || trim($frontendApp) === '') {
            return null;
        }

        $relative = trim(str_replace('\\', '/', $frontendApp), '/');

        return $relative !== '' ? $relative : null;
    }

    private function confirmedFrontendApp(array $frontendAppCandidates): ?string
    {
        if (($frontendAppCandidates['requested_candidate_selected'] ?? false) !== true) {
            return null;
        }

        $relative = collect((array) ($frontendAppCandidates['candidates'] ?? []))
            ->first(fn (array $candidate): bool => ($candidate['selection_state'] ?? null) === 'frontend_app_candidate_selected')['relative_name'] ?? null;

        return is_string($relative) && $relative !== '.' && trim($relative) !== ''
            ? $relative
            : null;
    }

    /**
     * @param  array<int,string>  $requires
     * @return array<string,mixed>
     */
    private function action(string $id, int $priority, string $reason, string $command, bool $mayWriteWhenExplicit, array $requires): array
    {
        return [
            'schema_version' => 'atlas.frontend.selected_workspace.next_best_action.v1',
            'id' => $id,
            'priority' => $priority,
            'reason' => $reason,
            'operator_action' => $id,
            'command' => $command,
            'writes_only_when_command_is_explicit' => $mayWriteWhenExplicit,
            'requires' => array_values($requires),
            'claim_policy' => [
                'next_best_action_is_navigation_not_execution_evidence' => true,
                'provider_dispatch_not_authorized_by_next_best_action' => true,
                'raw_workspace_path_returned' => false,
                'raw_task_text_returned' => false,
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function runtimeProjection(bool $selected, array $dispatchReadiness, array $taskBinding, array $frontendAppCandidates, ?string $confirmedFrontendApp): array
    {
        $runtimeProjectionAllowed = (bool) ($dispatchReadiness['runtime_projection_allowed'] ?? false);
        $taskBound = (bool) ($taskBinding['task_present'] ?? false) && ($taskBinding['status'] ?? null) === 'bound';
        $frontendAppInvalid = ($frontendAppCandidates['status'] ?? null) === 'requested_frontend_app_subscope_invalid';
        $frontendAppArg = $confirmedFrontendApp !== null ? ' --frontend-app='.$confirmedFrontendApp : '';
        $blockers = array_values(array_filter([
            ! $selected ? 'selected_repository_workspace_required' : null,
            $frontendAppInvalid ? 'requested_frontend_app_subscope_not_found' : null,
            ! $taskBound ? 'frontend_task_or_user_intent_required' : null,
            ! $runtimeProjectionAllowed ? 'runtime_projection_not_ready' : null,
        ], 'is_string'));

        $payload = [
            'schema_version' => 'atlas.frontend.selected_workspace.runtime_projection.v1',
            'status' => $blockers === [] ? 'ready' : 'blocked',
            'projection_type' => 'read_only_selected_repo_frontend_runtime_bundle',
            'runtime_projection_allowed' => $runtimeProjectionAllowed && $blockers === [],
            'provider_dispatch_allowed' => false,
            'frontend_app_scope' => $confirmedFrontendApp !== null ? [
                'status' => 'subscope_selected',
                'relative_name' => $confirmedFrontendApp,
                'relative_name_hash' => hash('sha256', $confirmedFrontendApp),
                'selected_repository_remains_primary_workspace' => true,
            ] : [
                'status' => 'repo_root_or_unconfirmed',
                'relative_name' => null,
                'relative_name_hash' => null,
                'selected_repository_remains_primary_workspace' => true,
            ],
            'projected_components' => [
                $this->projectionComponent('gauntlet', 'php artisan atlas:frontend:gauntlet --task="<intent>" --workspace=<selected-repo>'.$frontendAppArg.' --json --strict', true),
                $this->projectionComponent('company_repo_onboarding_read_only', 'php artisan atlas:frontend:onboard --task="<intent>" --workspace=<selected-repo>'.$frontendAppArg.' --json --strict', false),
                $this->projectionComponent('provider_instruction_packet_read_only', 'php artisan atlas:frontend:provider-packet --task="<intent>" --workspace=<selected-repo>'.$frontendAppArg.' --provider=<provider> --json --strict', true),
                $this->projectionComponent('execution_runbook_read_only', 'php artisan atlas:frontend:runbook --task="<intent>" --workspace=<selected-repo>'.$frontendAppArg.' --json --strict', true),
                $this->projectionComponent('evidence_kit_explicit_prepare', 'php artisan atlas:frontend:evidence-kit prepare --task="<intent>" --workspace=<selected-repo>'.$frontendAppArg.' --output=<evidence-dir> --json --strict', false),
                $this->projectionComponent('run_certification', 'php artisan atlas:frontend:run-certify --provider-packet=<provider-packet> --visual-report=<report> --design-review-report=<report> --quality-budget-report=<report> --evidence-manifest=<manifest> --outcome-store=<jsonl> --json --strict', false),
            ],
            'required_before_provider_dispatch' => [
                'task_bound_to_selected_repo',
                'valid_frontend_app_subscope_when_monorepo',
                'company_repo_onboarding_ready',
                'pre_execution_gate_passed',
                'provider_instruction_packet_ready',
                'execution_runbook_ready',
            ],
            'blockers' => $blockers,
            'claim_policy' => [
                'runtime_projection_is_not_execution_evidence' => true,
                'read_only_projection_does_not_write_files' => true,
                'provider_dispatch_not_authorized_by_projection' => true,
                'selected_repository_remains_primary_workspace' => true,
                'frontend_app_scope_is_subdirectory_not_workspace' => true,
                'raw_workspace_path_returned' => false,
                'raw_task_text_returned' => false,
            ],
        ];
        $payload['runtime_projection_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * @return array<string,mixed>
     */
    private function projectionComponent(string $id, string $command, bool $readOnly): array
    {
        return [
            'id' => $id,
            'command' => $command,
            'read_only_projection' => $readOnly,
            'command_hash' => hash('sha256', $command),
        ];
    }

    /**
     * @return array<string,string>
     */
    private function badge(string $id, string $value): array
    {
        return ['id' => $id, 'value' => $value];
    }

    /**
     * @param  array<int,array<string,mixed>>  $capabilities
     */
    private function capabilityValue(array $capabilities, string $id): string
    {
        $capability = collect($capabilities)->firstWhere('id', $id);

        return is_array($capability) ? (string) ($capability['status'] ?? 'unknown') : 'unknown';
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function recommendedCommandSequence(bool $selected, ?string $confirmedFrontendApp): array
    {
        $frontendAppArg = $confirmedFrontendApp !== null ? ' --frontend-app='.$confirmedFrontendApp : '';

        return [
            [
                'id' => 'selected_workspace',
                'required' => true,
                'command' => 'php artisan atlas:frontend:selected-workspace --workspace=<local-company-repo> --json --strict',
                'status_when_selected' => $selected ? 'ready' : 'blocked',
            ],
            [
                'id' => 'onboarding',
                'required' => true,
                'command' => 'php artisan atlas:frontend:onboard --task="<intent>" --workspace=<local-company-repo>'.$frontendAppArg.' --json --strict',
                'writes_only_when_command_is_explicit' => true,
            ],
            [
                'id' => 'provider_packet',
                'required' => true,
                'command' => 'php artisan atlas:frontend:provider-packet --task="<intent>" --workspace=<local-company-repo>'.$frontendAppArg.' --provider=<provider> --json --strict',
                'read_only_projection_allowed_before_dispatch' => true,
            ],
            [
                'id' => 'runbook',
                'required' => true,
                'command' => 'php artisan atlas:frontend:runbook --task="<intent>" --workspace=<local-company-repo>'.$frontendAppArg.' --json --strict',
                'evidence_kit_write_requires_explicit_prepare' => true,
            ],
            [
                'id' => 'evidence_and_certification',
                'required' => true,
                'command' => 'php artisan atlas:frontend:evidence-kit prepare --task="<intent>" --workspace=<local-company-repo>'.$frontendAppArg.' --output=<evidence-dir> --json --strict && php artisan atlas:frontend:provider-packet --task="<intent>" --workspace=<local-company-repo>'.$frontendAppArg.' --json --strict > <provider-packet> && php artisan atlas:frontend:run-certify --provider-packet=<provider-packet> --json --strict',
                'completion_claim_requires_measured_receipts' => true,
            ],
        ];
    }
}
