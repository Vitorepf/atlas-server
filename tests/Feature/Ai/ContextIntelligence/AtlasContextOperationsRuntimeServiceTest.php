<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\ContextIntelligence;

use App\Services\Ai\ContextIntelligence\AtlasContextOperationsRuntimeService;
use App\Services\Ai\ContextIntelligence\AtlasVerifiedCompactionService;
use Tests\Concerns\CreatesLongHorizonPersistenceTables;
use Tests\TestCase;

final class AtlasContextOperationsRuntimeServiceTest extends TestCase
{
    use CreatesLongHorizonPersistenceTables;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createLongHorizonPersistenceTables();
    }

    protected function tearDown(): void
    {
        $this->dropLongHorizonPersistenceTables();
        parent::tearDown();
    }

    public function test_runtime_composes_acie_acol_handoff_and_verified_compaction_for_forge(): void
    {
        $payload = app(AtlasContextOperationsRuntimeService::class)->evaluate([
            'prompt' => 'implemente esta Obra com evidencia e preserve decisoes',
            'domain' => 'programming',
            'flow_id' => 'atlas_forge',
            'flow_profile' => 'programming.forge',
            'runtime_mode' => 'forge',
            'policy_required' => true,
            'evidence_required' => true,
            'tool_plan_required' => true,
            'context_refs' => [
                'doc:forge-sdd',
                'file:app/Foo.php',
                'test:FooTest',
                'receipt:previous',
            ],
            'evidence_refs' => ['receipt:router_decision:abc', 'receipt:runtime_dispatch:def'],
            'must_keep_items' => [
                ['id' => 'mk-1', 'kind' => 'decision', 'digest' => 'preserve SDD'],
            ],
            'handoff_target' => ['kind' => 'atlas_forge', 'flow_id' => 'atlas_forge'],
        ]);

        $this->assertSame(AtlasContextOperationsRuntimeService::SCHEMA_VERSION, $payload['schema_version']);
        $this->assertContains($payload['status'], [
            AtlasContextOperationsRuntimeService::STATUS_READY,
            AtlasContextOperationsRuntimeService::STATUS_WATCH,
        ]);
        $this->assertSame('atlas.context_intelligence.context_certification.v1', data_get($payload, 'context_intelligence.schema_version'));
        $this->assertSame('atlas.conversation_ops.health_report.v1', data_get($payload, 'conversation_ops.schema_version'));
        $this->assertSame(AtlasVerifiedCompactionService::SCHEMA_VERSION, data_get($payload, 'verified_compaction.schema_version'));
        $this->assertSame(AtlasVerifiedCompactionService::STATUS_PASSED, data_get($payload, 'verified_compaction.status'));
        $this->assertSame(1.0, data_get($payload, 'verified_compaction.compaction_receipt.must_keep_coverage'));
        $this->assertSame('atlas.conversation_ops.handoff_packet.v1', data_get($payload, 'handoff_packet.schema_version'));
        $this->assertSame('atlas.conversation_ops.context_janitor_receipt.v1', data_get($payload, 'context_janitor.schema_version'));
        $this->assertSame('atlas.conversation_ops.memory_curator_receipt.v1', data_get($payload, 'memory_curator.schema_version'));
        $this->assertSame('atlas.conversation_ops.compression_critic_report.v1', data_get($payload, 'compression_critic.schema_version'));
        $this->assertTrue(data_get($payload, 'integration_policy.verified_compaction_required'));
        $this->assertTrue(data_get($payload, 'integration_policy.handoff_required'));
        $this->assertFalse(data_get($payload, 'claim_policy.provider_calls_made'));
        $this->assertArrayHasKey('operations_runtime_hash', $payload);
    }

    public function test_runtime_skips_compaction_for_small_direct_flow_but_keeps_policy(): void
    {
        $payload = app(AtlasContextOperationsRuntimeService::class)->evaluate([
            'prompt' => 'responda em uma frase',
            'domain' => 'conversation',
            'flow_id' => 'atlas_conversation',
            'runtime_mode' => 'standard',
            'context_refs' => ['doc:small'],
            'evidence_refs' => ['receipt:router'],
        ]);

        $this->assertSame('skipped', data_get($payload, 'verified_compaction.status'));
        $this->assertSame('not_required_for_flow', data_get($payload, 'verified_compaction.reason'));
        $this->assertFalse(data_get($payload, 'integration_policy.verified_compaction_required'));
        $this->assertNull($payload['handoff_packet']);
        $this->assertFalse(data_get($payload, 'claim_policy.rivals_compared'));
    }
}
