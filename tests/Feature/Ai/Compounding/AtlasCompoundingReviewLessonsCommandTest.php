<?php

namespace Tests\Feature\Ai\Compounding;

use App\Models\AiLearningCandidate;
use App\Models\AtlasMemoryEntry;
use App\Services\Ai\Memory\AtlasMemoryRegistryService;
use Tests\Concerns\BootsCompoundingSchema;
use Tests\Concerns\CreatesAtlasMemoryEntryTable;
use Tests\TestCase;

/**
 * Obra #14 H2.3 — ciclo completo da lição de obra: harvest → quarentena G0
 * (hold) → promoção EXPLÍCITA do operador via atlas:compounding:review-lessons
 * → memória governada no registry. Sem --promote NADA muda (G0 nunca
 * auto-promove).
 */
class AtlasCompoundingReviewLessonsCommandTest extends TestCase
{
    use BootsCompoundingSchema;
    use CreatesAtlasMemoryEntryTable;

    private string $fixture;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bootCompoundingSchema();
        $this->createAtlasMemoryEntryTable();

        $this->fixture = tempnam(sys_get_temp_dir(), 'obra14-h23-').'.md';
        file_put_contents($this->fixture, <<<'MD'
# Obra fixture H2.3

## NÃO-FAZER (refutados com prova)

| ID | Proposta refutada | Por quê |
|---|---|---|
| N-01 | Gerar família por catálogo | Medição refutou: economia desonesta. |
MD);
    }

    protected function tearDown(): void
    {
        @unlink($this->fixture);
        $this->dropAtlasMemoryEntryTable();
        $this->dropCompoundingSchema();

        parent::tearDown();
    }

    private function harvestOneCandidate(): AiLearningCandidate
    {
        $this->artisan('atlas:compounding:harvest-obra-lessons', ['doc' => $this->fixture])
            ->assertSuccessful();

        $candidate = AiLearningCandidate::query()->sole();
        $this->assertSame('held_for_evidence', $candidate->status);
        $this->assertSame('hold', $candidate->decision);
        $this->assertFalse($candidate->promotion_allowed);

        return $candidate;
    }

    public function test_read_only_digest_never_auto_promotes(): void
    {
        $candidate = $this->harvestOneCandidate();

        $this->artisan('atlas:compounding:review-lessons')
            ->expectsOutputToContain('1 lições de obra em quarentena')
            ->assertSuccessful();

        // Regra pétrea: sem --promote nada muda.
        $candidate->refresh();
        $this->assertSame('held_for_evidence', $candidate->status);
        $this->assertSame('hold', $candidate->decision);
        $this->assertFalse($candidate->promotion_allowed);
        $this->assertSame(0, AtlasMemoryEntry::query()->count());
    }

    public function test_explicit_promote_writes_governed_memory_and_marks_candidate(): void
    {
        $candidate = $this->harvestOneCandidate();
        $shortId = substr((string) $candidate->getKey(), 0, 8);

        $this->artisan('atlas:compounding:review-lessons', ['--promote' => $shortId, '--json' => true])
            ->assertSuccessful();

        $entry = AtlasMemoryEntry::query()->sole();
        $this->assertSame('refutation_memory', $entry->memory_type);
        $this->assertSame('global', $entry->scope_type);
        $this->assertSame('active', $entry->status);
        $this->assertSame((string) $candidate->claim, $entry->body);
        $this->assertSame('obra_lesson', $entry->source_type);
        $this->assertSame((string) $candidate->getKey(), $entry->source_id);
        $this->assertContains('obra-lesson', (array) $entry->tags);
        // URI/origem do doc de obra preservada na memória promovida.
        $this->assertSame($this->fixture, data_get($entry->metadata, 'doc_path'));
        $this->assertSame((string) $candidate->candidate_hash, data_get($entry->metadata, 'candidate_hash'));
        $this->assertContains($this->fixture, (array) data_get($entry->metadata, 'evidence_refs'));
        $this->assertSame('atlas.refutation_strength.v1', data_get($entry->metadata, 'refutation_strength.schema'));
        $this->assertGreaterThan(0, data_get($entry->metadata, 'refutation_strength.strength'));
        $this->assertGreaterThanOrEqual(1, data_get($entry->metadata, 'refutation_strength.denominator'));
        $this->assertIsArray(data_get($entry->metadata, 'refutation_strength.components'));

        $candidate->refresh();
        $this->assertSame('promoted', $candidate->status);
        $this->assertSame('promote', $candidate->decision);
        $this->assertTrue($candidate->promotion_allowed);
        $this->assertNotNull($candidate->decided_at);
        $this->assertSame((string) $entry->getKey(), data_get($candidate->payload, 'promoted_memory_entry_id'));

        // A memória promovida é recuperável pelo caminho canônico do registry
        // (mesma superfície que o recall híbrido consulta como fonte 'registry').
        $found = app(AtlasMemoryRegistryService::class)
            ->search(['types' => ['refutation_memory']]);
        $this->assertTrue($found->contains(fn (AtlasMemoryEntry $row): bool => $row->getKey() === $entry->getKey()));

        // Promover de novo o mesmo id falha: já saiu da quarentena.
        $this->artisan('atlas:compounding:review-lessons', ['--promote' => $shortId])
            ->assertFailed();
    }

    public function test_short_prefix_resolves_and_ambiguous_prefix_fails(): void
    {
        foreach (['aaaaaaaa-0000-4000-8000-000000000001', 'aaaabbbb-0000-4000-8000-000000000002'] as $i => $id) {
            AiLearningCandidate::query()->forceCreate([
                'id' => $id,
                'schema_version' => \App\Services\Ai\Compounding\AtlasObraLessonHarvester::SCHEMA_VERSION,
                'status' => 'held_for_evidence',
                'decision' => 'hold',
                'claim' => 'lição '.$i,
                'candidate_hash' => str_repeat((string) $i, 64),
                'receipt_hash' => str_repeat((string) $i, 64),
            ]);
        }

        // Prefixo compartilhado entre dois candidates → recusa explícita, sem erro SQL.
        $this->artisan('atlas:compounding:review-lessons', ['--reject' => 'aaaa', '--reason' => 'x'])
            ->expectsOutputToContain('ambíguo')
            ->assertFailed();

        // Prefixo curto não-uuid que desambigua → resolve (contrato documentado).
        $this->artisan('atlas:compounding:review-lessons', ['--reject' => 'aaaaaaaa', '--reason' => 'lição duplicada'])
            ->assertSuccessful();

        $this->assertSame('rejected', AiLearningCandidate::query()->findOrFail('aaaaaaaa-0000-4000-8000-000000000001')->status);
        $this->assertSame('hold', AiLearningCandidate::query()->findOrFail('aaaabbbb-0000-4000-8000-000000000002')->decision);
    }

    public function test_explicit_reject_requires_reason_and_marks_candidate(): void
    {
        $candidate = $this->harvestOneCandidate();
        $shortId = substr((string) $candidate->getKey(), 0, 8);

        $this->artisan('atlas:compounding:review-lessons', ['--reject' => $shortId])
            ->assertFailed();

        $this->artisan('atlas:compounding:review-lessons', ['--reject' => $shortId, '--reason' => 'lição duplicada'])
            ->assertSuccessful();

        $candidate->refresh();
        $this->assertSame('rejected', $candidate->status);
        $this->assertSame('reject', $candidate->decision);
        $this->assertFalse($candidate->promotion_allowed);
        $this->assertSame('lição duplicada', data_get($candidate->payload, 'rejection_reason'));
        $this->assertSame(0, AtlasMemoryEntry::query()->count());
    }
}
