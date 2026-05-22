<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Context;

use App\Services\Ai\Context\AtlasContextCacheCompilerRuntimeService;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

final class ContextCacheCompilerRuntimeTest extends TestCase
{
    public function test_warmup_builds_merkle_pack_and_delta_without_raw_content(): void
    {
        $secret = 'api_key=sk-ACCCRSEGREDO1234567890';
        $payload = app(AtlasContextCacheCompilerRuntimeService::class)->warm([
            'flow_id' => 'atlas_dev',
            'provider' => 'gpt',
            'nodes' => [
                ['zone' => 'governance_zone', 'kind' => 'owner_doc', 'source_path' => 'docs/a.md', 'content' => $secret, 'tokens' => 1000, 'must_keep' => true],
                ['zone' => 'task_delta_zone', 'kind' => 'task_delta', 'source_path' => 'operator', 'tokens' => 400, 'must_keep' => true],
            ],
            'changed_file_refs' => ['app/Foo.php'],
            'evidence_refs' => ['test:acc cr'],
        ]);
        $encoded = json_encode($payload, JSON_THROW_ON_ERROR);

        $this->assertSame(AtlasContextCacheCompilerRuntimeService::SCHEMA_VERSION, $payload['schema_version']);
        $this->assertSame('ready', $payload['status']);
        $this->assertSame(AtlasContextCacheCompilerRuntimeService::MERKLE_PACK_SCHEMA, data_get($payload, 'merkle_pack.schema_version'));
        $this->assertSame(AtlasContextCacheCompilerRuntimeService::CACHE_WARMUP_SCHEMA, data_get($payload, 'warmup_receipt.schema_version'));
        $this->assertSame(AtlasContextCacheCompilerRuntimeService::DELTA_REQUEST_SCHEMA, data_get($payload, 'delta_request.schema_version'));
        $this->assertSame(1.0, data_get($payload, 'quality_contract.must_keep_coverage'));
        $this->assertStringNotContainsString($secret, $encoded);
        $this->assertStringNotContainsString('sk-ACCCRSEGREDO', $encoded);
        $this->assertFalse(data_get($payload, 'claim_policy.providers_invoked'));
    }

    public function test_same_input_hash_is_deterministic_and_previous_prefix_can_hit(): void
    {
        $service = app(AtlasContextCacheCompilerRuntimeService::class);

        $first = $service->warm(['flow_id' => 'atlas_dev', 'provider' => 'gpt']);
        $second = $service->warm([
            'flow_id' => 'atlas_dev',
            'provider' => 'gpt',
            'previous_prefix_hash' => data_get($first, 'merkle_pack.cacheable_prefix_hash'),
        ]);

        $this->assertSame($first['context_cache_hash'], $service->warm(['flow_id' => 'atlas_dev', 'provider' => 'gpt'])['context_cache_hash']);
        $this->assertSame('hit', data_get($second, 'warmup_receipt.cache_status'));
    }

    public function test_prefix_drift_blocks_shadow_cache(): void
    {
        $payload = app(AtlasContextCacheCompilerRuntimeService::class)->warm([
            'expected_prefix_hash' => str_repeat('a', 64),
        ]);

        $this->assertSame('blocked', $payload['status']);
        $this->assertTrue(data_get($payload, 'prompt_prefix_drift.drift_detected'));
        $this->assertContains('prompt_prefix_drift', $payload['blockers']);
    }

    public function test_stale_must_keep_blocks_cache_quality(): void
    {
        $payload = app(AtlasContextCacheCompilerRuntimeService::class)->warm([
            'nodes' => [
                ['zone' => 'governance_zone', 'kind' => 'owner_doc', 'source_path' => 'docs/a.md', 'freshness' => 'stale', 'must_keep' => true],
                ['zone' => 'task_delta_zone', 'kind' => 'task_delta', 'source_path' => 'operator', 'must_keep' => true],
            ],
        ]);

        $this->assertSame('blocked', $payload['status']);
        $this->assertLessThan(1.0, data_get($payload, 'quality_contract.must_keep_coverage'));
        $this->assertContains('must_keep_coverage_below_one', $payload['blockers']);
    }

    public function test_command_emits_json(): void
    {
        $exit = Artisan::call('atlas:context:cache-warm', [
            '--flow-id' => 'atlas_dev',
            '--provider' => 'gpt',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame(AtlasContextCacheCompilerRuntimeService::SCHEMA_VERSION, $payload['schema_version']);
        $this->assertSame('ready', $payload['status']);
    }
}
