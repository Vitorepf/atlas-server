<?php

namespace Tests\Unit\Ai\Programming\Frontend;

use App\Services\Ai\Programming\Frontend\AtlasFrontendFrameworkAdapterRuntimeService;
use Tests\TestCase;

class AtlasFrontendFrameworkAdapterRuntimeServiceTest extends TestCase
{
    public function test_detects_vite_workspace_and_returns_hmr_contract(): void
    {
        $workspace = sys_get_temp_dir().'/atlas-frontend-adapter-vite-'.bin2hex(random_bytes(4));
        mkdir($workspace);
        file_put_contents($workspace.'/package.json', json_encode([
            'dependencies' => ['@vitejs/plugin-react' => '^latest', 'vite' => '^latest', 'react' => '^latest'],
            'scripts' => ['dev' => 'vite'],
        ]));
        file_put_contents($workspace.'/vite.config.ts', 'export default {};');

        $payload = app(AtlasFrontendFrameworkAdapterRuntimeService::class)->inspect($workspace);

        $this->assertSame('atlas.frontend.framework_adapter_runtime.v1', $payload['schema_version']);
        $this->assertSame('ready', $payload['status']);
        $this->assertContains('vite', $payload['detected_frameworks']);
        $this->assertSame('vite', data_get($payload, 'adapter.framework'));
        $this->assertTrue((bool) data_get($payload, 'adapter.hmr_supported'));
        $this->assertSame('http://localhost:5173', data_get($payload, 'adapter.default_url'));
        $this->assertFalse((bool) data_get($payload, 'live_mode_contract.source_policy.raw_package_json_returned'));
        $this->assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', (string) $payload['adapter_hash']);
    }

    public function test_detects_next_with_root_document_candidates(): void
    {
        $workspace = sys_get_temp_dir().'/atlas-frontend-adapter-next-'.bin2hex(random_bytes(4));
        mkdir($workspace);
        file_put_contents($workspace.'/package.json', json_encode([
            'dependencies' => ['next' => '^latest', 'react' => '^latest'],
            'scripts' => ['dev' => 'next dev'],
        ]));

        $payload = app(AtlasFrontendFrameworkAdapterRuntimeService::class)->inspect($workspace);

        $this->assertSame('next', data_get($payload, 'adapter.framework'));
        $this->assertContains('app/layout.tsx', data_get($payload, 'adapter.root_document_candidates'));
        $this->assertContains('npm run dev', data_get($payload, 'adapter.dev_command_candidates'));
    }

    public function test_unknown_workspace_is_partial_not_fake_ready(): void
    {
        $workspace = sys_get_temp_dir().'/atlas-frontend-adapter-unknown-'.bin2hex(random_bytes(4));
        mkdir($workspace);

        $payload = app(AtlasFrontendFrameworkAdapterRuntimeService::class)->inspect($workspace);

        $this->assertSame('partial', $payload['status']);
        $this->assertSame('unknown', data_get($payload, 'adapter.framework'));
        $this->assertFalse((bool) data_get($payload, 'adapter.hmr_supported'));
        $this->assertSame('framework_unknown', data_get($payload, 'warnings.0.id'));
    }
}
