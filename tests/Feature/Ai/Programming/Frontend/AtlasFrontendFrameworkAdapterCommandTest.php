<?php

namespace Tests\Feature\Ai\Programming\Frontend;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class AtlasFrontendFrameworkAdapterCommandTest extends TestCase
{
    public function test_adapters_command_emits_framework_contract(): void
    {
        $workspace = sys_get_temp_dir().'/atlas-frontend-adapter-command-'.bin2hex(random_bytes(4));
        mkdir($workspace);
        file_put_contents($workspace.'/package.json', json_encode([
            'dependencies' => ['astro' => '^latest'],
            'scripts' => ['dev' => 'astro dev'],
        ]));

        $exitCode = Artisan::call('atlas:frontend:adapters', [
            'inspect' => 'inspect',
            '--workspace' => $workspace,
            '--json' => true,
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('atlas.frontend.framework_adapter_runtime.v1', $output);
        $this->assertStringContainsString('"framework": "astro"', $output);
        $this->assertStringContainsString('browser_bridge_command', $output);
    }
}
