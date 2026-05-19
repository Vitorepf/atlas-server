<?php

declare(strict_types=1);

namespace App\Services\Ai\Vox;

/**
 * Compiles the heavy `compiled_prompt` for Atlas Vox V2 (intent_compile).
 *
 * Deterministic, no LLM, no provider call. Composes per-provider headers,
 * an objective block, a working-mode block, a constraints block, the
 * available context (or its explicit absence), the expected output, and
 * — when the risk class warrants it — a safety block.
 *
 * The output is meant to be copy-pasted into a fresh Codex or Claude
 * session by the operator. Vox itself NEVER calls a provider; the
 * compiled prompt is operational text, not an execution request.
 *
 * Template ids follow the canonical naming pattern documented in
 * `docs/contracts/vox/VoxIntentPacket.v1.md`:
 *   builtin.intent_compile.<provider>.<output_format>.pt-br@0.1.0
 *
 * Tests pin against the canonical template id strings.
 */
final class VoxPromptCompiler
{
    public const TEMPLATE_VERSION = '0.2.0';

    /**
     * V6.5-PROMPT-SELF-CRITIC · self-critic determinístico aplicado a cada
     * `compile()`. Nullable para preservar back-compat com `new VoxPromptCompiler()`
     * usado em testes e CLI tools antigos.
     */
    private VoxPromptSelfCritic $critic;

    public function __construct(?VoxPromptSelfCritic $critic = null)
    {
        $this->critic = $critic ?? new VoxPromptSelfCritic();
    }

    /**
     * V6-ES-C · seções canônicas presentes em todo `compiled_prompt` de
     * `intent_compile`. A certificação V6 confere que cada uma aparece
     * com header `## …`. Mudar nomes aqui quebra o cert intencionalmente.
     *
     * @var list<string>
     */
    public const CANONICAL_SECTIONS = [
        '## Contexto',
        '## Objetivo',
        '## Modo de trabalho',
        '## Contexto disponível',
        '## Saída esperada',
        '## Critérios de qualidade',
        '## O que NÃO fazer',
        '## Voz original',
    ];

    /**
     * @param  array{
     *   goal: string,
     *   constraints: list<string>,
     *   provider_hint: string,
     *   executor_hint: string,
     *   output_format: string,
     *   context_refs: list<array{kind: string, ref: ?string, resolved: bool}>,
     *   risk_class: string,
     *   risk_markers: list<string>,
     *   normalised_text: string,
     * }  $extracted
     * @return array{compiled_prompt: string, compiled_prompt_template: string, sections: list<string>}
     */
    public function compile(string $rawTranscript, array $extracted): array
    {
        $provider = $extracted['provider_hint'];
        $outputFormat = $extracted['output_format'];

        $sections = [];

        $sections[] = $this->header($provider);
        $sections[] = $this->objective($extracted['goal'], $rawTranscript);
        $sections[] = $this->workingMode($provider, $outputFormat, $extracted['executor_hint']);

        $constraintsBlock = $this->constraints($extracted['constraints'], $extracted['risk_class']);
        if ($constraintsBlock !== '') {
            $sections[] = $constraintsBlock;
        }

        $sections[] = $this->contextBlock($extracted['context_refs'], $rawTranscript);
        $sections[] = $this->expectedOutput($outputFormat, $extracted['executor_hint']);
        $sections[] = $this->qualityCriteria($outputFormat, $extracted);
        $sections[] = $this->doNotBlock(
            constraints: $extracted['constraints'],
            outputFormat: $outputFormat,
            riskClass: $extracted['risk_class'],
            executorHint: $extracted['executor_hint'],
        );

        if (in_array($extracted['risk_class'], [VoxSchema::RISK_R3, VoxSchema::RISK_R4], true)) {
            $sections[] = $this->safetyBlock(
                riskClass: $extracted['risk_class'],
                riskMarkers: $extracted['risk_markers'],
            );
        }

        $sections[] = $this->humanInputFootnote($rawTranscript);

        $compiled = trim(implode("\n\n", array_filter($sections, static fn ($s) => $s !== '')));
        $template = $this->templateId($provider, $outputFormat);

        $result = [
            'compiled_prompt' => $compiled,
            'compiled_prompt_template' => $template,
            'sections' => array_keys($this->labelMap()),
        ];

        // V6-FPG-B · self-check determinístico embutido. Não falha o compile,
        // só anexa um diagnóstico que telemetria/cert podem consumir.
        // V6-PCF · também envia provider_hint para validar consistência
        // entre o que o extractor escolheu e o que o template id codifica.
        $result['quality_self_check'] = $this->selfCheck($rawTranscript, $result, [
            'goal' => $extracted['goal'] ?? '',
            'constraints' => $extracted['constraints'] ?? [],
            'output_format' => $outputFormat,
            'risk_class' => $extracted['risk_class'] ?? 'R0',
            'provider_hint' => $provider,
        ]);

        // V6.5-PROMPT-SELF-CRITIC · envelope canônico `atlas.vox.prompt_quality.v1`.
        // O critic pode patchar `compiled_prompt` se houver issues simples
        // (boilerplate vazado, voz original ausente, vetos universais
        // perdidos). Quando há issue grave (negação perdida, risco
        // suavizado, ação não autorizada), `needs_review=true` para o
        // operador investigar — o compile NÃO falha.
        $quality = $this->critic->review($rawTranscript, $result, [
            'goal' => $extracted['goal'] ?? '',
            'constraints' => $extracted['constraints'] ?? [],
            'output_format' => $outputFormat,
            'risk_class' => $extracted['risk_class'] ?? 'R0',
            'provider_hint' => $provider,
        ]);

        // Se o critic patchou o prompt, adotamos a versão reparada — o
        // result devolvido carrega o prompt limpo, sem boilerplate e com
        // os blocos canônicos restaurados.
        if (($quality['repaired'] ?? false) === true) {
            $result['compiled_prompt'] = (string) ($quality['compiled_prompt'] ?? $result['compiled_prompt']);
        }
        $result['prompt_quality'] = $quality;

        return $result;
    }

    /**
     * @return array<string,string>
     */
    private function labelMap(): array
    {
        return [
            'header' => 'Contexto',
            'objective' => 'Objetivo',
            'working_mode' => 'Modo de trabalho',
            'constraints' => 'Restrições',
            'context' => 'Contexto disponível',
            'expected_output' => 'Saída esperada',
            'quality' => 'Critérios de qualidade',
            'do_not' => 'O que NÃO fazer',
            'safety' => 'Segurança',
            'human_input' => 'Voz original',
        ];
    }

    private function header(string $provider): string
    {
        switch ($provider) {
            case 'codex_cli':
                return "## Contexto\nVocê é o Codex operando no workspace do projeto Atlas. Trabalhe local-first, com fidelidade ao código existente. Leia antes de propor; use ferramentas como `rg`/`rg --files` para localizar antes de editar. Não invente arquivos nem dependências.";
            case 'claude_cli':
                return "## Contexto\nVocê é o Claude Code atuando no projeto Atlas como implementador/arquiteto. Trabalhe local-first. Se estiver em plan mode, produza um plano decision-complete antes de implementar. Se implementar, mantenha escopo mínimo e teste o que mudou.";
            case 'atlas':
                // V6-FPG-B · destinatário interno do Atlas (Atlas Dev / Atlas AI
                // / Kernel). V6-PCF · explicita governança (pipeline → receipt
                // → confirmation → evidence) que o Atlas owna.
                return "## Contexto\nVocê é o próprio Atlas (Atlas Dev / Atlas AI / Kernel local). Responda em PT-BR direto, fundamentado no canon vivo do repo: services em `app/Services/Ai/`, contracts em `docs/contracts/`, ADRs em `docs/architecture/`. Respeite a governança Atlas: pipeline → receipt → confirmation → evidence ledger são canônicos — não invente passo, não bypasse confirmação humana. Nunca chame provider externo, nunca cite memória longitudinal (V7 segue bloqueada). Toda afirmação sobre o sistema cita arquivo/serviço concreto.";
            case 'auto':
                return "## Contexto\nVocê pode ser Codex ou Claude operando no projeto Atlas; escolha o estilo que melhor se encaixa neste pedido, mas trabalhe local-first, sem provider pago e sem dependência nova.";
            case 'local':
            default:
                return "## Contexto\nResponda diretamente, sem assumir um executor externo. O destino é texto local (clipboard/campo focado do Atlas Desktop). Não invente provider; não chame API.";
        }
    }

    private function objective(string $goal, string $rawTranscript): string
    {
        $goal = trim($goal);
        if ($goal === '') {
            $goal = $this->trimForGoal($rawTranscript);
        }
        if ($goal === '') {
            $goal = '(objetivo não declarado explicitamente; ver "Voz original" abaixo)';
        }

        return "## Objetivo\n".$goal;
    }

    private function trimForGoal(string $rawTranscript): string
    {
        $first = trim((string) preg_split('/[\.!?]/u', $rawTranscript, 2)[0]);
        if (mb_strlen($first) > 200) {
            $first = mb_substr($first, 0, 200).'…';
        }

        return $first;
    }

    private function workingMode(string $provider, string $outputFormat, string $executorHint): string
    {
        $lines = ['## Modo de trabalho'];
        switch ($provider) {
            case 'codex_cli':
                $lines[] = '1. Antes de qualquer mudança, leia o código relevante (use `rg`).';
                $lines[] = '2. Não edite arquivos se o pedido for análise/diagnóstico.';
                $lines[] = '3. Se editar, mantenha escopo mínimo e respeite as restrições abaixo.';
                $lines[] = '4. Ao terminar, resuma: arquivos tocados, testes rodados, riscos identificados.';
                break;
            case 'claude_cli':
                $lines[] = '1. Se estiver em plan mode, entregue um plano decision-complete antes de tocar arquivo.';
                $lines[] = '2. Se for implementação, faça a menor mudança que resolve, com teste e justificativa.';
                $lines[] = '3. Não extrapole o escopo do pedido; sinalize abertamente se algo precisar de decisão.';
                $lines[] = '4. Ao terminar, mostre o diff conceitual + riscos + próximo passo.';
                break;
            case 'auto':
                $lines[] = '1. Identifique o executor mais adequado (Codex para investigação ampla, Claude para plano/decisão).';
                $lines[] = '2. Trabalhe sempre local-first; nada de provider pago, nada de dependência nova.';
                $lines[] = '3. Se houver dúvida sobre escopo, pergunte antes de agir.';
                break;
            case 'atlas':
                // V6-FPG-B · destinatário Atlas. Cita docs/contracts ao invés
                // de inventar conceito; nunca aciona V7; mantém PT-BR.
                // V6-PCF · governança explícita: pipeline/receipt/evidence.
                $lines[] = '1. Use canon vivo do repo: docs/contracts/vox/*, docs/architecture/*, services em app/Services/Ai/Vox.';
                $lines[] = '2. Respeite o pipeline Atlas: intent → receipt → confirmation → evidence ledger. Não pule etapa, não bypasse confirmação humana.';
                $lines[] = '3. Resposta em PT-BR direto, sem termo de marketing, sem hype.';
                $lines[] = '4. Se uma decisão precisa de ADR nova, marque explicitamente — V7/memória longitudinal segue bloqueada.';
                $lines[] = '5. Cite arquivo/serviço concreto sempre que afirmar algo sobre o sistema.';
                break;
            case 'local':
            default:
                $lines[] = '1. Produza diretamente a saída pedida (texto/nota/plano).';
                $lines[] = '2. Não invoque ferramentas externas; isso é resposta local.';
                $lines[] = '3. Se faltar informação, declare explicitamente em vez de inventar.';
                break;
        }

        switch ($outputFormat) {
            case 'diagnostic':
                $lines[] = '- Entregue diagnóstico, não correção. Suposições devem ser explícitas.';
                break;
            case 'plan':
                $lines[] = '- Entregue plano numerado, com pré-requisitos, passos e critério de pronto.';
                break;
            case 'diff':
                $lines[] = '- Quando alterar código, produza um diff claro e mínimo.';
                break;
            case 'notes':
                $lines[] = '- Foco em capturar nota/inbox curta e fiel ao que foi dito.';
                break;
            case 'text':
            default:
                $lines[] = '- Resposta em texto direto, sem boilerplate.';
                break;
        }

        if ($executorHint === 'terminal_propose') {
            $lines[] = '- Se sugerir comando de terminal, mostre o comando exato e justifique antes de qualquer execução; nunca execute sem confirmação humana.';
        }
        if ($executorHint === 'edit') {
            $lines[] = '- Edições devem citar caminho, função e razão; nada de mudanças amplas silenciosas.';
        }
        if ($executorHint === 'note') {
            $lines[] = '- Produza apenas o conteúdo da nota; sem comentários adicionais.';
        }

        return implode("\n", $lines);
    }

    /**
     * @param  list<string>  $constraints
     */
    private function constraints(array $constraints, string $riskClass): string
    {
        $items = array_values(array_unique(array_filter(array_map(
            static fn ($c) => trim((string) $c),
            $constraints,
        ), static fn ($c) => $c !== '')));

        if ($items === []) {
            return '';
        }

        $lines = ['## Restrições'];
        foreach ($items as $clause) {
            $lines[] = '- '.$clause;
        }
        $lines[] = '- Restrições acima são literais; não as reinterprete nem amplie.';
        if ($riskClass === VoxSchema::RISK_R2 || $riskClass === VoxSchema::RISK_R3 || $riskClass === VoxSchema::RISK_R4) {
            $lines[] = '- Se uma restrição entrar em conflito com a tarefa, pare e peça confirmação humana.';
        }

        return implode("\n", $lines);
    }

    /**
     * @param  list<array{kind: string, ref: ?string, resolved: bool}>  $contextRefs
     */
    private function contextBlock(array $contextRefs, string $rawTranscript = ''): string
    {
        $resolved = array_values(array_filter(
            $contextRefs,
            static fn ($r) => ($r['kind'] ?? 'none') !== 'none',
        ));

        $voiceClues = $this->extractVoiceContextClues($rawTranscript);

        if ($resolved === [] && $voiceClues === []) {
            return "## Contexto disponível\nNenhum contexto adicional foi anexado pelo Atlas Desktop nesta sessão. Se precisar de um arquivo, peça explicitamente ao operador antes de assumir.";
        }

        $lines = ['## Contexto disponível'];
        foreach ($resolved as $ref) {
            $kind = (string) ($ref['kind'] ?? 'none');
            $refStr = $ref['ref'] ?? null;
            $isResolved = (bool) ($ref['resolved'] ?? false);
            $label = $refStr === null ? '(sem ref)' : $refStr;
            $status = $isResolved ? 'resolvido' : 'NÃO resolvido — confirmar com operador';
            $lines[] = "- [{$kind}] {$label} — {$status}";
        }
        foreach ($voiceClues as $clue) {
            $lines[] = '- [voz] '.$clue;
        }

        return implode("\n", $lines);
    }

    /**
     * V6-ES-C · varre a voz original procurando pistas de contexto que o
     * operador deu sem anexar arquivo: "no projeto X", "no arquivo Y",
     * "no terminal", "já tentei Z", "porque W". Limita a 6 itens, preserva
     * frases curtas verbatim. Honesto: se nada bater, devolve [].
     *
     * @return list<string>
     */
    private function extractVoiceContextClues(string $rawTranscript): array
    {
        $raw = trim((string) preg_replace('/\s+/u', ' ', $rawTranscript));
        if ($raw === '') {
            return [];
        }
        $patterns = [
            // "no arquivo …", "no módulo …", "no projeto …", "na pasta …"
            '/(?<![\p{L}\p{N}_])(n[oa]\s+(?:arquivo|m[oó]dulo|projeto|pasta|diret[oó]rio|componente|servi[çc]o|controller|repo|reposit[óo]rio)\s+[\p{L}\p{N}_\-\.\/]{2,80})/iu',
            // "no terminal", "no inbox", "no overlay", "na surface …"
            '/(?<![\p{L}\p{N}_])(n[oa]\s+(?:terminal|inbox|overlay|surface|kernel|forge|c[óo]digo|cli)\b[\p{L}\p{N}_\s\-]{0,60})/iu',
            // "já tentei …", "tentei …", "tinha feito …"
            '/(?<![\p{L}\p{N}_])(j[áa]\s+tentei\s+[\p{L}\p{N}_\s\-]{2,80})/iu',
            // "porque …" / "por causa de …" — limita 90 chars
            '/(?<![\p{L}\p{N}_])(porque\s+[\p{L}\p{N}_\s\-,]{3,90})/iu',
            '/(?<![\p{L}\p{N}_])(por\s+causa\s+de\s+[\p{L}\p{N}_\s\-,]{3,90})/iu',
            // "quando …" causal/condicional
            '/(?<![\p{L}\p{N}_])(quando\s+[\p{L}\p{N}_\s\-,]{3,80})/iu',
        ];
        $clues = [];
        foreach ($patterns as $pat) {
            if (preg_match_all($pat, $raw, $matches) !== false) {
                foreach ($matches[1] ?? [] as $hit) {
                    $clean = trim((string) preg_replace('/\s+/u', ' ', (string) $hit));
                    // boundary cleanup — corta no próximo conector forte
                    $clean = (string) preg_split('/\s+(?:e|mas|porém|porem|então|entao)\s+/u', $clean, 2)[0];
                    $clean = rtrim($clean, " .,;");
                    if ($clean === '' || mb_strlen($clean) < 5) {
                        continue;
                    }
                    if (! in_array($clean, $clues, true)) {
                        $clues[] = $clean;
                    }
                }
            }
            if (count($clues) >= 6) {
                break;
            }
        }
        return array_slice($clues, 0, 6);
    }

    private function expectedOutput(string $outputFormat, string $executorHint = 'none'): string
    {
        $base = match ($outputFormat) {
            'diagnostic' => "## Saída esperada\n- Resumo do que foi observado.\n- Arquivos relevantes (caminhos absolutos quando possível).\n- Hipóteses de causa, ordenadas por probabilidade.\n- Riscos e perguntas em aberto.\n- Recomendação de próximo passo — sem implementar.",
            'plan' => "## Saída esperada\n- Pré-requisitos.\n- Passos numerados com critério de pronto por passo.\n- Riscos e mitigações.\n- Critério final de aceitação.\n- O que NÃO está incluído no plano.",
            'diff' => "## Saída esperada\n- Diff (ou descrição do diff) com arquivo, função e razão por mudança.\n- Lista de arquivos tocados.\n- Comandos de teste recomendados (PHPUnit/cargo/vitest) com o caminho exato pra rodar.\n- Riscos da mudança.",
            'notes' => "## Saída esperada\n- Nota concisa em PT-BR, primeira pessoa quando aplicável.\n- Sem comentário adicional fora da nota.\n- Sem links ou citações inventadas.",
            'command_proposal' => "## Saída esperada\n- Comando exato proposto, em bloco de código.\n- Justificativa em uma frase.\n- Plano de rollback (como desfazer se rodar e der ruim).\n- Marque claramente: aguarda confirmação humana antes de qualquer execução.",
            default => "## Saída esperada\n- Texto direto em PT-BR.\n- Sem boilerplate, sem floreio.\n- Se uma resposta exigir suposição, marque claramente como tal.",
        };
        if ($executorHint === 'terminal_propose' && $outputFormat !== 'command_proposal') {
            $base .= "\n- Se a saída sugerir comando, mostre o comando exato em bloco e marque que aguarda confirmação humana — nada é executado automaticamente.";
        }
        return $base;
    }

    /**
     * V6-ES-C · "Critérios de qualidade" — torna o prompt acionável para o
     * destinatário (Codex/Claude/Atlas) avaliar a própria saída antes de
     * entregar. Adiciona barra mínima de qualidade sem virar prosa
     * filosófica.
     *
     * @param  array<string,mixed>  $extracted
     */
    private function qualityCriteria(string $outputFormat, array $extracted): string
    {
        $lines = ['## Critérios de qualidade'];
        $lines[] = '- A resposta resolve o objetivo declarado, não um parecido.';
        $lines[] = '- Decisões assumidas aparecem explícitas, marcadas como suposição.';
        $lines[] = '- Nenhuma restrição da seção acima é reinterpretada para suavizar o pedido.';
        if (! empty($extracted['constraints'])) {
            $lines[] = '- As restrições da voz original são citadas na resposta (ou explicado por que não se aplicam).';
        }
        switch ($outputFormat) {
            case 'diff':
                $lines[] = '- O diff cita arquivo, função e razão; nada de mudança ampla silenciosa.';
                $lines[] = '- Testes recomendados rodam localmente (sem rede, sem provider pago).';
                break;
            case 'plan':
                $lines[] = '- Cada passo do plano tem critério de pronto verificável.';
                $lines[] = '- O plano declara o que NÃO está incluído, evitando creep.';
                break;
            case 'diagnostic':
                $lines[] = '- Hipóteses vêm ordenadas por probabilidade, não por preferência.';
                $lines[] = '- Cada hipótese cita evidência observável (arquivo, log, comportamento).';
                break;
            case 'command_proposal':
                $lines[] = '- Comando é determinístico e idempotente quando possível.';
                $lines[] = '- Existe um caminho de rollback documentado.';
                break;
            case 'notes':
                $lines[] = '- Nota cabe em uma tela; sem padding.';
                break;
            case 'text':
            default:
                $lines[] = '- Texto fica no tamanho mínimo que ainda responde o pedido.';
                break;
        }
        if (($extracted['executor_hint'] ?? 'none') === 'terminal_propose') {
            $lines[] = '- Qualquer comando proposto exige confirmação humana antes de rodar.';
        }
        return implode("\n", $lines);
    }

    /**
     * V6-ES-C · "O que NÃO fazer" — replica restrições da voz como veto
     * positivo + amarra invariantes Atlas (nada de áudio bruto, nada de
     * provider pago, nada de execução automática). Mantém o destinatário
     * dentro do trilho mesmo que ele "queira ajudar mais".
     *
     * @param  list<string>  $constraints
     */
    private function doNotBlock(
        array $constraints,
        string $outputFormat,
        string $riskClass,
        string $executorHint,
    ): string {
        $lines = ['## O que NÃO fazer'];

        // Vetos universais (sempre presentes — garantia enterprise).
        $lines[] = '- Não execute comandos de terminal sozinho. Toda execução exige confirmação humana.';
        $lines[] = '- Não use API paga, não chame provider remoto que cobre por uso.';
        $lines[] = '- Não invente arquivo, função ou dependência que não exista no repo.';
        $lines[] = '- Não ignore as restrições acima — se houver conflito, pare e pergunte.';

        if ($outputFormat === 'diagnostic') {
            $lines[] = '- Não edite arquivo, não rode comando. Este pedido é apenas diagnóstico/leitura.';
        }
        if ($outputFormat === 'plan') {
            $lines[] = '- Não comece a implementar antes que o plano seja aceito.';
        }
        if ($outputFormat === 'notes') {
            $lines[] = '- Não adicione comentário além do conteúdo da nota.';
        }
        if ($executorHint === 'terminal_propose') {
            $lines[] = '- Não execute o comando proposto — apenas sugira e aguarde aprovação.';
        }
        if (in_array($riskClass, [VoxSchema::RISK_R3, VoxSchema::RISK_R4], true)) {
            $lines[] = '- Não simule "como ficaria depois" para operação destrutiva. Mostre prosa, não código executável.';
        }

        // Eco das restrições da voz como vetos explícitos.
        foreach ($constraints as $clause) {
            $clause = trim((string) $clause);
            if ($clause === '') {
                continue;
            }
            $lines[] = '- Veto literal da voz: "'.$clause.'".';
        }

        return implode("\n", $lines);
    }

    /**
     * @param  list<string>  $riskMarkers
     */
    private function safetyBlock(string $riskClass, array $riskMarkers): string
    {
        $lines = ['## Segurança'];
        if ($riskClass === VoxSchema::RISK_R4) {
            $lines[] = '- A voz original contém marcadores de operação **destrutiva**: '
                .($riskMarkers === [] ? '(não classificado)' : implode(', ', $riskMarkers)).'.';
            $lines[] = '- NÃO execute. NÃO escreva o comando final. NÃO simule "como ficaria depois".';
            $lines[] = '- Trate como pedido de **avaliação**: explique o que aconteceria, por que é perigoso e qual a alternativa segura.';
            $lines[] = '- Se for absolutamente necessário propor um comando, descreva-o em prosa com avisos, nunca em bloco executável.';
        } else {
            $lines[] = '- Pedido envolve execução externa contida (R3): proponha o comando, mas exija confirmação humana antes de qualquer execução.';
            $lines[] = '- Não rode comandos destrutivos. Não toque banco, deploy ou git history.';
        }

        return implode("\n", $lines);
    }

    private function humanInputFootnote(string $rawTranscript): string
    {
        $sanitised = trim((string) preg_replace('/\s+/u', ' ', $rawTranscript));
        if ($sanitised === '') {
            return '';
        }

        return "## Voz original\n> ".$sanitised;
    }

    /**
     * V6-FPG-B · Quality self-check determinístico do compiled_prompt.
     *
     * Inspeciona o output do `compile()` cruzado com o que o operador
     * disse (constraints + raw transcript) e responde:
     *
     *   - tem objetivo? (## Objetivo não-vazio, ≥ 8 chars, não-placeholder)
     *   - tem restrições? (quando o operador falou negação)
     *   - tem saída esperada? (## Saída esperada com bullets)
     *   - perdeu alguma negação? (compara substrings das constraints
     *     normalizadas; falha grave se a voz disse "não X" e o veto literal
     *     "Veto literal da voz: 'não X'" sumiu do compiled_prompt)
     *   - ficou genérico demais? (objetivo curto sem nenhum nome próprio
     *     da voz; ou compiled_prompt < 600 chars)
     *
     * Retorna `score` ∈ [0, 1] + lista de `issues`. NÃO chama LLM. NÃO
     * sai pra rede. Determinístico, testável.
     *
     * V6-PCF · adiciona dois critérios extra:
     *   - provider_consistent: o `compiled_prompt_template` cita o provider
     *     hint declarado (fail = compiler escolheu provider diferente do
     *     que o extractor extraiu, sintoma de bug).
     *   - contradiction_detected: voz pediu read-only (só analisa / só leia)
     *     mas o output_format declarado faz edição (diff / command_proposal).
     *     Fail = extractor deixou contradição passar.
     *
     * Score agora pondera 7 critérios igualmente (1/7 cada).
     *
     * @param  array{compiled_prompt:string,compiled_prompt_template:string,sections:list<string>}  $compiled
     * @param  array{goal:string,constraints:list<string>,output_format:string,risk_class:string,provider_hint?:string}  $extracted
     * @return array{score:float, issues:list<string>, has_goal:bool, has_constraints:bool, has_expected_output:bool, lost_negations:list<string>, looks_generic:bool, provider_consistent:bool, contradiction_detected:bool}
     */
    public function selfCheck(
        string $rawTranscript,
        array $compiled,
        array $extracted,
    ): array {
        $prompt = (string) ($compiled['compiled_prompt'] ?? '');
        $issues = [];

        // 1) Objetivo presente e não-placeholder.
        $hasGoal = (
            str_contains($prompt, '## Objetivo')
            && trim((string) ($extracted['goal'] ?? '')) !== ''
            && ! str_contains($prompt, '(objetivo não declarado explicitamente')
        );
        if (! $hasGoal) {
            $issues[] = 'goal_missing_or_placeholder';
        }

        // 2) Restrições — só exige bloco quando o operador falou negação.
        $voiceHasNegation = (bool) preg_match(
            '/(?<![\p{L}\p{N}_])(n[ãa]o|sem|antes\s+de|nada\s+de|s[óo]|apenas|somente)\b/iu',
            $rawTranscript,
        );
        $hasConstraints = ! $voiceHasNegation
            || (str_contains($prompt, '## Restrições') && ! empty($extracted['constraints']));
        if ($voiceHasNegation && ! $hasConstraints) {
            $issues[] = 'voice_had_negation_but_constraints_block_missing';
        }

        // 3) Saída esperada presente com pelo menos um bullet.
        $hasExpectedOutput = false;
        if (preg_match('/## Saída esperada\s*\n((?:- [^\n]+\n?)+)/u', $prompt, $m) === 1) {
            $bullets = preg_match_all('/^- /mu', (string) $m[1]);
            $hasExpectedOutput = ($bullets ?: 0) >= 1;
        }
        if (! $hasExpectedOutput) {
            $issues[] = 'expected_output_block_empty_or_missing';
        }

        // 4) Negações preservadas. Cada constraint que tem `não|sem|nada de|
        //    só|apenas|somente` deve aparecer literalmente em "Veto literal
        //    da voz". Falhar aqui = bug grave (perdeu intenção do operador).
        $lostNegations = [];
        foreach (($extracted['constraints'] ?? []) as $clause) {
            $clause = trim((string) $clause);
            if ($clause === '') {
                continue;
            }
            if (preg_match('/^(n[ãa]o|sem|nada\s+de|s[óo]|apenas|somente)\b/iu', $clause) !== 1) {
                continue;
            }
            $marker = 'Veto literal da voz: "'.$clause.'"';
            if (! str_contains($prompt, $marker)) {
                $lostNegations[] = $clause;
            }
        }
        if ($lostNegations !== []) {
            $issues[] = 'lost_negations:'.implode('|', $lostNegations);
        }

        // 5) Genérico demais.
        //    Critério: ou (a) prompt < 600 chars ou (b) objetivo < 12 chars
        //    sem nenhum nome próprio capitalizado.
        $looksGeneric = false;
        if (mb_strlen($prompt) < 600) {
            $looksGeneric = true;
        } else {
            $goal = trim((string) ($extracted['goal'] ?? ''));
            $hasProperNoun = (bool) preg_match(
                '/(?<![\p{Lu}])[\p{Lu}][\p{L}_]{2,}/u',
                $rawTranscript,
            );
            if (mb_strlen($goal) < 12 && ! $hasProperNoun) {
                $looksGeneric = true;
            }
        }
        if ($looksGeneric) {
            $issues[] = 'prompt_looks_generic';
        }

        // 6) Provider consistency: o template id declarado deve citar o
        //    provider_hint que o extractor escolheu. Se divergiu, ou o
        //    compiler caiu no fallback `local` sem motivo, ou o extractor
        //    devolveu um provider que o compiler não suporta — bug.
        $providerConsistent = true;
        $templateId = (string) ($compiled['compiled_prompt_template'] ?? '');
        $declaredProvider = (string) ($extracted['provider_hint'] ?? 'local');
        $supported = ['codex_cli', 'claude_cli', 'atlas', 'auto', 'local'];
        $expectedProvider = in_array($declaredProvider, $supported, true)
            ? $declaredProvider
            : 'local';
        if ($templateId !== '' && ! str_contains($templateId, '.'.$expectedProvider.'.')) {
            $providerConsistent = false;
            $issues[] = 'provider_template_mismatch:'.$expectedProvider;
        }

        // 7) Contradiction detection: voz pediu read-only ("só analisa",
        //    "só leia", "apenas olha") mas o output_format escolhido faz
        //    edição (diff/command_proposal). Fail = extractor deixou
        //    contradição passar para o prompt — bug grave.
        $contradictionDetected = false;
        $readOnlyPattern = '/(?<![\p{L}\p{N}_])(?:s[óo]|apenas|somente)\s+(?:analisa|analise|leia|ler|l[êe]|olha|olhar)\b/iu';
        $voiceReadOnly = preg_match($readOnlyPattern, $rawTranscript) === 1;
        foreach (($extracted['constraints'] ?? []) as $clause) {
            if (preg_match($readOnlyPattern, (string) $clause) === 1) {
                $voiceReadOnly = true;
                break;
            }
        }
        $editFormats = ['diff', 'command_proposal'];
        if ($voiceReadOnly && in_array($extracted['output_format'] ?? '', $editFormats, true)) {
            $contradictionDetected = true;
            $issues[] = 'contradiction_read_only_but_edit_format';
        }

        // Score determinístico: 7 critérios igualmente ponderados.
        $passed = 0;
        if ($hasGoal) $passed++;
        if ($hasConstraints) $passed++;
        if ($hasExpectedOutput) $passed++;
        if ($lostNegations === []) $passed++;
        if (! $looksGeneric) $passed++;
        if ($providerConsistent) $passed++;
        if (! $contradictionDetected) $passed++;
        $score = round($passed / 7, 4);

        return [
            'score' => $score,
            'issues' => $issues,
            'has_goal' => $hasGoal,
            'has_constraints' => $hasConstraints,
            'has_expected_output' => $hasExpectedOutput,
            'lost_negations' => $lostNegations,
            'looks_generic' => $looksGeneric,
            'provider_consistent' => $providerConsistent,
            'contradiction_detected' => $contradictionDetected,
        ];
    }

    private function templateId(string $provider, string $outputFormat): string
    {
        $providerKey = in_array($provider, ['codex_cli', 'claude_cli', 'auto', 'local', 'atlas'], true)
            ? $provider
            : 'local';
        $formatKey = in_array($outputFormat, ['plan', 'diff', 'text', 'notes', 'diagnostic'], true)
            ? $outputFormat
            : 'text';

        return 'builtin.intent_compile.'.$providerKey.'.'.$formatKey.'.pt-br@'.self::TEMPLATE_VERSION;
    }
}
