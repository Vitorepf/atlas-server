<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Models\AtlasMemoryEntryRelation;
use App\Services\Ai\Context\AtlasDialecticTensionService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * T4-S5 (Obra #17) — the dialectic contradiction engine marks OPEN tensions: two recalled
 * memories that the brain recorded as an open `conflict` relation must not be delivered as
 * settled truth — they get a visible "tensão aberta" mark. Consumes the D3 conflict edges;
 * fail-open when the relations table is absent.
 */
final class AtlasDialecticTensionServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::dropIfExists('atlas_memory_entry_relations');
        Schema::create('atlas_memory_entry_relations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('source_memory_entry_id')->index();
            $table->uuid('target_memory_entry_id')->index();
            $table->string('relation_type', 40);
            $table->string('status', 24)->default('open');
            $table->float('confidence')->nullable();
            $table->text('reason')->nullable();
            $table->json('metadata')->default('{}');
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('atlas_memory_entry_relations');
        parent::tearDown();
    }

    public function test_marks_an_open_conflict_between_two_recalled_memories(): void
    {
        $a = (string) Str::uuid7();
        $b = (string) Str::uuid7();
        $this->relate($a, $b, 'conflict', 'open', 'decisão X contradiz a decisão Y');

        $marks = (new AtlasDialecticTensionService)->tensionMarks([
            ['id' => $a, 'title' => 'Usar Redis para cache'],
            ['id' => $b, 'title' => 'Cache só em memória de processo'],
        ]);

        self::assertCount(1, $marks, 'an open conflict between two recalled memories is marked');
        self::assertStringContainsString('tensão aberta', $marks[0]);
        self::assertStringContainsString('Usar Redis para cache', $marks[0]);
        self::assertStringContainsString('Cache só em memória de processo', $marks[0]);
        self::assertStringContainsString('decisão X contradiz', $marks[0]);
    }

    public function test_does_not_mark_a_resolved_conflict_or_a_duplicate_relation(): void
    {
        $a = (string) Str::uuid7();
        $b = (string) Str::uuid7();
        $c = (string) Str::uuid7();
        $this->relate($a, $b, 'conflict', 'resolved', 'já reconciliado');
        $this->relate($a, $c, 'duplicate', 'open', 'mesma coisa');

        $marks = (new AtlasDialecticTensionService)->tensionMarks([
            ['id' => $a, 'title' => 'A'],
            ['id' => $b, 'title' => 'B'],
            ['id' => $c, 'title' => 'C'],
        ]);

        self::assertSame([], $marks, 'only OPEN conflict relations are tensions');
    }

    public function test_no_tension_when_only_one_side_is_recalled(): void
    {
        $a = (string) Str::uuid7();
        $b = (string) Str::uuid7();
        $this->relate($a, $b, 'conflict', 'open', 'x');

        $marks = (new AtlasDialecticTensionService)->tensionMarks([
            ['id' => $a, 'title' => 'A'],
        ]);

        self::assertSame([], $marks);
    }

    private function relate(string $source, string $target, string $type, string $status, string $reason): void
    {
        AtlasMemoryEntryRelation::query()->create([
            'id' => (string) Str::uuid7(),
            'source_memory_entry_id' => $source,
            'target_memory_entry_id' => $target,
            'relation_type' => $type,
            'status' => $status,
            'reason' => $reason,
            'metadata' => [],
        ]);
    }
}
