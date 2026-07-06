<?php

namespace Tests\Unit\Ai\Compounding;

use App\Models\AiLearningCandidate;
use App\Services\Ai\Compounding\AtlasObraLessonHarvester;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Obra #13 item 5 — lições de obra (NÃO-FAZER + REFUTADA) viram learning
 * candidates governados em quarentena, com dedupe em re-run.
 */
class AtlasObraLessonHarvesterTest extends TestCase
{
    private string $fixture;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('ai_learning_candidates', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('schema_version')->nullable();
            $table->uuid('run_outcome_id')->nullable();
            $table->string('status')->nullable();
            $table->string('decision')->nullable();
            $table->string('memory_type')->nullable();
            $table->string('scope')->nullable();
            $table->text('claim')->nullable();
            $table->integer('confidence')->nullable();
            $table->boolean('promotion_allowed')->default(false);
            $table->json('evidence_refs')->nullable();
            $table->json('payload')->nullable();
            $table->string('candidate_hash')->nullable()->unique();
            $table->string('receipt_hash')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();
        });

        $this->fixture = tempnam(sys_get_temp_dir(), 'obra').'.md';
        file_put_contents($this->fixture, <<<'MD'
# Obra fixture

## Escopo
| Slice | Nota |
|---|---|
| S-01 | esta tabela NÃO deve ser colhida |

## NÃO-FAZER (refutados com prova)

| ID | Proposta refutada | Por quê |
|---|---|---|
| N-01 | Gerar família por catálogo | Medição refutou: economia desonesta. |
| N-02 | Comprimir docblocks | 320/429 não têm docblock; economia ≈ 0. |

## Governança de órfãos — catálogo REFUTADA na execução: 15 de 18 são WIRED, zero órfãos reais.
MD);
    }

    protected function tearDown(): void
    {
        @unlink($this->fixture);
        Schema::dropIfExists('ai_learning_candidates');
        parent::tearDown();
    }

    public function test_harvest_creates_quarantined_candidates_and_dedupes(): void
    {
        $harvester = app(AtlasObraLessonHarvester::class);

        $results = $harvester->harvest($this->fixture);

        $this->assertCount(3, $results); // 2 rows NÃO-FAZER + 1 REFUTADA inline
        $this->assertSame(3, AiLearningCandidate::query()->count());

        $candidate = AiLearningCandidate::query()->where('claim', 'like', '%Gerar família por catálogo%')->firstOrFail();
        $this->assertSame('hold', $candidate->decision);
        $this->assertSame('held_for_evidence', $candidate->status);
        $this->assertFalse($candidate->promotion_allowed);
        $this->assertSame('global', $candidate->scope);
        $this->assertStringStartsWith('NÃO re-propor:', $candidate->claim);
        $this->assertContains($this->fixture, $candidate->evidence_refs);

        $inline = AiLearningCandidate::query()->where('claim', 'like', '%REFUTADA na execução%')->first();
        $this->assertNotNull($inline);

        // dedupe: re-run não cria nada novo
        $rerun = $harvester->harvest($this->fixture);
        $this->assertSame(3, AiLearningCandidate::query()->count());
        $this->assertTrue(collect($rerun)->every(fn (array $l): bool => $l['already_exists']));
    }

    public function test_dry_run_does_not_persist(): void
    {
        $results = app(AtlasObraLessonHarvester::class)->harvest($this->fixture, dryRun: true);

        $this->assertCount(3, $results);
        $this->assertSame(0, AiLearningCandidate::query()->count());
    }
}
