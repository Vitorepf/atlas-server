<?php

namespace Tests\Feature\Ai\Programming\Frontend;

use App\Services\Ai\Programming\Frontend\AtlasFrontendEnterpriseBootstrapService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class AtlasFrontendEnterpriseBootstrapCommandTest extends TestCase
{
    public function test_enterprise_bootstrap_write_command_emits_payload_and_writes_docs(): void
    {
        $workspace = sys_get_temp_dir().'/atlas-frontend-enterprise-bootstrap-command-'.bin2hex(random_bytes(4));
        File::ensureDirectoryExists($workspace.'/src/pages');
        File::ensureDirectoryExists($workspace.'/src/components/ui');
        File::put($workspace.'/pnpm-lock.yaml', 'lockfileVersion: 9.0');
        File::put($workspace.'/package.json', json_encode([
            'scripts' => [
                'dev' => 'vite --host 127.0.0.1',
                'test' => 'vitest run',
                'build' => 'vite build',
                'typecheck' => 'tsc --noEmit',
            ],
            'dependencies' => ['react' => '^latest', 'vite' => '^latest', 'tailwindcss' => '^latest'],
        ], JSON_THROW_ON_ERROR));
        File::put($workspace.'/index.html', '<div id="root"></div>');
        File::put($workspace.'/src/pages/Dashboard.tsx', 'export function Dashboard() { return <main className="bg-primary" />; }');
        File::put($workspace.'/src/components/ui/Button.tsx', 'export function Button() { return <button className="bg-primary text-white" />; }');
        File::put($workspace.'/src/styles.css', ':root { --color-primary: #123456; --space-2: 8px; }');

        $exitCode = Artisan::call('atlas:frontend:enterprise-bootstrap', [
            'action' => 'write',
            '--task' => 'Criar SaaS novo com design ultra premium',
            '--workspace' => $workspace,
            '--json' => true,
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString(AtlasFrontendEnterpriseBootstrapService::SCHEMA_VERSION, $output);
        $this->assertStringContainsString('enterprise_bootstrap_hash', $output);
        $this->assertStringContainsString('template_docs_do_not_count_as_ready_context', $output);
        $this->assertTrue(File::isFile($workspace.'/docs/design/product-experience-brief.md'));
        $this->assertTrue(File::isFile($workspace.'/docs/design/atlas-frontend-product-blueprint.json'));
    }

    public function test_enterprise_bootstrap_command_accepts_frontend_app_scope(): void
    {
        $workspace = sys_get_temp_dir().'/atlas-frontend-enterprise-bootstrap-command-monorepo-'.bin2hex(random_bytes(4));
        File::ensureDirectoryExists($workspace.'/apps/web');

        $exitCode = Artisan::call('atlas:frontend:enterprise-bootstrap', [
            'action' => 'inspect',
            '--task' => 'Ajustar checkout web',
            '--workspace' => $workspace,
            '--frontend-app' => 'apps/web',
            '--json' => true,
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('frontend_app_scope', $output);
        $this->assertStringContainsString('subscope_selected', $output);
        $this->assertStringContainsString('--frontend-app=apps/web', $output);
        $this->assertStringNotContainsString($workspace.'/apps/web', $output);
    }
}
