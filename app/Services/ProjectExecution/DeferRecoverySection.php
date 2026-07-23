<?php

namespace App\Services\ProjectExecution;

use App\Models\AtlasProject;
use App\Models\AtlasTask;
use Illuminate\Support\Str;

/**
 * Defer classification and recovery-action helpers for ProjectExecutionService.
 * Pure derivations; no persistence.
 */
class DeferRecoverySection
{
    public function __construct(
        private readonly ProjectExecutionSupport $support,
    ) {}

    public function deferReasonCode(?string $reasonCode, ?string $reason): string
    {
        if (in_array($reasonCode, ['low_energy', 'too_big', 'unclear', 'blocked', 'waiting', 'calendar', 'avoidance', 'not_now'], true)) {
            return (string) $reasonCode;
        }

        $lower = Str::lower((string) $reason);
        if (preg_match('/\b(energia|cansad|sono|exaust|sem energia)\b/u', $lower)) {
            return 'low_energy';
        }
        if (preg_match('/\b(grande|muito|complex|escopo|longo)\b/u', $lower)) {
            return 'too_big';
        }
        if (preg_match('/\b(confus|claro|clareza|nao sei|não sei)\b/u', $lower)) {
            return 'unclear';
        }
        if (preg_match('/\b(bloque|depend|trav|imped)\b/u', $lower)) {
            return 'blocked';
        }
        if (preg_match('/\b(esper|aguard|retorno|resposta)\b/u', $lower)) {
            return 'waiting';
        }
        if (preg_match('/\b(reuni[aã]o|agenda|hor[aá]rio|calend[aá]rio|compromisso)\b/u', $lower)) {
            return 'calendar';
        }
        if (preg_match('/\b(procrast|resist|evit|chato|tedioso)\b/u', $lower)) {
            return 'avoidance';
        }

        return 'not_now';
    }

    /**
     * @return array{recommended_action: string, recommended_next_action: string, suggested_recovery_minutes: int}
     */
    public function deferSuggestion(AtlasTask $task, AtlasProject $project, string $reasonCode, ?string $reason): array
    {
        $minutes = match ($reasonCode) {
            'low_energy', 'avoidance' => 10,
            'too_big', 'unclear' => 15,
            'blocked', 'waiting' => 10,
            default => min(20, max(10, (int) ($task->estimated_minutes ?: 10))),
        };
        $action = match ($reasonCode) {
            'too_big', 'unclear' => 'rebuild_plan',
            'blocked', 'waiting' => 'ensure_next_action',
            'calendar', 'not_now' => 'postpone',
            default => 'recover',
        };
        $nextAction = match ($reasonCode) {
            'too_big' => 'Quebrar a ação atual em uma versão de 10 a 15 minutos',
            'unclear' => 'Escrever o próximo passo físico antes de executar',
            'blocked' => 'Registrar o bloqueio e a menor ação para destravar',
            'waiting' => 'Registrar quem ou o que está sendo aguardado',
            'calendar' => $task->title,
            'not_now' => $task->title,
            'low_energy' => 'Retomar com uma versão de baixa energia por 10 minutos',
            'avoidance' => 'Começar por 2 minutos e manter só o primeiro bloco',
            default => $task->title,
        };

        return [
            'recommended_action' => $action,
            'recommended_next_action' => $nextAction,
            'suggested_recovery_minutes' => $this->support->clamp($minutes, 5, 45),
        ];
    }

    public function deferReviewLabel(string $action): string
    {
        return match ($action) {
            'rebuild_plan' => 'Replanejar ação adiada',
            'ensure_next_action' => 'Destravar próxima ação',
            'postpone' => 'Manter adiado',
            default => 'Retomar com micro-ação',
        };
    }

    /**
     * @param  array<string, mixed>  $defer
     */
    public function deferReviewReason(array $defer): string
    {
        $reasonCode = (string) ($defer['last_reason_code'] ?? 'not_now');

        return match ($reasonCode) {
            'low_energy' => 'A ação foi adiada por energia baixa; retomar pequeno evita perder o projeto.',
            'too_big' => 'A ação parece grande demais; precisa quebrar antes de voltar para a agenda.',
            'unclear' => 'A ação foi adiada por falta de clareza; precisa virar próximo passo físico.',
            'blocked' => 'A ação foi adiada por bloqueio; precisa explicitar dependência ou destravamento.',
            'waiting' => 'A ação depende de espera; confirme se ainda está aguardando ou se há alternativa.',
            'calendar' => 'A ação foi adiada por agenda; confirme nova janela real.',
            'avoidance' => 'A ação foi adiada por resistência; retomar com micro-ação é melhor que replanejar tudo.',
            default => 'A ação adiada voltou para revisão; escolha retomar, manter adiada ou replanejar.',
        };
    }

    public function recoveryActionTitle(AtlasProject $project, AtlasTask $task): string
    {
        $source = trim((string) ($task->starter_step ?: $task->minimum_viable_action ?: $task->title ?: $project->next_action ?: $project->title));
        $source = preg_replace('/\s+/u', ' ', $source) ?: $source;

        return mb_substr('Retomar: '.$source, 0, 180);
    }

    public function recoveryStarterStep(AtlasProject $project, AtlasTask $task, int $targetMinutes): string
    {
        $starter = trim((string) ($task->starter_step ?: 'abrir o contexto do projeto'));
        $starter = mb_strtolower(mb_substr($starter, 0, 140));

        return "Abrir {$project->title}, {$starter} e trabalhar {$targetMinutes} minutos sem replanejar tudo.";
    }

    public function recoveryMinimumAction(AtlasProject $project, AtlasTask $task, int $targetMinutes): string
    {
        $minimum = trim((string) ($task->minimum_viable_action ?: $task->title));
        $minimum = $minimum !== '' ? mb_substr($minimum, 0, 150) : "um microavanço em {$project->title}";

        return "{$targetMinutes} minutos de retomada ou {$minimum}.";
    }
}
