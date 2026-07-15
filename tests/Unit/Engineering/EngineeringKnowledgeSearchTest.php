<?php

declare(strict_types=1);

namespace Tests\Unit\Engineering;

use App\Services\Engineering\EngineeringKnowledgeSearch;
use PHPUnit\Framework\TestCase;

/**
 * O caso real que motivou esta busca (medido em 15/07/2026):
 *
 * Perguntando "por que existe a regra de trabalhar so na main?", o Atlas
 * devolvia "Codex Review Chain Contract" — porque `contextRefs()` não comparava
 * texto NENHUM: ordenava por prioridade. O doc que responde,
 * `atlas-local-main-only-rule`, estava indexado e nunca aparecia.
 */
final class EngineeringKnowledgeSearchTest extends TestCase
{
    private EngineeringKnowledgeSearch $search;

    /** @var array<int, array<string,mixed>> */
    private array $corpus;

    protected function setUp(): void
    {
        $this->search = new EngineeringKnowledgeSearch();

        // Recorte fiel da KB real: o doc certo + os que vinham por prioridade.
        $this->corpus = [
            [
                'slug' => 'atlas-local-main-only-rule',
                'title' => 'Atlas Local Main Only Rule',
                'canonical_path' => 'docs/engineering-knowledge-base/atlas-local-main-only-rule.md',
                'summary' => 'Toda IA trabalha sempre na branch local main. Zero branch de obra, zero merge.',
                'body_excerpt' => 'No repo atlas-server, toda IA trabalha SEMPRE na branch local main.',
            ],
            [
                'slug' => 'atlas-ai-self-construction-codex-review-chain-contract',
                'title' => 'Atlas Self-Construction Codex Review Chain Contract',
                'canonical_path' => 'docs/engineering-knowledge-base/self-construction/codex-review-chain-contract.md',
                'summary' => 'Contract for the non-executing review, signature and merge-action chain.',
                'body_excerpt' => 'Review chain after Codex or provider packet flows complete.',
            ],
            [
                'slug' => 'atlas-code-multi-project-workspace-os',
                'title' => 'Atlas Code Multi Project Workspace OS',
                'canonical_path' => 'docs/engineering-knowledge-base/atlas-code-multi-project-workspace-os.md',
                'summary' => 'Cada produto que o Atlas opera é um Project/Workspace; obras vivem dentro.',
                'body_excerpt' => 'Atlas Code não é Atlas-only. Worktree allowlist por workspace.',
            ],
            [
                'slug' => 'atlas-ai-skill-system-v1',
                'title' => 'Atlas AI Skill System v1',
                'canonical_path' => '00-constituicao/atlas-ai-skill-system-v1.md',
                'summary' => 'Skills são contratos operacionais versionados, não personas nem prompts.',
                'body_excerpt' => 'O Atlas AI Skill System cria, ativa, avalia e promove skills.',
            ],
            // A ARMADILHA REAL: 'main' vive dentro de 'do-main'. Este doc não
            // estava no corpus do teste, o teste passou verde, e contra a KB de
            // verdade ele VENCEU a pergunta sobre a regra da main. Fica aqui
            // para sempre.
            [
                'slug' => 'atlas-ai-core-vs-domain',
                'title' => 'Atlas AI Core vs Domain',
                'canonical_path' => 'docs/engineering-knowledge-base/atlas-ai-core-vs-domain.md',
                'summary' => 'Fronteira entre o core do Atlas AI e cada domain que o consome.',
                'body_excerpt' => 'O core não conhece o domain; o domain consome o core.',
            ],
        ];
    }

    public function test_a_term_never_matches_inside_another_word(): void
    {
        // 'main' ⊂ 'domain'. Sem fronteira de palavra, a pergunta sobre a regra
        // da main devolvia 'atlas-ai-core-vs-domain' — medido contra a KB real
        // de 972 docs, com o unit test de 4 docs verde. A régua de verdade é o
        // corpus de verdade.
        self::assertFalse($this->search->mentions('atlas-ai-core-vs-domain', 'main'));
        self::assertFalse($this->search->mentions('o core não conhece o domain', 'main'));

        // Mas em slug o hífen É fronteira: aqui 'main' é palavra.
        self::assertTrue($this->search->mentions('atlas-local-main-only-rule', 'main'));
        self::assertTrue($this->search->mentions('trabalha sempre na branch local main.', 'main'));
    }

    public function test_the_domain_doc_never_wins_the_question_about_the_main_rule(): void
    {
        $ranked = $this->search->rank($this->corpus, 'por que existe a regra de trabalhar so na main?');

        self::assertSame('atlas-local-main-only-rule', $ranked[0]['slug']);
        self::assertNotContains('atlas-ai-core-vs-domain', array_column($ranked, 'slug'));
    }

    public function test_the_question_that_failed_now_finds_the_doc_that_answers_it(): void
    {
        $ranked = $this->search->rank($this->corpus, 'por que existe a regra de trabalhar so na main?');

        self::assertNotSame([], $ranked, 'a busca não pode voltar vazia para a pergunta canônica');
        self::assertSame('atlas-local-main-only-rule', $ranked[0]['slug']);
        // E o item que vinha por prioridade não lidera mais.
        self::assertNotSame('atlas-ai-self-construction-codex-review-chain-contract', $ranked[0]['slug']);
    }

    public function test_the_winning_doc_clears_this_scales_own_floor(): void
    {
        // Score léxico e similaridade vetorial não vivem na mesma escala. O doc
        // certo pontua 0.44 — cobertura parcial, porque a pergunta usa palavras
        // que o doc não usa ("trabalhar"). Ele É a resposta; então o piso desta
        // régua é 0.35, em vez de o número ser empurrado para caber num piso
        // alheio (0.45, que é do índice semântico).
        $ranked = $this->search->rank($this->corpus, 'por que existe a regra de trabalhar so na main?');

        self::assertGreaterThanOrEqual(EngineeringKnowledgeSearch::RELEVANCE_FLOOR, $ranked[0]['score']);
        self::assertSame('atlas-local-main-only-rule', $ranked[0]['slug']);
    }

    public function test_a_term_that_is_in_everything_finds_nothing(): void
    {
        // "atlas" está em todos os docs: não distingue nada, logo não responde
        // nada. Devolver os 972 itens porque todos contêm a palavra seria o
        // mesmo defeito de antes com outra roupa.
        self::assertSame([], $this->search->rank($this->corpus, 'atlas'));
    }

    public function test_a_question_about_worktree_finds_the_workspace_canon(): void
    {
        // A frequência vem do CORPUS REAL (972 docs), não deste recorte de 5:
        // aqui dentro "regra" parece raríssima, e no canon inteiro ela está em
        // toda parte. Um corpus de brinquedo mente sobre raridade — foi assim
        // que o unit test passou verde enquanto a busca errava na KB de verdade.
        $ranked = $this->search->rank(
            $this->corpus,
            'qual a regra de worktree?',
            5,
            ['regra' => 300, 'worktree' => 12],
            972,
        );

        self::assertSame('atlas-code-multi-project-workspace-os', $ranked[0]['slug']);
    }

    public function test_a_word_absent_from_the_corpus_kills_the_answer_instead_of_being_ignored(): void
    {
        // Caso REAL contra os 972 docs: "receita" no Atlas casa com receita
        // FINANCEIRA. Ignorando "brigadeiro" e "colher" (ausentes do corpus),
        // sobrava só "receita" decidindo — e a busca respondia brigadeiro com
        // doc de faturamento, nota 0.600. A palavra ausente É a informação: ela
        // entra no denominador com peso máximo e ninguém a cobre.
        $corpus = [[
            'slug' => 'atlas-receita-recorrente',
            'title' => 'Atlas Receita Recorrente',
            'canonical_path' => 'docs/engineering-knowledge-base/atlas-receita-recorrente.md',
            'summary' => 'Como a receita recorrente é medida e projetada.',
        ]];

        $ranked = $this->search->rank(
            $corpus,
            'receita de brigadeiro de colher',
            5,
            ['receita' => 40, 'brigadeiro' => 0, 'colher' => 0],
            972,
        );

        self::assertSame([], $ranked, 'palavra ausente do corpus não pode ser ignorada em silêncio');
    }

    public function test_a_question_the_kb_cannot_answer_returns_nothing(): void
    {
        // Devolver "o melhor de nada" é o defeito que estamos corrigindo.
        self::assertSame([], $this->search->rank($this->corpus, 'qual a cotação do dólar hoje?'));
    }

    public function test_stopwords_never_decide_a_match(): void
    {
        self::assertSame([], $this->search->terms('por que o que e de para'));
        self::assertSame([], $this->search->rank($this->corpus, 'por que o que'));
    }

    public function test_terms_ignore_accent_and_case(): void
    {
        self::assertSame(['regra', 'worktree'], $this->search->terms('Qual a REGRA de Worktree?'));
    }

    public function test_a_rare_term_outweighs_a_term_that_is_in_everything(): void
    {
        // 'atlas' está em todos os docs e não distingue nada; 'main-only' sim.
        $terms = $this->search->terms('atlas main-only');
        $frequency = $this->search->documentFrequency($this->corpus, $terms);

        self::assertSame(count($this->corpus), $frequency['atlas'], 'atlas deveria aparecer em todos');
        self::assertSame(1, $frequency['main-only'], 'main-only deveria ser raro');

        $ranked = $this->search->rank($this->corpus, 'atlas main-only');
        self::assertSame('atlas-local-main-only-rule', $ranked[0]['slug']);
    }

    public function test_the_name_of_the_thing_outweighs_a_mention_in_the_body(): void
    {
        $terms = ['codex'];
        $frequency = ['codex' => 2];

        $inTheName = $this->search->score($this->corpus[1], $terms, $frequency, 4);
        $inTheBody = $this->search->score([
            'slug' => 'outro-doc',
            'title' => 'Outro Doc',
            'canonical_path' => 'docs/outro.md',
            'summary' => 'menciona codex de passagem',
        ], $terms, $frequency, 4);

        self::assertGreaterThan($inTheBody, $inTheName);
    }
}
