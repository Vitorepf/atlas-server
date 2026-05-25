<?php

namespace App\Services\Ai\Programming\Frontend;

use App\Services\Ai\Mission\MissionCanonicalHash;
use Illuminate\Support\Facades\File;
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
        $maxDepth = max(1, min(4, (int) ($input['max_depth'] ?? 2)));
        $maxRepos = max(1, min(100, (int) ($input['max_repos'] ?? 30)));

        if ($root === '' || ! File::isDirectory($root)) {
            return $this->blocked($root, 'root_not_found');
        }

        $repositories = [];
        foreach ($this->packageJsonPaths($root, $maxDepth, $maxRepos) as $packagePath) {
            $workspace = dirname($packagePath);
            $repositories[] = $this->repoStatus($root, $workspace);
        }

        $summary = [
            'candidate_repo_count' => count($repositories),
            'ready_for_operator_execution_count' => collect($repositories)->where('status', 'ready_for_operator_execution')->count(),
            'prepared_needs_context_count' => collect($repositories)->where('status', 'prepared_needs_context')->count(),
            'blocked_count' => collect($repositories)->where('status', 'blocked')->count(),
            'skill_installed_count' => collect($repositories)->where('skill_pack_installed', true)->count(),
        ];

        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $repositories === [] ? 'blocked' : 'ready',
            'portfolio_type' => 'local_company_frontend_repo_portfolio',
            'source' => self::class,
            'root_hash' => hash('sha256', $root),
            'scan_policy' => [
                'max_depth' => $maxDepth,
                'max_repos' => $maxRepos,
                'raw_source_returned' => false,
                'absolute_paths_returned' => false,
                'invokes_provider' => false,
            ],
            'summary' => $summary,
            'repositories' => $repositories,
            'recommended_next_actions' => $this->nextActions($repositories),
            'claim_policy' => [
                'portfolio_scan_is_not_execution_evidence' => true,
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
    private function blocked(string $root, string $blocker): array
    {
        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => 'blocked',
            'portfolio_type' => 'local_company_frontend_repo_portfolio',
            'source' => self::class,
            'root_hash' => $root !== '' ? hash('sha256', $root) : null,
            'scan_policy' => [
                'raw_source_returned' => false,
                'absolute_paths_returned' => false,
                'invokes_provider' => false,
            ],
            'summary' => [
                'candidate_repo_count' => 0,
                'ready_for_operator_execution_count' => 0,
                'prepared_needs_context_count' => 0,
                'blocked_count' => 0,
                'skill_installed_count' => 0,
            ],
            'repositories' => [],
            'recommended_next_actions' => ['provide_existing_portfolio_root'],
            'claim_policy' => [
                'portfolio_scan_is_not_execution_evidence' => true,
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
    private function packageJsonPaths(string $root, int $maxDepth, int $maxRepos): array
    {
        $found = [];
        $queue = [[$root, 0]];

        while ($queue !== [] && count($found) < $maxRepos) {
            [$directory, $depth] = array_shift($queue);
            if (! is_string($directory) || ! File::isDirectory($directory)) {
                continue;
            }

            $package = $directory.'/package.json';
            if (File::isFile($package)) {
                $found[] = $package;

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
     * @return array<string,mixed>
     */
    private function repoStatus(string $root, string $workspace): array
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

        return [
            'repo_ref' => [
                'relative_name' => $relative,
                'relative_name_hash' => hash('sha256', $relative),
                'workspace_hash' => hash('sha256', $workspace),
            ],
            'status' => $status,
            'package_manager' => $intake['package_manager'] ?? 'unknown',
            'framework' => data_get($intake, 'framework.primary'),
            'skill_pack_installed' => $skillInstalled,
            'onboarding_receipt_present' => $onboardingReceipt,
            'dispatch_policy' => [
                'provider_dispatch_allowed' => $status === 'ready_for_operator_execution',
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
     * @param  array<int,array<string,mixed>>  $repositories
     * @return array<int,string>
     */
    private function nextActions(array $repositories): array
    {
        if ($repositories === []) {
            return ['point_portfolio_scan_at_parent_directory_with_company_repos'];
        }

        return collect($repositories)
            ->flatMap(fn (array $repo): array => (array) ($repo['recommended_next_actions'] ?? []))
            ->unique()
            ->values()
            ->all();
    }
}
