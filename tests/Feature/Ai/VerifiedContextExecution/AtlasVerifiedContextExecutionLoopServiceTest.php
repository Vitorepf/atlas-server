<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\VerifiedContextExecution;

use App\Services\Ai\VerifiedContextExecution\AtlasVerifiedContextExecutionLoopService;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

final class AtlasVerifiedContextExecutionLoopServiceTest extends TestCase
{
    private const GB = 1073741824;

    public function test_shadow_builds_eight_stage_read_only_verified_context_execution_loop(): void
    {
        $payload = app(AtlasVerifiedContextExecutionLoopService::class)->shadow([
            'flow_id' => 'atlas_dev',
            'domain' => 'programming',
            'provider' => 'gpt',
            'risk_level' => 'medium',
            'changed_files' => ['app/Services/Ai/Foo.php'],
            'code_graph' => ['related_tests' => ['tests/Feature/Ai/FooTest.php']],
            'memory_total_bytes' => 48 * self::GB,
            'memory_available_bytes' => 20 * self::GB,
            'swap_used_bytes' => 0,
            'cpu_load' => 0.2,
        ]);

        $this->assertSame(AtlasVerifiedContextExecutionLoopService::SHADOW_SCHEMA, $payload['schema_version']);
        $this->assertSame('ready', $payload['status']);
        $this->assertCount(8, $payload['stages']);
        $this->assertSame([
            'context_compile',
            'must_keep_guard',
            'cache_delta',
            'token_economy',
            'local_verification',
            'failure_capsule',
            'repair_strategy',
            'outcome_memory_candidate',
        ], array_column($payload['stages'], 'id'));
        $this->assertSame(1.0, data_get($payload, 'quality_contract.must_keep_coverage'));
        $this->assertFalse(data_get($payload, 'claim_policy.providers_invoked'));
        $this->assertFalse(data_get($payload, 'claim_policy.commands_executed'));
        $this->assertFalse(data_get($payload, 'claim_policy.writes'));
        $this->assertNotEmpty($payload['avcel_hash']);
    }

    public function test_shadow_turns_failure_log_into_repair_strategy_without_exposing_secret(): void
    {
        $payload = app(AtlasVerifiedContextExecutionLoopService::class)->shadow([
            'changed_files' => ['app/Foo.php'],
            'command' => 'php artisan test tests/Feature/FooTest.php',
            'exit_code' => 1,
            'stderr' => 'tests/Feature/FooTest.php:42 Failed asserting that false is true. token=SECRET123',
            'failing_test' => 'Tests\\Feature\\FooTest::test_example',
            'memory_total_bytes' => 48 * self::GB,
            'memory_available_bytes' => 20 * self::GB,
        ]);
        $encoded = json_encode($payload, JSON_THROW_ON_ERROR);

        $this->assertSame('watch', $payload['status']);
        $this->assertSame('repair_with_failure_capsule', data_get($payload, 'repair_strategy.strategy'));
        $this->assertTrue(data_get($payload, 'repair_strategy.auto_repair_allowed'));
        $this->assertSame('repair_preparation', data_get($payload, 'outcome_memory_candidate.outcome_type'));
        $this->assertNotEmpty(data_get($payload, 'repair_strategy.failure_signature'));
        $this->assertFalse(data_get($payload, 'repair_strategy.repair_context_policy.send_raw_log'));
        $this->assertStringNotContainsString('SECRET123', $encoded);
    }

    public function test_shadow_blocks_when_scope_or_must_keep_quality_fails(): void
    {
        $payload = app(AtlasVerifiedContextExecutionLoopService::class)->shadow([
            'changed_files' => ['.env'],
            'forbidden_files' => ['.env'],
            'must_keep_coverage' => 0.99,
        ]);

        $this->assertSame('blocked', $payload['status']);
        $this->assertContains('must_keep_coverage_below_one', data_get($payload, 'quality_contract.blockers'));
        $this->assertContains('local_verification_blocked', data_get($payload, 'quality_contract.blockers'));
        $this->assertSame('blocked_candidate', data_get($payload, 'outcome_memory_candidate.status'));
    }

    public function test_certification_passes_and_proves_core_invariants(): void
    {
        $payload = app(AtlasVerifiedContextExecutionLoopService::class)->certify([
            'memory_total_bytes' => 48 * self::GB,
            'memory_available_bytes' => 20 * self::GB,
        ]);

        $this->assertSame(AtlasVerifiedContextExecutionLoopService::CERTIFICATION_SCHEMA, $payload['schema_version']);
        $this->assertSame('ready', $payload['status']);
        $this->assertSame(0, data_get($payload, 'summary.fail'));
        $this->assertNotEmpty($payload['certification_hash']);
    }

    public function test_command_emits_shadow_and_certify_json(): void
    {
        $shadowExit = Artisan::call('atlas:verified-context-execution', [
            'action' => 'shadow',
            '--changed-file' => ['app/Foo.php'],
            '--available-gb' => '20',
            '--json' => true,
        ]);
        $shadow = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(0, $shadowExit);
        $this->assertSame(AtlasVerifiedContextExecutionLoopService::SHADOW_SCHEMA, $shadow['schema_version']);

        $certifyExit = Artisan::call('atlas:verified-context-execution', [
            'action' => 'certify',
            '--available-gb' => '20',
            '--json' => true,
        ]);
        $certify = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(0, $certifyExit);
        $this->assertSame(AtlasVerifiedContextExecutionLoopService::CERTIFICATION_SCHEMA, $certify['schema_version']);
    }
}
