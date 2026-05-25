<?php

namespace Tests\Unit\Ai\Programming\Frontend;

use App\Services\Ai\Programming\Frontend\AtlasFrontendDesignSystemInventoryService;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class AtlasFrontendDesignSystemInventoryServiceTest extends TestCase
{
    public function test_inventory_detects_tokens_components_and_design_libraries_without_raw_source(): void
    {
        $workspace = sys_get_temp_dir().'/atlas-frontend-inventory-ready-'.bin2hex(random_bytes(4));
        File::makeDirectory($workspace.'/src/components/ui', 0755, true);
        file_put_contents($workspace.'/package.json', json_encode([
            'dependencies' => [
                'tailwindcss' => '^latest',
                '@radix-ui/react-slot' => '^latest',
                'lucide-react' => '^latest',
            ],
        ]));
        file_put_contents($workspace.'/tailwind.config.ts', 'export default {};');
        file_put_contents($workspace.'/src/components/ui/Button.tsx', <<<'TSX'
export function Button() {
  return <button className="bg-primary text-foreground rounded-md px-4">Save</button>;
}
TSX);
        file_put_contents($workspace.'/src/styles.css', ':root { --color-primary: #123456; --space-panel: 16px; }');

        $payload = app(AtlasFrontendDesignSystemInventoryService::class)->inspect($workspace);

        $this->assertSame('atlas.frontend.design_system_inventory.v1', $payload['schema_version']);
        $this->assertSame('ready', $payload['status']);
        $this->assertContains('tailwind', data_get($payload, 'design_system_signals.libraries'));
        $this->assertContains('radix', data_get($payload, 'design_system_signals.libraries'));
        $this->assertSame('src/components/ui/Button.tsx', data_get($payload, 'components.candidates.0.path'));
        $this->assertSame('primitive', data_get($payload, 'components.candidates.0.kind'));
        $this->assertFalse((bool) data_get($payload, 'scanned.raw_source_returned'));
        $this->assertFalse((bool) data_get($payload, 'scanned.absolute_paths_returned'));
        $this->assertNotEmpty(data_get($payload, 'tokens.css_variables'));
        $this->assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', (string) $payload['inventory_hash']);
    }

    public function test_sparse_workspace_is_partial_not_fake_ready(): void
    {
        $workspace = sys_get_temp_dir().'/atlas-frontend-inventory-sparse-'.bin2hex(random_bytes(4));
        File::makeDirectory($workspace);

        $payload = app(AtlasFrontendDesignSystemInventoryService::class)->inspect($workspace);

        $this->assertSame('partial', $payload['status']);
        $this->assertSame('design_system_inventory_sparse', data_get($payload, 'warnings.0.id'));
    }
}
