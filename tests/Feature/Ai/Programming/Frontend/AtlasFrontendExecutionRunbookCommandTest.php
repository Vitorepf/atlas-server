<?php

namespace Tests\Feature\Ai\Programming\Frontend;

use App\Services\Ai\Programming\Frontend\AtlasFrontendExecutionRunbookService;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class AtlasFrontendExecutionRunbookCommandTest extends TestCase
{
    public function test_runbook_command_emits_blocked_runbook_for_missing_repo(): void
    {
        $exitCode = Artisan::call('atlas:frontend:runbook', [
            '--task' => 'Ajustar tela',
            '--workspace' => sys_get_temp_dir().'/atlas-frontend-runbook-missing-'.bin2hex(random_bytes(4)),
            '--frontend-app' => 'apps/web',
            '--acceptance' => true,
            '--json' => true,
            '--strict' => true,
        ]);
        $output = Artisan::output();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString(AtlasFrontendExecutionRunbookService::SCHEMA_VERSION, $output);
        $this->assertStringContainsString('frontend_app_scope', $output);
        $this->assertStringContainsString('runbook_hash', $output);
        $this->assertStringContainsString('repo_workspace_not_found', $output);
    }
}
