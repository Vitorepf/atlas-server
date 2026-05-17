<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Programming\AtlasDev;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

final class AtlasDevSeniorLoopAuditCommandTest extends TestCase
{
    private string $receiptsPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->receiptsPath = sys_get_temp_dir().'/atlas-dev-senior-loop-receipts-'.bin2hex(random_bytes(4));
        File::ensureDirectoryExists($this->receiptsPath);

        config()->set('atlas_dev.receipts_path', $this->receiptsPath);
        config()->set('atlas_dev.efficient.plan_enabled', true);
        config()->set('atlas_dev.efficient.desktop_enabled', true);
        config()->set('app.key', 'base64:'.base64_encode(str_repeat('S', 32)));
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->receiptsPath);

        parent::tearDown();
    }

    public function test_senior_loop_audit_strict_passes_and_persists_audit_receipt(): void
    {
        $exit = Artisan::call('atlas:dev:senior-loop:audit', [
            '--json' => true,
            '--strict' => true,
        ]);
        $payload = json_decode(Artisan::output(), true);

        $this->assertSame(0, $exit, Artisan::output());
        $this->assertIsArray($payload);
        $this->assertSame('atlas.dev.senior_engineer_loop_audit.v1', $payload['schema_version']);
        $this->assertSame('passed', $payload['status']);
        $this->assertSame([], $payload['blockers']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $payload['audit_hash']);

        $this->assertSame([
            'ambiguity_resolution_engine',
            'architecture_aware_editing',
            'autonomous_debug_loop',
            'desktop_engineer_cockpit',
            'enterprise_hardening',
            'learning_error_ledger_curator_flow',
            'multi_step_work_planner',
        ], array_keys($payload['capabilities']));
        $this->assertNotContains(false, array_values($payload['capabilities']));

        $this->assertSame('selected_highest_confidence_workspace_interpretation', $payload['ambiguity_resolution']['decision']);
        $this->assertStringStartsWith('workspace://', $payload['ambiguity_resolution']['hypotheses'][0]['evidence_ref']);
        $this->assertStringNotContainsString(sys_get_temp_dir(), Artisan::output());
        $this->assertGreaterThanOrEqual(5, count($payload['multi_step_plan']));
        $this->assertSame('autonomous_repair_loop', $payload['debug_loop']['mode']);
        $this->assertSame('bounded_change', $payload['architecture_review']['verdict']);
        $this->assertContains('learning', $payload['desktop_cockpit']['panels']);
        $this->assertFalse($payload['learning_handoff']['auto_apply']);
        $this->assertStringEndsWith('/senior_engineer_loop_audit.json', $payload['persisted_ref']);

        $persisted = $this->receiptsPath.'/'.$payload['run_id'].'/senior_engineer_loop_audit.json';
        $this->assertFileExists($persisted);
        $persistedPayload = json_decode((string) file_get_contents($persisted), true);
        $this->assertSame($payload['audit_hash'], $persistedPayload['audit_hash']);
    }
}
