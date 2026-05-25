<?php

namespace App\Services\Ai\Programming\Frontend;

use App\Services\Ai\Mission\MissionCanonicalHash;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RuntimeException;

final class AtlasFrontendCompanyPortfolioService
{
    public const SCHEMA_VERSION = 'atlas.frontend.company_portfolio.v1';

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function scan(array $input): array
    {
        $root = rtrim(trim((string) ($input['root'] ?? '')), DIRECTORY_SEPARATOR);
        $task = trim((string) ($input['task'] ?? ''));
        $maxDepth = max(1, min(4, (int) ($input['max_depth'] ?? 2)));
        $maxRepos = max(1, min(100, (int) ($input['max_repos'] ?? 30)));

        if ($root === '' || ! File::isDirectory($root)) {
            return $this->blocked($root, $task, 'root_not_found');
        }

        $repositories = [];
        foreach ($this->repositoryRoots($root, $maxDepth, $maxRepos) as $repositoryRoot) {
            $repositories[] = $this->repoStatus($root, (string) ($repositoryRoot['workspace'] ?? ''), $task, (array) ($repositoryRoot['markers'] ?? []));
        }

        $summary = [
            'candidate_repo_count' => count($repositories),
            'ready_for_operator_execution_count' => collect($repositories)->where('status', 'ready_for_operator_execution')->count(),
            'prepared_needs_context_count' => collect($repositories)->where('status', 'prepared_needs_context')->count(),
            'blocked_count' => collect($repositories)->where('status', 'blocked')->count(),
            'skill_installed_count' => collect($repositories)->where('skill_pack_installed', true)->count(),
            'selected_workspace_handoff_required' => true,
            'portfolio_dispatch_allowed' => false,
        ];

        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $repositories === [] ? 'blocked' : 'ready',
            'portfolio_type' => 'local_company_frontend_repo_portfolio',
            'inventory_role' => 'optional_repository_discovery_only',
            'primary_runtime_entrypoint' => 'operator_selected_repository_workspace',
            'source' => self::class,
            'root_hash' => hash('sha256', $root),
            'task_binding' => $this->taskBinding($task),
            'scan_policy' => [
                'discovery_model' => 'local_folder_with_multiple_repositories',
                'repo_root_markers' => $this->repoRootMarkers(),
                'max_depth' => $maxDepth,
                'max_repos' => $maxRepos,
                'portfolio_root_is_not_selected_workspace' => true,
                'raw_source_returned' => false,
                'absolute_paths_returned' => false,
                'invokes_provider' => false,
                'executes_repo_commands' => false,
                'portfolio_scan_required_for_frontend_dispatch' => false,
                'selected_workspace_required_for_frontend_dispatch' => true,
            ],
            'summary' => $summary,
            'repositories' => $repositories,
            'selection_brief' => $this->selectionBrief($repositories),
            'selection_handoff' => $this->selectionHandoff($repositories),
            'recommended_next_actions' => $this->nextActions($repositories),
            'claim_policy' => [
                'portfolio_scan_is_not_execution_evidence' => true,
                'portfolio_root_is_not_selected_workspace' => true,
                'portfolio_candidate_is_not_selected_workspace' => true,
                'provider_dispatch_requires_selected_workspace_contract' => true,
                'onboarding_required_before_provider_dispatch' => true,
                'completion_requires_repo_level_run_certification' => true,
                'raw_customer_source_returned' => false,
                'world_best_claim_allowed' => false,
            ],
            'blockers' => $repositories === [] ? ['no_package_json_candidates_found'] : [],
            'warnings' => ['portfolio_scan_does_not_execute_repo_commands'],
        ];
        $payload['portfolio_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * @return array<string,mixed>
     */
    private function blocked(string $root, string $task, string $blocker): array
    {
        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => 'blocked',
            'portfolio_type' => 'local_company_frontend_repo_portfolio',
            'inventory_role' => 'optional_repository_discovery_only',
            'primary_runtime_entrypoint' => 'operator_selected_repository_workspace',
            'source' => self::class,
            'root_hash' => $root !== '' ? hash('sha256', $root) : null,
            'task_binding' => $this->taskBinding($task),
            'scan_policy' => [
                'discovery_model' => 'local_folder_with_multiple_repositories',
                'repo_root_markers' => $this->repoRootMarkers(),
                'portfolio_root_is_not_selected_workspace' => true,
                'raw_source_returned' => false,
                'absolute_paths_returned' => false,
                'invokes_provider' => false,
                'executes_repo_commands' => false,
                'portfolio_scan_required_for_frontend_dispatch' => false,
                'selected_workspace_required_for_frontend_dispatch' => true,
            ],
            'summary' => [
                'candidate_repo_count' => 0,
                'ready_for_operator_execution_count' => 0,
                'prepared_needs_context_count' => 0,
                'blocked_count' => 0,
                'skill_installed_count' => 0,
                'selected_workspace_handoff_required' => true,
                'portfolio_dispatch_allowed' => false,
            ],
            'repositories' => [],
            'selection_brief' => $this->selectionBrief([]),
            'selection_handoff' => $this->selectionHandoff([]),
            'recommended_next_actions' => ['provide_existing_portfolio_root'],
            'claim_policy' => [
                'portfolio_scan_is_not_execution_evidence' => true,
                'portfolio_root_is_not_selected_workspace' => true,
                'portfolio_candidate_is_not_selected_workspace' => true,
                'provider_dispatch_requires_selected_workspace_contract' => true,
                'raw_customer_source_returned' => false,
                'world_best_claim_allowed' => false,
            ],
            'blockers' => [$blocker],
            'warnings' => [],
        ];
        $payload['portfolio_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * @return array<int,string>
     */
    private function repositoryRoots(string $root, int $maxDepth, int $maxRepos): array
    {
        $found = [];
        $queue = [[$root, 0]];
        $seen = [];

        while ($queue !== [] && count($found) < $maxRepos) {
            [$directory, $depth] = array_shift($queue);
            if (! is_string($directory) || ! File::isDirectory($directory)) {
                continue;
            }

            $markers = $this->projectMarkers($directory);
            if ($depth > 0 && $markers !== [] && ! isset($seen[$directory])) {
                $seen[$directory] = true;
                $found[] = [
                    'workspace' => $directory,
                    'markers' => $markers,
                ];

                continue;
            }

            if ($depth >= $maxDepth) {
                continue;
            }

            foreach (File::directories($directory) as $child) {
                $name = basename($child);
                if (in_array($name, ['.git', 'node_modules', 'vendor', 'storage', 'dist', 'build'], true)) {
                    continue;
                }
                $queue[] = [$child, $depth + 1];
            }
        }

        return $found;
    }

    /**
     * @return array<int,string>
     */
    private function repoRootMarkers(): array
    {
        return ['.git', 'package.json', 'pnpm-workspace.yaml', 'composer.json', 'artisan', 'pyproject.toml', 'go.mod', 'Package.swift'];
    }

    /**
     * @return array<int,string>
     */
    private function projectMarkers(string $workspace): array
    {
        $markers = [];
        foreach ($this->repoRootMarkers() as $marker) {
            if (File::exists($workspace.DIRECTORY_SEPARATOR.$marker)) {
                $markers[] = $marker;
            }
        }

        return $markers;
    }

    /**
     * @return array<string,mixed>
     */
    private function repoStatus(string $root, string $workspace, string $task, array $projectMarkers): array
    {
        $relative = trim(str_replace('\\', '/', substr($workspace, strlen($root))), '/');
        $relative = $relative !== '' ? $relative : '.';
        $skillInstalled = File::isFile($workspace.'/.atlas/skills/atlas-frontend/SKILL.md');
        $onboardingReceipt = File::isFile($workspace.'/.atlas/frontend/onboarding-receipt.json');

        try {
            $intake = app(AtlasFrontendRepoIntakeService::class)->inspect($workspace);
        } catch (RuntimeException $exception) {
            $intake = [
                'status' => 'blocked',
                'framework' => ['primary' => null],
                'package_manager' => 'unknown',
                'blockers' => [$exception->getMessage()],
                'recommended_next_actions' => ['confirm_frontend_workspace_or_create_package_manifest'],
            ];
        }

        $intakeReady = ($intake['status'] ?? null) === 'ready';
        $status = match (true) {
            $intakeReady && $skillInstalled => 'ready_for_operator_execution',
            $skillInstalled || in_array(($intake['status'] ?? null), ['ready', 'warning'], true) => 'prepared_needs_context',
            default => 'blocked',
        };
        $taskFit = $this->taskFit($task, $relative, $intake);
        $frontendAppCandidateSummary = $this->frontendAppCandidateSummary($workspace, $task);
        $score = $this->candidateScore($status, $skillInstalled, $onboardingReceipt, $intake, $taskFit);

        return [
            'repo_ref' => [
                'relative_name' => $relative,
                'relative_name_hash' => hash('sha256', $relative),
                'workspace_hash' => hash('sha256', $workspace),
                'project_markers' => $projectMarkers,
            ],
            'status' => $status,
            'candidate_score' => $score,
            'task_fit' => $taskFit,
            'frontend_app_candidate_summary' => $frontendAppCandidateSummary,
            'selection_state' => 'candidate_not_selected',
            'package_manager' => $intake['package_manager'] ?? 'unknown',
            'framework' => data_get($intake, 'framework.primary'),
            'skill_pack_installed' => $skillInstalled,
            'onboarding_receipt_present' => $onboardingReceipt,
            'selection_handoff' => [
                'required' => true,
                'selected_workspace_schema' => AtlasFrontendSelectedWorkspaceService::SCHEMA_VERSION,
                'next_command' => 'php artisan atlas:frontend:selected-workspace --task="<intent>" --workspace=<portfolio-root>/'.$relative.' --json --strict',
                'raw_absolute_path_returned' => false,
            ],
            'dispatch_policy' => [
                'provider_dispatch_allowed' => false,
                'reason' => 'portfolio_inventory_cannot_authorize_provider_dispatch',
                'requires_selected_workspace_contract' => true,
                'run_onboarding_first' => ! $skillInstalled,
                'fill_design_context_first' => ($intake['status'] ?? null) !== 'ready',
            ],
            'blockers' => (array) ($intake['blockers'] ?? []),
            'recommended_next_actions' => $status === 'ready_for_operator_execution'
                ? ['run_proof_pilot_for_specific_frontend_task']
                : array_values(array_unique(array_merge(
                    $skillInstalled ? [] : ['run_atlas_frontend_onboard_for_repo'],
                    (array) ($intake['recommended_next_actions'] ?? []),
                ))),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function frontendAppCandidateSummary(string $workspace, string $task): array
    {
        $selectedWorkspace = app(AtlasFrontendSelectedWorkspaceService::class)->resolve([
            'workspace' => $workspace,
            'task' => $task,
            'selection_source' => 'portfolio_scan_projection',
        ]);
        $candidates = (array) data_get($selectedWorkspace, 'frontend_app_candidates.candidates', []);

        return [
            'schema_version' => 'atlas.frontend.company_portfolio.frontend_app_candidate_summary.v1',
            'status' => data_get($selectedWorkspace, 'frontend_app_candidates.status', 'not_evaluated'),
            'candidate_count' => (int) data_get($selectedWorkspace, 'frontend_app_candidates.candidate_count', 0),
            'monorepo_like' => (bool) data_get($selectedWorkspace, 'frontend_app_candidates.monorepo_like', false),
            'primary_candidate_ref' => data_get($selectedWorkspace, 'frontend_app_candidates.primary_candidate_ref'),
            'primary_candidate_relative_name_hash' => data_get($selectedWorkspace, 'frontend_app_candidates.primary_candidate_relative_name') !== null
                ? hash('sha256', (string) data_get($selectedWorkspace, 'frontend_app_candidates.primary_candidate_relative_name'))
                : null,
            'operator_confirmation_required' => (bool) data_get($selectedWorkspace, 'frontend_app_candidates.operator_decision.required', false),
            'operator_action' => data_get($selectedWorkspace, 'frontend_app_candidates.operator_decision.action'),
            'top_candidates' => collect($candidates)
                ->take(3)
                ->map(fn (array $candidate): array => [
                    'app_ref' => $candidate['app_ref'] ?? null,
                    'relative_name_hash' => isset($candidate['relative_name']) ? hash('sha256', (string) $candidate['relative_name']) : null,
                    'score' => (int) ($candidate['score'] ?? 0),
                    'framework_signals' => $candidate['framework_signals'] ?? [],
                    'command_inventory' => $candidate['command_inventory'] ?? [],
                    'selection_state' => $candidate['selection_state'] ?? 'frontend_app_candidate_not_selected',
                ])
                ->values()
                ->all(),
            'claim_policy' => [
                'portfolio_still_requires_selected_workspace_contract' => true,
                'frontend_app_candidate_is_subscope_not_repo' => true,
                'raw_relative_names_returned' => false,
                'raw_absolute_paths_returned' => false,
                'provider_dispatch_allowed' => false,
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $intake
     */
    private function candidateScore(string $status, bool $skillInstalled, bool $onboardingReceipt, array $intake, array $taskFit): int
    {
        $score = match ($status) {
            'ready_for_operator_execution' => 80,
            'prepared_needs_context' => 50,
            default => 10,
        };

        $score += $skillInstalled ? 5 : 0;
        $score += $onboardingReceipt ? 5 : 0;
        $score += data_get($intake, 'framework.primary') !== null ? 5 : 0;
        $score += count((array) data_get($intake, 'repo_map.test_commands', [])) > 0 ? 3 : 0;
        $score += count((array) data_get($intake, 'repo_map.build_commands', [])) > 0 ? 2 : 0;
        $score += (int) floor(((int) ($taskFit['score'] ?? 0)) / 5);

        return min(100, $score);
    }

    /**
     * @param  array<string,mixed>  $intake
     * @return array<string,mixed>
     */
    private function taskFit(string $task, string $relative, array $intake): array
    {
        if ($task === '') {
            return [
                'schema_version' => 'atlas.frontend.company_portfolio.task_fit.v1',
                'status' => 'not_provided',
                'score' => 0,
                'matched_signal_count' => 0,
                'matched_signal_hashes' => [],
                'task_hash' => null,
                'raw_task_returned' => false,
            ];
        }

        $tokens = $this->taskTokens($task);
        $haystack = Str::ascii(strtolower(implode(' ', array_filter([
            $relative,
            (string) data_get($intake, 'framework.primary', ''),
            (string) ($intake['package_manager'] ?? ''),
            implode(' ', (array) data_get($intake, 'framework.detected', [])),
            implode(' ', collect((array) data_get($intake, 'repo_map.route_candidates', []))
                ->pluck('path')
                ->take(20)
                ->all()),
        ]))));
        $matched = array_values(array_filter($tokens, fn (string $token): bool => str_contains($haystack, $token)));
        $score = min(100, count($matched) * 20);

        return [
            'schema_version' => 'atlas.frontend.company_portfolio.task_fit.v1',
            'status' => $score > 0 ? 'matched' : 'no_obvious_match',
            'score' => $score,
            'matched_signal_count' => count($matched),
            'matched_signal_hashes' => array_map(fn (string $signal): string => hash('sha256', $signal), $matched),
            'task_hash' => hash('sha256', $task),
            'raw_task_returned' => false,
        ];
    }

    /**
     * @return array<int,string>
     */
    private function taskTokens(string $task): array
    {
        $normalized = Str::ascii(strtolower($task));
        preg_match_all('/[a-z0-9][a-z0-9_-]{2,}/', $normalized, $matches);

        return collect($matches[0] ?? [])
            ->reject(fn (string $token): bool => in_array($token, [
                'frontend', 'front', 'design', 'tela', 'page', 'pagina', 'site', 'app', 'web', 'repo', 'repositorio',
                'refinar', 'melhorar', 'ajustar', 'criar', 'implementar', 'premium', 'responsivo',
            ], true))
            ->unique()
            ->take(12)
            ->values()
            ->all();
    }

    /**
     * @return array<string,mixed>
     */
    private function taskBinding(string $task): array
    {
        return [
            'schema_version' => 'atlas.frontend.company_portfolio.task_binding.v1',
            'status' => $task === '' ? 'missing_task' : 'task_bound_for_candidate_ranking',
            'task_present' => $task !== '',
            'task_hash' => $task !== '' ? hash('sha256', $task) : null,
            'raw_task_returned' => false,
            'claim_policy' => [
                'task_binding_guides_ranking_only' => true,
                'selected_workspace_still_required' => true,
            ],
        ];
    }

    /**
     * @param  array<int,array<string,mixed>>  $repositories
     * @return array<string,mixed>
     */
    private function selectionHandoff(array $repositories): array
    {
        $primaryCandidate = collect($repositories)
            ->sortByDesc(fn (array $repo): int => (int) ($repo['candidate_score'] ?? 0))
            ->first();

        return [
            'schema_version' => 'atlas.frontend.company_portfolio.selection_handoff.v1',
            'status' => $primaryCandidate === null ? 'no_candidates' : 'candidate_ready_for_operator_selection',
            'selected_workspace_schema' => AtlasFrontendSelectedWorkspaceService::SCHEMA_VERSION,
            'primary_candidate_ref' => is_array($primaryCandidate) ? data_get($primaryCandidate, 'repo_ref.relative_name_hash') : null,
            'next_command' => 'php artisan atlas:frontend:selected-workspace --task="<intent>" --workspace=<chosen-local-company-repo> --json --strict',
            'claim_policy' => [
                'handoff_selects_one_repo_before_runtime' => true,
                'portfolio_scan_only_discovers_repository_candidates' => true,
                'raw_absolute_path_returned' => false,
            ],
        ];
    }

    /**
     * @param  array<int,array<string,mixed>>  $repositories
     * @return array<string,mixed>
     */
    private function selectionBrief(array $repositories): array
    {
        $ranked = collect($repositories)
            ->sortByDesc(fn (array $repo): int => (int) ($repo['candidate_score'] ?? 0))
            ->values();
        $primary = $ranked->first();

        return [
            'schema_version' => 'atlas.frontend.company_portfolio.selection_brief.v1',
            'status' => $ranked->isEmpty() ? 'no_candidates' : 'ready_for_operator_choice',
            'purpose' => 'help_operator_choose_one_repository_before_selected_workspace_runtime',
            'primary_candidate_ref' => is_array($primary) ? data_get($primary, 'repo_ref.relative_name_hash') : null,
            'ranked_candidates' => $ranked
                ->take(10)
                ->map(fn (array $repo, int $index): array => [
                    'rank' => $index + 1,
                    'candidate_ref' => data_get($repo, 'repo_ref.relative_name_hash'),
                    'display_label' => data_get($repo, 'repo_ref.relative_name'),
                    'status' => $repo['status'] ?? 'unknown',
                    'score' => (int) ($repo['candidate_score'] ?? 0),
                    'framework' => $repo['framework'] ?? null,
                    'package_manager' => $repo['package_manager'] ?? 'unknown',
                    'task_fit_status' => data_get($repo, 'task_fit.status', 'not_provided'),
                    'task_fit_score' => (int) data_get($repo, 'task_fit.score', 0),
                    'task_fit_signal_count' => (int) data_get($repo, 'task_fit.matched_signal_count', 0),
                    'frontend_app_candidate_status' => data_get($repo, 'frontend_app_candidate_summary.status', 'not_evaluated'),
                    'frontend_app_candidate_count' => (int) data_get($repo, 'frontend_app_candidate_summary.candidate_count', 0),
                    'frontend_app_primary_candidate_ref' => data_get($repo, 'frontend_app_candidate_summary.primary_candidate_ref'),
                    'frontend_app_operator_confirmation_required' => (bool) data_get($repo, 'frontend_app_candidate_summary.operator_confirmation_required', false),
                    'selection_state' => $repo['selection_state'] ?? 'candidate_not_selected',
                    'decision_reasons' => $this->decisionReasons($repo),
                    'must_do_before_execution' => $this->mustDoBeforeExecution($repo),
                ])
                ->all(),
            'operator_decision' => [
                'required' => ! $ranked->isEmpty(),
                'action' => $ranked->isEmpty() ? 'provide_existing_portfolio_root' : 'choose_one_candidate_then_run_selected_workspace',
                'command' => 'php artisan atlas:frontend:selected-workspace --task="<intent>" --workspace=<chosen-local-company-repo> --json --strict',
            ],
            'claim_policy' => [
                'selection_brief_is_not_runtime_scope' => true,
                'selection_brief_is_not_execution_evidence' => true,
                'raw_absolute_paths_returned' => false,
                'provider_dispatch_allowed' => false,
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $repo
     * @return array<int,string>
     */
    private function decisionReasons(array $repo): array
    {
        $reasons = [];
        if (($repo['status'] ?? null) === 'ready_for_operator_execution') {
            $reasons[] = 'ready_frontend_context_and_skill_pack';
        } elseif (($repo['status'] ?? null) === 'prepared_needs_context') {
            $reasons[] = 'frontend_repo_detected_but_context_or_skill_incomplete';
        } else {
            $reasons[] = 'blocked_until_frontend_operating_map_is_repaired';
        }
        if (($repo['framework'] ?? null) !== null) {
            $reasons[] = 'framework_detected';
        }
        if ((bool) ($repo['skill_pack_installed'] ?? false)) {
            $reasons[] = 'atlas_frontend_skill_pack_installed';
        }
        if ((bool) ($repo['onboarding_receipt_present'] ?? false)) {
            $reasons[] = 'onboarding_receipt_present';
        }

        return array_values(array_unique($reasons));
    }

    /**
     * @param  array<string,mixed>  $repo
     * @return array<int,string>
     */
    private function mustDoBeforeExecution(array $repo): array
    {
        return array_values(array_unique(array_filter([
            'run_selected_workspace_contract',
            (bool) ($repo['skill_pack_installed'] ?? false) ? null : 'run_or_confirm_atlas_frontend_onboarding',
            (bool) data_get($repo, 'dispatch_policy.fill_design_context_first') ? 'complete_design_context' : null,
            'run_pre_execution_gate_provider_packet_runbook_and_evidence',
        ])));
    }

    /**
     * @param  array<int,array<string,mixed>>  $repositories
     * @return array<int,string>
     */
    private function nextActions(array $repositories): array
    {
        if ($repositories === []) {
            return ['point_portfolio_scan_at_parent_directory_with_company_repos'];
        }

        return collect(['choose_one_candidate_and_run_selected_workspace_contract'])
            ->merge(collect($repositories)
                ->flatMap(fn (array $repo): array => (array) ($repo['recommended_next_actions'] ?? []))
            )
            ->unique()
            ->values()
            ->all();
    }
}
