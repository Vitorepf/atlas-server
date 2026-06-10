<?php

namespace App\Services\Ai\Programming\Frontend;

use App\Services\Ai\Mission\MissionCanonicalHash;
use App\Services\Ai\Support\JsonFileStore;
use Illuminate\Support\Facades\File;
use RuntimeException;

final class AtlasFrontendFrameworkAdapterRuntimeService
{
    public const SCHEMA_VERSION = 'atlas.frontend.framework_adapter_runtime.v1';

    /**
     * @return array<string,mixed>
     */
    public function inspect(string $workspace): array
    {
        $workspace = $this->workspace($workspace);
        $package = $this->package($workspace);
        $dependencies = array_merge(
            (array) ($package['dependencies'] ?? []),
            (array) ($package['devDependencies'] ?? []),
        );
        $frameworks = $this->detectFrameworks($workspace, $dependencies);
        $adapter = $this->adapter($frameworks);

        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $adapter['framework'] === 'unknown' ? 'partial' : 'ready',
            'workspace_hash' => hash('sha256', $workspace),
            'package_present' => $package !== [],
            'detected_frameworks' => $frameworks,
            'adapter' => $adapter,
            'live_mode_contract' => [
                'browser_bridge_command' => 'php artisan atlas:frontend:bridge inject --workspace=<workspace> --file=<html-file> --json',
                'preview_relay_command' => 'php artisan atlas:frontend:relay inject --workspace=<workspace> --file=<html-file> --json',
                'source_patch_command' => 'php artisan atlas:frontend:live prepare|accept|discard|recover --json',
                'visual_verification_required' => true,
                'source_policy' => [
                    'raw_package_json_returned' => false,
                    'absolute_path_returned' => false,
                    'provider_credentials_required' => false,
                ],
            ],
            'warnings' => $adapter['framework'] === 'unknown' ? [
                ['id' => 'framework_unknown', 'reason' => 'Atlas could not infer a supported frontend framework from package metadata or config files.'],
            ] : [],
        ];
        $payload['adapter_hash'] = MissionCanonicalHash::sha256($payload);

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

        return JsonFileStore::readArray($path) ?? [];
    }

    /**
     * @param  array<string,mixed>  $dependencies
     * @return array<int,string>
     */
    private function detectFrameworks(string $workspace, array $dependencies): array
    {
        $frameworks = [];
        foreach ([
            'next' => 'next',
            'nuxt' => 'nuxt',
            'astro' => 'astro',
            'sveltekit' => '@sveltejs/kit',
            'remix' => '@remix-run/dev',
            'angular' => '@angular/core',
            'expo' => 'expo',
            'vite' => 'vite',
            'react' => 'react',
            'vue' => 'vue',
            'svelte' => 'svelte',
        ] as $framework => $dependency) {
            if (array_key_exists($dependency, $dependencies)) {
                $frameworks[] = $framework;
            }
        }
        foreach ([
            'vite' => ['vite.config.js', 'vite.config.ts', 'vite.config.mjs'],
            'next' => ['next.config.js', 'next.config.mjs', 'next.config.ts'],
            'nuxt' => ['nuxt.config.js', 'nuxt.config.ts'],
            'astro' => ['astro.config.mjs', 'astro.config.ts'],
            'sveltekit' => ['svelte.config.js', 'svelte.config.ts'],
            'remix' => ['remix.config.js'],
        ] as $framework => $files) {
            foreach ($files as $file) {
                if (File::isFile($workspace.'/'.$file)) {
                    $frameworks[] = $framework;
                }
            }
        }

        return array_values(array_unique($frameworks));
    }

    /**
     * @param  array<int,string>  $frameworks
     * @return array<string,mixed>
     */
    private function adapter(array $frameworks): array
    {
        $priority = ['next', 'nuxt', 'sveltekit', 'astro', 'remix', 'angular', 'expo', 'vite', 'react', 'vue', 'svelte'];
        $framework = 'unknown';
        foreach ($priority as $candidate) {
            if (in_array($candidate, $frameworks, true)) {
                $framework = $candidate;
                break;
            }
        }

        $defaults = [
            'framework' => $framework,
            'hmr_supported' => $framework !== 'unknown',
            'preview_mode' => 'browser_event_css_preview_plus_source_patch',
            'requires_dev_server' => $framework !== 'unknown',
            'bridge_mount_strategy' => 'inject_into_rendered_html_or_root_document_during_local_preview',
            'safe_file_targets' => ['html', 'htm'],
        ];

        return array_merge($defaults, match ($framework) {
            'next' => [
                'dev_command_candidates' => ['npm run dev', 'pnpm dev', 'yarn dev'],
                'root_document_candidates' => ['pages/_document.tsx', 'pages/_document.jsx', 'app/layout.tsx', 'app/layout.jsx'],
                'default_url' => 'http://localhost:3000',
            ],
            'nuxt' => [
                'dev_command_candidates' => ['npm run dev', 'pnpm dev', 'yarn dev'],
                'root_document_candidates' => ['app.vue', 'layouts/default.vue'],
                'default_url' => 'http://localhost:3000',
            ],
            'sveltekit' => [
                'dev_command_candidates' => ['npm run dev -- --host 127.0.0.1', 'pnpm dev -- --host 127.0.0.1'],
                'root_document_candidates' => ['src/app.html', 'src/routes/+layout.svelte'],
                'default_url' => 'http://localhost:5173',
            ],
            'astro' => [
                'dev_command_candidates' => ['npm run dev -- --host 127.0.0.1', 'pnpm dev -- --host 127.0.0.1'],
                'root_document_candidates' => ['src/layouts/Layout.astro', 'src/pages/index.astro'],
                'default_url' => 'http://localhost:4321',
            ],
            'remix' => [
                'dev_command_candidates' => ['npm run dev', 'pnpm dev'],
                'root_document_candidates' => ['app/root.tsx', 'app/root.jsx'],
                'default_url' => 'http://localhost:3000',
            ],
            'angular' => [
                'dev_command_candidates' => ['npm start', 'ng serve'],
                'root_document_candidates' => ['src/index.html'],
                'default_url' => 'http://localhost:4200',
            ],
            'expo' => [
                'dev_command_candidates' => ['npm run web', 'npx expo start --web'],
                'root_document_candidates' => ['app/+html.tsx', 'web/index.html'],
                'default_url' => 'http://localhost:8081',
            ],
            'vite', 'react', 'vue', 'svelte' => [
                'dev_command_candidates' => ['npm run dev -- --host 127.0.0.1', 'pnpm dev -- --host 127.0.0.1'],
                'root_document_candidates' => ['index.html', 'src/main.tsx', 'src/main.jsx', 'src/main.ts', 'src/main.js'],
                'default_url' => 'http://localhost:5173',
            ],
            default => [
                'hmr_supported' => false,
                'dev_command_candidates' => [],
                'root_document_candidates' => [],
                'default_url' => null,
            ],
        });
    }
}
