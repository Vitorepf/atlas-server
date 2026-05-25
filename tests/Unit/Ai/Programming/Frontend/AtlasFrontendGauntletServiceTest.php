<?php

namespace Tests\Unit\Ai\Programming\Frontend;

use App\Services\Ai\Programming\Frontend\AtlasFrontendDesignDossierService;
use App\Services\Ai\Programming\Frontend\AtlasFrontendGauntletService;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class AtlasFrontendGauntletServiceTest extends TestCase
{
    public function test_ready_local_company_repo_gauntlet_allows_provider_dispatch(): void
    {
        $workspace = $this->readyWorkspace();

        $payload = app(AtlasFrontendGauntletService::class)->run([
            'task' => 'Ajustar componente Button no frontend da empresa',
            'workspace' => $workspace,
            'acceptance_criteria' => true,
            'test_plan' => true,
            'visual_quality_plan' => true,
            'evidence_plan' => true,
        ]);

        $this->assertSame(AtlasFrontendGauntletService::SCHEMA_VERSION, $payload['schema_version']);
        $this->assertSame('ready', $payload['status']);
        $this->assertSame('company_owned_local_repo_frontend_gauntlet', $payload['operating_mode']);
        $this->assertTrue((bool) data_get($payload, 'claim_policy.provider_dispatch_allowed'));
        $this->assertTrue((bool) data_get($payload, 'claim_policy.premium_frontend_claim_allowed'));
        $this->assertFalse((bool) data_get($payload, 'claim_policy.world_best_claim_allowed'));
        $this->assertContains('company_design_dossier', collect($payload['phase_results'])->pluck('id')->all());
        $this->assertContains('product_blueprint', collect($payload['phase_results'])->pluck('id')->all());
        $this->assertContains('repo_intake', collect($payload['phase_results'])->pluck('id')->all());
        $this->assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', (string) $payload['gauntlet_hash']);
    }

    public function test_gauntlet_blocks_missing_design_context_and_plans(): void
    {
        $workspace = sys_get_temp_dir().'/atlas-frontend-gauntlet-blocked-'.bin2hex(random_bytes(4));
        File::ensureDirectoryExists($workspace);

        $payload = app(AtlasFrontendGauntletService::class)->run([
            'task' => 'Criar redesign premium SaaS novo',
            'workspace' => $workspace,
        ]);

        $this->assertSame('blocked', $payload['status']);
        $this->assertFalse((bool) data_get($payload, 'claim_policy.provider_dispatch_allowed'));
        $this->assertContains('fill_company_design_dossier_docs', $payload['required_next_actions']);
        $this->assertContains('generate_or_write_product_blueprint', $payload['required_next_actions']);
        $this->assertContains('run_or_improve_design_system_inventory', $payload['required_next_actions']);
        $this->assertContains('confirm_frontend_workspace_or_create_package_manifest', $payload['required_next_actions']);
        $this->assertStringContainsString('php artisan atlas:frontend:blueprint generate --task="<brief>"', implode("\n", $payload['recommended_command_sequence']));
        $this->assertStringContainsString('php artisan atlas:frontend:intake --workspace=', implode("\n", $payload['recommended_command_sequence']));
        $this->assertStringContainsString('php artisan atlas:frontend:design-dossier inspect --workspace=', implode("\n", $payload['recommended_command_sequence']));
    }

    private function readyWorkspace(): string
    {
        $workspace = sys_get_temp_dir().'/atlas-frontend-gauntlet-ready-'.bin2hex(random_bytes(4));
        File::ensureDirectoryExists($workspace.'/src/components/ui');
        File::ensureDirectoryExists($workspace.'/src/pages');
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
        File::put($workspace.'/src/pages/Dashboard.tsx', 'export function Dashboard() { return <main />; }');
        File::put($workspace.'/src/components/ui/Button.tsx', 'export function Button() { return <button className="bg-primary text-white" />; }');
        File::put($workspace.'/src/styles.css', ':root { --color-primary: #123456; --space-2: 8px; }');

        $dossier = app(AtlasFrontendDesignDossierService::class);
        foreach ($dossier->requiredDocuments() as $definition) {
            File::ensureDirectoryExists(dirname($workspace.'/'.$definition['path']));
            File::put($workspace.'/'.$definition['path'], $this->filledDocument((string) $definition['title'], (array) $definition['sections']));
        }

        return $workspace;
    }

    /**
     * @param  array<int,string>  $sections
     */
    private function filledDocument(string $title, array $sections): string
    {
        $lines = ['# '.$title, '', 'Status: canonical', ''];
        foreach ($sections as $section) {
            $lines[] = '## '.$section;
            $lines[] = 'Approved design context for premium company frontend work, including product goals, UX constraints, visual quality bars, and measurable evidence required before delivery.';
            $lines[] = '';
        }

        return implode(PHP_EOL, $lines);
    }
}
