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
    public const TEMPLATE_VERSION = '0.1.0';

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

        $sections[] = $this->contextBlock($extracted['context_refs']);
        $sections[] = $this->expectedOutput($outputFormat);

        if (in_array($extracted['risk_class'], [VoxSchema::RISK_R3, VoxSchema::RISK_R4], true)) {
            $sections[] = $this->safetyBlock(
                riskClass: $extracted['risk_class'],
                riskMarkers: $extracted['risk_markers'],
            );
        }

        $sections[] = $this->humanInputFootnote($rawTranscript);

        $compiled = trim(implode("\n\n", array_filter($sections, static fn ($s) => $s !== '')));
        $template = $this->templateId($provider, $outputFormat);

        return [
            'compiled_prompt' => $compiled,
            'compiled_prompt_template' => $template,
            'sections' => array_keys($this->labelMap()),
        ];
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
    private function contextBlock(array $contextRefs): string
    {
        $resolved = array_values(array_filter(
            $contextRefs,
            static fn ($r) => ($r['kind'] ?? 'none') !== 'none',
        ));

        if ($resolved === []) {
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

        return implode("\n", $lines);
    }

    private function expectedOutput(string $outputFormat): string
    {
        return match ($outputFormat) {
            'diagnostic' => "## Saída esperada\n- Resumo do que foi observado.\n- Arquivos relevantes (caminhos absolutos quando possível).\n- Hipóteses de causa, ordenadas por probabilidade.\n- Riscos e perguntas em aberto.\n- Recomendação de próximo passo — sem implementar.",
            'plan' => "## Saída esperada\n- Pré-requisitos.\n- Passos numerados com critério de pronto por passo.\n- Riscos e mitigações.\n- Critério final de aceitação.\n- O que NÃO está incluído no plano.",
            'diff' => "## Saída esperada\n- Diff (ou descrição do diff) com arquivo, função e razão por mudança.\n- Lista de arquivos tocados.\n- Comandos de teste executados (ou recomendados, se nada rodou).\n- Riscos da mudança.",
            'notes' => "## Saída esperada\n- Nota concisa em PT-BR, primeira pessoa quando aplicável.\n- Sem comentário adicional fora da nota.\n- Sem links ou citações inventadas.",
            default => "## Saída esperada\n- Texto direto em PT-BR.\n- Sem boilerplate, sem floreio.\n- Se uma resposta exigir suposição, marque claramente como tal.",
        };
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

    private function templateId(string $provider, string $outputFormat): string
    {
        $providerKey = in_array($provider, ['codex_cli', 'claude_cli', 'auto', 'local'], true)
            ? $provider
            : 'local';
        $formatKey = in_array($outputFormat, ['plan', 'diff', 'text', 'notes', 'diagnostic'], true)
            ? $outputFormat
            : 'text';

        return 'builtin.intent_compile.'.$providerKey.'.'.$formatKey.'.pt-br@'.self::TEMPLATE_VERSION;
    }
}
