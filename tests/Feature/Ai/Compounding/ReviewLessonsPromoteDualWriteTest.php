<?php

namespace Tests\Feature\Ai\Compounding;

use App\Models\AiCompoundingMemory;
use App\Models\AiLearningCandidate;
use App\Models\AtlasMemoryEntry;
use App\Services\Ai\Compounding\AtlasObraLessonHarvester;
use App\Services\Ai\Compounding\CompoundingHash;
use Tests\Concerns\BootsCompoundingSchema;
use Tests\Concerns\CreatesAtlasMemoryEntryTable;
use Tests\TestCase;

/**
 * OPE-02 — review-lessons --promote dual-writes registry + compounding memory
 * when automatic quality checks pass; registry-only with honest blocked_reason otherwise.
 */
class ReviewLessonsPromoteDualWriteTest extends TestCase
{
    use BootsCompoundingSchema;
    use CreatesAtlasMemoryEntryTable;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bootCompoundingSchema();
        $this->createAtlasMemoryEntryTable();
    }

    protected function tearDown(): void
    {
        $this->dropAtlasMemoryEntryTable();
        $this->dropCompoundingSchema();

        parent::tearDown();
    }

    public function test_promote_dual_writes_registry_and_active_compounding_memory(): void
    {
        $candidate = $this->quarantineCandidate(
            claim: 'NÃO re-propor: dual-write happy path — prova real.',
            confidence: 85,
            evidenceRefs: ['doc:/tmp/obra-dual-write.md', 'commit:abc123'],
        );

        $shortId = substr((string) $candidate->getKey(), 0, 8);

        $this->artisan('atlas:compounding:review-lessons', ['--promote' => $shortId, '--json' => true])
            ->assertSuccessful();

        $this->assertSame(1, AtlasMemoryEntry::query()->count());
        $this->assertSame(1, AiCompoundingMemory::query()->where('status', 'active')->count());

        $entry = AtlasMemoryEntry::query()->sole();
        $memory = AiCompoundingMemory::query()->sole();

        $this->assertSame($candidate->claim, $memory->claim);
        $this->assertSame(['doc:/tmp/obra-dual-write.md', 'commit:abc123'], $memory->evidence_refs);
        $this->assertSame(85, $memory->confidence);
        $this->assertSame('operator:review-lessons', data_get($memory->payload, 'promoted_by'));
        $this->assertSame((string) $entry->getKey(), data_get($memory->payload, 'promoted_memory_entry_id'));
        $this->assertSame('operator:review-lessons', data_get($entry->metadata, 'promoted_by'));
        $this->assertSame((string) $memory->getKey(), data_get($entry->metadata, 'promoted_compounding_memory_id'));
        $this->assertSame((string) $memory->getKey(), data_get($candidate->refresh()->payload, 'promoted_compounding_memory_id'));
    }

    public function test_promote_without_evidence_writes_registry_only_and_reports_blocked_reason(): void
    {
        $candidate = $this->quarantineCandidate(
            claim: 'NÃO re-propor: sem evidência — compounding bloqueado.',
            confidence: 90,
            evidenceRefs: [],
        );

        $shortId = substr((string) $candidate->getKey(), 0, 8);

        $this->artisan('atlas:compounding:review-lessons', ['--promote' => $shortId, '--json' => true])
            ->assertSuccessful()
            ->expectsOutputToContain('compounding_memory_requires_evidence_confidence_and_revalidation');

        $this->assertSame(1, AtlasMemoryEntry::query()->count());
        $this->assertSame(0, AiCompoundingMemory::query()->count());

        $entry = AtlasMemoryEntry::query()->sole();
        $this->assertSame(
            'compounding_memory_requires_evidence_confidence_and_revalidation',
            data_get($entry->metadata, 'compounding_blocked_reason'),
        );
        $this->assertSame(
            'compounding_memory_requires_evidence_confidence_and_revalidation',
            data_get($candidate->refresh()->payload, 'compounding_blocked_reason'),
        );
    }

    public function test_repromote_same_claim_does_not_duplicate_active_compounding_memory(): void
    {
        $claim = 'NÃO re-propor: idempotência compounding — mesma claim.';

        $first = $this->quarantineCandidate(
            claim: $claim,
            confidence: 82,
            evidenceRefs: ['doc:/tmp/obra-idempotent-a.md'],
            hashSeed: 'idempotent-a',
        );
        $second = $this->quarantineCandidate(
            claim: $claim,
            confidence: 88,
            evidenceRefs: ['doc:/tmp/obra-idempotent-b.md'],
            hashSeed: 'idempotent-b',
        );

        $this->artisan('atlas:compounding:review-lessons', [
            '--promote' => (string) $first->getKey(),
            '--json' => true,
        ])->assertSuccessful();

        $this->artisan('atlas:compounding:review-lessons', [
            '--promote' => (string) $second->getKey(),
            '--json' => true,
        ])->assertSuccessful();

        $this->assertSame(2, AtlasMemoryEntry::query()->count());
        $this->assertSame(1, AiCompoundingMemory::query()->where('status', 'active')->count());

        $memory = AiCompoundingMemory::query()->sole();
        $this->assertSame(88, $memory->confidence, 're-promote revalidates confidence on existing memory');
    }

    /**
     * @param  array<int,string>  $evidenceRefs
     */
    private function quarantineCandidate(
        string $claim,
        int $confidence,
        array $evidenceRefs,
        string $hashSeed = 'default',
    ): AiLearningCandidate {
        $payload = [
            'schema_version' => AtlasObraLessonHarvester::SCHEMA_VERSION,
            'candidate_hash' => CompoundingHash::make(['seed' => $hashSeed, 'claim' => $claim]),
            'status' => 'held_for_evidence',
            'decision' => 'hold',
            'memory_type' => 'refutation_memory',
            'scope' => 'global',
            'claim' => $claim,
            'confidence' => $confidence,
            'promotion_allowed' => false,
            'evidence_refs' => $evidenceRefs,
            'payload' => [
                'doc_path' => '/tmp/obra-fixture.md',
                'signals' => ['revalidation_policy' => 'revalidate_on_failure_or_expiry'],
            ],
        ];
        $payload['receipt_hash'] = CompoundingHash::make($payload);

        return AiLearningCandidate::query()->create($payload);
    }
}
