<?php

namespace Tests\Feature\Ai\Programming\Frontend;

use App\Services\Ai\Programming\Frontend\AtlasFrontendProductBlueprintService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class AtlasFrontendBlueprintCommandTest extends TestCase
{
    public function test_blueprint_generate_command_emits_product_blueprint(): void
    {
        $workspace = sys_get_temp_dir().'/atlas-frontend-blueprint-command-'.bin2hex(random_bytes(4));
        File::ensureDirectoryExists($workspace);

        $exitCode = Artisan::call('atlas:frontend:blueprint', [
            'action' => 'generate',
            '--task' => 'Criar novo SaaS dashboard premium',
            '--workspace' => $workspace,
            '--json' => true,
            '--strict' => true,
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString(AtlasFrontendProductBlueprintService::SCHEMA_VERSION, $output);
        $this->assertStringContainsString('product_blueprint', $output);
    }

    public function test_blueprint_write_command_writes_document(): void
    {
        $workspace = sys_get_temp_dir().'/atlas-frontend-blueprint-command-write-'.bin2hex(random_bytes(4));
        File::ensureDirectoryExists($workspace);

        $exitCode = Artisan::call('atlas:frontend:blueprint', [
            'action' => 'write',
            '--task' => 'Criar checkout ecommerce premium',
            '--workspace' => $workspace,
            '--json' => true,
        ]);

        $this->assertSame(0, $exitCode);
        $this->assertTrue(File::isFile($workspace.'/docs/design/atlas-frontend-product-blueprint.json'));
    }

    public function test_blueprint_command_accepts_frontend_app_scope(): void
    {
        $workspace = sys_get_temp_dir().'/atlas-frontend-blueprint-command-monorepo-'.bin2hex(random_bytes(4));
        File::ensureDirectoryExists($workspace.'/apps/web');

        $exitCode = Artisan::call('atlas:frontend:blueprint', [
            'action' => 'generate',
            '--task' => 'Ajustar checkout web premium',
            '--workspace' => $workspace,
            '--frontend-app' => 'apps/web',
            '--json' => true,
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('frontend_app_scope', $output);
        $this->assertStringContainsString('subscope_selected', $output);
        $this->assertStringNotContainsString($workspace.'/apps/web', $output);
    }
}
