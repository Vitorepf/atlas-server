<?php

namespace App\Services\Ai\Programming\Frontend;

use App\Services\Ai\Mission\MissionCanonicalHash;
use Illuminate\Support\Facades\File;
use RuntimeException;

final class AtlasFrontendRepoIntakeService
{
    public const SCHEMA_VERSION = 'atlas.frontend.repo_intake.v1';

    /**
     * @return array<string,mixed>
     */
    public function inspect(string $workspace): array
    {
        $workspace = $this->workspace($workspace);
        $package = $this->package($workspace);
        $packageManager = $this->packageManager($workspace);
        $scripts = $this->scripts($package);
        $framework = app(AtlasFrontendFrameworkAdapterRuntimeService::class)->inspect($workspace);
        $dossier = app(AtlasFrontendDesignDossierService::class)->inspect($workspace);
        $inventory = app(AtlasFrontendDesignSystemInventoryService::class)->inspect($workspace);
        $testCommands = $this->testCommands($scripts, $packageManager);
        $buildCommands = $this->buildCommands($scripts, $packageManager);
        $qualityCommands = $this->qualityCommands($scripts, $packageManager);
        $routes = $this->routeCandidates($workspace);
        $entrypoints = $this->entrypointCandidates($workspace);
        $blockers = $this->blockers($package, $framework, $dossier, $inventory, $testCommands, $entrypoints);
        $warnings = $this->warnings($buildCommands, $qualityCommands, $routes);
        $status = $blockers === [] ? ($warnings === [] ? 'ready' : 'warning') : 'blocked';

        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $status,
            'source' => self::class,
            'intake_type' => 'company_owned_frontend_repo_operating_map',
            'workspace_hash' => hash('sha256', $workspace),
            'package_manager' => $packageManager,
            'package_present' => $package !== [],
            'framework' => [
                'status' => $framework['status'] ?? 'unknown',
                'detected' => $framework['detected_frameworks'] ?? [],
                'primary' => data_get($framework, 'adapter.framework'),
                'default_url' => data_get($framework, 'adapter.default_url'),
                'dev_command_candidates' => data_get($framework, 'adapter.dev_command_candidates', []),
            ],
            'design_context' => [
                'dossier_status' => $dossier['status'] ?? 'unknown',
                'inventory_status' => $inventory['status'] ?? 'unknown',
                'design_doc_ids' => $dossier['required_document_ids'] ?? [],
                'design_system_libraries' => data_get($inventory, 'design_system_signals.libraries', []),
                'component_count' => data_get($inventory, 'components.count', 0),
            ],
            'repo_map' => [
                'entrypoints' => $entrypoints,
                'route_candidates' => $routes,
                'test_commands' => $testCommands,
                'build_commands' => $buildCommands,
                'quality_commands' => $qualityCommands,
                'raw_source_returned' => false,
                'absolute_paths_returned' => false,
            ],
            'recommended_next_actions' => $this->nextActions($blockers, $warnings),
            'claim_policy' => [
                'provider_can_start_with_repo_map' => $status !== 'blocked',
                'premium_frontend_claim_requires_ready_dossier' => true,
                'repo_intake_is_not_completion_evidence' => true,
                'raw_customer_source_returned' => false,
                'world_best_claim_allowed' => false,
            ],
            'blockers' => $blockers,
            'warnings' => $warnings,
        ];
        $payload['repo_intake_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    private function workspace(string $workspace): string
    {
        $workspace = trim($workspace) !== '' ? $workspace : base_path();
        $real = realpath($workspace);
        if ($real === false || ! File::isDirectory($real)) {
            throw new RuntimeException('workspace_not_found');
        }

        return $real;
    }

    /**
     * @return array<string,mixed>
     */
    private function package(string $workspace): array
    {
        $path = $workspace.'/package.json';
        if (! File::isFile($path)) {
            return [];
        }

        $decoded = json_decode(File::get($path), true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param  array<string,mixed>  $package
     * @return array<string,string>
     */
    private function scripts(array $package): array
    {
        return collect((array) ($package['scripts'] ?? []))
            ->filter(fn (mixed $command, mixed $name): bool => is_string($name) && is_string($command))
            ->mapWithKeys(fn (string $command, string $name): array => [$name => $command])
            ->all();
    }

    private function packageManager(string $workspace): string
    {
        return match (true) {
            File::isFile($workspace.'/pnpm-lock.yaml') => 'pnpm',
            File::isFile($workspace.'/yarn.lock') => 'yarn',
            File::isFile($workspace.'/bun.lockb') || File::isFile($workspace.'/bun.lock') => 'bun',
            File::isFile($workspace.'/package-lock.json') => 'npm',
            default => 'unknown',
        };
    }

    /**
     * @param  array<string,string>  $scripts
     * @return array<int,string>
     */
    private function testCommands(array $scripts, string $packageManager): array
    {
        return $this->commandsByName($scripts, ['test', 'vitest', 'jest', 'e2e', 'playwright', 'cypress'], $packageManager);
    }

    /**
     * @param  array<string,string>  $scripts
     * @return array<int,string>
     */
    private function buildCommands(array $scripts, string $packageManager): array
    {
        return $this->commandsByName($scripts, ['build', 'compile'], $packageManager);
    }

    /**
     * @param  array<string,string>  $scripts
     * @return array<int,string>
     */
    private function qualityCommands(array $scripts, string $packageManager): array
    {
        return $this->commandsByName($scripts, ['lint', 'typecheck', 'format', 'check', 'storybook'], $packageManager);
    }

    /**
     * @param  array<string,string>  $scripts
     * @param  array<int,string>  $needles
     * @return array<int,string>
     */
    private function commandsByName(array $scripts, array $needles, string $packageManager): array
    {
        $commands = [];
        $runner = in_array($packageManager, ['pnpm', 'yarn', 'bun'], true) ? $packageManager : 'npm';
        foreach ($scripts as $name => $command) {
            foreach ($needles as $needle) {
                if (str_contains(strtolower($name), $needle) || str_contains(strtolower($command), $needle)) {
                    $commands[] = $runner.' run '.$name;
                    break;
                }
            }
        }

        return array_values(array_unique($commands));
    }

    /**
     * @return array<int,array<string,string>>
     */
    private function routeCandidates(string $workspace): array
    {
        $candidates = [];
        foreach ([
            'app',
            'pages',
            'src/pages',
            'src/routes',
            'src/app',
        ] as $directory) {
            $root = $workspace.'/'.$directory;
            if (! File::isDirectory($root)) {
                continue;
            }
            foreach ((array) File::allFiles($root) as $file) {
                $relative = ltrim(str_replace('\\', '/', substr($file->getPathname(), strlen($workspace))), '/');
                if (! preg_match('/\.(tsx|jsx|vue|svelte|astro)$/', $relative)) {
                    continue;
                }
                $candidates[] = [
                    'path' => $relative,
                    'path_hash' => hash('sha256', $relative),
                    'kind' => str_contains($relative, '[') || str_contains($relative, '$') ? 'dynamic_route' : 'route',
                ];
                if (count($candidates) >= 80) {
                    return $candidates;
                }
            }
        }

        return $candidates;
    }

    /**
     * @return array<int,array<string,string>>
     */
    private function entrypointCandidates(string $workspace): array
    {
        $candidates = [];
        foreach ([
            'index.html',
            'src/main.tsx',
            'src/main.jsx',
            'src/App.tsx',
            'src/App.jsx',
            'app/layout.tsx',
            'app/page.tsx',
            'pages/_app.tsx',
            'pages/index.tsx',
        ] as $relative) {
            $path = $workspace.'/'.$relative;
            if (! File::isFile($path)) {
                continue;
            }
            $candidates[] = [
                'path' => $relative,
                'path_hash' => hash('sha256', $relative),
                'content_hash' => hash_file('sha256', $path),
            ];
        }

        return $candidates;
    }

    /**
     * @param  array<string,mixed>  $package
     * @param  array<string,mixed>  $framework
     * @param  array<string,mixed>  $dossier
     * @param  array<string,mixed>  $inventory
     * @param  array<int,string>  $testCommands
     * @param  array<int,array<string,string>>  $entrypoints
     * @return array<int,string>
     */
    private function blockers(array $package, array $framework, array $dossier, array $inventory, array $testCommands, array $entrypoints): array
    {
        $blockers = [];
        if ($package === []) {
            $blockers[] = 'package_json_missing';
        }
        if (($framework['status'] ?? null) !== 'ready') {
            $blockers[] = 'frontend_framework_unknown';
        }
        if (($dossier['status'] ?? null) !== 'ready') {
            $blockers[] = 'company_design_dossier_not_ready';
        }
        if (($inventory['status'] ?? null) !== 'ready') {
            $blockers[] = 'design_system_inventory_not_ready';
        }
        if ($testCommands === []) {
            $blockers[] = 'test_command_missing';
        }
        if ($entrypoints === []) {
            $blockers[] = 'frontend_entrypoint_missing';
        }

        return array_values(array_unique($blockers));
    }

    /**
     * @param  array<int,string>  $buildCommands
     * @param  array<int,string>  $qualityCommands
     * @param  array<int,array<string,string>>  $routes
     * @return array<int,string>
     */
    private function warnings(array $buildCommands, array $qualityCommands, array $routes): array
    {
        $warnings = [];
        if ($buildCommands === []) {
            $warnings[] = 'build_command_missing';
        }
        if ($qualityCommands === []) {
            $warnings[] = 'lint_or_typecheck_command_missing';
        }
        if ($routes === []) {
            $warnings[] = 'route_candidates_not_found';
        }

        return $warnings;
    }

    /**
     * @param  array<int,string>  $blockers
     * @param  array<int,string>  $warnings
     * @return array<int,string>
     */
    private function nextActions(array $blockers, array $warnings): array
    {
        $map = [
            'package_json_missing' => 'confirm_frontend_workspace_or_create_package_manifest',
            'frontend_framework_unknown' => 'add_or_confirm_supported_frontend_framework_adapter',
            'company_design_dossier_not_ready' => 'run_atlas_frontend_design_dossier_template_and_fill_docs',
            'design_system_inventory_not_ready' => 'add_or_document_design_system_tokens_components',
            'test_command_missing' => 'add_frontend_test_command_or_document_test_reason',
            'frontend_entrypoint_missing' => 'confirm_app_entrypoint_or_route_root',
            'build_command_missing' => 'add_frontend_build_command_or_document_build_reason',
            'lint_or_typecheck_command_missing' => 'add_lint_typecheck_or_document_quality_reason',
            'route_candidates_not_found' => 'confirm_routes_in_task_spec',
        ];

        return collect(array_merge($blockers, $warnings))
            ->map(fn (string $id): string => $map[$id] ?? 'review_'.$id)
            ->unique()
            ->values()
            ->all();
    }
}
