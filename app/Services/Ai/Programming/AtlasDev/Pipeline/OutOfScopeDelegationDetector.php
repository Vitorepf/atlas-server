<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\AtlasDev\Pipeline;

use App\Services\Ai\Programming\AtlasDev\Schemas\OperationEnvelope;

/**
 * Detects intents that fall outside Atlas Dev's charter and suggests which
 * Atlas AI flow should handle them.
 *
 * In-scope (returns null):
 *   - patch / repair / review / frontend / refactor inside a workspace
 *   - workspace-bound questions ("explique este arquivo X.php")
 *   - workspace-bound code generation
 *
 * Out-of-scope (returns a {@see DelegationSuggestion}):
 *   - "explique como OAuth funciona" without workspace -> atlas_research
 *   - "vamos discutir o futuro do produto" -> atlas_conversation
 *   - "investigar este traceback" sem alvo de código -> atlas_debug
 *   - "reescrever o sistema todo" / "Obra multi-semana" -> atlas_forge
 *   - explanation-only requests with no patch hint -> atlas_explain
 *
 * Detector is a deterministic, observable rule set. No model calls. Order
 * matters: forge markers win first, then debug (specific traceback talk),
 * then research/conversation/explain by intent shape.
 */
class OutOfScopeDelegationDetector
{
    /** Multi-system / Obra / long-running work that Atlas Dev fast-path cannot scope. */
    private const FORGE_PHRASES = [
        'obra ', 'obra de ',
        'multi-semana', 'multi semana', 'varias semanas', 'várias semanas',
        'multi-week', 'multi week', 'multiple weeks',
        'sistema inteiro', 'sistema todo', 'app inteiro', 'aplicacao inteira', 'aplicação inteira',
        'whole system', 'entire system', 'entire app', 'entire codebase', 'whole codebase',
        'reescrever todo', 'reescrever tudo', 'rewrite the entire', 'rewrite all',
        'varios agentes', 'vários agentes', 'multi-agente', 'multi agente', 'multiple agents',
        'epic ', 'roadmap inteiro',
    ];

    /** Logs/traces/incident chatter; Atlas Dev only debugs when a code target exists. */
    private const DEBUG_PHRASES = [
        'investigar log', 'investigar logs', 'analise os logs', 'analisar logs',
        'analyze logs', 'check the logs', 'look at the logs',
        'traceback', 'stack trace', 'analyze this trace', 'analyze this stack',
        'incidente', 'incident', 'postmortem', 'post-mortem',
        'investigar erro em producao', 'investigar erro em produção',
        'erro em produção', 'erro em producao', 'production error',
        'debug this trace', 'investigate this exception',
        'observability', 'observabilidade',
    ];

    /** Conversational / exploratory chatter. */
    private const CONVERSATION_PHRASES = [
        'vamos conversar', 'vamos discutir', 'vamos pensar juntos',
        'let us chat', "let's chat", 'let us discuss', "let's discuss",
        'o que voce acha', 'o que você acha', 'qual sua opiniao', 'qual sua opinião',
        'opina sobre', 'opine sobre', 'what do you think', 'your opinion on',
        'brainstorm ', 'pense comigo',
    ];

    /** Conceptual research markers — "how does X work" pedindo educação geral. */
    private const RESEARCH_PHRASES = [
        'como funciona ', 'como o ', 'como a ',
        'how does ', 'how do ',
        'historia do ', 'história do ', 'historia da ', 'história da ',
        'history of ', 'background on ',
        'qual a diferenca entre', 'qual a diferença entre', 'difference between ',
        'compare ', 'comparison between ',
        'state of the art', 'estado da arte',
        'pesquisa sobre', 'research on', 'research about',
        'oque sao ', 'o que sao ', 'o que são ', 'what are ',
    ];

    /** Phrases that very strongly imply explanation-only (no patch). */
    private const EXPLAIN_ONLY_PHRASES = [
        'apenas explique', 'so explique', 'só explique', 'somente explique',
        'apenas resuma', 'so resuma', 'só resuma', 'somente resuma',
        'explain only', 'just explain', 'just summarize', 'summary only',
        'sem aplicar', 'sem mudar codigo', 'sem mudar código', 'sem patch',
        'do not change', "don't change", 'no patch', 'no edits',
    ];

    public function detect(OperationEnvelope $envelope, TaskClassification $classification): ?DelegationSuggestion
    {
        $haystack = $this->buildHaystack($envelope);

        // Forge always wins: multi-system Obras must not run on the fast path,
        // even when the operator is sitting inside a workspace.
        if ($this->containsAny($haystack, self::FORGE_PHRASES)) {
            return new DelegationSuggestion(
                suggestedFlow: DelegationSuggestion::FLOW_FORGE,
                reason: 'multi_system_or_obra_intent',
            );
        }

        // Workspace-bound runs stay inside Atlas Dev for research/conversation/
        // debug/explain — the read_only_answer route handles "explain this file"
        // style questions. Only multi-system Obras (handled above) escape.
        if ($envelope->preflight->workspaceResolved) {
            return null;
        }

        if ($this->containsAny($haystack, self::DEBUG_PHRASES)) {
            return new DelegationSuggestion(
                suggestedFlow: DelegationSuggestion::FLOW_DEBUG,
                reason: 'logs_or_trace_without_code_target',
            );
        }

        if ($this->containsAny($haystack, self::CONVERSATION_PHRASES) && ! $classification->writeImplied) {
            return new DelegationSuggestion(
                suggestedFlow: DelegationSuggestion::FLOW_CONVERSATION,
                reason: 'free_form_exploration_chat',
            );
        }

        if ($this->containsAny($haystack, self::RESEARCH_PHRASES) && ! $classification->writeImplied) {
            return new DelegationSuggestion(
                suggestedFlow: DelegationSuggestion::FLOW_RESEARCH,
                reason: 'conceptual_question_without_workspace_target',
            );
        }

        if ($this->containsAny($haystack, self::EXPLAIN_ONLY_PHRASES) && ! $classification->writeImplied) {
            return new DelegationSuggestion(
                suggestedFlow: DelegationSuggestion::FLOW_EXPLAIN,
                reason: 'explanation_only_without_patch_target',
            );
        }

        return null;
    }

    private function buildHaystack(OperationEnvelope $envelope): string
    {
        $parts = [$envelope->normalizedIntent];
        foreach ($envelope->userConstraints as $constraint) {
            if (is_string($constraint)) {
                $parts[] = $constraint;
            }
        }

        return strtolower(implode("\n", $parts));
    }

    /**
     * @param  list<string>  $needles
     */
    private function containsAny(string $haystack, array $needles): bool
    {
        foreach ($needles as $needle) {
            if ($needle === '') {
                continue;
            }
            if (str_contains($haystack, $needle)) {
                return true;
            }
        }

        return false;
    }
}
