<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\Support;

use App\Services\Ai\Programming\AtlasCodeForgeUxOrchestratorService as Host;

/**
 * Pure UX projection helpers for {@see Host}.
 *
 * State machine labels, blocker translation, progress, and finalize envelope —
 * no DB, no services, no clock. Host keeps snapshot I/O + queueStaleSeconds.
 */
final class CodeForgeUxProjectionSupport
{

    /**
     * Canonical state priority (Atlas Code Human Interface Upgrade v2):
     *   blocked > review_required > running > prepared > ready_to_define > idle > completed
     *
     * Critical rule: if fast_path is queued but live_execution is blocked,
     * the resolved state is one of the blocked_* variants, never RUNNING.
     *
     * @param  array<string,mixed>  $signals
     */
    public static function resolveState(array $signals): string
    {
        // 1. Hardest blockers first — they win over any "in-progress" signal.
        if ($signals['capacity_exhausted'] ?? false) {
            return Host::STATE_BLOCKED_CAPACITY;
        }

        if ($signals['execution_blocked'] ?? false) {
            return self::classifyExecutionBlocked($signals);
        }

        if ($signals['queue_stale'] ?? false) {
            return Host::STATE_WAITING_WORKER;
        }

        // 2. Provider/dispatch confirmation pending.
        $invocationBlockers = (array) ($signals['invocation_blockers'] ?? []);
        if (($signals['invocation_status'] ?? '') === 'blocked' && $invocationBlockers !== []) {
            if (in_array('operator_provider_approval_required', $invocationBlockers, true)) {
                return Host::STATE_WAITING_PROVIDER_CONFIRMATION;
            }
            if (in_array('budget_approval_required', $invocationBlockers, true)) {
                return Host::STATE_WAITING_BUDGET_CONFIRMATION;
            }
            if (in_array('runtime_dispatch_confirmation_required', $invocationBlockers, true)) {
                return Host::STATE_WAITING_RUNTIME_DISPATCH_CONFIRMATION;
            }
            if (in_array('provider_driver_missing', $invocationBlockers, true)
                || in_array('provider_driver_not_configured', $invocationBlockers, true)) {
                return Host::STATE_BLOCKED_DRIVER;
            }
        }

        if (($signals['provider'] ?? null) !== null
            && ! ($signals['driver_configured_for_selected'] ?? false)
            && ($signals['runtime_dispatch_status'] ?? '') === 'configured') {
            return Host::STATE_BLOCKED_DRIVER;
        }

        // 3. Terminal review/rollback states.
        if (($signals['rollback_state'] ?? '') === 'rolled_back') {
            return Host::STATE_ROLLED_BACK;
        }
        if (($signals['review_status'] ?? '') === 'rejected') {
            return Host::STATE_REJECTED;
        }
        if (($signals['completion_status'] ?? '') === 'completed'
            || (($signals['final_completion_allowed'] ?? false) && ($signals['human_approved'] ?? false))) {
            return Host::STATE_COMPLETED;
        }

        // 4. Repair and review next.
        if ($signals['repair_available'] ?? false) {
            return Host::STATE_REPAIR_REQUIRED;
        }
        if (($signals['review_required'] ?? false) || in_array($signals['execution_status'] ?? '', ['passed', 'degraded'], true)) {
            return Host::STATE_WAITING_REVIEW;
        }

        // 5. Running. Already gated above by execution_blocked + queue_stale.
        if (in_array($signals['execution_status'] ?? '', ['running', 'queued'], true)
            || ($signals['fast_path_status'] ?? '') === 'queued'
            || ($signals['execution_async_status'] ?? '') === 'running') {
            return Host::STATE_RUNNING;
        }

        // 6. Ready states.
        if (($signals['fast_path_status'] ?? '') === 'prepared') {
            return Host::STATE_READY_TO_EXECUTE;
        }
        if ($signals['spec_plan_ready'] ?? false) {
            return Host::STATE_PREPARED;
        }
        if ($signals['intake_ready'] ?? false) {
            return Host::STATE_READY_TO_PREPARE;
        }

        // 7. Intake incomplete → translate to specific blocked_definition variants
        //    so the human knows exactly which field to fill.
        $intakeBlockers = (array) ($signals['intake_blockers'] ?? []);
        $intakeMissing = (array) ($signals['intake_missing_fields'] ?? []);
        if (! empty($intakeBlockers) || ! empty($intakeMissing)) {
            return Host::STATE_BLOCKED_DEFINITION;
        }

        return Host::STATE_READY_TO_DEFINE;
    }

    /**
     * @param  array<string,mixed>  $signals
     */
    public static function classifyExecutionBlocked(array $signals): string
    {
        $remaining = array_map('strval', (array) ($signals['execution_remaining_blockers'] ?? []));
        $filesOut = (array) ($signals['files_out_of_scope'] ?? []);

        if (! empty($filesOut)) {
            return Host::STATE_BLOCKED_SCOPE;
        }
        foreach ($remaining as $code) {
            $low = strtolower($code);
            if (str_contains($low, 'out_of_scope') || str_contains($low, 'files_outside') || str_contains($low, 'scope_guard')) {
                return Host::STATE_BLOCKED_SCOPE;
            }
            if (str_contains($low, 'spec_or_context_insufficient') || str_starts_with($low, 'blocked_missing_')) {
                return Host::STATE_BLOCKED_DEFINITION;
            }
            if (str_contains($low, 'provider_driver_missing') || str_contains($low, 'provider_driver_not_configured')) {
                return Host::STATE_BLOCKED_DRIVER;
            }
            if (str_contains($low, 'provider_capacity_exhausted')) {
                return Host::STATE_BLOCKED_CAPACITY;
            }
            if (str_contains($low, 'provider')) {
                return Host::STATE_BLOCKED_PROVIDER;
            }
            if (str_contains($low, 'governed_execution_exception') || str_contains($low, 'governance')) {
                return Host::STATE_BLOCKED_GOVERNANCE;
            }
        }

        return Host::STATE_BLOCKED_GOVERNANCE;
    }

    /**
     * @param  array<string,mixed>  $signals
     */
    public static function humanLabel(string $state): string
    {
        return match ($state) {
            Host::STATE_NO_OBRA => 'Selecione ou crie uma Obra',
            Host::STATE_INTAKE_REQUIRED, Host::STATE_READY_TO_DEFINE => 'Definir a Obra',
            Host::STATE_INTAKE_READY => 'Definicao pronta',
            Host::STATE_READY_TO_PREPARE => 'Pronto para preparar o Forge',
            Host::STATE_PREPARED => 'Forge preparado',
            Host::STATE_READY_TO_EXECUTE => 'Pronto para executar',
            Host::STATE_RUNNING => 'Executando',
            Host::STATE_WAITING_WORKER => 'Aguardando worker',
            Host::STATE_WAITING_PROVIDER_CONFIRMATION => 'Aguardando aprovacao de provider',
            Host::STATE_WAITING_BUDGET_CONFIRMATION => 'Aguardando aprovacao de custo',
            Host::STATE_WAITING_RUNTIME_DISPATCH_CONFIRMATION => 'Aguardando confirmacao de runtime dispatch',
            Host::STATE_WAITING_REVIEW => 'Aguardando revisao',
            Host::STATE_REPAIR_REQUIRED => 'Reparo necessario',
            Host::STATE_BLOCKED_SCOPE => 'Bloqueado por escopo',
            Host::STATE_BLOCKED_DEFINITION => 'Definicao ou contexto insuficiente',
            Host::STATE_BLOCKED_PROVIDER => 'Provider indisponivel',
            Host::STATE_BLOCKED_DRIVER => 'Driver do provider nao configurado',
            Host::STATE_BLOCKED_CAPACITY => 'Nenhum provider disponivel',
            Host::STATE_BLOCKED_GOVERNANCE => 'Bloqueado por governanca',
            Host::STATE_BLOCKED => 'Bloqueado',
            Host::STATE_COMPLETED => 'Concluido',
            Host::STATE_FAILED => 'Falhou',
            Host::STATE_REJECTED => 'Rejeitado',
            Host::STATE_ROLLED_BACK => 'Revertido',
            Host::STATE_IDLE => 'Ocioso',
            default => 'Estado desconhecido',
        };
    }

    /**
     * @param  array<string,mixed>  $signals
     */
    public static function humanDetail(string $state, array $signals): string
    {
        $files = (array) ($signals['files_out_of_scope'] ?? []);
        $intakeMissing = (array) ($signals['intake_missing_fields'] ?? []);
        $intakeBlockers = (array) ($signals['intake_blockers'] ?? []);

        return match ($state) {
            Host::STATE_INTAKE_REQUIRED, Host::STATE_BLOCKED_DEFINITION, Host::STATE_READY_TO_DEFINE => match (true) {
                in_array('blocked_missing_objective', $intakeBlockers, true) || in_array('objective', $intakeMissing, true) => 'Falta dizer o objetivo da Obra.',
                in_array('blocked_missing_business_rule', $intakeBlockers, true) || in_array('business_rule', $intakeMissing, true) => 'Falta a regra de negocio que nao pode quebrar.',
                in_array('blocked_missing_acceptance_criteria', $intakeBlockers, true) || in_array('acceptance_criteria', $intakeMissing, true) => 'Faltam criterios de aceite.',
                in_array('blocked_missing_canonical_docs', $intakeBlockers, true) => 'Faltam docs/arquivos de referencia.',
                in_array('spec_or_context_insufficient', $intakeBlockers, true) => 'Definicao ou contexto sao insuficientes para o Forge planejar.',
                default => 'A definicao da Obra (objetivo, regra de negocio, criterios, escopo) ainda nao esta completa.',
            },
            Host::STATE_INTAKE_READY => 'A definicao da Obra esta completa. Voce pode preparar o Forge agora.',
            Host::STATE_READY_TO_PREPARE => 'A Obra esta pronta. Preparar gera spec/plan/tasks sem chamar provider.',
            Host::STATE_PREPARED => 'Spec/Plan/Tasks gerados. Voce pode iniciar a execucao governada.',
            Host::STATE_READY_TO_EXECUTE => 'Tudo pronto para executar. Atlas Decide selecionou provider/modelo.',
            Host::STATE_RUNNING => 'O Forge esta trabalhando. Voce nao precisa fazer nada agora.',
            Host::STATE_WAITING_WORKER => 'A execucao foi enfileirada mas o worker do Forge ainda nao processou.',
            Host::STATE_WAITING_PROVIDER_CONFIRMATION => 'O Atlas detectou que o provider real exige aprovacao explicita.',
            Host::STATE_WAITING_BUDGET_CONFIRMATION => 'O provider externo exige aprovacao de budget antes de qualquer chamada paga.',
            Host::STATE_WAITING_RUNTIME_DISPATCH_CONFIRMATION => 'O dispatch precisa ser explicitamente confirmado antes da invocacao.',
            Host::STATE_WAITING_REVIEW => 'O Forge terminou. Revise as provas antes de permitir completion.',
            Host::STATE_REPAIR_REQUIRED => 'Atlas detectou falha reparavel. Planeje o reparo antes de continuar.',
            Host::STATE_BLOCKED_SCOPE => empty($files)
                ? 'A execucao tentou tocar arquivo fora do escopo permitido.'
                : sprintf(
                    'A execucao tentou alterar %s. Adicione ao "Pode mexer" so se for realmente parte da Obra.',
                    count($files) === 1 ? $files[0] : sprintf('%d arquivos fora do escopo', count($files)),
                ),
            Host::STATE_BLOCKED_PROVIDER => 'Nenhum provider externo aprovado/disponivel atendeu o dispatch.',
            Host::STATE_BLOCKED_DRIVER => 'O driver do provider selecionado nao esta configurado neste ambiente.',
            Host::STATE_BLOCKED_CAPACITY => 'Nenhum provider tem capacidade neste momento.',
            Host::STATE_BLOCKED_GOVERNANCE => 'A execucao foi bloqueada por uma excecao de governanca (governed_execution_exception).',
            Host::STATE_BLOCKED => 'Forge bloqueado honestamente; verifique os blockers.',
            Host::STATE_COMPLETED => 'Entrega aprovada com evidencia.',
            Host::STATE_FAILED => 'A execucao falhou irrecuperavelmente. Inspecione antes de retentar.',
            Host::STATE_REJECTED => 'A revisao humana rejeitou esta execucao.',
            Host::STATE_ROLLED_BACK => 'A promocao foi revertida sob governanca.',
            Host::STATE_IDLE => 'Nada a fazer agora.',
            default => 'Sem detalhes adicionais.',
        };
    }

    public static function primaryActionLabel(string $state): string
    {
        return match ($state) {
            Host::STATE_NO_OBRA => 'Criar ou selecionar Obra',
            Host::STATE_INTAKE_REQUIRED, Host::STATE_READY_TO_DEFINE, Host::STATE_BLOCKED_DEFINITION => 'Completar Definicao',
            Host::STATE_INTAKE_READY, Host::STATE_READY_TO_PREPARE => 'Preparar Forge',
            Host::STATE_PREPARED, Host::STATE_READY_TO_EXECUTE => 'Executar Forge',
            Host::STATE_RUNNING => 'Atualizar Status',
            Host::STATE_WAITING_WORKER => 'Aguardar ou iniciar worker',
            Host::STATE_WAITING_PROVIDER_CONFIRMATION => 'Autorizar chamada',
            Host::STATE_WAITING_BUDGET_CONFIRMATION => 'Autorizar custo',
            Host::STATE_WAITING_RUNTIME_DISPATCH_CONFIRMATION => 'Confirmar Runtime Dispatch',
            Host::STATE_WAITING_REVIEW => 'Abrir revisao',
            Host::STATE_REPAIR_REQUIRED => 'Planejar Reparo',
            Host::STATE_BLOCKED_SCOPE => 'Corrigir escopo',
            Host::STATE_BLOCKED_PROVIDER => 'Ver Capacity',
            Host::STATE_BLOCKED_DRIVER => 'Ver Avancado',
            Host::STATE_BLOCKED_CAPACITY => 'Ver Capacity',
            Host::STATE_BLOCKED_GOVERNANCE => 'Inspecionar Bloqueio',
            Host::STATE_BLOCKED => 'Inspecionar Bloqueio',
            Host::STATE_COMPLETED, Host::STATE_REJECTED, Host::STATE_ROLLED_BACK, Host::STATE_FAILED => 'Ver provas',
            default => 'Atualizar Status',
        };
    }

    public static function primaryActionKind(string $state): string
    {
        return match ($state) {
            Host::STATE_NO_OBRA => Host::ACTION_KIND_BIND_OBRA,
            Host::STATE_INTAKE_REQUIRED, Host::STATE_READY_TO_DEFINE, Host::STATE_BLOCKED_DEFINITION => Host::ACTION_KIND_INTAKE,
            Host::STATE_INTAKE_READY, Host::STATE_READY_TO_PREPARE => Host::ACTION_KIND_PREPARE_FAST_PATH,
            Host::STATE_PREPARED, Host::STATE_READY_TO_EXECUTE => Host::ACTION_KIND_EXECUTE_FAST_PATH,
            Host::STATE_RUNNING => Host::ACTION_KIND_REFRESH_STATUS,
            Host::STATE_WAITING_WORKER => Host::ACTION_KIND_WAIT_WORKER,
            Host::STATE_WAITING_PROVIDER_CONFIRMATION => Host::ACTION_KIND_CONFIRM_PROVIDER,
            Host::STATE_WAITING_BUDGET_CONFIRMATION => Host::ACTION_KIND_CONFIRM_BUDGET,
            Host::STATE_WAITING_RUNTIME_DISPATCH_CONFIRMATION => Host::ACTION_KIND_CONFIRM_RUNTIME_DISPATCH,
            Host::STATE_WAITING_REVIEW => Host::ACTION_KIND_OPEN_REVIEW,
            Host::STATE_REPAIR_REQUIRED => Host::ACTION_KIND_PLAN_REPAIR,
            Host::STATE_BLOCKED_SCOPE => Host::ACTION_KIND_FIX_SCOPE,
            Host::STATE_BLOCKED_PROVIDER, Host::STATE_BLOCKED_DRIVER, Host::STATE_BLOCKED_CAPACITY, Host::STATE_BLOCKED_GOVERNANCE => Host::ACTION_KIND_OPEN_ADVANCED,
            Host::STATE_COMPLETED, Host::STATE_REJECTED, Host::STATE_ROLLED_BACK, Host::STATE_FAILED => Host::ACTION_KIND_VIEW_EVIDENCE,
            default => Host::ACTION_KIND_REFRESH_STATUS,
        };
    }

    /**
     * @param  array<string,mixed>  $signals
     */
    public static function primaryActionEnabled(string $state, array $signals): bool
    {
        return match ($state) {
            Host::STATE_NO_OBRA => false,
            Host::STATE_BLOCKED_CAPACITY => false,
            Host::STATE_WAITING_WORKER => true, // refresh is safe
            default => true,
        };
    }

    /**
     * @param  array<string,mixed>  $signals
     */
    public static function primaryActionDisabledReason(string $state, array $signals): ?string
    {
        if ($state === Host::STATE_NO_OBRA) {
            return 'obra_not_bound';
        }
        if ($state === Host::STATE_BLOCKED_CAPACITY) {
            return 'provider_capacity_exhausted';
        }

        return null;
    }

    /**
     * @param  array<string,mixed>  $signals
     */
    public static function nextSafeStep(string $state, array $signals): string
    {
        $files = (array) ($signals['files_out_of_scope'] ?? []);

        return match ($state) {
            Host::STATE_NO_OBRA => 'Crie ou selecione uma Obra no rail esquerdo.',
            Host::STATE_INTAKE_REQUIRED, Host::STATE_READY_TO_DEFINE, Host::STATE_BLOCKED_DEFINITION => 'Abra a aba Definir e preencha os campos faltantes.',
            Host::STATE_INTAKE_READY, Host::STATE_READY_TO_PREPARE => 'Clique em Preparar Forge para gerar spec/plan/tasks.',
            Host::STATE_PREPARED, Host::STATE_READY_TO_EXECUTE => 'Clique em Executar Forge para enfileirar runtime governado.',
            Host::STATE_RUNNING => 'Aguarde o polling. Voce nao precisa fazer nada agora.',
            Host::STATE_WAITING_WORKER => sprintf('A fila atlas-code-forge ainda nao processou (queued ha %ds). Aguarde ou inicie o worker em Avancado.', (int) ($signals['queue_stale_seconds'] ?? 0)),
            Host::STATE_WAITING_PROVIDER_CONFIRMATION => 'Abra Avancado > Provider Invocation e marque confirm_provider_call.',
            Host::STATE_WAITING_BUDGET_CONFIRMATION => 'Abra Avancado > Provider Invocation e marque confirm_budget apos validar custo.',
            Host::STATE_WAITING_RUNTIME_DISPATCH_CONFIRMATION => 'Confirme runtime dispatch antes da invocacao.',
            Host::STATE_WAITING_REVIEW => 'Abra a aba Revisar para aprovar, rejeitar ou rollback.',
            Host::STATE_REPAIR_REQUIRED => 'Inspecione o failure packet e planeje patch minimo.',
            Host::STATE_BLOCKED_SCOPE => empty($files)
                ? 'Adicione o arquivo afetado em "Pode mexer" ou ajuste o plano para nao tocar fora do escopo.'
                : sprintf('Adicione %s ao "Pode mexer" na aba Definir, ou ajuste o plano para nao tocar nesse arquivo.', $files[0]),
            Host::STATE_BLOCKED_PROVIDER => 'Abra Avancado > Capacity para entender qual provider falhou.',
            Host::STATE_BLOCKED_DRIVER => 'Abra Avancado > Provider Driver Status e configure o driver real.',
            Host::STATE_BLOCKED_CAPACITY => 'Aguarde capacity ou troque a estrategia. Atlas Decide bloqueia honestamente.',
            Host::STATE_BLOCKED_GOVERNANCE => 'Abra Avancado > Stage Timeline para entender qual etapa lancou a excecao.',
            Host::STATE_BLOCKED => 'Inspecione os blockers; sem flags de runtime, nada e executado.',
            Host::STATE_COMPLETED => 'Veja as provas geradas e o receipt da completion claim.',
            Host::STATE_REJECTED => 'Inspecione a razao da rejeicao antes de retomar.',
            Host::STATE_ROLLED_BACK => 'O rollback restaurou o estado anterior. Investigue antes de promover novamente.',
            Host::STATE_FAILED => 'Inspecione o failure packet antes de retentar.',
            Host::STATE_IDLE => 'Sem proximo passo agora.',
            default => 'Continue o Forge pelo botao primario.',
        };
    }

    /**
     * @param  array<string,mixed>  $signals
     * @return list<string>
     */
    public static function resolveBlockers(string $state, array $signals): array
    {
        $blockers = [];
        if (($signals['capacity_exhausted'] ?? false) || $state === Host::STATE_BLOCKED_CAPACITY) {
            $blockers[] = 'provider_capacity_exhausted';
        }
        if ($state === Host::STATE_BLOCKED_SCOPE) {
            $blockers[] = 'files_outside_task_contract';
        }
        if ($state === Host::STATE_BLOCKED_DEFINITION) {
            foreach ((array) ($signals['intake_blockers'] ?? []) as $code) {
                if (is_string($code) && $code !== '') {
                    $blockers[] = $code;
                }
            }
            foreach ((array) ($signals['intake_missing_fields'] ?? []) as $field) {
                $blockers[] = 'blocked_missing_'.$field;
            }
        }
        if ($state === Host::STATE_BLOCKED_DRIVER) {
            $blockers[] = 'provider_driver_not_configured';
        }
        if ($state === Host::STATE_BLOCKED_PROVIDER) {
            $blockers[] = 'provider_unavailable';
        }
        if ($state === Host::STATE_BLOCKED_GOVERNANCE) {
            foreach ((array) ($signals['execution_remaining_blockers'] ?? []) as $code) {
                if (is_string($code) && $code !== '') {
                    $blockers[] = $code;
                }
            }
            if ($blockers === []) {
                $blockers[] = 'governed_execution_exception';
            }
        }
        if ($state === Host::STATE_WAITING_WORKER) {
            $blockers[] = 'worker_queue_stale';
        }
        if ($state === Host::STATE_WAITING_PROVIDER_CONFIRMATION) {
            $blockers[] = 'operator_provider_approval_required';
        }
        if ($state === Host::STATE_WAITING_BUDGET_CONFIRMATION) {
            $blockers[] = 'budget_approval_required';
        }
        if ($state === Host::STATE_WAITING_RUNTIME_DISPATCH_CONFIRMATION) {
            $blockers[] = 'runtime_dispatch_confirmation_required';
        }
        if ($state === Host::STATE_REPAIR_REQUIRED) {
            $blockers[] = 'repair_required';
        }
        if ($state === Host::STATE_BLOCKED && $blockers === []) {
            $blockers[] = 'forge_blocked';
        }

        return array_values(array_unique($blockers));
    }

    /**
     * Map technical blocker codes to a stable, human-facing translation block.
     *
     * @param  array<string,mixed>  $signals
     * @return array<string,mixed>
     */
    public static function resolveBlockerTranslation(string $state, array $signals): array
    {
        $files = (array) ($signals['files_out_of_scope'] ?? []);
        $remaining = (array) ($signals['execution_remaining_blockers'] ?? []);

        $translation = [
            'kind' => null,
            'human_title' => null,
            'human_detail' => null,
            'suggested_action_label' => null,
            'suggested_action_kind' => null,
            'technical_detail' => null,
            'files_out_of_scope' => array_values($files),
            'is_blocking' => false,
        ];

        switch ($state) {
            case Host::STATE_BLOCKED_SCOPE:
                $translation = array_merge($translation, [
                    'kind' => 'blocked_scope',
                    'human_title' => 'Bloqueado por escopo',
                    'human_detail' => 'A execucao tentou tocar arquivo fora do escopo permitido.',
                    'suggested_action_label' => 'Corrigir escopo',
                    'suggested_action_kind' => Host::ACTION_KIND_FIX_SCOPE,
                    'technical_detail' => $remaining ? implode(', ', array_map('strval', $remaining)) : 'files_outside_task_contract',
                    'is_blocking' => true,
                ]);
                break;
            case Host::STATE_BLOCKED_DEFINITION:
            case Host::STATE_INTAKE_REQUIRED:
            case Host::STATE_READY_TO_DEFINE:
                $intakeBlockers = (array) ($signals['intake_blockers'] ?? []);
                $missing = (array) ($signals['intake_missing_fields'] ?? []);
                $primaryCode = $intakeBlockers[0] ?? ($missing[0] ?? null);
                [$title, $detail, $actionLabel] = self::translateDefinitionBlocker($primaryCode);
                $translation = array_merge($translation, [
                    'kind' => 'blocked_definition',
                    'human_title' => $title,
                    'human_detail' => $detail,
                    'suggested_action_label' => $actionLabel,
                    'suggested_action_kind' => Host::ACTION_KIND_INTAKE,
                    'technical_detail' => $primaryCode,
                    'is_blocking' => $state !== Host::STATE_READY_TO_DEFINE,
                ]);
                break;
            case Host::STATE_WAITING_WORKER:
                $translation = array_merge($translation, [
                    'kind' => 'waiting_worker',
                    'human_title' => 'Execucao aguardando worker',
                    'human_detail' => sprintf('A fila atlas-code-forge ainda nao processou (queued ha %ds).', (int) ($signals['queue_stale_seconds'] ?? 0)),
                    'suggested_action_label' => 'Aguardar ou iniciar worker',
                    'suggested_action_kind' => Host::ACTION_KIND_WAIT_WORKER,
                    'technical_detail' => 'queue=atlas-code-forge stale_seconds='.(int) ($signals['queue_stale_seconds'] ?? 0),
                    'is_blocking' => true,
                ]);
                break;
            case Host::STATE_BLOCKED_CAPACITY:
                $translation = array_merge($translation, [
                    'kind' => 'blocked_capacity',
                    'human_title' => 'Nenhum provider disponivel',
                    'human_detail' => 'Atlas Decide nao encontrou provider capaz neste momento.',
                    'suggested_action_label' => 'Ver Capacity',
                    'suggested_action_kind' => Host::ACTION_KIND_OPEN_ADVANCED,
                    'technical_detail' => 'provider_capacity_exhausted',
                    'is_blocking' => true,
                ]);
                break;
            case Host::STATE_BLOCKED_PROVIDER:
                $translation = array_merge($translation, [
                    'kind' => 'blocked_provider',
                    'human_title' => 'Provider indisponivel',
                    'human_detail' => 'Nenhum provider externo aprovado/disponivel atendeu o dispatch.',
                    'suggested_action_label' => 'Ver Capacity',
                    'suggested_action_kind' => Host::ACTION_KIND_OPEN_ADVANCED,
                    'technical_detail' => $remaining ? implode(', ', array_map('strval', $remaining)) : 'provider_unavailable',
                    'is_blocking' => true,
                ]);
                break;
            case Host::STATE_BLOCKED_DRIVER:
                $translation = array_merge($translation, [
                    'kind' => 'blocked_driver',
                    'human_title' => 'Driver do provider nao configurado',
                    'human_detail' => 'O Atlas tem decisao de provider mas o driver real nao esta plugado.',
                    'suggested_action_label' => 'Ver Avancado',
                    'suggested_action_kind' => Host::ACTION_KIND_OPEN_ADVANCED,
                    'technical_detail' => 'provider_driver_not_configured',
                    'is_blocking' => true,
                ]);
                break;
            case Host::STATE_BLOCKED_GOVERNANCE:
                $translation = array_merge($translation, [
                    'kind' => 'blocked_governance',
                    'human_title' => 'Bloqueado por governanca',
                    'human_detail' => 'O Forge lancou uma excecao governada (governed_execution_exception).',
                    'suggested_action_label' => 'Inspecionar Bloqueio',
                    'suggested_action_kind' => Host::ACTION_KIND_OPEN_ADVANCED,
                    'technical_detail' => $remaining ? implode(', ', array_map('strval', $remaining)) : 'governed_execution_exception',
                    'is_blocking' => true,
                ]);
                break;
            case Host::STATE_WAITING_PROVIDER_CONFIRMATION:
            case Host::STATE_WAITING_BUDGET_CONFIRMATION:
            case Host::STATE_WAITING_RUNTIME_DISPATCH_CONFIRMATION:
                $translation = array_merge($translation, [
                    'kind' => 'waiting_provider_approval',
                    'human_title' => 'Aguardando aprovacao de provider/custo',
                    'human_detail' => 'Atlas nao chama provider real sem confirmacoes explicitas.',
                    'suggested_action_label' => 'Autorizar chamada',
                    'suggested_action_kind' => self::primaryActionKind($state),
                    'technical_detail' => $state,
                    'is_blocking' => true,
                ]);
                break;
            case Host::STATE_REPAIR_REQUIRED:
                $translation = array_merge($translation, [
                    'kind' => 'repair_required',
                    'human_title' => 'Reparo necessario',
                    'human_detail' => 'Atlas detectou falha reparavel. Planeje patch minimo antes de continuar.',
                    'suggested_action_label' => 'Planejar Reparo',
                    'suggested_action_kind' => Host::ACTION_KIND_PLAN_REPAIR,
                    'technical_detail' => 'repair_available=true',
                    'is_blocking' => true,
                ]);
                break;
        }

        return $translation;
    }

    /**
     * @return array{0:string,1:string,2:string}
     */
    public static function translateDefinitionBlocker(?string $code): array
    {
        return match ($code) {
            'blocked_missing_objective', 'objective' => ['Falta dizer o objetivo', 'Atlas precisa saber o que voce quer entregar.', 'Preencher objetivo'],
            'blocked_missing_business_rule', 'business_rule' => ['Falta regra de negocio', 'Atlas precisa saber qual regra nao pode quebrar.', 'Preencher regra'],
            'blocked_missing_acceptance_criteria', 'acceptance_criteria' => ['Faltam criterios de aceite', 'Atlas precisa saber como saberemos que deu certo.', 'Preencher criterios'],
            'blocked_missing_canonical_docs' => ['Faltam docs de referencia', 'Aponte arquivos canon que devem governar a implementacao.', 'Adicionar docs'],
            'spec_or_context_insufficient' => ['Definicao ou contexto insuficiente', 'A definicao atual nao da pra Atlas montar um plano com seguranca.', 'Completar Definicao'],
            default => ['Definir a Obra', 'Complete a definicao para liberar o Forge.', 'Completar Definicao'],
        };
    }

    /**
     * @param  array<string,mixed>  $signals
     */
    public static function definitionStatus(array $signals): string
    {
        if (! empty($signals['intake_blockers']) || ! empty($signals['intake_missing_fields'])) {
            return 'blocking_execution';
        }
        if ($signals['intake_ready'] ?? false) {
            return 'ready';
        }

        return 'incomplete';
    }

    /**
     * @param  array<string,mixed>  $liveExecution
     * @param  array<string,mixed>  $liveExecutionAsync
     * @return list<string>
     */
    public static function collectFilesOutOfScope(array $liveExecution, array $liveExecutionAsync): array
    {
        $files = [];
        foreach ([$liveExecution, $liveExecutionAsync] as $exec) {
            foreach ((array) data_get($exec, 'issues', []) as $issue) {
                if (! is_array($issue)) {
                    continue;
                }
                $code = (string) ($issue['code'] ?? '');
                $path = (string) ($issue['path'] ?? '');
                if (str_contains($code, 'out_of_scope') && $path !== '') {
                    $files[] = $path;
                }
            }
            foreach ((array) data_get($exec, 'scope_violations', []) as $entry) {
                if (is_string($entry) && $entry !== '') {
                    $files[] = $entry;
                } elseif (is_array($entry)) {
                    $path = (string) ($entry['path'] ?? '');
                    if ($path !== '') {
                        $files[] = $path;
                    }
                }
            }
            foreach ((array) data_get($exec, 'rejected_files', []) as $entry) {
                if (is_string($entry) && $entry !== '') {
                    $files[] = $entry;
                }
            }
        }

        return array_values(array_unique($files));
    }

    /**
     * @param  array<string,mixed>  $signals
     * @param  array<string,mixed>  $advancedRefs
     * @return array<string,mixed>
     */
    public static function finalize(
        string $state,
        ?string $obraId,
        bool $obraPresent,
        string $generatedAt,
        array $blockers,
        array $signals,
        string $humanLabel,
        string $humanDetail,
        string $primaryActionLabel,
        string $primaryActionKind,
        bool $primaryActionEnabled,
        ?string $primaryActionDisabledReason,
        string $nextSafeStep,
        array $advancedRefs = [],
    ): array {
        $providerCalled = (bool) ($signals['provider_called'] ?? false);
        $externalCall = (bool) ($signals['external_provider_call'] ?? false);
        $completionPromoted = (bool) ($signals['completion_claim_promoted'] ?? false);
        $reviewRequired = (bool) ($signals['review_required'] ?? false);
        $finalAllowed = (bool) ($signals['final_completion_allowed'] ?? false);

        $blockerTranslation = self::resolveBlockerTranslation($state, $signals);

        return [
            'schema_version' => Host::SCHEMA_VERSION,
            'generated_at' => $generatedAt,
            'obra_id' => $obraId,
            'obra_present' => $obraPresent,
            'state' => $state,
            'human_status_label' => $humanLabel,
            'human_status_detail' => $humanDetail,
            'primary_action_label' => $primaryActionLabel,
            'primary_action_kind' => $primaryActionKind,
            'primary_action_enabled' => $primaryActionEnabled,
            'primary_action_disabled_reason' => $primaryActionDisabledReason,
            'next_safe_step' => $nextSafeStep,
            'blockers' => $blockers,
            'blocker_translation' => $blockerTranslation,
            'definition_status' => self::definitionStatus($signals),
            'completion_gating' => [
                'review_required' => $reviewRequired,
                'final_completion_allowed' => $finalAllowed,
                'approve_button_visible' => $reviewRequired || in_array($state, [Host::STATE_WAITING_REVIEW, Host::STATE_REPAIR_REQUIRED], true),
                'reject_button_visible' => $reviewRequired || in_array($state, [Host::STATE_WAITING_REVIEW, Host::STATE_REPAIR_REQUIRED], true),
                'rollback_button_visible' => in_array($state, [Host::STATE_COMPLETED, Host::STATE_REJECTED, Host::STATE_ROLLED_BACK], true),
            ],
            'safety_summary' => [
                'external_provider_call' => $externalCall,
                'provider_tokens_spent' => $providerCalled && $externalCall ? 'unknown' : 0,
                'completion_claim_promoted' => $completionPromoted,
                'review_completion_gate_preserved' => true,
            ],
            'provider_summary' => [
                'provider' => $signals['provider'] ?? null,
                'model' => $signals['model'] ?? null,
                'decision_source' => $signals['decision_source'] ?? 'unknown',
                'capacity_state' => $signals['capacity_state'] ?? 'unknown',
                'driver_configured' => (bool) ($signals['driver_configured_for_selected'] ?? false),
            ],
            'evidence_summary' => [
                'evidence_ref_count' => (int) ($signals['evidence_ref_count'] ?? 0),
                'ledger_event_count' => (int) ($signals['ledger_event_count'] ?? 0),
            ],
            'evidence_separation' => [
                'obra_evidence_ref_count' => (int) ($signals['evidence_ref_count'] ?? 0),
                'obra_ledger_event_count' => (int) ($signals['ledger_event_count'] ?? 0),
                'system_certification_visible' => true,
                'note' => 'Provas desta Obra sao distintas das Certificacoes do sistema Atlas. UI deve segregar.',
            ],
            'review_summary' => [
                'review_required' => $reviewRequired,
                'review_status' => $signals['review_status'] ?? 'pending',
                'human_approved' => (bool) ($signals['human_approved'] ?? false),
                'final_completion_allowed' => $finalAllowed,
            ],
            'checklist' => [
                'obra' => $obraPresent,
                'intake' => (bool) ($signals['intake_ready'] ?? false),
                'spec_plan' => (bool) ($signals['spec_plan_ready'] ?? false),
                'provider' => ($signals['provider'] ?? null) !== null,
                'execution' => in_array($signals['execution_status'] ?? null, ['passed', 'degraded'], true),
                'review' => ($signals['review_status'] ?? '') === 'approved',
                'evidence' => ((int) ($signals['evidence_ref_count'] ?? 0)) > 0,
            ],
            'chat_message_kinds' => Host::canonicalChatMessageKinds(),
            'progress_percent' => self::progressPercent($state, $signals),
            'signals' => $signals,
            'advanced_refs' => $advancedRefs,
            'external_provider_call' => $externalCall,
            'provider_tokens_spent' => $providerCalled && $externalCall ? 'unknown' : false,
            'completion_claim_promoted' => $completionPromoted,
            'separated_from' => 'external_rivals_certification',
            'note' => 'Atlas Code Forge UX Orchestrator: camada humana sobre runtime governado. Nunca chama provider externo nem promove completion claim. Detalhes tecnicos vivem na aba Avancado.',
        ];
    }

    /**
     * @param  array<string,mixed>  $signals
     */
    public static function progressPercent(string $state, array $signals): int
    {
        $order = [
            Host::STATE_NO_OBRA => 0,
            Host::STATE_IDLE => 0,
            Host::STATE_READY_TO_DEFINE => 5,
            Host::STATE_INTAKE_REQUIRED => 10,
            Host::STATE_BLOCKED_DEFINITION => 15,
            Host::STATE_INTAKE_READY => 25,
            Host::STATE_READY_TO_PREPARE => 30,
            Host::STATE_PREPARED => 45,
            Host::STATE_READY_TO_EXECUTE => 55,
            Host::STATE_RUNNING => 65,
            Host::STATE_WAITING_WORKER => 60,
            Host::STATE_WAITING_PROVIDER_CONFIRMATION => 70,
            Host::STATE_WAITING_BUDGET_CONFIRMATION => 72,
            Host::STATE_WAITING_RUNTIME_DISPATCH_CONFIRMATION => 74,
            Host::STATE_WAITING_REVIEW => 85,
            Host::STATE_REPAIR_REQUIRED => 60,
            Host::STATE_BLOCKED_SCOPE => 35,
            Host::STATE_BLOCKED_PROVIDER => 30,
            Host::STATE_BLOCKED_DRIVER => 30,
            Host::STATE_BLOCKED_CAPACITY => 25,
            Host::STATE_BLOCKED_GOVERNANCE => 35,
            Host::STATE_BLOCKED => 35,
            Host::STATE_COMPLETED => 100,
            Host::STATE_FAILED => 90,
            Host::STATE_REJECTED => 90,
            Host::STATE_ROLLED_BACK => 80,
        ];

        return (int) ($order[$state] ?? 0);
    }
}
