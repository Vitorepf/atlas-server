<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfImprovement\Support;

use App\Services\Ai\SelfImprovement\AtlasSelfImprovementActivationCockpitService as Cockpit;
use App\Services\Ai\SelfImprovement\AtlasSelfImprovementForgeActivationService as ForgeActivation;

/**
 * Pure presentation / counter helpers for Self-Improvement Activation Cockpit.
 *
 * No I/O, no Eloquent, no Carbon, no providers. Callers assemble inputs;
 * this class only maps status/arrays → labels, tones, counters and human copy.
 */
final class ActivationCockpitPresentationSupport
{
    /**
     * @param  array<string,mixed>|null  $approval
     * @param  array<string,mixed>|null  $rejection
     */
    public static function approvalState(string $status, ?array $approval, ?array $rejection): string
    {
        if ($status === ForgeActivation::STATUS_OBRA_CREATED) {
            return 'accepted_obra_created';
        }
        if ($status === ForgeActivation::STATUS_ACCEPTED) {
            return 'accepted_pending_materialise';
        }
        if ($status === ForgeActivation::STATUS_REJECTED) {
            return $rejection !== null ? 'rejected_by_human' : 'rejected_by_gate';
        }
        if ($status === ForgeActivation::STATUS_PENDING_HUMAN_REVIEW) {
            return 'awaiting_human_review';
        }
        if ($status === ForgeActivation::STATUS_NEEDS_REVISION) {
            return 'needs_revision';
        }
        if ($status === ForgeActivation::STATUS_DRY_RUN) {
            return 'dry_run_planned';
        }
        if ($approval !== null) {
            return 'accepted_obra_created';
        }

        return 'blocked';
    }

    /**
     * @param  array<string,mixed>|null  $activation
     */
    public static function nextSafeAction(string $status, ?array $activation): string
    {
        $blockers = is_array($activation['blockers'] ?? null) ? $activation['blockers'] : [];
        $missingDocs = is_array($activation['missing_required_docs'] ?? null) ? $activation['missing_required_docs'] : [];

        if ($missingDocs !== []) {
            return 'Adicionar docs canônicas obrigatórias antes de aprovar.';
        }
        if ($status === ForgeActivation::STATUS_OBRA_CREATED) {
            return 'Abrir Obra no Forge — Fast Path NÃO executa sozinho.';
        }
        if ($status === ForgeActivation::STATUS_PENDING_HUMAN_REVIEW) {
            return 'Revisor humano deve aprovar com reviewer + reason.';
        }
        if ($status === ForgeActivation::STATUS_NEEDS_REVISION) {
            return 'Reescrever proposta para corrigir hard fails do power gate.';
        }
        if ($status === ForgeActivation::STATUS_REJECTED) {
            return 'Reescrever proposta ou encerrar — nada virou Obra.';
        }
        if ($status === ForgeActivation::STATUS_BLOCKED) {
            return 'Resolver blockers ('.implode(', ', $blockers).') antes de replanejar.';
        }
        if ($status === ForgeActivation::STATUS_DRY_RUN) {
            return 'Replanejar sem dry-run para registrar a activation.';
        }
        if ($status === ForgeActivation::STATUS_ACCEPTED) {
            return 'Confirmar criação da Obra — Forge não executa automaticamente.';
        }

        return 'Inspecionar status no detalhe.';
    }

    public static function statusLabel(string $status): string
    {
        return match ($status) {
            ForgeActivation::STATUS_BLOCKED => 'Bloqueada',
            ForgeActivation::STATUS_NEEDS_REVISION => 'Precisa revisão',
            ForgeActivation::STATUS_PENDING_HUMAN_REVIEW => 'Aguardando humano',
            ForgeActivation::STATUS_REJECTED => 'Rejeitada',
            ForgeActivation::STATUS_ACCEPTED => 'Aceita',
            ForgeActivation::STATUS_OBRA_CREATED => 'Obra criada',
            ForgeActivation::STATUS_DRY_RUN => 'Dry-run',
            default => 'Desconhecido',
        };
    }

    public static function statusTone(string $status): string
    {
        return match ($status) {
            ForgeActivation::STATUS_OBRA_CREATED,
            ForgeActivation::STATUS_ACCEPTED => Cockpit::TONE_MOSS,
            ForgeActivation::STATUS_PENDING_HUMAN_REVIEW,
            ForgeActivation::STATUS_NEEDS_REVISION,
            ForgeActivation::STATUS_DRY_RUN => Cockpit::TONE_BRONZE,
            ForgeActivation::STATUS_REJECTED,
            ForgeActivation::STATUS_BLOCKED => Cockpit::TONE_REC_RED,
            default => Cockpit::TONE_INK,
        };
    }

    /**
     * @return array<string,int>
     */
    public static function emptyCounters(): array
    {
        return [
            'total' => 0,
            'blocked' => 0,
            'needs_revision' => 0,
            'pending_human_review' => 0,
            'rejected' => 0,
            'accepted' => 0,
            'obra_created' => 0,
            'dry_run_planned' => 0,
            'with_obra' => 0,
            'with_blockers' => 0,
        ];
    }

    /**
     * @param  array<string,int>  $counters
     * @param  array<string,mixed>  $row
     */
    public static function incrementCounters(array &$counters, array $row): void
    {
        $counters['total']++;
        $status = (string) ($row['status'] ?? 'blocked');
        if (isset($counters[$status])) {
            $counters[$status]++;
        }
        if (($row['created_obra_id'] ?? null) !== null) {
            $counters['with_obra']++;
        }
        if (($row['has_blockers'] ?? false) === true) {
            $counters['with_blockers']++;
        }
    }

    /**
     * @param  array<string,int>  $counters
     * @param  array<string,mixed>|null  $selected
     */
    public static function cockpitHumanSummary(array $counters, ?array $selected): string
    {
        if ($selected !== null) {
            $label = (string) ($selected['status_label'] ?? 'Activation');
            $title = (string) (data_get($selected, 'proposal_summary.title') ?? 'sem título');

            return sprintf('%s — %s. %s', $label, $title, (string) ($selected['next_safe_action'] ?? ''));
        }
        if ($counters['total'] === 0) {
            return 'Nenhuma activation registrada. Crie uma proposta para começar.';
        }
        $pending = $counters['pending_human_review'] + $counters['needs_revision'];
        if ($pending > 0) {
            return sprintf('%d activations pedindo revisão humana, %d viraram Obra.', $pending, $counters['obra_created']);
        }

        return sprintf('%d activations no total · %d criaram Obra · %d rejeitadas.',
            $counters['total'],
            $counters['obra_created'],
            $counters['rejected'],
        );
    }

    /**
     * @param  array<string,int>  $counters
     * @param  array<string,mixed>|null  $selected
     */
    public static function cockpitNextSafeAction(array $counters, ?array $selected): string
    {
        if ($selected !== null) {
            return (string) ($selected['next_safe_action'] ?? 'Inspecionar activation.');
        }
        if (($counters['pending_human_review'] ?? 0) > 0) {
            return 'Revisar activations aguardando humano antes de criar novas propostas.';
        }
        if ($counters['total'] === 0) {
            return 'Planejar primeira proposta via CLI ou POST /atlas-code/self-improvement/forge-activations.';
        }

        return 'Manter ciclo: planejar → revisar → aceitar/rejeitar → abrir Obra no Forge.';
    }

    /**
     * @param  array<string,mixed>  $packet
     * @param  array<string,mixed>  $gate  reserved (signature parity with service)
     * @param  array<string,mixed>|null  $createdObra  reserved (signature parity with service)
     * @param  array<string,mixed>|null  $rejection
     */
    public static function detailHumanSummary(
        string $status,
        array $packet,
        array $gate,
        ?array $createdObra,
        ?array $rejection,
    ): string {
        unset($gate, $createdObra);

        $title = self::stringOrNull($packet['title'] ?? null) ?? 'Proposta';

        return match ($status) {
            ForgeActivation::STATUS_OBRA_CREATED => sprintf('Obra criada a partir de "%s" — abrir no Forge para continuar.', $title),
            ForgeActivation::STATUS_ACCEPTED => sprintf('Aceita "%s", aguardando materialização da Obra.', $title),
            ForgeActivation::STATUS_PENDING_HUMAN_REVIEW => sprintf('"%s" passou no power gate mas exige aprovação humana explícita.', $title),
            ForgeActivation::STATUS_NEEDS_REVISION => sprintf('"%s" tem hard fails que precisam ser corrigidos antes de virar Obra.', $title),
            ForgeActivation::STATUS_REJECTED => sprintf('"%s" foi rejeitada%s.', $title, $rejection !== null ? ' por um humano' : ' pelo power gate'),
            ForgeActivation::STATUS_BLOCKED => sprintf('"%s" está bloqueada por blockers de governança.', $title),
            ForgeActivation::STATUS_DRY_RUN => sprintf('"%s" é um dry-run; nada foi persistido.', $title),
            default => sprintf('"%s" em estado %s.', $title, $status),
        };
    }

    private static function stringOrNull(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
