<?php

declare(strict_types=1);

namespace App\Services\Ai\Vox\Routing;

use App\Services\Ai\Vox\VoxSchema;
use Closure;

/**
 * COMPOSITE-STEP SEGMENTATION concern, extracted from the god-class
 * {@see VoxFlowOrchestrator}.
 *
 * Owns the splitComposite/segmentByConnectors/segmentHasIntentVerb/classifySegmentAsStep/detectSegmentR4Marker/
 * buildSingleStep/stepInvolvesExecution/stepsAreAllSafe pipeline that turns a normalized
 * Vox utterance into a 1-or-N step plan with an execution policy.
 *
 * The orchestrator keeps compositePolicy/defaultPolicyFor/humaniseR4Marker as thin helpers
 * that this collaborator resolves via closures — same closure-binding pattern as
 * AtlasLoopRefillerSupplyLaneCoordinator / AtlasLoopCampaignProviderHealthRecorder.
 */
class VoxCompositeStepSegmenter
{
    /**
     * @param  Closure(bool,bool,bool,bool): string  $compositePolicyResolver  Picks policy from {hasR4,hasExecution,hasNonExecution,allSafe}.
     * @param  Closure(bool,string,bool): string  $defaultPolicyResolver  Picks default policy for a non-composite step.
     * @param  Closure(string): string  $humaniseR4MarkerResolver  Maps an R4 marker code to a human label.
     */
    public function __construct(
        private readonly Closure $compositePolicyResolver,
        private readonly Closure $defaultPolicyResolver,
        private readonly Closure $humaniseR4MarkerResolver,
    ) {}

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
                : ($this->defaultPolicyResolver)(
                    false,
                    $singleRisk,
                    $this->stepInvolvesExecution($step),
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

        $policy = ($this->compositePolicyResolver)(
            $hasR4,
            $hasExecution,
            $hasNonExecution,
            $this->stepsAreAllSafe($steps),
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
    public function segmentByConnectors(string $text): array
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
    public function segmentHasIntentVerb(string $seg): bool
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
    public function classifySegmentAsStep(string $segment, int $order): array
    {
        $seg = mb_strtolower($segment);

        // 1. Marcador destrutivo (R4) — vence tudo.
        $r4 = $this->detectSegmentR4Marker($seg);
        if ($r4 !== null) {
            $human = ($this->humaniseR4MarkerResolver)($r4);

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
    public function detectSegmentR4Marker(string $seg): ?string
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
    public function buildSingleStep(
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
    public function stepInvolvesExecution(array $step): bool
    {
        return $step['mode'] === VoxSchema::MODE_GOVERNED_EXECUTE;
    }

    /**
     * @param  list<array{order:int,mode:string,destination:string,summary:string,risk_class:string,requires_confirmation:bool}>  $steps
     */
    public function stepsAreAllSafe(array $steps): bool
    {
        foreach ($steps as $step) {
            $r = $step['risk_class'];
            if ($r !== VoxSchema::RISK_R0 && $r !== VoxSchema::RISK_R1) {
                return false;
            }
        }

        return true;
    }

    /**
     * Public-facing entry point used by the orchestrator delegator.
     *
     * @return array{is_composite:bool,steps:list<array{order:int,mode:string,destination:string,summary:string,risk_class:string,requires_confirmation:bool}>,execution_policy:string,recommended_next_step:int}
     */
    public function split(
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
        return $this->splitComposite($text, $normalized, $singleMode, $singleDestination, $singleRisk, $provider, $executor, $outputFormat, $r4Marker, $needsClarification);
    }
}
