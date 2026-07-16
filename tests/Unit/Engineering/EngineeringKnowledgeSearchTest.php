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

    public function test_the_pack_keeps_the_leader_and_drops_the_passing_mentions(): void
    {
        // Medido contra a KB real: "por que existe a regra da main?" devolvia
        // o doc certo a 0.711 e três a 0.427 — que só CITAM "regra" e "main" no
        // corpo. Na busca isso é aceitável: o operador vê e julga. Num Context
        // Pack não há ninguém julgando, e empilhar os quatro como se fossem
        // igualmente lei ensina o agente a ignorar a seção inteira.
        $ranked = [
            ['slug' => 'atlas-local-main-only-rule', 'score' => 0.711],
            ['slug' => 'recovery-stashes-readme', 'score' => 0.427],
            ['slug' => 'aaeos-l7-convergence-roadmap', 'score' => 0.427],
        ];

        $dominant = $this->search->dominant($ranked);

        self::assertCount(1, $dominant);
        self::assertSame('atlas-local-main-only-rule', $dominant[0]['slug']);
    }

    public function test_a_real_tie_survives_because_the_question_has_two_laws(): void
    {
        // O corte é contra distração, não contra pluralidade. Quando duas leis
        // cobrem a pergunta de verdade, cortar uma seria esconder metade da
        // resposta — que é o mesmo defeito, do outro lado.
        $ranked = [
            ['slug' => 'lei-a', 'score' => 0.70],
            ['slug' => 'lei-b', 'score' => 0.68],
            ['slug' => 'passagem', 'score' => 0.40],
        ];

        $dominant = $this->search->dominant($ranked);

        self::assertSame(['lei-a', 'lei-b'], array_column($dominant, 'slug'));
    }

    public function test_nothing_ranked_yields_nothing_dominant(): void
    {
        // Ausência não vira líder de lista vazia: sem canon, o agente responde
        // sem canon — que é honesto — em vez de receber uma seção vazia com
        // cara de autoridade.
        self::assertSame([], $this->search->dominant([]));
    }

    public function test_the_acronym_is_the_name_so_the_mother_doc_is_findable(): void
    {
        // O caso real, medido contra a KB: "o que é o ACOS?" devolvia cinco
        // satélites (que trazem a sigla no slug) e deixava o doc-MÃE em 0.2506
        // — abaixo do piso, fora da resposta. O cérebro do Atlas não achava a
        // autoridade do próprio Atlas, porque ela soletra o nome.
        $mae = [
            'slug' => 'atlas-cognition-operating-system',
            'title' => 'Atlas Cognition Operating System',
            'canonical_path' => 'docs/engineering-knowledge-base/atlas-cognition-operating-system.md',
            'summary' => 'A autoridade-mae da camada cognitiva.',
        ];

        self::assertTrue($this->search->isInitialismOf($mae, 'acos'));
        self::assertSame('acos', $this->search->initials('Atlas Cognition Operating System'));
        self::assertSame('acos', $this->search->initials('atlas-cognition-operating-system'));

        // A regra é geral, e por isso cobre a casa de siglas inteira de graca.
        self::assertSame('awis', $this->search->initials('Atlas Workspace Intelligence System'));
        self::assertSame('aobg', $this->search->initials('Atlas Open Brain Gateway'));
    }

    public function test_a_short_name_never_forms_an_initialism(): void
    {
        // "Atlas AI" viraria "aa" e casaria com meio corpus. Duas palavras nao
        // sao sigla; sao so um nome curto.
        self::assertSame('', $this->search->initials('Atlas AI'));
        self::assertSame('', $this->search->initials('Codex'));
    }

    public function test_being_the_thing_beats_talking_about_it_when_scores_tie(): void
    {
        // Os dois cobrem a MESMA informacao da pergunta e empatam. Sem criterio,
        // quem lidera e a ordem que o banco devolveu — acaso com cara de
        // decisao. A autoridade e quem tem o nome da coisa por inteiro.
        $mae = [
            'slug' => 'atlas-cognition-operating-system',
            'title' => 'Atlas Cognition Operating System',
            'canonical_path' => 'docs/engineering-knowledge-base/atlas-cognition-operating-system.md',
            'summary' => 'ACOS: a autoridade-mae da camada cognitiva.',
        ];
        $satelite = [
            'slug' => 'atlas-acos-areas-map',
            'title' => 'Atlas ACOS Areas Map',
            'canonical_path' => 'docs/engineering-knowledge-base/atlas-acos-areas-map.md',
            'summary' => 'Mapa de areas do ACOS.',
        ];

        self::assertTrue($this->search->isTheThing($mae, ['acos']));
        self::assertFalse($this->search->isTheThing($satelite, ['acos']));

        $ranked = $this->search->rank([$satelite, $mae], 'o que e o ACOS?', 5, ['acos' => 50], 827);

        self::assertSame('atlas-cognition-operating-system', $ranked[0]['slug'], 'a mae lidera o empate');
        self::assertSame('atlas-acos-areas-map', $ranked[1]['slug']);
    }

    public function test_when_the_authority_is_present_its_satellites_are_noise(): void
    {
        // "O que é o ACOS?" tem UMA resposta: o doc-mae. Os satelites empatam em
        // 0.418 so porque a sigla esta no slug deles — cinco docs onde um
        // responde nao e generosidade, e diluir a autoridade em ruido.
        $ranked = [
            ['slug' => 'atlas-cognition-operating-system', 'score' => 0.418, 'is_the_thing' => true],
            ['slug' => 'atlas-acos-areas-map', 'score' => 0.418, 'is_the_thing' => false],
            ['slug' => 'atlas-acos-delta-series', 'score' => 0.418, 'is_the_thing' => false],
        ];

        $dominant = $this->search->dominant($ranked);

        self::assertCount(1, $dominant);
        self::assertSame('atlas-cognition-operating-system', $dominant[0]['slug']);
    }

    public function test_without_an_authority_the_tie_stays_whole(): void
    {
        // Sem doc que SEJA a coisa, nao ha autoridade para eclipsar ninguem:
        // cortar aqui seria escolher no acaso e chamar de decisao.
        $ranked = [
            ['slug' => 'doc-a', 'score' => 0.60, 'is_the_thing' => false],
            ['slug' => 'doc-b', 'score' => 0.58, 'is_the_thing' => false],
        ];

        self::assertCount(2, $this->search->dominant($ranked));
    }
}
