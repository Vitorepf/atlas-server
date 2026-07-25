<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\Support;

use App\Services\Ai\Programming\AtlasCodeAttentionControlPlaneService as Plane;

/**
 * Pure classification helpers for Atlas Code Attention Control Plane.
 *
 * Extracted from AtlasCodeAttentionControlPlaneService private methods.
 * No I/O, no provider calls, no DB, no filesystem.
 */
final class CodeAttentionClassifier
{
    /**
     * Map UX orchestrator state + signals → attention item fields.
     *
     * @param  array<string,mixed>  $signals
     * @return array{0:?string,1:string,2:string,3:string,4:string,5:list<string>,6:string,7:string}
     */
    public static function classify(string $state, array $signals): array
    {
        $reviewRequired = (bool) ($signals['review_required'] ?? false);
        $humanApproved = (bool) ($signals['human_approved'] ?? false);
        $finalAllowed = (bool) ($signals['final_completion_allowed'] ?? false);
        $reviewStatus = (string) ($signals['review_status'] ?? 'pending');

        // Final acceptance: runtime believes it's done but human hasn't signed off.
        if ($state === 'completed' && ! $humanApproved) {
            return [
                Plane::KIND_FINAL_ACCEPTANCE,
                Plane::SEVERITY_HIGH,
                'A Obra pode ser aceita como concluida?',
                'Runtime sinalizou completion. Falta apenas o aceite humano final.',
                Plane::ACTION_APPROVE,
                [Plane::ACTION_OPEN_OBRA, Plane::ACTION_APPROVE, Plane::ACTION_REJECT, Plane::ACTION_ROLLBACK],
                'Sem aceite, a Obra fica suspensa em estado pre-final.',
                'review',
            ];
        }

        if ($finalAllowed && $reviewStatus === 'approved' && ! $humanApproved) {
            return [
                Plane::KIND_FINAL_ACCEPTANCE,
                Plane::SEVERITY_HIGH,
                'Encerrar a Obra com aceite humano?',
                'Revisao aprovou e completion claim esta liberada. Falta a assinatura humana.',
                Plane::ACTION_APPROVE,
                [Plane::ACTION_OPEN_OBRA, Plane::ACTION_APPROVE, Plane::ACTION_REJECT, Plane::ACTION_ROLLBACK],
                'A Obra fica pronta-mas-nao-aceita; gates ficam abertos.',
                'review',
            ];
        }

        // Review pending.
        if ($state === 'waiting_review' || ($reviewRequired && $reviewStatus !== 'approved' && $reviewStatus !== 'rejected')) {
            return [
                Plane::KIND_REVIEW_NEEDED,
                Plane::SEVERITY_MEDIUM,
                'A Obra pode ir para revisao final?',
                'Execucao governada terminou. Revise diffs, gates e provas antes de qualquer completion.',
                Plane::ACTION_APPROVE,
                [Plane::ACTION_OPEN_OBRA, Plane::ACTION_APPROVE, Plane::ACTION_REQUEST_REPAIR, Plane::ACTION_REJECT],
                'Sem revisao, completion fica bloqueada e novos passos podem ser construidos sobre base nao auditada.',
                'review',
            ];
        }

        if ($state === 'repair_required') {
            return [
                Plane::KIND_REPAIR_DECISION,
                Plane::SEVERITY_HIGH,
                'Como tratar a falha reparavel detectada?',
                'Atlas detectou falha que pode ser corrigida com patch minimo, mas precisa de orientacao humana.',
                Plane::ACTION_REQUEST_REPAIR,
                [Plane::ACTION_OPEN_OBRA, Plane::ACTION_REQUEST_REPAIR, Plane::ACTION_REJECT, Plane::ACTION_PAUSE, Plane::ACTION_ROLLBACK],
                'Sem decisao, o Forge fica parado e o ciclo de reparo nao avanca.',
                'repair',
            ];
        }

        if ($state === 'blocked_scope') {
            $files = (array) ($signals['files_out_of_scope'] ?? []);
            $detail = $files === []
                ? 'A execucao tentou tocar arquivo fora do escopo permitido.'
                : sprintf('A execucao tentou alterar %s, fora de "Pode mexer".', $files[0]);

            return [
                Plane::KIND_SCOPE_DECISION,
                Plane::SEVERITY_MEDIUM,
                'Ampliar o escopo permitido ou bloquear a mudanca?',
                $detail,
                Plane::ACTION_DENY_SCOPE_CHANGE,
                [Plane::ACTION_OPEN_OBRA, Plane::ACTION_APPROVE_SCOPE_CHANGE, Plane::ACTION_DENY_SCOPE_CHANGE, Plane::ACTION_PAUSE],
                'Sem decisao, o Forge fica bloqueado e nenhum patch avanca.',
                'intake',
            ];
        }

        if ($state === 'waiting_provider_confirmation') {
            return [
                Plane::KIND_PROVIDER_APPROVAL,
                Plane::SEVERITY_HIGH,
                'Autorizar a chamada de provider real?',
                'Atlas Decide selecionou provider externo. Atlas nunca chama sem aprovacao explicita.',
                Plane::ACTION_APPROVE_PROVIDER,
                [Plane::ACTION_OPEN_OBRA, Plane::ACTION_APPROVE_PROVIDER, Plane::ACTION_REJECT, Plane::ACTION_PAUSE],
                'Sem aprovacao, nenhuma chamada externa acontece; a Obra fica parada.',
                'advanced',
            ];
        }

        if ($state === 'waiting_budget_confirmation') {
            return [
                Plane::KIND_PROVIDER_APPROVAL,
                Plane::SEVERITY_HIGH,
                'Autorizar o custo da chamada externa?',
                'Antes de qualquer chamada paga, custo precisa de aprovacao explicita.',
                Plane::ACTION_APPROVE_PROVIDER,
                [Plane::ACTION_OPEN_OBRA, Plane::ACTION_APPROVE_PROVIDER, Plane::ACTION_REJECT, Plane::ACTION_PAUSE],
                'Sem aprovacao de custo, a chamada paga nao acontece e a Obra fica parada.',
                'advanced',
            ];
        }

        if ($state === 'waiting_runtime_dispatch_confirmation') {
            return [
                Plane::KIND_RUNTIME_APPROVAL,
                Plane::SEVERITY_MEDIUM,
                'Confirmar runtime dispatch para a invocacao?',
                'Dispatch precisa ser explicitamente confirmado antes de qualquer invocacao do provider.',
                Plane::ACTION_APPROVE_RUNTIME,
                [Plane::ACTION_OPEN_OBRA, Plane::ACTION_APPROVE_RUNTIME, Plane::ACTION_REJECT, Plane::ACTION_PAUSE],
                'Sem confirmacao, dispatch fica em pre-flight e a Obra nao avanca.',
                'advanced',
            ];
        }

        if (in_array($state, ['blocked_definition', 'intake_required', 'ready_to_define'], true)) {
            return [
                Plane::KIND_INTAKE_NEEDED,
                Plane::SEVERITY_LOW,
                'A Obra precisa de definicao mais clara?',
                'Faltam campos da intake (objetivo, regra que nao pode quebrar, criterios de aceite ou escopo).',
                Plane::ACTION_REFINE_INTAKE,
                [Plane::ACTION_OPEN_OBRA, Plane::ACTION_REFINE_INTAKE, Plane::ACTION_DISMISS_WITH_REASON],
                'Sem definicao, qualquer plano gerado depois fica fragil.',
                'intake',
            ];
        }

        if (in_array($state, ['blocked_provider', 'blocked_driver', 'blocked_capacity', 'blocked_governance'], true)) {
            return [
                Plane::KIND_BLOCKED_ATTENTION,
                Plane::SEVERITY_HIGH,
                'A Obra esta bloqueada governada — inspecionar bloqueio?',
                'Forge parou honestamente: provider/driver/capacity/governanca impedem avanco.',
                Plane::ACTION_OPEN_OBRA,
                [Plane::ACTION_OPEN_OBRA, Plane::ACTION_PAUSE, Plane::ACTION_DISMISS_WITH_REASON],
                'Sem inspecao, a Obra continua bloqueada e a causa real nao e tratada.',
                'advanced',
            ];
        }

        return [null, Plane::SEVERITY_LOW, '', '', Plane::ACTION_OPEN_OBRA, [], '', 'overview'];
    }

    /** Severity order for queue sorting. Lower = higher priority. */
    public static function severityRank(string $severity): int
    {
        return match ($severity) {
            Plane::SEVERITY_HIGH => 0,
            Plane::SEVERITY_MEDIUM => 1,
            default => 2,
        };
    }

    /** True when the action mutates obra state / requires a receipt. */
    public static function actionMutates(string $action): bool
    {
        return $action !== Plane::ACTION_OPEN_OBRA;
    }

    /** Map UX state → coarse obra phase label for the attention item. */
    public static function phaseForState(string $state): string
    {
        return match (true) {
            in_array($state, ['blocked_definition', 'intake_required', 'ready_to_define'], true) => 'intake',
            in_array($state, ['blocked_scope'], true) => 'build',
            in_array($state, ['waiting_provider_confirmation', 'waiting_budget_confirmation', 'waiting_runtime_dispatch_confirmation'], true) => 'forge_prep',
            $state === 'waiting_review' => 'review',
            $state === 'repair_required' => 'build',
            $state === 'completed' => 'decision',
            str_starts_with($state, 'blocked') => 'build',
            default => 'overview',
        };
    }

    /**
     * Stable attention item key derived from obra + kind + state hash.
     * Changes when state changes so dismissals are state-scoped.
     */
    public static function itemKey(string $obraId, string $kind, string $state): string
    {
        $hash = substr(hash('sha256', $obraId.'|'.$kind.'|'.$state), 0, 16);

        return 'attn_'.$hash;
    }
}
