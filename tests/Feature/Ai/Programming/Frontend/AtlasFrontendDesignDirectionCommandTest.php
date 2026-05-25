<?php

namespace Tests\Feature\Ai\Programming\Frontend;

use App\Services\Ai\Programming\Frontend\AtlasFrontendDesignDirectionAdvisorService;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class AtlasFrontendDesignDirectionCommandTest extends TestCase
{
    public function test_directions_command_emits_governed_direction_payload(): void
    {
        $exitCode = Artisan::call('atlas:frontend:directions', [
            '--task' => 'Criar tela ecommerce premium com marca e assets reais',
            '--json' => true,
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString(AtlasFrontendDesignDirectionAdvisorService::SCHEMA_VERSION, $output);
        $this->assertStringContainsString('brand_product_depth', $output);
        $this->assertStringContainsString('selection_requires_reason', $output);
    }

    public function test_directions_command_blocks_missing_task(): void
    {
        $exitCode = Artisan::call('atlas:frontend:directions', [
            '--json' => true,
        ]);
        $output = Artisan::output();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('task_missing', $output);
    }
}
