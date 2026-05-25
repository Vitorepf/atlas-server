<?php

namespace Tests\Feature\Ai\Programming\Frontend;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class AtlasFrontendDesignSystemInventoryCommandTest extends TestCase
{
    public function test_inventory_command_emits_design_system_inventory(): void
    {
        $workspace = sys_get_temp_dir().'/atlas-frontend-inventory-command-'.bin2hex(random_bytes(4));
        File::makeDirectory($workspace.'/components', 0755, true);
        file_put_contents($workspace.'/package.json', json_encode([
            'dependencies' => ['@mui/material' => '^latest'],
        ]));
        file_put_contents($workspace.'/components/Card.tsx', 'export function Card() { return <section className="bg-surface shadow-sm" />; }');

        $exitCode = Artisan::call('atlas:frontend:inventory', [
            'inspect' => 'inspect',
            '--workspace' => $workspace,
            '--json' => true,
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('atlas.frontend.design_system_inventory.v1', $output);
        $this->assertStringContainsString('"mui"', $output);
        $this->assertStringContainsString('raw_source_returned', $output);
    }
}
