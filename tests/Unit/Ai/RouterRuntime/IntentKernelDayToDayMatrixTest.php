<?php

namespace Tests\Unit\Ai\RouterRuntime;

use App\Services\Ai\RouterRuntime\IntentKernelService;
use PHPUnit\Framework\TestCase;

/**
 * Matriz do dia a dia do operador (régua permanente, 03/07): 20 frases reais
 * cobrindo os 13 tipos de intenção. Probe de 03/07 mediu 11/20 caindo em
 * unknown (→ conversa sem tools); após fronteira-de-palavra p/ tokens curtos
 * ('fix' casava "renda FIXa") e vocabulário PT-BR real, 0/20. Este teste
 * congela o 0/20 — regressão aqui = trabalho caindo em conversa de novo.
 */
class IntentKernelDayToDayMatrixTest extends TestCase
{
    /** @return array<string,array{0:string,1:string}> */
    public static function matrix(): array
    {
        return [
            'analise rigorosa fluxo' => ['faça uma analise rigorosa e me fala todo o fluxo do atlas dev', 'review|explain|programming'],
            'verificacao rigorosa' => ['realiza uma verificacao rigorosa de todo o fluxo do atlas dev para programacao seria', 'review|programming'],
            'corrigir bug' => ['corrija o bug em src/Calculator.php', 'debug|programming'],
            'otimizar' => ['otimize o RipgrepRunner para nao varrer storage', 'programming'],
            'simplificar' => ['simplifique o TaskClassifier removendo duplicacao', 'programming'],
            'buscar melhorias' => ['busca melhorias e alavancagens extraordinarias no autonomos', 'programming|research|plan'],
            'meta-pergunta' => ['que modelo e vc ?', 'conversation'],
            'smalltalk' => ['bom dia tudo bem', 'conversation'],
            'carteira' => ['monta a carteira com alocacao de risco', 'finance'],
            'renda fixa' => ['quanto devo alocar em renda fixa este mes', 'finance'],
            'campanha ads' => ['cria uma campanha para o produto novo no google ads', 'marketing'],
            'copy vsl' => ['escreve a copy da VSL do funil', 'marketing'],
            'postura defensiva' => ['qual a postura defensiva ideal para o meu mac', 'cyber'],
            'pipeline automacao' => ['monta um pipeline de automacao para os relatorios semanais', 'automation'],
            'metas pessoais' => ['me ajuda a organizar minhas metas do trimestre', 'personal_development|strategy'],
            'estado da arte' => ['pesquisa o estado da arte de agentes de codigo', 'research'],
            'planejar migracao' => ['planeja a migracao do banco para a nova estrutura', 'plan'],
            'explicar loop' => ['explica como funciona o loop de reparo', 'explain'],
            'revisar diff' => ['revisa esse diff que acabei de gerar', 'review'],
            'plano prioridades' => ['faz um plano de prioridades do atlas para a semana', 'strategy|plan'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('matrix')]
    public function test_day_to_day_phrase_classifies_into_expected_intent(string $phrase, string $expected): void
    {
        $shape = (new IntentKernelService)->classifyShape($phrase);

        $this->assertContains(
            $shape['intent_type'],
            explode('|', $expected),
            sprintf("'%s' => %s (conf %.2f), esperado %s", $phrase, $shape['intent_type'], $shape['confidence'], $expected),
        );
    }
}
