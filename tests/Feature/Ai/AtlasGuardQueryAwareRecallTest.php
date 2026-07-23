<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Models\AtlasMemoryEntry;
use App\Services\Ai\Memory\AtlasHybridMemoryRetrievalService;
use Illuminate\Support\Str;
use ReflectionMethod;
use Tests\Concerns\CreatesAtlasMemoryEntryTable;
use Tests\TestCase;

/**
 * WO-17-T0.2 — a PERGUNTA precisa chegar ao candidate set PELO caminho do recall.
 *
 * O T0.1 tornou `relevantForContext()` query-aware, mas `AtlasHybridMemoryRetrievalService::recall()`
 * (o caminho que o guard de decisão usa) chamava `registryItems()` -> `relevantForContext($context)`
 * SEM repassar a pergunta: o candidate set do guard continuava CEGO (só o re-rank vetorial
 * reordenava um conjunto já errado). Este teste congela o forward de 1 linha: com a query, a
 * decisão relevante de baixa prioridade ENTRA no candidate set do recall; sem a query, o baseline
 * cego a deixa de fora. É o antes/depois do T0.2 no nível de unidade.
 */
final class AtlasGuardQueryAwareRecallTest extends TestCase
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

    private function seedDecision(string $title, string $body, int $priority): AtlasMemoryEntry
    {
        return AtlasMemoryEntry::query()->create([
            'id' => (string) Str::uuid7(),
            'memory_type' => 'decision',
            'scope_type' => 'global',
            'title' => $title,
            'summary' => $title,
            'body' => $body,
            'status' => 'active',
            'priority' => $priority,
            'importance' => 4,
            'confidence' => 0.5,
            'privacy_class' => 'normal',
            'external_ai_allowed' => true,
            'source_type' => 'test_fixture',
        ]);
    }

    /** @return array<int,array<string,mixed>> the private registry candidate set of recall(). */
    private function registryItems(string $query): array
    {
        $svc = app(AtlasHybridMemoryRetrievalService::class);
        $m = new ReflectionMethod($svc, 'registryItems');
        $m->setAccessible(true);

        return (array) $m->invoke($svc, $query, [], [], 12, true);
    }

    private function contains(array $items, string $id): bool
    {
        foreach ($items as $item) {
            if ((string) ($item['id'] ?? '') === $id) {
                return true;
            }
        }

        return false;
    }

    public function test_recall_forwards_the_question_into_the_registry_candidate_set(): void
    {
        // 12 decisões genéricas de prioridade ALTA enchem o LIMIT do caminho cego…
        foreach (range(1, 12) as $i) {
            $this->seedDecision('Regra operacional genérica '.$i, 'Conteúdo neutro sem relação '.$i, 90);
        }
        // …e UMA decisão de prioridade baixa que é exatamente sobre a query (a zona governada).
        $target = $this->seedDecision(
            'Retriever do guard deve ser query-aware',
            'O candidate set do decisionViolationCheck precisa usar a pergunta senão a decisão governante fica invisível.',
            10,
        );

        $withQuery = $this->registryItems('retriever guard query-aware candidate set decisão governante');
        $this->assertTrue(
            $this->contains($withQuery, $target->id),
            'DEPOIS: com a pergunta repassada, a decisão relevante de baixa prioridade precisa entrar no candidate set do recall.',
        );
    }

    public function test_blind_recall_drops_the_low_priority_relevant_decision(): void
    {
        foreach (range(1, 12) as $i) {
            $this->seedDecision('Regra operacional genérica '.$i, 'Conteúdo neutro sem relação '.$i, 90);
        }
        $target = $this->seedDecision(
            'Retriever do guard deve ser query-aware',
            'O candidate set do decisionViolationCheck precisa usar a pergunta senão a decisão governante fica invisível.',
            10,
        );

        // ANTES: sem query, o caminho cego (priority DESC + LIMIT 12) descarta a decisão relevante.
        $blind = $this->registryItems('');
        $this->assertFalse(
            $this->contains($blind, $target->id),
            'ANTES: o candidate set cego (priority+LIMIT) deixa a decisão governante de baixa prioridade de fora — o gargalo que o T0.1 conserta.',
        );
    }
}
