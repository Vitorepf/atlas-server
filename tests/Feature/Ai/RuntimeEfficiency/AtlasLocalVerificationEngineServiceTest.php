<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\RuntimeEfficiency;

use App\Services\Ai\RuntimeEfficiency\AtlasLocalVerificationEngineService;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

final class AtlasLocalVerificationEngineServiceTest extends TestCase
{
    public function test_selects_tests_and_emits_read_only_local_verification_plan(): void
    {
        $payload = app(AtlasLocalVerificationEngineService::class)->run([
            'flow_id' => 'atlas_dev',
            'risk_level' => 'medium',
            'changed_files' => ['app/Services/Ai/RuntimeEfficiency/AtlasQualityPreservingEfficiencySystemService.php'],
            'code_graph' => ['related_tests' => ['tests/Feature/Ai/RuntimeEfficiency/AtlasQualityPreservingEfficiencySystemServiceTest.php']],
            'resource_policy' => ['mode' => 'normal', 'heavy_jobs_allowed' => true],
        ]);

        $this->assertSame(AtlasLocalVerificationEngineService::SCHEMA_VERSION, $payload['schema_version']);
        $this->assertSame('ready', $payload['status']);
        $this->assertSame(AtlasLocalVerificationEngineService::TEST_IMPACT_SCHEMA, data_get($payload, 'test_impact.schema_version'));
        $this->assertContains('tests/Feature/Ai/RuntimeEfficiency/AtlasQualityPreservingEfficiencySystemServiceTest.php', data_get($payload, 'test_impact.selected_tests'));
        $this->assertContains('targeted_tests', data_get($payload, 'gate_plan.admitted_gate_groups'));
        $this->assertFalse(data_get($payload, 'claim_policy.commands_executed'));
    }

    public function test_scope_violation_blocks_before_any_command_execution(): void
    {
        $payload = app(AtlasLocalVerificationEngineService::class)->run([
            'changed_files' => ['.env', 'app/Foo.php'],
            'forbidden_files' => ['.env'],
        ]);

        $this->assertSame('blocked', $payload['status']);
        $this->assertContains('diff_scope_violation', $payload['blockers']);
        $this->assertSame(['.env'], data_get($payload, 'scope_guard.forbidden_hits'));
        $this->assertFalse(data_get($payload, 'claim_policy.commands_executed'));
    }

    public function test_failure_capsule_hashes_raw_log_and_preserves_root_cause(): void
    {
        $secretLog = 'tests/Feature/FooTest.php:42 Failed asserting that false is true. token=SECRET123';
        $payload = app(AtlasLocalVerificationEngineService::class)->run([
            'changed_files' => ['app/Foo.php'],
            'command' => 'php artisan test tests/Feature/FooTest.php',
            'exit_code' => 1,
            'stderr' => $secretLog,
            'failing_test' => 'Tests\\Feature\\FooTest::test_example',
        ]);
        $encoded = json_encode($payload, JSON_THROW_ON_ERROR);

        $this->assertSame('watch', $payload['status']);
        $this->assertSame(AtlasLocalVerificationEngineService::FAILURE_CAPSULE_SCHEMA, data_get($payload, 'failure_capsule.schema_version'));
        $this->assertTrue(data_get($payload, 'failure_capsule.root_cause_present'));
        $this->assertSame('failing_test:Tests\\Feature\\FooTest::test_example', data_get($payload, 'failure_capsule.root_cause_candidate'));
        $this->assertNotEmpty(data_get($payload, 'failure_capsule.raw_log_hash'));
        $this->assertStringNotContainsString('SECRET123', data_get($payload, 'failure_capsule.raw_log_hash'));
        $this->assertStringNotContainsString('SECRET123', $encoded);
        $this->assertStringContainsString('Failed asserting', $encoded);
    }

    public function test_high_risk_deep_gate_is_blocked_when_resource_policy_disallows_heavy_jobs(): void
    {
        $payload = app(AtlasLocalVerificationEngineService::class)->run([
            'risk_level' => 'high',
            'changed_files' => ['app/Foo.php'],
            'code_graph' => ['related_tests' => ['tests/Feature/FooTest.php']],
            'resource_policy' => ['mode' => 'light', 'heavy_jobs_allowed' => false],
        ]);

        $this->assertSame('ready', $payload['status']);
        $this->assertNotContains('deep_gates', data_get($payload, 'gate_plan.admitted_gate_groups'));
        $this->assertSame('light', data_get($payload, 'gate_plan.resource_mode'));
    }

    public function test_command_emits_json(): void
    {
        $exit = Artisan::call('atlas:local-verification:run', [
            '--changed-file' => ['app/Foo.php'],
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame(AtlasLocalVerificationEngineService::SCHEMA_VERSION, $payload['schema_version']);
    }
}
