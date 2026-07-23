<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Context;

use App\Models\AiCompoundingMemory;
use App\Services\Ai\Memory\AtlasHybridMemoryRetrievalService;
use Tests\Concerns\BootsCompoundingSchema;
use Tests\TestCase;

final class HybridRecallCompoundingArmDefaultOnTest extends TestCase
{
    use BootsCompoundingSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootCompoundingSchema();
    }

    protected function tearDown(): void
    {
        $this->dropCompoundingSchema();
        parent::tearDown();
    }

    public function test_default_hybrid_recall_serves_only_gate_promoted_compounding_memories(): void
    {
        $active = $this->seedMemory('Use the receipt hash to prove compounding recall lift.', 'active');
        $this->seedMemory('Pending compounding memory must not be served.', 'pending');
        $this->seedMemory('Retired compounding memory must not be served.', 'retired');

        $this->assertTrue((bool) config('atlas.semantic_memory.compounding_recall_enabled'));

        $recall = app(AtlasHybridMemoryRetrievalService::class)->recall(
            'compounding recall lift receipt hash',
            [],
            [],
            [
                'limit' => 5,
                'include_registry' => false,
                'include_verbatim' => false,
                'include_semantic' => false,
                'record_usage' => false,
            ],
        );

        $this->assertSame(1, data_get($recall, 'summary.compounding_candidates'));
        $this->assertSame([(string) $active->id], collect($recall['sources']['compounding'] ?? [])->pluck('id')->values()->all());
        $this->assertSame(['compounding'], collect($recall['recall'] ?? [])->pluck('source')->unique()->values()->all());
        $this->assertStringContainsString('receipt hash', (string) data_get($recall, 'recall.0.excerpt'));
    }

    private function seedMemory(string $claim, string $status): AiCompoundingMemory
    {
        return AiCompoundingMemory::query()->create([
            'schema_version' => 'atlas.ai.compounding.memory.v1',
            'learning_candidate_id' => null,
            'memory_type' => 'routing_memory',
            'scope' => 'atlas-server',
            'flow_id' => 'atlas_dev',
            'status' => $status,
            'claim' => $claim,
            'confidence' => 91,
            'evidence_refs' => ['receipt:ope04'],
            'revalidation_policy' => 'revalidate_on_failure_or_expiry',
            'valid_until' => now()->addDays(7),
            'last_revalidated_at' => now(),
            'payload' => ['raw' => 'not served'],
            'memory_hash' => hash('sha256', $claim),
        ]);
    }
}
