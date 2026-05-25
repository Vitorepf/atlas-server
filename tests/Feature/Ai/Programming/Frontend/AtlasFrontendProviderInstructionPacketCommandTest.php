<?php

namespace Tests\Feature\Ai\Programming\Frontend;

use App\Services\Ai\Programming\Frontend\AtlasFrontendProviderInstructionPacketService;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class AtlasFrontendProviderInstructionPacketCommandTest extends TestCase
{
    public function test_provider_packet_command_blocks_strict_when_context_is_missing(): void
    {
        $exitCode = Artisan::call('atlas:frontend:provider-packet', [
            '--task' => 'Criar tela premium',
            '--workspace' => sys_get_temp_dir().'/atlas-frontend-provider-packet-command-missing-'.bin2hex(random_bytes(4)),
            '--frontend-app' => 'apps/web',
            '--provider' => 'codex_cli',
            '--json' => true,
            '--strict' => true,
        ]);
        $output = Artisan::output();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString(AtlasFrontendProviderInstructionPacketService::SCHEMA_VERSION, $output);
        $this->assertStringContainsString('frontend_app_scope', $output);
        $this->assertStringContainsString('provider_instruction_packet_hash', $output);
        $this->assertStringContainsString('work_order_phase_blocked_pre_execution_gate', $output);
        $this->assertStringContainsString('runbook_repo_workspace_not_found', $output);
    }
}
