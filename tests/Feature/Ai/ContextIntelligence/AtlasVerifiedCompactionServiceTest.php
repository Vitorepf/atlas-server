<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\ContextIntelligence;

use App\Services\Ai\ContextIntelligence\AtlasVerifiedCompactionService;
use App\Services\Ai\LongHorizon\AtlasLongHorizonCanon;
use Tests\Concerns\CreatesLongHorizonPersistenceTables;
use Tests\TestCase;

final class AtlasVerifiedCompactionServiceTest extends TestCase
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

    public function test_verified_compaction_passes_when_must_keep_coverage_is_full(): void
    {
        $payload = app(AtlasVerifiedCompactionService::class)->compact([
            'scope_type' => AtlasLongHorizonCanon::SCOPE_TYPE_DEV_SESSION,
            'scope_id' => 'dev-session-acie',
            'source_context_refs' => ['file:app/Services/Ai/ContextIntelligence/AtlasContextIntelligenceService.php'],
            'must_keep_items' => [
                ['id' => 'mk-decision', 'kind' => 'decision', 'digest' => 'ACIE is internal infrastructure'],
                ['id' => 'mk-blocker', 'kind' => 'blocker', 'digest' => 'Do not run benchmark'],
            ],
            'evidence_refs' => ['test:AtlasVerifiedCompactionServiceTest'],
        ]);

        $this->assertSame(AtlasVerifiedCompactionService::SCHEMA_VERSION, $payload['schema_version']);
        $this->assertSame(AtlasVerifiedCompactionService::STATUS_PASSED, $payload['status']);
        $this->assertSame(1.0, $payload['compaction_receipt']['must_keep_coverage']);
        $this->assertTrue($payload['compaction_receipt']['write_allowed']);
        $this->assertSame([], $payload['semantic_diff']['missing_must_keep_ids']);
        $this->assertSame([], $payload['blockers']);
        $this->assertArrayHasKey('verified_compaction_hash', $payload);
        $this->assertFalse($payload['claim_policy']['provider_calls_made']);
    }

    public function test_verified_compaction_blocks_when_semantic_diff_loses_must_keep(): void
    {
        $payload = app(AtlasVerifiedCompactionService::class)->compact([
            'scope_type' => AtlasLongHorizonCanon::SCOPE_TYPE_DEV_SESSION,
            'scope_id' => 'dev-session-loss',
            'must_keep_items' => [
                ['id' => 'mk-critical', 'kind' => 'decision', 'digest' => 'Preserve this decision'],
            ],
            'forced_discards' => [
                ['id' => 'mk-critical', 'reason' => AtlasLongHorizonCanon::DISCARDED_REASON_BUDGET_PRESSURE],
            ],
        ]);

        $this->assertSame(AtlasVerifiedCompactionService::STATUS_BLOCKED, $payload['status']);
        $this->assertSame(0.0, $payload['compaction_receipt']['must_keep_coverage']);
        $this->assertFalse($payload['compaction_receipt']['write_allowed']);
        $this->assertSame(['mk-critical'], $payload['semantic_diff']['missing_must_keep_ids']);
        $this->assertContains('must_keep_coverage_below_one', array_column($payload['blockers'], 'id'));
    }

    public function test_semantic_diff_hash_is_deterministic(): void
    {
        $service = app(AtlasVerifiedCompactionService::class);
        $a = $service->semanticDiff([
            ['id' => 'mk-1', 'kind' => 'decision', 'digest' => 'x'],
        ]);
        $b = $service->semanticDiff([
            ['id' => 'mk-1', 'kind' => 'decision', 'digest' => 'x'],
        ]);

        $this->assertSame($a['semantic_diff_hash'], $b['semantic_diff_hash']);
    }
}
