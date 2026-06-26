<?php

declare(strict_types=1);

namespace App\Services\Ai\Vox\Routing;

use App\Services\Ai\Vox\VoxSchema;

/**
 * Atlas Vox V6.5 · Flow Orchestrator.
 *
 * Consumidor puro do `VoxAutoModeRouter` + `VoxIntentExtractor` outputs.
 * Não toma decisões NOVAS de modo; ele ENRIQUECE a decisão existente com
 * a camada humana que o overlay precisa pra explicar o fluxo ao operador:
 *
 *   - destino concreto (clipboard / atlas / codex / claude / terminal_proposal / note / none)
 *   - banda de confiança humana (high/medium/low) em vez de score cru
 *   - detecção de referência ambígua ("isso", "esse arquivo", "aqui")
 *     que exige clarificação quando NÃO há context_ref resolvido
 *   - detecção de duas intenções no mesmo enunciado
 *   - "what I heard / what I understood / what I will do / why this flow"
 *     em PT-BR humano, prontos pra render
 *   - safe_fallback explícito quando o sistema NÃO deve agir
 *
 * Hard contract (Leis 0 / 0.5 / 0.75 / 0.9):
 *   - Determinístico. Sem rede, sem LLM, sem provider call, sem random.
 *   - NUNCA escolhe ação destrutiva. R4 sempre vai pra `governed_execute`
 *     com `what_i_will_do` declarando explicitamente que NÃO vai executar.
 *   - Quando ambíguo, `safe_fallback = ask_clarification`. O Kernel pode
 *     decidir não acionar nada e devolver `clarifying_question` pro overlay.
 *
 * Output schema canônico: `atlas.vox.flow_decision.v1`.
 */
final class VoxFlowOrchestrator
{
    public const SCHEMA = VoxSchema::FLOW_DECISION;
    public const VERSION = VoxSchema::FLOW_ORCHESTRATOR_VERSION;

    private const DESTINATION_CLIPBOARD = 'clipboard';
    private const DESTINATION_ATLAS = 'atlas';
    private const DESTINATION_CODEX = 'codex';
    private const DESTINATION_CLAUDE = 'claude';
    private const DESTINATION_TERMINAL_PROPOSAL = 'terminal_proposal';
    private const DESTINATION_NOTE = 'note';
    private const DESTINATION_NONE = 'none';

    private const CONFIDENCE_HIGH = 'high';
    private const CONFIDENCE_MEDIUM = 'medium';
    private const CONFIDENCE_LOW = 'low';

    private const FALLBACK_DICTATION = 'dictation';
    private const FALLBACK_COPY_TEXT = 'copy_text';
    private const FALLBACK_ASK_CLARIFICATION = 'ask_clarification';
    private const FALLBACK_CANCEL = 'cancel';

    private ?VoxClarificationDetector $clarificationDetectorInstance = null;

    /**
     * Entry point.
     *
     * @param  array<string,mixed>  $transcript     VoxTranscript-like payload (text obrigatório).
     * @param  array<string,mixed>  $intentPacket   intent_packet do VoxCompiler — fonte de mode/risk/provider/executor/constraints/context_refs.
     * @param  array<string,mixed>  $autoDecision   auto_mode_decision do VoxAutoModeRouter — fonte de confidence + marker R4 + reason.
     * @param  array<string,mixed>  $hints          { context_refs: list<...> } — fonte alternativa de context_refs quando o packet não recarrega.
     * @return array{
     *   schema: string,
     *   version: string,
     *   mode: string,
     *   destination: string,
     *   risk_class: string,
     *   confidence: string,
     *   needs_clarification: bool,
     *   clarifying_question: ?string,
     *   what_i_heard: string,
     *   what_i_understood: string,
     *   what_i_will_do: string,
     *   why_this_flow: string,
     *   safe_fallback: string
     * }
     */
    public function decide(
        array $transcript,
        array $intentPacket,
        array $autoDecision,
        array $hints = [],
    ): array {
        $text = (string) ($transcript['text'] ?? '');
        $normalized = $this->normalise($text);

        $mode = $this->resolveMode($intentPacket, $autoDecision);
        $risk = (string) ($intentPacket['risk_class'] ?? VoxSchema::RISK_R0);
        $provider = (string) ($intentPacket['provider_hint'] ?? 'local');
        $executor = (string) ($intentPacket['executor_hint'] ?? 'none');
        $outputFormat = (string) ($intentPacket['output_format'] ?? 'text');
        $constraints = array_values(array_filter((array) ($intentPacket['constraints'] ?? []), 'is_string'));
        $contextRefs = $this->normalizeContextRefs(
            $intentPacket['context_refs'] ?? $hints['context_refs'] ?? [],
        );
        $hasResolvedContext = $this->hasResolvedContext($contextRefs);
        $r4Marker = $this->extractR4Marker($autoDecision);

        $rawConfidence = (float) ($autoDecision['confidence'] ?? 0.0);
        $confidenceBand = $this->confidenceBand($rawConfidence);

        $ambiguity = $this->detectAmbiguousReference(
            $normalized,
            $text,
            $hasResolvedContext,
        );
        $doubleIntent = $this->detectDoubleIntent($normalized);
        // V6.5-CLARIFICATION-ENGINE · 4 detectores adicionais cobrindo os
        // gaps de probe real: falas curtas com pronome sem payload.
        $missingPolishTarget = $this->detectMissingPolishTarget($text, $mode, $hasResolvedContext);
        $missingAiObjective = $this->detectMissingObjectiveForAi($text, $mode, $hasResolvedContext);
        $missingExecuteTarget = $this->detectMissingExecuteTarget($text, $mode);
        $genericFaz = $this->detectGenericFaz($text, $hasResolvedContext);

        $needsClarification = false;
        $clarifyingQuestion = null;

        if ($ambiguity !== null) {
            $needsClarification = true;
            $clarifyingQuestion = $ambiguity;
        } elseif ($doubleIntent !== null) {
            $needsClarification = true;
            $clarifyingQuestion = $doubleIntent;
        } elseif ($missingPolishTarget !== null) {
            $needsClarification = true;
            $clarifyingQuestion = $missingPolishTarget;
        } elseif ($missingAiObjective !== null) {
            $needsClarification = true;
            $clarifyingQuestion = $missingAiObjective;
        } elseif ($missingExecuteTarget !== null) {
            $needsClarification = true;
            $clarifyingQuestion = $missingExecuteTarget;
        } elseif ($genericFaz !== null) {
            $needsClarification = true;
            $clarifyingQuestion = $genericFaz;
        } elseif ($confidenceBand === self::CONFIDENCE_LOW
            && $mode === VoxSchema::MODE_GOVERNED_EXECUTE
        ) {
            $needsClarification = true;
            $clarifyingQuestion = 'Não entendi o que executar. Pode dizer o alvo? Ex.: "roda os testes".';
        } elseif ($confidenceBand === self::CONFIDENCE_LOW
            && $normalized === ''
        ) {
            $needsClarification = true;
            $clarifyingQuestion = 'Não captei sua fala. Pode repetir mais perto do microfone?';
        }

        $destination = $this->resolveDestination(
            mode: $mode,
            provider: $provider,
            executor: $executor,
            outputFormat: $outputFormat,
            r4Marker: $r4Marker,
            needsClarification: $needsClarification,
            rawText: $text,
        );

        $safeFallback = $this->resolveFallback(
            mode: $mode,
            risk: $risk,
            confidence: $confidenceBand,
            needsClarification: $needsClarification,
            r4Marker: $r4Marker,
        );

        $whatIHeard = $this->stripExcessWhitespace($text);
        $whatIUnderstood = $this->describeUnderstanding(
            mode: $mode,
            provider: $provider,
            constraints: $constraints,
            r4Marker: $r4Marker,
            needsClarification: $needsClarification,
            ambiguityKind: $ambiguity !== null,
            doubleIntent: $doubleIntent !== null,
        );
        $whatIWillDo = $this->describeAction(
            mode: $mode,
            destination: $destination,
            risk: $risk,
            r4Marker: $r4Marker,
            needsClarification: $needsClarification,
        );
        $whyThisFlow = $this->describeChoice(
            mode: $mode,
            autoDecision: $autoDecision,
            r4Marker: $r4Marker,
            confidenceBand: $confidenceBand,
        );

        // V6.5 · Composite Intent Splitter. Determinístico, sem rede. Quando
        // a fala tem duas ou mais intenções ligadas por "e depois", "aí",
        // "então" etc., produzimos um plano em steps + execution_policy.
        // Frontend antigo pode ignorar o bloco — sem regressão.
        $composite = $this->splitComposite(
            text: $text,
            normalized: $normalized,
            singleMode: $mode,
            singleDestination: $destination,
            singleRisk: $risk,
            provider: $provider,
            executor: $executor,
            outputFormat: $outputFormat,
            r4Marker: $r4Marker,
            needsClarification: $needsClarification,
        );

        return [
            'schema' => self::SCHEMA,
            'version' => self::VERSION,
            'mode' => $mode,
            'destination' => $destination,
            'risk_class' => $risk,
            'confidence' => $confidenceBand,
            'needs_clarification' => $needsClarification,
            'clarifying_question' => $clarifyingQuestion,
            'what_i_heard' => $whatIHeard,
            'what_i_understood' => $whatIUnderstood,
            'what_i_will_do' => $whatIWillDo,
            'why_this_flow' => $whyThisFlow,
            'safe_fallback' => $safeFallback,
            'composite' => $composite,
        ];
    }

    private function resolveMode(array $intentPacket, array $autoDecision): string
    {
        $effective = (string) ($intentPacket['mode'] ?? '');
        if (in_array($effective, [
            VoxSchema::MODE_DICTATION,
            VoxSchema::MODE_PROMPT_POLISH,
            VoxSchema::MODE_INTENT_COMPILE,
            VoxSchema::MODE_GOVERNED_EXECUTE,
        ], true)) {
            return $effective;
        }
        $auto = (string) ($autoDecision['selected_mode'] ?? VoxSchema::MODE_DICTATION);

        return $auto !== '' ? $auto : VoxSchema::MODE_DICTATION;
    }

    private function normalise(string $text): string
    {
        $t = mb_strtolower($text);
        $t = (string) preg_replace('/\s+/u', ' ', $t);

        return trim($t);
    }

    private function confidenceBand(float $c): string
    {
        if ($c >= 0.85) {
            return self::CONFIDENCE_HIGH;
        }
        if ($c >= 0.72) {
            return self::CONFIDENCE_MEDIUM;
        }

        return self::CONFIDENCE_LOW;
    }

    private function extractR4Marker(array $autoDecision): ?string
    {
        $markers = $autoDecision['markers'] ?? null;
        if (! is_array($markers)) {
            return null;
        }
        $marker = $markers['r4_marker'] ?? null;
        if (is_string($marker) && $marker !== '') {
            return $marker;
        }

        return null;
    }

    /**
     * @param  mixed  $raw
     * @return list<array{kind: string, ref: ?string, resolved: bool}>
     */
    private function normalizeContextRefs(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $entry) {
            if (! is_array($entry)) {
                continue;
            }
            $kind = (string) ($entry['kind'] ?? '');
            if ($kind === '') {
                continue;
            }
            $ref = $entry['ref'] ?? null;
            $ref = is_string($ref) && $ref !== '' ? $ref : null;
            $resolved = array_key_exists('resolved', $entry)
                ? (bool) $entry['resolved']
                : ($ref !== null);
            $out[] = ['kind' => $kind, 'ref' => $ref, 'resolved' => $resolved];
        }

        return $out;
    }

    /**
     * @param  list<array{kind: string, ref: ?string, resolved: bool}>  $refs
     */
    private function hasResolvedContext(array $refs): bool
    {
        foreach ($refs as $r) {
            if (in_array($r['kind'], ['file', 'selection', 'active_window'], true)
                && $r['resolved']
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * V6.5 · detecta pronome ambíguo em verbo que ESPERA objeto concreto.
     * Só dispara quando NÃO há context_ref resolvido (file/selection/window)
     * E NÃO há identificador específico na própria fala (nome próprio em
     * TitleCase, filename `.ext`, path com `/`). Verbos de criação/anotação
     * ("anota X", "escreve Y") NÃO disparam: neles, o complemento é o
     * conteúdo da própria fala, não uma referência externa.
     */
    private function detectAmbiguousReference(
        string $normalizedText,
        string $rawText,
        bool $hasContext,
    ): ?string {
        return $this->clarificationDetector()->detectAmbiguousReference($normalizedText, $rawText, $hasContext);
    }

    /**
     * V6.5-CLARIFICATION-ENGINE · mode=prompt_polish sem payload concreto.
     * "melhora isso", "corrige esse erro" sem texto/colon-conteúdo → ASK.
     * "melhora esse texto: <conteúdo>", "deixa esse texto mais profissional
     * pra mandar pro cliente" (texto longo) → não dispara.
     */
    private function detectMissingPolishTarget(
        string $rawText,
        string $mode,
        bool $hasContext,
    ): ?string {
        return $this->clarificationDetector()->detectMissingPolishTarget($rawText, $mode, $hasContext);
    }

    /**
     * V6.5-CLARIFICATION-ENGINE · mode=intent_compile sem objetivo claro.
     * "manda pro Codex" sem verbo de objetivo (investigar/analisar/criar/...)
     * e sem payload → ASK "O que pedir pra IA?". Casos com objetivo OU
     * texto longo passam direto.
     */
    private function detectMissingObjectiveForAi(
        string $rawText,
        string $mode,
        bool $hasContext,
    ): ?string {
        return $this->clarificationDetector()->detectMissingObjectiveForAi($rawText, $mode, $hasContext, $this->guessProviderFromText($rawText));
    }

    /**
     * V6.5-CLARIFICATION-ENGINE · mode=governed_execute sem comando
     * concreto. "executa no terminal" sem `:` e sem nome de script/teste.
     */
    private function detectMissingExecuteTarget(string $rawText, string $mode): ?string
    {
        return $this->clarificationDetector()->detectMissingExecuteTarget($rawText, $mode);
    }

    /**
     * V6.5-CLARIFICATION-ENGINE · "faz isso" genérico sem verbo concreto.
     * O router classifica como governed_execute com peso baixo; aqui
     * pedimos verbo concreto antes de tratar como ação.
     */
    private function detectGenericFaz(string $rawText, bool $hasContext): ?string
    {
        return $this->clarificationDetector()->detectGenericFaz($rawText, $hasContext);
    }

    private function guessProviderFromText(string $rawText): string
    {
        if (preg_match('/\bcodex\b/iu', $rawText) === 1) {
            return 'codex';
        }
        if (preg_match('/\bclaude\b/iu', $rawText) === 1) {
            return 'claude';
        }

        return 'ai';
    }

    /**
     * V6.5 · detecta duas intenções no mesmo enunciado SOMENTE quando a
     * primeira intenção não tem alvo claro (verbo + conjunção imediata).
     * Frases com alvo concreto na primeira parte ("melhora esse texto e
     * cria prompt pro Codex") são delegadas pro Composite Splitter, que
     * monta um plano em steps com `single_safe_step` policy.
     *
     * V6.5-CLARIFICATION-ENGINE · scoped pra resolver conflito com composite.
     */
    private function detectDoubleIntent(string $text): ?string
    {
        return $this->clarificationDetector()->detectDoubleIntent($text);
    }

    /**
     * V6.5 · detecta intenção explícita de captura como nota/inbox.
     * Cobre "salva no inbox", "salva isso no inbox", "salva como nota",
     * "cria uma nota", "joga no inbox" — variantes coloquiais reais.
     */
    private function mentionsNoteIntent(string $rawText): bool
    {
        return $this->clarificationDetector()->mentionsNoteIntent($rawText);
    }

    /**
     * Heurística de identificador específico: nome próprio TitleCase
     * (≥ 2 caracteres pra evitar palavras como "Um"/"Eu"/"Já"), arquivo
     * com extensão conhecida, ou path absoluto/relativo. Tudo isso é
     * sinal suficiente de que o alvo está nomeado.
     */
    private function hasSpecificIdentifier(string $rawText): bool
    {
        return $this->clarificationDetector()->hasSpecificIdentifier($rawText);
    }

    private function clarificationDetector(): VoxClarificationDetector
    {
        return $this->clarificationDetectorInstance ??= new VoxClarificationDetector;
    }

    private function resolveDestination(
        string $mode,
        string $provider,
        string $executor,
        string $outputFormat,
        ?string $r4Marker,
        bool $needsClarification,
        string $rawText = '',
    ): string {
        // Marcador R4: NUNCA destinamos pra execução remota direta.
        if ($r4Marker !== null) {
            return $executor === 'terminal_propose'
                ? self::DESTINATION_TERMINAL_PROPOSAL
                : self::DESTINATION_NONE;
        }

        // Clarificação pendente: destino fica `none` (UI vai pedir, não agir).
        if ($needsClarification) {
            return self::DESTINATION_NONE;
        }

        // V6.5 · override pra dictation com intenção explícita de inbox/nota
        // ("salva isso no inbox", "salva como nota") — o extractor às vezes
        // escolhe `plan` por causa de palavras como "planejamento" e perde
        // a sinalização de captura.
        if ($mode === VoxSchema::MODE_DICTATION
            && $this->mentionsNoteIntent($rawText)
        ) {
            return self::DESTINATION_NOTE;
        }

        // V6.5 · output_format=notes do extractor pode disparar por substring
        // contaminação ("anota" contém "nota"). Só aceita o destino note se
        // o operador também usou uma frase explícita de captura.
        $extractorSaysNotes = $mode === VoxSchema::MODE_DICTATION
            && $outputFormat === 'notes'
            && $this->mentionsNoteIntent($rawText);

        return match (true) {
            $extractorSaysNotes => self::DESTINATION_NOTE,
            $mode === VoxSchema::MODE_DICTATION => self::DESTINATION_CLIPBOARD,
            $mode === VoxSchema::MODE_PROMPT_POLISH => self::DESTINATION_CLIPBOARD,
            $mode === VoxSchema::MODE_INTENT_COMPILE && $provider === 'codex_cli' => self::DESTINATION_CODEX,
            $mode === VoxSchema::MODE_INTENT_COMPILE && $provider === 'claude_cli' => self::DESTINATION_CLAUDE,
            $mode === VoxSchema::MODE_INTENT_COMPILE => self::DESTINATION_ATLAS,
            $mode === VoxSchema::MODE_GOVERNED_EXECUTE && $executor === 'terminal_propose' => self::DESTINATION_TERMINAL_PROPOSAL,
            $mode === VoxSchema::MODE_GOVERNED_EXECUTE && $executor === 'note' => self::DESTINATION_NOTE,
            $mode === VoxSchema::MODE_GOVERNED_EXECUTE && $provider === 'codex_cli' => self::DESTINATION_CODEX,
            $mode === VoxSchema::MODE_GOVERNED_EXECUTE && $provider === 'claude_cli' => self::DESTINATION_CLAUDE,
            $mode === VoxSchema::MODE_GOVERNED_EXECUTE => self::DESTINATION_NONE,
            default => self::DESTINATION_NONE,
        };
    }

    private function resolveFallback(
        string $mode,
        string $risk,
        string $confidence,
        bool $needsClarification,
        ?string $r4Marker,
    ): string {
        if ($needsClarification) {
            return self::FALLBACK_ASK_CLARIFICATION;
        }
        if ($r4Marker !== null || $risk === VoxSchema::RISK_R4) {
            return self::FALLBACK_CANCEL;
        }
        if ($mode === VoxSchema::MODE_GOVERNED_EXECUTE && $confidence === self::CONFIDENCE_LOW) {
            return self::FALLBACK_ASK_CLARIFICATION;
        }
        if ($mode === VoxSchema::MODE_DICTATION) {
            return self::FALLBACK_COPY_TEXT;
        }
        if ($mode === VoxSchema::MODE_PROMPT_POLISH) {
            return self::FALLBACK_COPY_TEXT;
        }
        if ($mode === VoxSchema::MODE_INTENT_COMPILE) {
            return self::FALLBACK_COPY_TEXT;
        }
        // governed_execute não-R4 com confiança ≥ medium: o fallback seguro
        // é deixar o operador copiar a proposta (terminal_proposal/note)
        // e decidir manualmente, não cair em ditado livre.
        if ($mode === VoxSchema::MODE_GOVERNED_EXECUTE) {
            return self::FALLBACK_COPY_TEXT;
        }

        return self::FALLBACK_DICTATION;
    }

    private function stripExcessWhitespace(string $text): string
    {
        $t = (string) preg_replace('/\s+/u', ' ', $text);

        return trim($t);
    }

    /**
     * @param  list<string>  $constraints
     */
    private function describeUnderstanding(
        string $mode,
        string $provider,
        array $constraints,
        ?string $r4Marker,
        bool $needsClarification,
        bool $ambiguityKind,
        bool $doubleIntent,
    ): string {
        if ($r4Marker !== null) {
            $human = $this->humaniseR4Marker($r4Marker);

            return "Pedido com marcador destrutivo ({$human}). Preciso de confirmação literal humana antes de qualquer efeito.";
        }
        if ($ambiguityKind) {
            return 'Pedido faz referência a algo ("isso"/"esse arquivo"/"aqui") sem contexto resolvido.';
        }
        if ($doubleIntent) {
            return 'Detectei duas intenções no mesmo enunciado. Preciso de uma ordem clara.';
        }
        $constraintNote = '';
        if ($constraints !== []) {
            $sample = array_slice($constraints, 0, 2);
            $constraintNote = ' (restrições: '.implode('; ', $sample).')';
        }

        return match ($mode) {
            VoxSchema::MODE_DICTATION => 'Anotar/inserir texto livre no destino atual.',
            VoxSchema::MODE_PROMPT_POLISH => 'Limpar e melhorar o texto sem mudar o sentido'.$constraintNote.'.',
            VoxSchema::MODE_INTENT_COMPILE => 'Montar um prompt forte para a IA ('.$this->providerLabel($provider).')'.$constraintNote.'.',
            VoxSchema::MODE_GOVERNED_EXECUTE => 'Ação no sistema (terminal/arquivo)'.$constraintNote.'.',
            default => 'Intenção sem sinal forte — tratando como ditado.',
        };
    }

    private function describeAction(
        string $mode,
        string $destination,
        string $risk,
        ?string $r4Marker,
        bool $needsClarification,
    ): string {
        if ($needsClarification) {
            return 'Preciso de mais detalhe antes de agir. Vou perguntar e esperar sua resposta.';
        }
        if ($r4Marker !== null || $risk === VoxSchema::RISK_R4) {
            return 'NÃO vou executar direto. Vou propor a ação e pedir confirmação literal antes de qualquer efeito destrutivo.';
        }

        return match ($mode) {
            VoxSchema::MODE_DICTATION => match ($destination) {
                self::DESTINATION_NOTE => 'Vou salvar sua fala como nota no inbox.',
                default => 'Vou colocar o texto no destino selecionado (campo ativo / clipboard).',
            },
            VoxSchema::MODE_PROMPT_POLISH => 'Vou devolver o texto melhorado pra você revisar e colar.',
            VoxSchema::MODE_INTENT_COMPILE => match ($destination) {
                self::DESTINATION_CODEX => 'Vou montar o prompt pronto pra colar no Codex CLI — você dispara a execução.',
                self::DESTINATION_CLAUDE => 'Vou montar o prompt pronto pra colar no Claude CLI — você dispara a execução.',
                default => 'Vou montar o prompt estruturado e te devolver pra revisar antes de mandar.',
            },
            VoxSchema::MODE_GOVERNED_EXECUTE => match ($destination) {
                self::DESTINATION_TERMINAL_PROPOSAL => 'Vou propor o comando no terminal. NÃO executo — você decide se roda.',
                self::DESTINATION_NOTE => 'Vou salvar como nota no inbox depois da sua confirmação.',
                self::DESTINATION_CODEX => 'Vou pedir confirmação humana antes de invocar o Codex CLI.',
                self::DESTINATION_CLAUDE => 'Vou pedir confirmação humana antes de invocar o Claude CLI.',
                default => 'Vou propor a ação e pedir sua confirmação antes de qualquer efeito.',
            },
            default => 'Vou tratar como ditado livre.',
        };
    }

    private function describeChoice(
        string $mode,
        array $autoDecision,
        ?string $r4Marker,
        string $confidenceBand,
    ): string {
        if ($r4Marker !== null) {
            return 'Detectei marcador destrutivo no que você falou — política Atlas exige confirmação humana.';
        }
        $routerReason = (string) ($autoDecision['reason_pt_br'] ?? '');
        if ($routerReason !== '') {
            // Reuse the router's already-PT-BR explanation as the primary
            // reason; trim runaway long lines so it lands cleanly in the UI.
            $reason = mb_strlen($routerReason) > 220
                ? mb_substr($routerReason, 0, 217).'…'
                : $routerReason;

            return $reason;
        }

        return match ($mode) {
            VoxSchema::MODE_DICTATION => 'Sem verbo de transformação ou execução — tratei como texto livre.',
            VoxSchema::MODE_PROMPT_POLISH => 'Detectei pedido de melhorar/organizar texto existente.',
            VoxSchema::MODE_INTENT_COMPILE => 'Detectei pedido para a IA (Codex/Claude/Atlas) — compilei como prompt.',
            VoxSchema::MODE_GOVERNED_EXECUTE => 'Detectei verbo de ação no sistema — ativei o caminho governado.',
            default => 'Confiança '.$confidenceBand.'; fallback seguro.',
        };
    }

    // ════════════════════════════════════════════════════════════════════
    // V6.5 · Composite Intent Splitter
    // ════════════════════════════════════════════════════════════════════
    //
    // Detecta múltiplas intenções no mesmo enunciado e devolve um plano
    // determinístico (sem rede, sem LLM) que o overlay/cabine pode usar
    // pra confirmar passo a passo. Garantias hard:
    //
    //   - Nunca executa cadeia automaticamente. Quando há execução
    //     misturada com qualquer outra intenção, `execution_policy` cai
    //     em `step_by_step_confirmation` no mínimo.
    //   - Quando há marcador destrutivo em qualquer step, `execution_policy`
    //     vira `preview_only` (UI só mostra; usuário precisa agir manual).
    //   - Frase simples sem conector vira `is_composite=false` com 1 step.
    //     Callers antigos seguem lendo `mode`/`destination` — sem regressão.
    //
    // Algoritmo:
    //   1. Quebra a normalização em segmentos por conectores PT-BR canônicos
    //      ("e depois", "depois disso", "em seguida", "aí", "então", "depois", "e").
    //   2. Para cada segmento, classifica mode/destination/risk via heurísticas
    //      léxicas (mesmo vocabulário que o resto do orchestrator usa).
    //   3. Decide execution_policy + recommended_next_step.

    /**
     * @return array{
     *   is_composite: bool,
     *   steps: list<array{
     *     order: int,
     *     mode: string,
     *     destination: string,
     *     summary: string,
     *     risk_class: string,
     *     requires_confirmation: bool
     *   }>,
     *   execution_policy: string,
     *   recommended_next_step: int
     * }
     */
    private function splitComposite(
        string $text,
        string $normalized,
        string $singleMode,
        string $singleDestination,
        string $singleRisk,
        string $provider,
        string $executor,
        string $outputFormat,
        ?string $r4Marker,
        bool $needsClarification,
    ): array {
        // Caso degenerado: texto vazio. Devolvemos plano vazio mas
        // estruturado pra UI não precisar `if (composite)`.
        if ($normalized === '') {
            $step = $this->buildSingleStep(
                segment: '',
                singleMode: $singleMode,
                singleDestination: $singleDestination,
                singleRisk: $singleRisk,
                provider: $provider,
                executor: $executor,
                outputFormat: $outputFormat,
                r4Marker: $r4Marker,
            );

            return [
                'is_composite' => false,
                'steps' => [$step],
                'execution_policy' => VoxSchema::COMPOSITE_POLICY_SINGLE_SAFE_STEP,
                'recommended_next_step' => 1,
            ];
        }

        // V6.5-COMPOSITE · NÃO faz early return quando clarification está
        // ativa. Splitter continua rodando para o caso de a UI inteligente
        // querer mostrar steps mesmo quando o backend pediu pergunta.
        // A coerência fica garantida no final: quando `$needsClarification`,
        // a policy é forçada a `preview_only` independentemente do que o
        // splitter decidiu — sem cadeia automática.

        $segments = $this->segmentByConnectors($normalized);
        if (count($segments) <= 1) {
            $step = $this->buildSingleStep(
                segment: $normalized,
                singleMode: $singleMode,
                singleDestination: $singleDestination,
                singleRisk: $singleRisk,
                provider: $provider,
                executor: $executor,
                outputFormat: $outputFormat,
                r4Marker: $r4Marker,
            );

            $policy = $needsClarification
                ? VoxSchema::COMPOSITE_POLICY_PREVIEW_ONLY
                : $this->defaultPolicyFor(
                    isComposite: false,
                    risk: $singleRisk,
                    hasExecution: $this->stepInvolvesExecution($step),
                );

            return [
                'is_composite' => false,
                'steps' => [$step],
                'execution_policy' => $policy,
                'recommended_next_step' => 1,
            ];
        }

        // Tem ≥ 2 segmentos. Classifica cada um e monta steps.
        $steps = [];
        $i = 1;
        foreach ($segments as $segment) {
            $steps[] = $this->classifySegmentAsStep($segment, $i);
            $i++;
        }
        // Filtro: segmentos que viraram "ruído" (sem verbo classificável) com
        // texto curto devem ser dobrados no anterior, mas pra V0 mantemos
        // tudo para auditoria honesta — o overlay decide como humanizar.

        $hasR4 = false;
        $hasExecution = false;
        $hasNonExecution = false;
        foreach ($steps as $step) {
            if ($step['risk_class'] === VoxSchema::RISK_R4) {
                $hasR4 = true;
            }
            if ($this->stepInvolvesExecution($step)) {
                $hasExecution = true;
            } else {
                $hasNonExecution = true;
            }
        }

        $policy = $this->compositePolicy(
            hasR4: $hasR4,
            hasExecution: $hasExecution,
            hasNonExecution: $hasNonExecution,
            allSafe: $this->stepsAreAllSafe($steps),
        );
        // Clarification pendente sempre rebaixa a policy pra preview_only —
        // mesmo se os steps individualmente seriam seguros. Nada de cadeia
        // automática enquanto o operador não tirou a dúvida.
        if ($needsClarification) {
            $policy = VoxSchema::COMPOSITE_POLICY_PREVIEW_ONLY;
        }

        return [
            'is_composite' => true,
            'steps' => $steps,
            'execution_policy' => $policy,
            'recommended_next_step' => 1,
        ];
    }

    /**
     * Quebra a fala normalizada em segmentos por conectores PT-BR canônicos.
     * Ordem dos padrões importa: mais longos primeiro pra não comer "depois"
     * antes de "e depois".
     *
     * @return list<string>
     */
    private function segmentByConnectors(string $text): array
    {
        $boundary = '/\s+(?:e\s+depois|depois\s+disso|em\s+seguida|primeiro,?|depois,?|ai|a[ií],?|então,?|entao,?|e)\s+/iu';
        $parts = preg_split($boundary, $text);
        if (! is_array($parts)) {
            return [$text];
        }
        $clean = [];
        foreach ($parts as $part) {
            $part = trim((string) $part);
            if ($part === '') {
                continue;
            }
            $clean[] = $part;
        }
        if ($clean === []) {
            return [$text];
        }
        if (count($clean) === 1) {
            return $clean;
        }
        // Heurística anti-falso-positivo: descarta split se algum segmento
        // ficou trivialmente curto (< 3 chars) OU se nenhum segmento tem um
        // verbo de intenção classificável. Em ambos os casos, devolve o
        // texto original como 1 segmento — preserva o caminho não-composto.
        $hasVerbAnywhere = false;
        foreach ($clean as $seg) {
            if (mb_strlen($seg) < 3) {
                return [$text];
            }
            if ($this->segmentHasIntentVerb($seg)) {
                $hasVerbAnywhere = true;
            }
        }
        if (! $hasVerbAnywhere) {
            return [$text];
        }
        // Heurística anti-falso-positivo extra: se 2+ segmentos NÃO têm
        // verbo de intenção próprio, fundimos tudo (era enumeração, não
        // composição). Ex.: "anota leite, pão e ovos" — 3 itens, zero
        // verbos de intenção nos lados direitos.
        $segmentsWithVerb = 0;
        foreach ($clean as $seg) {
            if ($this->segmentHasIntentVerb($seg)) {
                $segmentsWithVerb++;
            }
        }
        if ($segmentsWithVerb < 2) {
            return [$text];
        }

        return $clean;
    }

    /** Detecta verbo de intenção mínima num segmento (qualquer mode). */
    private function segmentHasIntentVerb(string $seg): bool
    {
        return preg_match(
            '/\b(?:'
            // ditado
            .'anota|anote|escreve|escreva|registra|registre|salva|salve|joga|jogue'
            // polish
            .'|melhora|melhore|melhorar|organiza|organize|polir|reescreve|reescreva|limpa|limpe|arruma|arrume|corrige|corrija|deixa|deixe'
            // intent compile
            .'|cria|crie|criar|monta|monte|montar|faz|faça|fazer|gera|gere|gerar|prepara|prepare|preparar|pergunta|pergunte|manda|mande|mandar|envia|envie|enviar'
            // governed execute / shell
            .'|executa|execute|executar|roda|rode|rodar|aplica|aplique|aplicar|builda|builde|buildar|deploya|deploye|deploye'
            // destrutivos
            .'|apaga|apague|apagar|deleta|delete|deletar|remove|remova|remover|trunca|trunque|truncar|dropa|drope|dropar'
            // leitura/análise
            .'|olha|olhe|olhar|analisa|analise|analisar|investiga|investigue|investigar|revisa|revise|revisar|verifica|verifique'
            // copia (destino terminal)
            .'|copia|copie|copiar|insere|insira|inserir'
            // commit/git
            .'|commit|commita|commite|commitar|pusha|pushe|pushar'
            .')\b/iu',
            $seg,
        ) === 1;
    }

    /**
     * Classifica um segmento como step do plano composite. Retorna shape
     * canônico { order, mode, destination, summary, risk_class,
     * requires_confirmation }.
     *
     * @return array{order:int,mode:string,destination:string,summary:string,risk_class:string,requires_confirmation:bool}
     */
    private function classifySegmentAsStep(string $segment, int $order): array
    {
        $seg = mb_strtolower($segment);

        // 1. Marcador destrutivo (R4) — vence tudo.
        $r4 = $this->detectSegmentR4Marker($seg);
        if ($r4 !== null) {
            $human = $this->humaniseR4Marker($r4);

            return [
                'order' => $order,
                'mode' => VoxSchema::MODE_GOVERNED_EXECUTE,
                'destination' => 'none',
                'summary' => "Ação destrutiva detectada ({$human}) — NÃO executo direto, exige confirmação literal.",
                'risk_class' => VoxSchema::RISK_R4,
                'requires_confirmation' => true,
            ];
        }

        // 2. Verbo de execução de shell (R3). Inclui "roda", "executa",
        //    "builda", "aplica", além de "commit/push" (ambos não-destrutivos
        //    no contexto V6 — destrutivos viraram R4 acima).
        if (preg_match(
            '/\b(?:roda|rode|rodar|executa|execute|executar|builda|builde|buildar|aplica|aplique|aplicar|deploya|deploye|commit|commita|commite|commitar|pusha|pushe|pushar|testa|teste|testar)\b/u',
            $seg,
        ) === 1) {
            return [
                'order' => $order,
                'mode' => VoxSchema::MODE_GOVERNED_EXECUTE,
                'destination' => 'terminal_proposal',
                'summary' => 'Proponho o comando — você decide se executa.',
                'risk_class' => VoxSchema::RISK_R3,
                'requires_confirmation' => true,
            ];
        }

        // 3. Verbo de criação/envio de prompt p/ IA.
        $hasCreatePromptVerb = preg_match('/\b(?:cria|crie|criar|monta|monte|montar|faz|faça|fazer|gera|gere|gerar|prepara|prepare|preparar)\b/u', $seg) === 1;
        $hasSendVerb = preg_match('/\b(?:manda|mande|mandar|envia|envie|enviar|pergunta|pergunte)\b/u', $seg) === 1;
        $mentionsCodex = preg_match('/\bcodex\b/u', $seg) === 1;
        $mentionsClaude = preg_match('/\bclaude\b/u', $seg) === 1;
        $mentionsPrompt = preg_match('/\bprompt\b/u', $seg) === 1;
        if (($hasCreatePromptVerb && $mentionsPrompt)
            || ($hasSendVerb && ($mentionsCodex || $mentionsClaude))
        ) {
            $destination = match (true) {
                $mentionsCodex => 'codex',
                $mentionsClaude => 'claude',
                default => 'atlas',
            };
            $summary = match ($destination) {
                'codex' => 'Vou preparar o prompt pra você colar no Codex.',
                'claude' => 'Vou preparar o prompt pra você colar no Claude.',
                default => 'Vou preparar o prompt aqui mesmo no Atlas.',
            };

            return [
                'order' => $order,
                'mode' => VoxSchema::MODE_INTENT_COMPILE,
                'destination' => $destination,
                'summary' => $summary,
                'risk_class' => VoxSchema::RISK_R0,
                'requires_confirmation' => false,
            ];
        }

        // 4. Verbo de polish/melhorar.
        if (preg_match('/\b(?:melhora|melhore|melhorar|organiza|organize|polir|reescreve|reescreva|limpa|limpe|arruma|arrume|corrige|corrija|deixa|deixe)\b/u', $seg) === 1
            && preg_match('/\b(?:texto|frase|isso|isto|aquilo|parágrafo|paragrafo|mensagem|mensage|resumo|email)\b/u', $seg) === 1
        ) {
            return [
                'order' => $order,
                'mode' => VoxSchema::MODE_PROMPT_POLISH,
                'destination' => 'clipboard',
                'summary' => 'Vou polir o texto e devolver pra você revisar e colar.',
                'risk_class' => VoxSchema::RISK_R0,
                'requires_confirmation' => false,
            ];
        }
        // V6.5 · "faz um resumo" puro também cai em polish/clipboard.
        if (preg_match('/\b(?:faz|faça|fazer|gera|gere|gerar)\s+(?:um\s+)?(?:resumo|sum[áa]rio|s[íi]ntese)\b/u', $seg) === 1) {
            return [
                'order' => $order,
                'mode' => VoxSchema::MODE_PROMPT_POLISH,
                'destination' => 'clipboard',
                'summary' => 'Vou gerar o resumo e devolver pra você revisar.',
                'risk_class' => VoxSchema::RISK_R0,
                'requires_confirmation' => false,
            ];
        }

        // 5. Captura como nota.
        if (preg_match('/\b(?:salva|salve|salvar|guarda|guarde|guardar|joga|jogue|jogar|registra|registre|registrar|anota|anote|anotar|cria|crie|criar)\b[^\n]{0,40}\b(?:nota|notas|inbox|caderno|registro)\b/u', $seg) === 1) {
            return [
                'order' => $order,
                'mode' => VoxSchema::MODE_DICTATION,
                'destination' => 'note',
                'summary' => 'Vou salvar como nota no Atlas Inbox.',
                'risk_class' => VoxSchema::RISK_R0,
                'requires_confirmation' => false,
            ];
        }

        // 6. Análise / leitura sem execução (R1).
        if (preg_match('/\b(?:analisa|analise|analisar|investiga|investigue|investigar|olha|olhe|olhar|revisa|revise|revisar|verifica|verifique)\b/u', $seg) === 1) {
            return [
                'order' => $order,
                'mode' => VoxSchema::MODE_INTENT_COMPILE,
                'destination' => 'atlas',
                'summary' => 'Vou montar uma análise read-only — sem editar nada.',
                'risk_class' => VoxSchema::RISK_R1,
                'requires_confirmation' => false,
            ];
        }

        // 7. Copia (destino terminal/clipboard sem execução).
        if (preg_match('/\b(?:copia|copie|copiar|insere|insira|inserir)\b/u', $seg) === 1) {
            return [
                'order' => $order,
                'mode' => VoxSchema::MODE_DICTATION,
                'destination' => 'clipboard',
                'summary' => 'Vou colocar o conteúdo no clipboard pra você usar.',
                'risk_class' => VoxSchema::RISK_R0,
                'requires_confirmation' => false,
            ];
        }

        // 8. Default: ditado simples — caminho seguro pra segmento que escapou
        //    da heurística. Sem inventar destino exótico.
        return [
            'order' => $order,
            'mode' => VoxSchema::MODE_DICTATION,
            'destination' => 'clipboard',
            'summary' => 'Vou tratar como ditado livre no destino atual.',
            'risk_class' => VoxSchema::RISK_R0,
            'requires_confirmation' => false,
        ];
    }

    /** Helper: detecta marcador R4 num segmento já normalizado. */
    private function detectSegmentR4Marker(string $seg): ?string
    {
        $patterns = [
            'rm_rf' => '/\brm\s+-[a-z]*r[a-z]*f[a-z]*\b/iu',
            'sudo' => '/\bsudo\b/iu',
            'dd_if' => '/\bdd\s+if=/iu',
            'mkfs' => '/\bmkfs\b/iu',
            'git_push_force' => '/\bgit\s+push\s+(?:--force|-f|--force-with-lease)\b/iu',
            'git_reset_hard' => '/\bgit\s+reset\s+--hard\b/iu',
            'drop_database' => '/\bdrop\s+database\b/iu',
            'truncate_table' => '/\btruncate(?:\s+table)?\b/iu',
            'curl_pipe_shell' => '/\bcurl\b[^\n]*\|\s*(?:sh|bash|zsh|fish)\b/iu',
            'wget_pipe_shell' => '/\bwget\b[^\n]*\|\s*(?:sh|bash|zsh|fish)\b/iu',
            'apagar_tudo' => '/\bapag(?:a|ar)\s+tudo\b/iu',
            'deletar_projeto' => '/\bdelet(?:a|ar)\s+(?:o\s+)?projeto\b/iu',
            'force_push' => '/\bforce[\s\-]push\b/iu',
        ];
        foreach ($patterns as $label => $re) {
            if (preg_match($re, $seg) === 1) {
                return $label;
            }
        }

        return null;
    }

    /**
     * Constrói o step único usado quando o splitter não detectou múltiplas
     * intenções. Reusa os destinos/risk já calculados pelo orchestrator
     * principal pra zero divergência.
     *
     * @return array{order:int,mode:string,destination:string,summary:string,risk_class:string,requires_confirmation:bool}
     */
    private function buildSingleStep(
        string $segment,
        string $singleMode,
        string $singleDestination,
        string $singleRisk,
        string $provider,
        string $executor,
        string $outputFormat,
        ?string $r4Marker,
    ): array {
        $summary = match (true) {
            $r4Marker !== null || $singleRisk === VoxSchema::RISK_R4 =>
                'Ação destrutiva detectada — preciso de confirmação literal antes de qualquer efeito.',
            $singleMode === VoxSchema::MODE_GOVERNED_EXECUTE && $singleDestination === 'terminal_proposal' =>
                'Proponho o comando — você decide se executa.',
            $singleMode === VoxSchema::MODE_INTENT_COMPILE && $singleDestination === 'codex' =>
                'Vou preparar o prompt pra você colar no Codex.',
            $singleMode === VoxSchema::MODE_INTENT_COMPILE && $singleDestination === 'claude' =>
                'Vou preparar o prompt pra você colar no Claude.',
            $singleMode === VoxSchema::MODE_INTENT_COMPILE =>
                'Vou preparar o prompt aqui mesmo no Atlas.',
            $singleMode === VoxSchema::MODE_PROMPT_POLISH =>
                'Vou polir o texto e devolver pra você revisar.',
            $singleMode === VoxSchema::MODE_DICTATION && $singleDestination === 'note' =>
                'Vou salvar como nota no Atlas Inbox.',
            $singleMode === VoxSchema::MODE_DICTATION =>
                'Vou tratar como ditado no destino atual (clipboard/composer).',
            default => 'Vou seguir o caminho mais seguro pra essa fala.',
        };
        $requires = $singleRisk === VoxSchema::RISK_R4
            || $singleRisk === VoxSchema::RISK_R3
            || $singleRisk === VoxSchema::RISK_R2
            || $singleMode === VoxSchema::MODE_GOVERNED_EXECUTE;
        unset($segment, $provider, $executor, $outputFormat);

        return [
            'order' => 1,
            'mode' => $singleMode,
            'destination' => $singleDestination,
            'summary' => $summary,
            'risk_class' => $singleRisk,
            'requires_confirmation' => $requires,
        ];
    }

    /**
     * @param  array{order:int,mode:string,destination:string,summary:string,risk_class:string,requires_confirmation:bool}  $step
     */
    private function stepInvolvesExecution(array $step): bool
    {
        return $step['mode'] === VoxSchema::MODE_GOVERNED_EXECUTE;
    }

    /**
     * @param  list<array{order:int,mode:string,destination:string,summary:string,risk_class:string,requires_confirmation:bool}>  $steps
     */
    private function stepsAreAllSafe(array $steps): bool
    {
        foreach ($steps as $step) {
            $r = $step['risk_class'];
            if ($r !== VoxSchema::RISK_R0 && $r !== VoxSchema::RISK_R1) {
                return false;
            }
        }

        return true;
    }

    private function compositePolicy(
        bool $hasR4,
        bool $hasExecution,
        bool $hasNonExecution,
        bool $allSafe,
    ): string {
        if ($hasR4) {
            return VoxSchema::COMPOSITE_POLICY_PREVIEW_ONLY;
        }
        if ($hasExecution && $hasNonExecution) {
            return VoxSchema::COMPOSITE_POLICY_STEP_BY_STEP;
        }
        if ($hasExecution) {
            return VoxSchema::COMPOSITE_POLICY_STEP_BY_STEP;
        }
        if ($allSafe) {
            return VoxSchema::COMPOSITE_POLICY_SINGLE_SAFE_STEP;
        }

        return VoxSchema::COMPOSITE_POLICY_STEP_BY_STEP;
    }

    private function defaultPolicyFor(bool $isComposite, string $risk, bool $hasExecution): string
    {
        if ($risk === VoxSchema::RISK_R4) {
            return VoxSchema::COMPOSITE_POLICY_PREVIEW_ONLY;
        }
        if ($hasExecution) {
            return VoxSchema::COMPOSITE_POLICY_STEP_BY_STEP;
        }
        unset($isComposite);

        return VoxSchema::COMPOSITE_POLICY_SINGLE_SAFE_STEP;
    }

    private function providerLabel(string $provider): string
    {
        return match ($provider) {
            'codex_cli' => 'Codex',
            'claude_cli' => 'Claude',
            'local' => 'Atlas local',
            'auto' => 'Atlas',
            default => 'Atlas',
        };
    }

    private function humaniseR4Marker(string $marker): string
    {
        return match ($marker) {
            'rm_rf' => 'rm -rf',
            'sudo' => 'sudo',
            'dd_if' => 'dd if=',
            'mkfs' => 'mkfs',
            'git_push_force' => 'git push --force',
            'git_reset_hard' => 'git reset --hard',
            'drop_database' => 'drop database',
            'truncate_table' => 'truncate table',
            'curl_pipe_shell' => 'curl | sh',
            'wget_pipe_shell' => 'wget | sh',
            'apagar_tudo' => 'apagar tudo',
            'deletar_projeto' => 'deletar projeto',
            'deploy' => 'deploy automático',
            'force_push' => 'force push',
            default => str_replace('_', ' ', $marker),
        };
    }
}
