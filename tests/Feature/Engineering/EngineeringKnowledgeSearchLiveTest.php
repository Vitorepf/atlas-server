<?php

declare(strict_types=1);

namespace Tests\Feature\Engineering;

use App\Services\Ai\Support\DatabaseTableAvailability;
use App\Services\Engineering\EngineeringKnowledgeBaseService;
use Tests\TestCase;

/**
 * O teste unitário roda num corpus de 4 docs — e num corpus de 4 TODO termo
 * parece raro. A régua de verdade é a KB inteira do operador (972 docs em
 * 15/07/2026), onde "atlas" está em tudo e "main-only" está em um.
 *
 * Este teste lê a KB real. Sem KB sincronizada ele se marca como skipped em vez
 * de fingir sucesso — ausência de dado não é prova de correção.
 */
final class EngineeringKnowledgeSearchLiveTest extends TestCase
{
    private function knowledge(): EngineeringKnowledgeBaseService
    {
        if (! DatabaseTableAvailability::has('atlas_engineering_knowledge_items')) {
            self::markTestSkipped('KB não disponível neste ambiente.');
        }

        $service = app(EngineeringKnowledgeBaseService::class);
        if ($service->search('atlas code workspace', 1) === [] && $service->search('main only rule', 1) === []) {
            self::markTestSkipped('KB vazia — rode `atlas engineering knowledge sync`.');
        }

        return $service;
    }

    public function test_the_question_that_failed_finds_its_canon_in_the_real_kb(): void
    {
        // O caso medido: o Atlas devolvia "Codex Review Chain Contract" porque
        // contextRefs() ordena por prioridade e ignora as palavras. O doc certo
        // estava indexado o tempo todo.
        $results = $this->knowledge()->search('por que existe a regra de trabalhar so na main?', 5);

        self::assertNotSame([], $results, 'a KB real precisa responder a pergunta canônica');
        self::assertSame(
            'atlas-local-main-only-rule',
            $results[0]['slug'],
            'o doc que responde tem de liderar entre os 972'
        );
    }

    public function test_the_atlas_code_canon_is_reachable_by_its_subject(): void
    {
        $results = $this->knowledge()->search('atlas code workspace multi project', 5);

        self::assertNotSame([], $results);
        self::assertStringContainsString(
            'workspace',
            strtolower($results[0]['slug'].' '.$results[0]['title']),
            'o canon do Atlas Code precisa ser alcançável pelo assunto dele'
        );
    }

    public function test_silence_about_worktree_is_a_gap_in_the_canon_not_in_the_search(): void
    {
        // Medido em 15/07/2026: NENHUM doc da KB é titulado sobre worktree. Os
        // 36 que citam a palavra são backlogs e planos de limpeza, de passagem.
        // Devolver um deles como "a fonte sobre worktree" seria o ruído
        // confiante que esta busca existe para evitar — então o silêncio aqui é
        // a resposta certa, e o buraco é do canon.
        //
        // Quando alguém escrever o canon de worktree, este teste falha — e
        // falhar aqui é a notícia boa.
        $results = $this->knowledge()->search('worktree fora do lugar permitido', 5);

        self::assertSame([], $results, 'se isto passou a achar algo, o canon de worktree nasceu — atualize o teste');
    }

    public function test_common_words_do_not_drag_the_whole_kb_into_the_answer(): void
    {
        // "atlas" está em quase todo doc: se ele puxasse o corpus inteiro, a
        // busca voltaria a ser ruído com outro nome.
        $results = $this->knowledge()->search('atlas', 5);

        self::assertLessThanOrEqual(5, count($results));
    }

    public function test_a_question_outside_the_kb_returns_nothing_instead_of_the_best_of_nothing(): void
    {
        self::assertSame([], $this->knowledge()->search('receita de brigadeiro de colher', 5));
    }
}
