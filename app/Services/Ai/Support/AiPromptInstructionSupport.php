<?php

declare(strict_types=1);

namespace App\Services\Ai\Support;

use App\Support\YesNo;

/**
 * Pure prompt instruction / runtime-context section builders peeled from AiPromptBuilder.
 *
 * No I/O, no DI, no provider calls, no time side effects.
 * Host only supplies the already-built options payload map.
 */
final class AiPromptInstructionSupport
{
    /**
     * @param  array<string,mixed>  $options
     */
    public static function persistentContextPromptSection(array $options): string
    {
        $runtime = data_get($options, 'payload.persistent_context');
        if (! is_array($runtime) || ($runtime['schema_version'] ?? null) !== 'atlas.persistent_context.runtime.v1') {
            return '';
        }

        $handoff = is_array($runtime['provider_handoff'] ?? null) ? $runtime['provider_handoff'] : [];
        $ledgerItems = array_slice((array) data_get($runtime, 'must_know_ledger.items', []), 0, 12);
        $readFirst = array_slice((array) data_get($handoff, 'read_first', []), 0, 8);
        $required = array_slice((array) data_get($handoff, 'required_before_execution', []), 0, 8);
        $blockers = array_slice((array) data_get($runtime, 'sufficiency.blockers', []), 0, 8);

        $lines = [
            '# Atlas Persistent Context Runtime',
            '',
            'Use este bloco como contexto obrigatorio antes de responder. Ele existe para impedir que a sessao/provider nasca sem memoria operacional.',
            '- Status: '.(string) ($runtime['status'] ?? 'unknown'),
            '- Sufficiency: '.(string) data_get($runtime, 'sufficiency.status', 'unknown'),
            '- Context pack hash: '.(string) ($runtime['context_pack_hash'] ?? 'missing'),
            '- Must-know ledger hash: '.(string) ($runtime['must_know_ledger_hash'] ?? 'missing'),
            '- Provider handoff hash: '.(string) data_get($handoff, 'context_pack_hash', 'missing'),
            '- Execution allowed: '.YesNo::format(data_get($handoff, 'execution_allowed', false)),
        ];

        if ($readFirst !== []) {
            $lines[] = '';
            $lines[] = 'Read-first refs:';
            foreach ($readFirst as $ref) {
                if (is_scalar($ref)) {
                    $lines[] = '- '.(string) $ref;
                }
            }
        }

        if ($ledgerItems !== []) {
            $lines[] = '';
            $lines[] = 'Must-know ledger:';
            foreach ($ledgerItems as $item) {
                if (! is_array($item)) {
                    continue;
                }

                $digest = trim((string) ($item['digest'] ?? ''));
                if ($digest === '') {
                    continue;
                }

                $lines[] = '- '.(string) ($item['kind'] ?? 'fact').': '.$digest;
            }
        }

        if ($required !== []) {
            $lines[] = '';
            $lines[] = 'Antes de executar:';
            foreach ($required as $rule) {
                if (is_scalar($rule)) {
                    $lines[] = '- '.(string) $rule;
                }
            }
        }

        if ($blockers !== []) {
            $lines[] = '';
            $lines[] = 'Blockers de contexto:';
            foreach ($blockers as $blocker) {
                $lines[] = '- '.(is_scalar($blocker) ? (string) $blocker : json_encode($blocker, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            }
        }

        $lines[] = '';
        $lines[] = 'Nao invente source refs. Se este bloco disser execution_allowed=no ou sufficiency=blocked, declare o bloqueio antes de executar.';

        return implode("\n", $lines);
    }

    /**
     * @param  array<string,mixed>  $options
     */
    public static function awisRuntimeContextPromptSection(array $options): string
    {
        $context = data_get($options, 'payload.awis_runtime_context');
        if (! is_array($context) || ($context['schema_version'] ?? null) !== 'atlas.awis.runtime_context_hint.v1') {
            return '';
        }

        $workspaceName = AiPromptTextSupport::awisPromptScalar(
            data_get($context, 'workspace.name', data_get($context, 'workspace.key')),
            'workspace desconhecido',
        );
        $neverStartCold = (bool) data_get($context, 'never_start_cold', false);
        $files = AiPromptTextSupport::awisPromptList(data_get($context, 'working_set.files', []), 5);
        $docs = AiPromptTextSupport::awisPromptList(data_get($context, 'working_set.docs', []), 5);
        $commands = AiPromptTextSupport::awisPromptList(data_get($context, 'working_set.commands', []), 5);

        $lines = [
            '# Atlas Workspace Intelligence System',
            '',
            'Use este bloco como contexto operacional compacto do workspace antes de responder. Ele existe para impedir que a sessão nasça fria.',
            '- Workspace: '.$workspaceName,
            '- Nunca iniciar frio: '.($neverStartCold ? 'sim' : 'não'),
        ];

        $startupLines = [];
        $launchMode = AiPromptTextSupport::awisPromptScalar(data_get($context, 'startup_contract.launch_mode'), '');
        if ($launchMode !== '') {
            $startupLines[] = 'modo de partida: '.$launchMode;
        }
        $contextMode = AiPromptTextSupport::awisPromptScalar(data_get($context, 'startup_contract.context_mode'), '');
        if ($contextMode !== '') {
            $startupLines[] = 'modo de contexto: '.$contextMode;
        }
        if ((bool) data_get($context, 'startup_contract.prefer_summary', false)) {
            $startupLines[] = 'preferir resumo antes de expandir';
        }
        $readiness = array_filter([
            'partida' => data_get($context, 'startup_contract.readiness.startup'),
            'kernel' => data_get($context, 'startup_contract.readiness.context_kernel'),
            'artifact' => data_get($context, 'startup_contract.readiness.artifact_replay'),
            'próxima sessão' => data_get($context, 'startup_contract.readiness.next_session_brain'),
        ], fn ($value): bool => is_int($value) || is_float($value));
        foreach ($readiness as $label => $value) {
            $startupLines[] = 'readiness '.$label.': '.max(0, min(100, (int) round($value))).'%';
        }
        $startupLines = [
            ...$startupLines,
            ...array_map(fn (string $item): string => 'sequência: '.$item, AiPromptTextSupport::awisPromptList(data_get($context, 'startup_contract.load_sequence', []), 6)),
            ...array_map(fn (string $item): string => 'revalidar antes de enviar: '.$item, AiPromptTextSupport::awisPromptList(data_get($context, 'startup_contract.revalidate_before_send', []), 6)),
            ...array_map(fn (string $item): string => 'fronteira humana: '.$item, AiPromptTextSupport::awisPromptList(data_get($context, 'startup_contract.human_boundary', []), 4)),
        ];
        if ($startupLines !== []) {
            $lines = [
                ...$lines,
                ...AiPromptTextSupport::awisPromptSectionLines('Contrato de partida', $startupLines),
            ];
        }

        $lines = [
            ...$lines,
            ...AiPromptTextSupport::awisPromptSectionLines('Carregar primeiro', AiPromptTextSupport::awisPromptList(data_get($context, 'load_first', []), 8)),
            ...AiPromptTextSupport::awisPromptSectionLines('Resumo ouro', AiPromptTextSupport::awisPromptList(data_get($context, 'use_as_summary', []), 6)),
            ...AiPromptTextSupport::awisPromptSectionLines('Validar com', AiPromptTextSupport::awisPromptList(data_get($context, 'validate_with', []), 6)),
            ...AiPromptTextSupport::awisPromptSectionLines('Evitar carregar', AiPromptTextSupport::awisPromptList(data_get($context, 'avoid_loading', []), 6)),
        ];

        if ($files !== [] || $docs !== [] || $commands !== []) {
            $lines[] = '';
            $lines[] = 'Working set provável:';
            foreach ($files as $file) {
                $lines[] = '- arquivo: '.$file;
            }
            foreach ($docs as $doc) {
                $lines[] = '- doc: '.$doc;
            }
            foreach ($commands as $command) {
                $lines[] = '- comando: '.$command;
            }
        }

        $verifyBeforeTrust = AiPromptTextSupport::awisPromptList(data_get($context, 'evidence_gate.verify_before_trust', []), 4);
        $humanBoundary = AiPromptTextSupport::awisPromptList(data_get($context, 'evidence_gate.human_boundary', []), 4);
        if ($verifyBeforeTrust !== [] || $humanBoundary !== []) {
            $lines[] = '';
            $lines[] = 'Evidence gate:';
            foreach ($verifyBeforeTrust as $rule) {
                $lines[] = '- verificar antes de confiar: '.$rule;
            }
            foreach ($humanBoundary as $boundary) {
                $lines[] = '- fronteira humana: '.$boundary;
            }
        }

        $spaceLines = [
            ...array_map(fn (string $item): string => 'Space ativo: '.$item, AiPromptTextSupport::awisPromptList(data_get($context, 'space_context.active_spaces', []), 4)),
            ...array_map(fn (string $item): string => 'Space forte: '.$item, AiPromptTextSupport::awisPromptList(data_get($context, 'space_context.strongest_spaces', []), 5)),
            ...array_map(fn (string $item): string => 'carregar: '.$item, AiPromptTextSupport::awisPromptList(data_get($context, 'space_context.load_first', []), 6)),
            ...array_map(fn (string $item): string => 'manter: '.$item, AiPromptTextSupport::awisPromptList(data_get($context, 'space_context.carry_forward', []), 6)),
            ...array_map(fn (string $item): string => 'validar: '.$item, AiPromptTextSupport::awisPromptList(data_get($context, 'space_context.validate_before_use', []), 5)),
            ...array_map(fn (string $item): string => 'limite humano: '.$item, AiPromptTextSupport::awisPromptList(data_get($context, 'space_context.human_boundary', []), 4)),
            ...array_map(fn (string $item): string => 'artifact: '.$item, AiPromptTextSupport::awisPromptList(data_get($context, 'space_context.artifact_refs', []), 4)),
        ];
        if ($spaceLines !== []) {
            $lines = [
                ...$lines,
                ...AiPromptTextSupport::awisPromptSectionLines('Spaces vivos', $spaceLines),
            ];
        }

        $artifactLines = [];
        if ((bool) data_get($context, 'artifact_context.replay_ready', false)) {
            $artifactLines[] = 'replay pronto';
        }
        $latestArtifactHash = AiPromptTextSupport::awisPromptScalar(data_get($context, 'artifact_context.latest_artifact_hash'), '');
        if ($latestArtifactHash !== '') {
            $artifactLines[] = 'artifact recente: '.$latestArtifactHash;
        }
        $artifactLines = [
            ...$artifactLines,
            ...array_map(fn (string $item): string => 'carregar: '.$item, AiPromptTextSupport::awisPromptList(data_get($context, 'artifact_context.load_order', []), 5)),
            ...array_map(fn (string $item): string => 'validar: '.$item, AiPromptTextSupport::awisPromptList(data_get($context, 'artifact_context.validate_with', []), 4)),
            ...array_map(fn (string $item): string => 'padrão reutilizável: '.$item, AiPromptTextSupport::awisPromptList(data_get($context, 'artifact_context.reusable_patterns', []), 5)),
            ...array_map(fn (string $item): string => 'Space preservado: '.$item, AiPromptTextSupport::awisPromptList(data_get($context, 'artifact_context.strongest_spaces', []), 4)),
            ...array_map(fn (string $item): string => 'atenção: '.$item, AiPromptTextSupport::awisPromptList(data_get($context, 'artifact_context.warnings', []), 4)),
        ];
        if ($artifactLines !== []) {
            $lines = [
                ...$lines,
                ...AiPromptTextSupport::awisPromptSectionLines('Artifacts reutilizáveis', $artifactLines),
            ];
        }

        $recentMaintenance = AiPromptTextSupport::awisPromptList(data_get($context, 'continue_learning.maintenance_recent', []), 5);
        if ($recentMaintenance !== []) {
            $lines = [
                ...$lines,
                ...AiPromptTextSupport::awisPromptSectionLines('Manutenção recente AWIS', $recentMaintenance),
            ];
        }

        $lines = [
            ...$lines,
            ...AiPromptTextSupport::awisPromptSectionLines('Próxima sessão · carregar', AiPromptTextSupport::awisPromptList(data_get($context, 'next_session.first_load', []), 6)),
            ...AiPromptTextSupport::awisPromptSectionLines('Próxima sessão · validar', AiPromptTextSupport::awisPromptList(data_get($context, 'next_session.validate_with', []), 5)),
            ...AiPromptTextSupport::awisPromptSectionLines('Promover para memória quando', AiPromptTextSupport::awisPromptList(data_get($context, 'next_session.promote_when', []), 5)),
            ...AiPromptTextSupport::awisPromptSectionLines('Rebaixar quando', AiPromptTextSupport::awisPromptList(data_get($context, 'next_session.demote_when', []), 5)),
        ];

        $learning = [
            'record_outcome' => 'registrar resultado real',
            'update_memory' => 'atualizar memória AWIS',
            'update_space_pack' => 'atualizar Space pack',
            'preserve_artifact_after_success' => 'preservar artifact após sucesso',
        ];
        $enabledLearning = [];
        foreach ($learning as $key => $label) {
            if ((bool) data_get($context, 'continue_learning.'.$key, false)) {
                $enabledLearning[] = $label;
            }
        }
        if ($enabledLearning !== []) {
            $lines = [
                ...$lines,
                ...AiPromptTextSupport::awisPromptSectionLines('Aprendizado contínuo', $enabledLearning),
            ];
        }

        $lines[] = '';
        $lines[] = 'Não trate este bloco como conversa bruta. Se algo estiver ausente ou inseguro, declare a lacuna e use contexto verificável.';

        return implode("\n", $lines);
    }

    /**
     * @param  array<string,mixed>  $options
     */
    public static function workflowInstructions(array $options): string
    {
        $mode = data_get($options, 'payload.atlas_workflow_mode');

        return match ($mode) {
            'semantic_clarification' => <<<'TXT'
# Modo de trabalho: Aclaramento semantico

Voce e o Aclarador do Atlas. Retorne somente JSON valido, sem markdown e sem comentario externo. Nao descarte captura curta por tamanho. Diferencie captura bruta de conhecimento validado. O JSON deve conter exatamente estas chaves de topo: main_thesis, atomic_ideas, suggested_type, tension_or_question, density, possible_destination, authorship_question, future_triggers.
TXT,
            'plan' => <<<'TXT'
# Modo de trabalho: Planejar

Nao execute acao externa. Estruture o problema, explicite premissas, riscos, ordem de implementacao, criterios de verificacao e o proximo passo concreto. Se houver tradeoff, mostre a decisao recomendada e por que ela e melhor para o Atlas agora.
TXT,
            'review' => <<<'TXT'
# Modo de trabalho: Revisar

Assuma postura de revisao rigorosa. Procure bugs, inconsistencias, riscos de arquitetura, pontos de quebra, lacunas de teste e divergencias com a identidade do Atlas. Priorize achados acionaveis antes de resumo. Nao reescreva tudo se o problema for local.
TXT,
            'dev' => <<<'TXT'
# Modo de trabalho: Desenvolvimento pesado no Mac

Atue como executor técnico do Atlas. Entenda o workspace, preserve mudanças existentes, edite com escopo claro, valide com comandos relevantes e entregue resumo operacional. Não despeje código na resposta final; cite arquivos, decisões, testes e riscos residuais. Se não puder executar ou validar algo, diga isso de forma objetiva.
TXT,
            'debug' => <<<'TXT'
# Modo de trabalho: Debug

Investigue o erro de forma operacional: reproduza quando possível, isole a causa provável, diferencie sintoma de raiz e proponha uma correção mínima. Não edite código sem permissão explícita do operador ou sem modo de escrita autorizado. Cite evidências concretas: arquivo, comando, erro, log ou teste.
TXT,
            'research' => <<<'TXT'
# Modo de trabalho: Pesquisa

Pesquise dentro do contexto realmente disponível para o Atlas: repositório, Vault, documentos enviados, contexto recuperado e traces. Cite somente fontes efetivamente fornecidas ou recuperadas pelo Atlas. Se faltar ferramenta de web/search ou se uma fonte externa não estiver disponível, declare a limitação em vez de inventar referência. Separe evidência, inferência e recomendação.
TXT,
            'quality_repair' => <<<'TXT'
# Modo de trabalho: Reparo de qualidade

Sua função é produzir a versão final que o Atlas deveria entregar ao operador. Corrija perda de continuidade, vazamento de contexto interno, excesso de código, excesso de verbosidade ou falta de clareza. Não explique o processo interno de reparo. Não mencione traces, jobs, context_pack, prompts, provider ou avaliação de qualidade.
TXT,
            default => '',
        };
    }

    /**
     * @param  array<string,mixed>  $options
     */
    public static function atlasModeInstructions(array $options): string
    {
        $contract = data_get($options, 'payload.atlas_mode_contract');
        if (! is_array($contract) || $contract === []) {
            return '';
        }

        $mode = (string) data_get($contract, 'mode', data_get($options, 'payload.atlas_mode', 'general'));
        $objective = (string) data_get($contract, 'objective', 'responder com clareza e continuidade');
        $expected = AiPromptTextSupport::stringList(data_get($contract, 'expected_output', []));
        $quality = data_get($options, 'payload.quality_policy');
        $qualityRules = is_array($quality) && $quality !== []
            ? AiPromptTextSupport::keyValueLines($quality)
            : '- sem regras adicionais';

        $modeRules = match ($mode) {
            'programming' => <<<'TXT'
- Trate esta conversa como trabalho de engenharia: plano, escopo, execução, validação e riscos.
- Se houver ferramenta/harness disponivel, use-o para leitura, comandos, scripts e testes conforme permissões.
- Se nao executar algo, explique o bloqueio operacional e deixe o proximo passo concreto.
TXT,
            'operational' => <<<'TXT'
- Trate esta conversa como operacao: explique o que aconteceu, por que importa, evidencias, risco e proxima decisao.
- Use metricas, traces, context bundles e historico antes de recomendar mudanca.
- Se o item exigir codigo, promova para programacao com briefing claro em vez de misturar diagnostico e patch sem controle.
TXT,
            default => <<<'TXT'
- Trate esta conversa como geral: nao herde contexto operacional ou de codigo por acidente.
- Responda com simplicidade, peça lacunas essenciais e sugira proximo passo apenas quando ajudar.
TXT,
        };

        $expectedText = $expected === '' ? '- resposta clara' : $expected;

        return <<<TXT
# Modo Atlas AI

Modo: {$mode}
Objetivo: {$objective}

Contrato esperado:
{$expectedText}

Politica de qualidade:
{$qualityRules}

Regras do modo:
{$modeRules}
TXT;
    }

    /**
     * @param  array<string,mixed>  $options
     */
    public static function specialistFlowInstructions(array $options): string
    {
        $execution = data_get($options, 'payload.specialist_flow_execution');
        if (! is_array($execution) || $execution === []) {
            return '';
        }

        $flowId = (string) data_get($execution, 'flow_id', 'unknown');
        $handlerId = (string) data_get($execution, 'handler_id', 'unknown');
        $status = (string) data_get($execution, 'status', 'ready_for_provider');
        $runtimeReceipt = (string) data_get($execution, 'runtime_receipt_id', 'missing');
        $runtimeHash = (string) data_get($execution, 'runtime_contract_hash', 'missing');
        $promptContract = AiPromptTextSupport::stringList(data_get($execution, 'provider_prompt_contract', []));
        $responseShape = AiPromptTextSupport::stringList(data_get($execution, 'response_shape', []));
        $auditChecks = AiPromptTextSupport::stringList(data_get($execution, 'audit_checks', []));
        $qualityRubric = AiPromptTextSupport::stringList(data_get($execution, 'quality_rubric', []));
        $completionChecks = AiPromptTextSupport::stringList(data_get($execution, 'completion_checks', []));
        $failureModes = AiPromptTextSupport::stringList(data_get($execution, 'failure_modes', []));
        $delegation = data_get($execution, 'delegation');
        $delegationLines = is_array($delegation) && $delegation !== []
            ? AiPromptTextSupport::keyValueLines($delegation)
            : '- status: not_delegated';

        return <<<TXT
# Atlas AI Specialist Flow Handler

Flow: {$flowId}
Handler: {$handlerId}
Status: {$status}
Runtime receipt: {$runtimeReceipt}
Runtime contract hash: {$runtimeHash}

Contrato do handler:
{$promptContract}

Formato esperado (chaves semanticas internas — NUNCA escreva esses identificadores literais no corpo da resposta):
{$responseShape}

Auditoria obrigatoria:
{$auditChecks}

Rubrica de qualidade:
{$qualityRubric}

Checks de conclusao:
{$completionChecks}

Modos de falha proibidos:
{$failureModes}

Delegation:
{$delegationLines}

Regras:
- Siga este handler como contrato operacional do fluxo escolhido pelo Atlas AI Router.
- Se o status for delegated, nao execute o trabalho neste fluxo; explique o handoff e o proximo passo.
- Nao oculte ausencia de evidencia exigida pelo handler.
- As chaves do formato esperado sao alvos semanticos para o conteudo, nao titulos literais. NUNCA escreva "source_refs", "uncertainty", "SOURCE_REFS", "UNCERTAINTY", "claims_table", "open_questions", "findings", "assumptions" ou qualquer identificador em snake_case/UPPER_CASE como cabecalho da resposta. Use titulos editoriais curtos em portugues quando precisar separar secoes (ex: "Resposta", "Fontes", "Incerteza", "Achados"). Para respostas curtas, prefira prosa continua sem cabecalhos.
- Quando o conteudo de uma secao for puramente uma lista de identificadores tecnicos/auditoria (refs, hashes, receipts, traces), entregue como nota de rodape em portugues ou omita do corpo principal — o Atlas expoe esses dados separadamente no painel de contexto.
- Separe secoes principais (≥2) com uma linha "---" em branco entre elas para ativar o divisor editorial Atlas.
TXT;
    }

    /**
     * @param  array<string,mixed>  $options
     */
    public static function permissionInstructions(array $options): string
    {
        $permissions = data_get($options, 'payload.tool_permissions');
        if (! is_array($permissions) || $permissions === []) {
            return '';
        }

        $mode = (string) data_get($permissions, 'mode', 'read');
        $workspaceValue = data_get($permissions, 'workspace', data_get($options, 'payload.workspace'));
        $workspace = is_scalar($workspaceValue) ? (string) $workspaceValue : 'nao definido';
        $capabilities = data_get($permissions, 'capabilities', []);
        $allowedRoots = data_get($permissions, 'allowed_roots', data_get($permissions, 'permission_decision.metadata.allowed_roots', []));
        $sandboxValue = data_get($permissions, 'codex_sandbox');
        $sandbox = is_scalar($sandboxValue) ? (string) $sandboxValue : 'nao definido';

        $capabilityText = is_array($capabilities) && $capabilities !== []
            ? implode(', ', array_map(fn (mixed $capability): string => (string) $capability, $capabilities))
            : 'read_files, inspect_git';
        $rootsText = is_array($allowedRoots) && $allowedRoots !== []
            ? implode(', ', array_map(fn (mixed $root): string => (string) $root, $allowedRoots))
            : $workspace;
        $scopeRule = $mode === 'danger'
            ? '- Em modo danger, voce pode ler, escrever e executar comandos dentro das raizes autorizadas, nao apenas no workspace atual. Nao use sudo nem acesse fora dessas raizes sem pedido explicito.'
            : '- Em modo read/write, mantenha leitura, escrita e comandos dentro do workspace autorizado.';

        return <<<TXT
# Runtime de ferramentas Atlas

Modo autorizado: {$mode}
Workspace autorizado: {$workspace}
Raizes autorizadas: {$rootsText}
Sandbox Codex previsto: {$sandbox}
Capacidades: {$capabilityText}

Regras:
- Execute leitura, escrita ou comandos somente dentro das capacidades e raizes acima.
{$scopeRule}
- Não tente contornar sandbox, permissões, workspace ou políticas do Atlas.
- Se precisar de uma capacidade maior, pare e explique a solicitação de permissão em termos operacionais.
TXT;
    }

    /**
     * @param  array<string,mixed>  $options
     */
    public static function outputContract(array $options): string
    {
        $mode = data_get($options, 'payload.atlas_workflow_mode');

        $base = <<<'TXT'
# Contrato de saida Atlas

- O produto final é o Atlas; Claude, Codex e outros modelos são motores internos.
- Use memória e contexto de forma silenciosa. Não anuncie que "contexto foi mapeado".
- Responda com clareza executiva: decisão, ação, risco e validação quando relevante.
- Evite código na resposta final, salvo pedido explícito do operador.
- Em tarefas técnicas, cite arquivos alterados e comandos de verificação.
- Para perguntas sobre arquivos, pastas ou contagens no filesystem, use comando deterministico quando houver acesso a ferramentas e diga se ocultos foram incluídos.
- Se a resposta anterior do operador for curta ("C", "ambos", "continua"), use a conversa recente antes de pedir referência.
- NUNCA escreva marcação interna no texto: nada de <antThinking>, pseudo-chamadas de ferramenta (<tool...>, code interpreter) nem blocos repetidos. Sem acesso a ferramentas nesta superfície, diga o que FARIA e peça o dado — jamais finja executar.
TXT;

        if ($mode === 'dev') {
            return $base."\n- Para desenvolvimento, prefira resumo de mudanças e validação a explicações longas.";
        }

        return $base;
    }

    /**
     * @param  array<string,mixed>  $options
     */
    public static function voiceResponseInstructions(array $options): string
    {
        $contract = data_get($options, 'payload.voice_response_contract');
        if (! is_array($contract) || ! in_array((string) data_get($contract, 'mode'), ['spoken_concise', 'spoken_result'], true)) {
            return '';
        }

        $targetChars = max(160, min(800, (int) data_get($contract, 'target_chars', 360)));
        $hardMaxChars = max($targetChars, min(1200, (int) data_get($contract, 'hard_max_chars', 520)));
        $maxSentences = max(1, min(5, (int) data_get($contract, 'max_sentences', 3)));

        return <<<TXT
# Contrato de resposta falada Atlas Voice

Esta resposta sera falada em voz alta. Priorize tempo ate a primeira fala e clareza oral.

Regras:
- Responda em portugues brasileiro natural, direto e sem markdown.
- Nao reduza o escopo do pedido por ser voz: execute a intencao completa antes de formular a resposta falada.
- Use no maximo {$maxSentences} frases curtas quando a pergunta permitir.
- Mira de tamanho: ate {$targetChars} caracteres; limite duro: {$hardMaxChars} caracteres.
- Nao use listas longas, cabecalhos, tabelas, JSON, codigo ou referencias internas.
- Se o trabalho exigir analise longa, faca o trabalho completo, responda em voz com conclusao eficiente e deixe detalhes essenciais no texto da conversa.
- Se faltar contexto, faca uma pergunta objetiva em uma frase.
TXT;
    }

    /**
     * @param  array<int,array<string,mixed>>  $catalog
     */
    public static function skillCatalogSection(array $catalog): string
    {
        if ($catalog === []) {
            return '';
        }

        $lines = ['# Available Skills'];
        foreach ($catalog as $entry) {
            $compatibility = is_string($entry['compatibility'] ?? null) && $entry['compatibility'] !== ''
                ? ' ['.$entry['compatibility'].']'
                : '';
            $lines[] = '- '.$entry['name'].': '.$entry['description'].$compatibility;
        }

        $lines[] = '';
        $lines[] = 'Use uma skill quando o pedido combinar com a descricao. Nao carregue referencias, scripts ou assets automaticamente; solicite leitura sob demanda quando necessario.';

        return implode("\n", $lines);
    }
}
