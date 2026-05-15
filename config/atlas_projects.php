<?php

declare(strict_types=1);

/**
 * Atlas Code · Project/Workspace Profiles (read-model seed).
 *
 * See docs/engineering-knowledge-base/atlas-code-multi-project-workspace-os.md.
 *
 * "Project/Workspace é onde o software vive." Atlas, Blackink e qualquer outro
 * produto entram aqui como perfis operacionais. Obras vivem dentro de um
 * Projeto via metadata.workspace_slug; este arquivo NÃO modela Obras.
 *
 * First iteration is config-driven on purpose: extensível, auditável e
 * versionável no repo sem migration. Quando AtlasDesktop ganhar real project
 * switching com persistência editável, este service vira fonte de leitura
 * unificada (DB ∪ config).
 *
 * Required fields per profile: id, slug, name, kind, workspace_path,
 *   repo_root, production_status, stack_summary, commands, test_commands,
 *   build_commands, dev_server_command, critical_areas, docs_status,
 *   default_risk, deployment_notes.
 *
 * `workspace_path` may resolve to a path that does not exist yet (Blackink
 * antes do clone). Service marca workspace_path_exists conforme realidade.
 */

return [
    'default_slug' => env('ATLAS_CODE_DEFAULT_PROJECT', 'atlas'),

    'profiles' => [
        [
            'id' => 'atlas',
            'slug' => 'atlas',
            'name' => 'Atlas',
            'kind' => 'product',
            'workspace_path' => env('ATLAS_PROJECT_ATLAS_PATH', base_path('..')),
            'repo_root' => env('ATLAS_PROJECT_ATLAS_REPO', base_path('..')),
            'production_status' => 'development',
            'stack_summary' => 'Laravel 12 + PHP 8.4 + Postgres backend · Tauri 2 + React + TypeScript desktop · Atlas Kernel pipeline.',
            'commands' => [
                'install' => 'composer install && pnpm install -r',
                'tinker' => '/opt/homebrew/bin/php artisan tinker',
            ],
            'test_commands' => [
                '/opt/homebrew/bin/php artisan test',
                '/opt/homebrew/bin/php artisan atlas:engineering:knowledge docs-health --json',
            ],
            'build_commands' => [
                'pnpm --filter @atlas/desktop build',
            ],
            'dev_server_command' => '/opt/homebrew/bin/php artisan serve',
            'critical_areas' => [
                'atlas-server/app/Services/Ai',
                'atlas-server/app/Http/Controllers/AtlasCode*',
                'atlas-desktop/apps/desktop/src/surfaces',
                'docs/engineering-knowledge-base',
            ],
            'docs_status' => 'canonical',
            'default_risk' => 'medium',
            'deployment_notes' => 'Atlas é o próprio repositório onde Atlas Code roda. Self-modifying — mudanças em produção exigem evidência.',
            // Surfaces que este Projeto/Workspace habilita. Meta 5 expõe
            // este campo para que a UI possa esconder tabs incompatíveis
            // (ex: projeto sem docs canônicas → cartografia indisponível).
            'surfaces_enabled' => ['atlas_ai', 'cartografia', 'code', 'atencao'],
        ],
        [
            'id' => 'blackink',
            'slug' => 'blackink',
            'name' => 'Blackink',
            'kind' => 'product',
            'workspace_path' => env('ATLAS_PROJECT_BLACKINK_PATH', ''),
            'repo_root' => env('ATLAS_PROJECT_BLACKINK_REPO', ''),
            'production_status' => 'production',
            'stack_summary' => 'Produto em produção · stack ainda não catalogada neste perfil.',
            'commands' => [],
            'test_commands' => [],
            'build_commands' => [],
            'dev_server_command' => null,
            'critical_areas' => [],
            'docs_status' => 'incomplete',
            'default_risk' => 'high',
            'deployment_notes' => 'Blackink está em produção. Intervenções pequenas exigem rollback claro; trabalhos estruturais precisam virar Obra governada.',
            // Blackink ainda não tem docs canônicas para Cartografia;
            // Atlas AI e Atenção ficam permitidas, Code/Cartografia
            // aparecem com badge "limitado" até docs status melhorar.
            'surfaces_enabled' => ['atlas_ai', 'atencao', 'code'],
        ],
    ],
];
