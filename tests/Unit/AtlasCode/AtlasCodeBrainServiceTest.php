<?php

declare(strict_types=1);

namespace Tests\Unit\AtlasCode;

use App\Services\AtlasCode\AtlasCodeBrainService;
use PHPUnit\Framework\TestCase;

/**
 * O piso de relevância é o que separa o cérebro do ruído.
 *
 * Medido em 15/07/2026: perguntando "por que existe a regra de trabalhar só na
 * main?", o Open Brain devolveu no topo "Atlas AI Skill System v1" (0.317) —
 * enquanto `atlas-local-main-only-rule.md`, que responde a pergunta, existe no
 * repo e não está no índice semântico. Citar aquelas fontes como resposta seria
 * ruído confiante: o pior tipo.
 */
final class AtlasCodeBrainServiceTest extends TestCase
{
    private AtlasCodeBrainService $brain;

    protected function setUp(): void
    {
        $this->brain = new AtlasCodeBrainService();
    }

    public function test_weak_sources_never_become_an_answer(): void
    {
        // Estes são os scores REAIS que o cérebro devolveu naquele dia.
        $refs = [
            ['type' => 'semantic_note', 'title' => 'Atlas AI Skill System v1', 'score' => 0.317],
            ['type' => 'semantic_note', 'title' => 'Memória Semântica Ativa Compartilhada', 'score' => 0.196],
            ['type' => 'semantic_note', 'title' => 'Atlas AI Harness v1.1', 'score' => 0.184],
        ];

        self::assertSame([], $this->brain->aboveFloor($refs));
    }

    public function test_strong_sources_come_back_ranked_and_bounded(): void
    {
        $refs = [
            ['title' => 'terceira', 'score' => 0.51],
            ['title' => 'primeira', 'score' => 0.92],
            ['title' => 'quarta', 'score' => 0.46],
            ['title' => 'segunda', 'score' => 0.77],
            ['title' => 'fraca', 'score' => 0.2],
        ];

        $relevant = $this->brain->aboveFloor($refs);

        // As melhores primeiro, e no máximo três: uma lista longa de fontes é
        // ruído com outro nome.
        self::assertSame(['primeira', 'segunda', 'terceira'], array_column($relevant, 'title'));
    }

    public function test_a_source_without_score_is_not_promoted_by_default(): void
    {
        // Ref sem score não é ref boa: é ref não medida. Tratar ausência de
        // medida como relevância seria inventar autoridade.
        $refs = [
            ['type' => 'atlas_memory_entry', 'title' => 'sem score'],
            ['type' => 'atlas_verbatim_memory', 'title' => 'score nulo', 'score' => null],
        ];

        self::assertSame([], $this->brain->aboveFloor($refs));
    }

    public function test_the_label_falls_back_through_real_fields_never_to_a_guess(): void
    {
        self::assertSame('Título', $this->brain->label(['title' => 'Título', 'path' => 'x.md']));
        self::assertSame('slug-da-fonte', $this->brain->label(['slug' => 'slug-da-fonte']));
        self::assertSame('docs/a.md', $this->brain->label(['path' => 'docs/a.md']));
        self::assertSame('fonte sem título', $this->brain->label(['type' => 'semantic_note']));
    }

    public function test_a_silent_brain_is_reported_never_invented(): void
    {
        // Cérebro que estoura → ausência dita. O caminho passa pelo catch do
        // service, e o operador recebe uma frase honesta, não um 500 nem uma
        // resposta plausível.
        $consulted = $this->brain->consult('essa arquitetura tem risco?', '/caminho/que/nao/existe');

        self::assertFalse($consulted['answered']);
        self::assertSame([], $consulted['evidence']);
        self::assertStringNotContainsString('risco', $consulted['answer']);
    }
}
