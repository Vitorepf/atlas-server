<?php

declare(strict_types=1);

namespace Tests\Unit\AtlasCode;

use App\Services\AtlasCode\AtlasCodeQuestionRouter;
use PHPUnit\Framework\TestCase;

/**
 * A pílula tem de entender o operador escrevendo como ele escreve: com acento
 * ou sem, com "pq" ou "por quê", com pontuação ou no grito. E, quando não
 * entende, tem de dizer que não entendeu — nunca escolher uma intenção no
 * chute, porque responder a pergunta errada com confiança é pior que não
 * responder.
 */
final class AtlasCodeQuestionRouterTest extends TestCase
{
    private AtlasCodeQuestionRouter $router;

    protected function setUp(): void
    {
        $this->router = new AtlasCodeQuestionRouter();
    }

    public function test_asks_about_problems_in_the_many_ways_a_human_asks(): void
    {
        foreach ([
            'tem algum problema?',
            'tem alguma violação?',
            'tá tudo bem?',
            'o que está fora da main?',
            'tem algo errado aqui',
            'ESTÁ TUDO CERTO?',
        ] as $question) {
            self::assertSame(
                AtlasCodeQuestionRouter::INTENT_PROBLEMS,
                $this->router->route($question)['intent'],
                "deveria entender: {$question}"
            );
        }
    }

    public function test_asks_what_changed_and_reads_the_window(): void
    {
        self::assertSame(
            ['intent' => 'changes', 'window' => 'today', 'term' => null, 'hashes' => []],
            $this->router->route('o que mudou hoje?')
        );
        self::assertSame('yesterday', $this->router->route('o que mudou ontem?')['window']);
        self::assertSame('week', $this->router->route('o que aconteceu essa semana?')['window']);
        self::assertSame('month', $this->router->route('quais as mudanças do mês?')['window']);

        // Sem janela dita, hoje é a pergunta implícita de quem abre o app.
        self::assertSame('today', $this->router->route('o que mudou?')['window']);
    }

    public function test_why_a_branch_exists_is_not_a_search_for_branches(): void
    {
        self::assertSame(AtlasCodeQuestionRouter::INTENT_WHY_BRANCH, $this->router->route('por que essa branch existe?')['intent']);
        self::assertSame(AtlasCodeQuestionRouter::INTENT_WHY_BRANCH, $this->router->route('pq essa obra existe')['intent']);
        self::assertSame(AtlasCodeQuestionRouter::INTENT_WHY_BRANCH, $this->router->route('por quê esse worktree existe?')['intent']);
    }

    public function test_who_touched_extracts_the_target_not_the_filler(): void
    {
        self::assertSame(
            ['intent' => 'who_touched', 'window' => null, 'term' => 'worker'],
            $this->router->route('quem mexeu no worker?')
        );
        self::assertSame('billing', $this->router->route('quem tocou em billing?')['term']);

        // O alvo sai em caixa baixa de propósito: ninguém digita
        // 'AtlasCodeView.swift' com a caixa exata num iPhone. Quem procura é
        // que tem de ser insensível a caixa — a exigência não é do operador.
        self::assertSame('atlascodeview.swift', $this->router->route('quem alterou o arquivo AtlasCodeView.swift')['term']);
    }

    public function test_find_extracts_the_search_term(): void
    {
        self::assertSame(
            ['intent' => 'find', 'window' => null, 'term' => 'rename'],
            $this->router->route('cadê o commit do rename?')
        );
        self::assertSame('stripe', $this->router->route('procura stripe')['term']);
        self::assertSame('folha do commit', $this->router->route('me mostra a folha do commit')['term']);
    }

    public function test_a_question_it_cannot_filter_is_admitted_not_guessed(): void
    {
        // Estas exigem o cérebro: cruzam canon, decisão e julgamento. O
        // roteador não finge que sabe.
        foreach ([
            'esse commit foi uma boa ideia?',
            'como eu deveria refatorar isso?',
            'qual o risco dessa arquitetura?',
            '',
            '???',
        ] as $question) {
            self::assertSame(
                AtlasCodeQuestionRouter::INTENT_UNKNOWN,
                $this->router->route($question)['intent'],
                "deveria admitir que não é filtro: {$question}"
            );
        }
    }

    public function test_a_target_that_is_only_filler_is_absent_not_empty_string(): void
    {
        // "quem mexeu nisso?" sem alvo: quem responde precisa pedir o alvo, e
        // não sair procurando pela palavra "isso" no repositório.
        self::assertNull($this->router->route('quem mexeu nisso?')['term']);
        self::assertSame(AtlasCodeQuestionRouter::INTENT_WHO_TOUCHED, $this->router->route('quem mexeu nisso?')['intent']);
    }

    public function test_every_pill_suggestion_routes_to_a_real_intent(): void
    {
        // Espelha AtlasCodeAskSuggestions.all (atlas-native/Sources/AtlasCore/
        // AtlasCodeAsk.swift). Uma sugestão que cai em `unknown` é a pílula
        // prometendo o que não cumpre — que foi o defeito da primeira versão
        // dela. Se alguém adicionar sugestão lá e não aqui, este teste é o
        // canário: a lista some do par e a promessa quebra em silêncio.
        $suggestions = [
            'o que mudou hoje?',
            'tem algum problema?',
            'revise os commits de hoje',
            'por que essa branch existe?',
        ];

        foreach ($suggestions as $suggestion) {
            self::assertNotSame(
                AtlasCodeQuestionRouter::INTENT_UNKNOWN,
                $this->router->route($suggestion)['intent'],
                "a pílula sugere mas não sabe responder: {$suggestion}"
            );
        }
    }

    public function test_ordering_a_review_is_not_asking_what_changed(): void
    {
        // "revise o que mudou hoje" contém "que mudou" — mas é ORDEM, não
        // pergunta. Quem manda revisar quer agentes trabalhando, não uma lista.
        foreach ([
            'revise os commits de hoje',
            'revisa o que mudou hoje',
            'revise tudo que mudou hoje',
            'faz uma revisão dos commits',
            'analisa os commits de hoje',
        ] as $order) {
            self::assertSame(
                AtlasCodeQuestionRouter::INTENT_REVIEW_BATCH,
                $this->router->route($order)['intent'],
                "deveria mandar revisar: {$order}"
            );
        }

        // A janela é a mesma gramática de tempo.
        self::assertSame('week', $this->router->route('revise os commits da semana')['window']);
        self::assertSame('today', $this->router->route('revise os commits')['window']);
    }

    public function test_asking_about_a_review_is_not_ordering_one(): void
    {
        // "o que a revisão achou?" é pergunta sobre resultado — mandar 6
        // agentes trabalharem por causa dela seria obedecer o que ninguém pediu.
        self::assertNotSame(
            AtlasCodeQuestionRouter::INTENT_REVIEW_BATCH,
            $this->router->route('o que a revisão achou?')['intent']
        );
    }

    public function test_normalization_makes_accent_and_case_irrelevant(): void
    {
        self::assertSame('por que essa branch existe', $this->router->normalize('Por quê essa BRANCH existe?'));
        self::assertSame('o que mudou hoje', $this->router->normalize('  O que mudou hoje??  '));
    }
}
