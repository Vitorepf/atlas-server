<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Memory;

use App\Models\AtlasMemoryEntry;
use App\Services\Ai\Memory\AtlasMemoryRegistryService;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesAtlasMemoryEntryTable;
use Tests\TestCase;

/**
 * WO-17-T0.1 — a PERGUNTA entra na seleção de candidatos do recall.
 * Baseline provado em 06/07: relevantForContext() selecionava só por
 * escopo+priority+LIMIT; uma memória de baixa prioridade relevante à query
 * era invisível. Este teste congela o comportamento novo E a retrocompat.
 */
class MemoryQueryAwareRetrievalTest extends TestCase
{
    use CreatesAtlasMemoryEntryTable;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createAtlasMemoryEntryTable();
    }

    protected function tearDown(): void
    {
        $this->dropAtlasMemoryEntryTable();
        parent::tearDown();
    }

    private function seedEntry(string $title, string $body, int $priority): AtlasMemoryEntry
    {
        return AtlasMemoryEntry::query()->create([
            'id' => (string) Str::uuid7(),
            'memory_type' => 'technical_context',
            'scope_type' => 'global',
            'title' => $title,
            'summary' => $title,
            'body' => $body,
            'status' => 'active',
            'priority' => $priority,
            'importance' => 4,
            'confidence' => 0.5,
            'source_type' => 'test_fixture',
        ]);
    }

    public function test_query_pulls_relevant_low_priority_entry_into_the_candidate_set(): void
    {
        // 12 entradas genéricas de prioridade ALTA (enchem o LIMIT default do caminho cego)…
        foreach (range(1, 12) as $i) {
            $this->seedEntry('Regra operacional genérica '.$i, 'Conteúdo genérico sem relação '.$i, 90);
        }
        // …e UMA entrada de prioridade baixa que é exatamente sobre a query.
        $target = $this->seedEntry(
            'Migração do scheduler para launchd',
            'O scheduler roda via launchd com heartbeat em storage; quando parar, checar print-disabled primeiro.',
            10,
        );

        $hits = app(AtlasMemoryRegistryService::class)->relevantForContext(
            ['query' => 'por que o scheduler launchd parou'],
            [],
            12,
        );

        $this->assertTrue(
            $hits->contains(fn ($e) => $e->id === $target->id),
            'A memória relevante à query (prioridade baixa) precisa entrar no candidate set — o baseline cego a deixava de fora.',
        );
    }

    public function test_without_query_key_behavior_is_backward_compatible(): void
    {
        foreach (range(1, 5) as $i) {
            $this->seedEntry('Entrada '.$i, 'Corpo '.$i, 50 + $i);
        }

        $legacy = app(AtlasMemoryRegistryService::class)->relevantForContext([], [], 3);

        // Contrato antigo intacto: 3 itens, ordenados por priority desc.
        $this->assertCount(3, $legacy);
        $priorities = $legacy->pluck('priority')->all();
        $sorted = $priorities;
        rsort($sorted);
        $this->assertSame($sorted, $priorities, 'Sem query, a ordenação por prioridade não pode mudar.');
    }

    public function test_query_with_no_lexical_match_degrades_to_legacy_set_never_throws(): void
    {
        $this->seedEntry('Única entrada', 'Conteúdo qualquer', 60);

        $hits = app(AtlasMemoryRegistryService::class)->relevantForContext(
            ['query' => 'zzz-termo-inexistente-xyzzy'],
            [],
            5,
        );

        $this->assertGreaterThanOrEqual(1, $hits->count(), 'Query sem match não pode zerar o recall — degrade para o conjunto legado.');
    }
}
