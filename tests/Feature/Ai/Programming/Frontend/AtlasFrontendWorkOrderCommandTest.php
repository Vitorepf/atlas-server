<?php

namespace Tests\Feature\Ai\Programming\Frontend;

use App\Services\Ai\Programming\Frontend\AtlasFrontendWorkOrderService;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class AtlasFrontendWorkOrderCommandTest extends TestCase
{
    public function test_work_order_command_emits_repair_packet_and_fails_strict_when_blocked(): void
    {
        $exitCode = Artisan::call('atlas:frontend:work-order', [
            '--task' => 'Criar redesign premium SaaS novo',
            '--workspace' => sys_get_temp_dir().'/atlas-frontend-work-order-command-missing-'.bin2hex(random_bytes(4)),
            '--json' => true,
            '--strict' => true,
        ]);
        $output = Artisan::output();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString(AtlasFrontendWorkOrderService::SCHEMA_VERSION, $output);
        $this->assertStringContainsString('context_repair_before_provider_dispatch', $output);
        $this->assertStringContainsString('work_order_hash', $output);
    }
}
