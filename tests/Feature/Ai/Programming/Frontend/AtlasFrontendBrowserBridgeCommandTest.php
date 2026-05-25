<?php

namespace Tests\Feature\Ai\Programming\Frontend;

use Tests\TestCase;

class AtlasFrontendBrowserBridgeCommandTest extends TestCase
{
    public function test_bridge_command_injects_and_removes_script(): void
    {
        $workspace = sys_get_temp_dir().'/atlas-frontend-bridge-command-'.bin2hex(random_bytes(4));
        mkdir($workspace);
        file_put_contents($workspace.'/index.html', '<html><body><main>App</main></body></html>');

        $this->artisan('atlas:frontend:bridge', [
            'action' => 'inject',
            '--workspace' => $workspace,
            '--file' => 'index.html',
            '--json' => true,
        ])->assertExitCode(0);

        $this->assertStringContainsString('__ATLAS_FRONTEND_PICK_EVENTS__', file_get_contents($workspace.'/index.html'));

        $this->artisan('atlas:frontend:bridge', [
            'action' => 'remove',
            '--workspace' => $workspace,
            '--file' => 'index.html',
            '--json' => true,
        ])->assertExitCode(0);

        $this->assertStringNotContainsString('__ATLAS_FRONTEND_PICK_EVENTS__', file_get_contents($workspace.'/index.html'));
    }
}
