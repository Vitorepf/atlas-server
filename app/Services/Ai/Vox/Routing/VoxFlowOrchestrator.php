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
    private ?VoxCompositeStepSegmenter $compositeStepSegmenterInstance = null;

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
        return $this->compositeStepSegmenter()->split(
            $text,
            $normalized,
            $singleMode,
            $singleDestination,
            $singleRisk,
            $provider,
            $executor,
            $outputFormat,
            $r4Marker,
            $needsClarification,
        );
    }

    private function compositeStepSegmenter(): VoxCompositeStepSegmenter
    {
        return $this->compositeStepSegmenterInstance ??= new VoxCompositeStepSegmenter(
            compositePolicyResolver: function (bool $hasR4, bool $hasExecution, bool $hasNonExecution, bool $allSafe): string {
                return $this->compositePolicy($hasR4, $hasExecution, $hasNonExecution, $allSafe);
            },
            defaultPolicyResolver: function (bool $isComposite, string $risk, bool $hasExecution): string {
                return $this->defaultPolicyFor($isComposite, $risk, $hasExecution);
            },
            humaniseR4MarkerResolver: function (string $marker): string {
                return $this->humaniseR4Marker($marker);
            },
        );
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
        return $this->compositeStepSegmenter()->segmentByConnectors($text);
    }

    /** Detecta verbo de intenção mínima num segmento (qualquer mode). */
    private function segmentHasIntentVerb(string $seg): bool
    {
        return $this->compositeStepSegmenter()->segmentHasIntentVerb($seg);
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
        return $this->compositeStepSegmenter()->classifySegmentAsStep($segment, $order);
    }

    /** Helper: detecta marcador R4 num segmento já normalizado. */
    private function detectSegmentR4Marker(string $seg): ?string
    {
        return $this->compositeStepSegmenter()->detectSegmentR4Marker($seg);
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
        return $this->compositeStepSegmenter()->buildSingleStep(
            $segment,
            $singleMode,
            $singleDestination,
            $singleRisk,
            $provider,
            $executor,
            $outputFormat,
            $r4Marker,
        );
    }

    /**
     * @param  array{order:int,mode:string,destination:string,summary:string,risk_class:string,requires_confirmation:bool}  $step
     */
    private function stepInvolvesExecution(array $step): bool
    {
        return $this->compositeStepSegmenter()->stepInvolvesExecution($step);
    }

    /**
     * @param  list<array{order:int,mode:string,destination:string,summary:string,risk_class:string,requires_confirmation:bool}>  $steps
     */
    private function stepsAreAllSafe(array $steps): bool
    {
        return $this->compositeStepSegmenter()->stepsAreAllSafe($steps);
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
