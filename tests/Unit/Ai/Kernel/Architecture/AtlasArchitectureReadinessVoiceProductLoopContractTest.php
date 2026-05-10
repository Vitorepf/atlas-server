<?php

namespace Tests\Unit\Ai\Kernel\Architecture;

use App\Services\Ai\Kernel\Architecture\AtlasArchitectureReadinessService;
use Tests\TestCase;

final class AtlasArchitectureReadinessVoiceProductLoopContractTest extends TestCase
{
    public function test_shared_readiness_snapshot_keeps_voice_product_loop_dod_explicit(): void
    {
        $payload = app(AtlasArchitectureReadinessService::class)->snapshot([
            'owner' => 'knowledge_governance',
        ]);

        $this->assertSame('atlas.architecture_readiness.v1', $payload['schema_version']);
        $this->assertSame('Voice Realtime product loop', data_get($payload, 'safe_next_blocks.0.block'));
        $this->assertStringContainsString('sem provider direto', data_get($payload, 'safe_next_blocks.0.dod_minimum'));
        $this->assertStringContainsString('VOICE_* real', data_get($payload, 'safe_next_blocks.0.dod_minimum'));
        $this->assertStringContainsString('review humano', data_get($payload, 'safe_next_blocks.0.dod_minimum'));
        $this->assertContains(
            'runtime_language_boundary',
            data_get($payload, 'architecture_operations.related_operation_ids'),
        );
    }
}
