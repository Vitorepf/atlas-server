<?php

namespace Tests\Feature\Ai\Programming\Frontend;

use App\Services\Ai\Programming\Frontend\AtlasFrontendTaskSpecCompilerService;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class AtlasFrontendTaskSpecCommandTest extends TestCase
{
    public function test_spec_command_emits_provider_safe_task_spec(): void
    {
        $exitCode = Artisan::call('atlas:frontend:spec', [
            '--task' => 'Criar checkout ecommerce com estado vazio, erro e performance',
            '--acceptance' => true,
            '--asset-context' => true,
            '--json' => true,
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString(AtlasFrontendTaskSpecCompilerService::SCHEMA_VERSION, $output);
        $this->assertStringContainsString('task_spec_hash', $output);
        $this->assertStringContainsString('ecommerce_frontend', $output);
        $this->assertStringContainsString('/checkout', $output);
        $this->assertStringContainsString('"raw_task_returned": false', $output);
        $this->assertStringNotContainsString('Criar checkout ecommerce', $output);
    }

    public function test_spec_command_blocks_missing_task(): void
    {
        $exitCode = Artisan::call('atlas:frontend:spec', [
            '--json' => true,
        ]);
        $output = Artisan::output();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('task_missing', $output);
    }
}
