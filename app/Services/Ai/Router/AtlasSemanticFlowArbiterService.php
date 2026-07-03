<?php

declare(strict_types=1);

namespace App\Services\Ai\Router;

use Symfony\Component\Process\Process;

/**
 * Árbitro SEMÂNTICO de flow — o degrau que o léxico não alcança.
 *
 * Decisão do operador (03/07): "ser por frase não funciona — tem muitos
 * cenários e a lista só cresce". Este órgão faz o que o Claude Code/Codex
 * fazem por construção: um MODELO lê a mensagem e escolhe o destino,
 * entendendo semanticamente ("analise esse ativo" → finanças; "analisa essa
 * página, o botão quebrou" → debug) sem nenhuma lista de frases.
 *
 * Contratos:
 *  - Roda no WORKER (assíncrono), nunca no router HTTP: o hermes one-shot
 *    local custa ~15-20s, invisível dentro de um job que já leva 30-300s,
 *    inaceitável no enqueue. {@see AiWorker::applySemanticFlowArbiter}
 *  - Só arbitra quando o léxico NÃO teve confiança (fallback S52); atalhos
 *    de confiança forte e escolha explícita do picker continuam soberanos.
 *  - Resposta do modelo é validada contra o catálogo fechado de flows —
 *    qualquer coisa fora vira null (fail-open para o gateway agêntico S52).
 *  - Provider local (hermes), custo zero, nada sai da máquina.
 */
final class AtlasSemanticFlowArbiterService
{
    /**
     * Catálogo fechado: os flows roteáveis do chat com as MESMAS descrições
     * do picker do desktop. Cresce junto com AtlasAiRouterDecision::FLOW_*
     * (flow novo = código novo = uma linha aqui, no mesmo PR).
     */
    public const FLOW_CATALOG = [
        AtlasAiRouterDecision::FLOW_DEV => 'programação: feature pequena/média, ajuste, refator em código',
        AtlasAiRouterDecision::FLOW_DEBUG => 'programação: isolar e corrigir bug, erro, página/tela quebrada, stack trace',
        AtlasAiRouterDecision::FLOW_REVIEW => 'programação: revisar diff, código, arquitetura ou plano técnico',
        AtlasAiRouterDecision::FLOW_PLAN => 'programação: planejar implementação antes de executar',
        AtlasAiRouterDecision::FLOW_RESEARCH => 'pesquisa técnica ou de mercado, comparação, síntese executiva',
        AtlasAiRouterDecision::FLOW_EXPLAIN => 'explicar conceito, código ou sistema existente',
        AtlasAiRouterDecision::FLOW_FINANCE => 'finanças: análise de ativo/ação/cripto, carteira, risco, tese de trade',
        AtlasAiRouterDecision::FLOW_MARKETING => 'marketing: campanha, anúncio, copy, funil, métrica de conversão',
        AtlasAiRouterDecision::FLOW_STRATEGY => 'estratégia: objetivo, prioridade, escolha de caminho de negócio',
        AtlasAiRouterDecision::FLOW_CYBER => 'segurança: postura defensiva, auditoria, resposta a incidente',
        AtlasAiRouterDecision::FLOW_PERSONAL_DEVELOPMENT => 'pessoal: meta, hábito, organização pessoal',
        AtlasAiRouterDecision::FLOW_AUTOMATION => 'automação: workflow, integração, pipeline, agendamento',
        AtlasAiRouterDecision::FLOW_CONVERSATION => 'papo livre, cumprimento, sem tarefa nem domínio técnico',
    ];

    /** @var callable(string): ?string */
    private $runner;

    /**
     * @param  ?callable(string): ?string  $runner  executor do prompt (testes
     *                                              injetam fake; produção usa o hermes one-shot local)
     */
    public function __construct(?callable $runner = null)
    {
        $this->runner = $runner ?? function (string $prompt): ?string {
            $binary = (string) config('atlas.ai.semantic_arbiter.binary', 'hermes');
            $timeout = (float) config('atlas.ai.semantic_arbiter.timeout_seconds', 60);
            $process = new Process([$binary, '-z', $prompt], base_path(), null, null, $timeout);
            $process->run();

            return $process->isSuccessful() ? $process->getOutput() : null;
        };
    }

    /**
     * Escolhe o flow para a mensagem, ou null (fail-open) quando o modelo
     * não responde / responde fora do catálogo / o árbitro está desligado.
     */
    public function arbitrate(string $message): ?string
    {
        $message = trim($message);
        if ($message === '' || ! (bool) config('atlas.ai.semantic_arbiter.enabled', true)) {
            return null;
        }

        $catalog = '';
        foreach (self::FLOW_CATALOG as $id => $description) {
            $catalog .= "- {$id}: {$description}\n";
        }

        $prompt = "Você é o roteador do Atlas. Escolha o flow certo para a mensagem do operador.\n"
            ."Responda APENAS com o id do flow (uma linha, sem explicação).\n\n"
            ."Flows disponíveis:\n{$catalog}\n"
            .'Mensagem do operador: "'.mb_substr($message, 0, 1200)."\"\n\nFlow:";

        try {
            $raw = ($this->runner)($prompt);
        } catch (\Throwable) {
            return null;
        }
        if (! is_string($raw)) {
            return null;
        }

        // O modelo pode ecoar prosa em volta: aceitar a ÚLTIMA ocorrência de
        // um id válido no output (one-shot costuma terminar com a resposta).
        $found = null;
        foreach (array_keys(self::FLOW_CATALOG) as $id) {
            $pos = mb_strrpos(mb_strtolower($raw), $id);
            if ($pos !== false && ($found === null || $pos > $found[1])) {
                $found = [$id, $pos];
            }
        }

        return $found[0] ?? null;
    }
}
