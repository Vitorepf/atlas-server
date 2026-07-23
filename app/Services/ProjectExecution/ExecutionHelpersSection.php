<?php

namespace App\Services\ProjectExecution;

use App\Models\AtlasProject;
use App\Models\AtlasProjectStep;
use App\Models\AtlasTask;
use App\Services\Ai\Support\DatabaseTableAvailability;
use Carbon\CarbonImmutable;

/**
 * Execution-packet and completion helpers for ProjectExecutionService.
 * Read-only derivations over task/step/project state (including project
 * completion readiness); no persistence.
 */
class ExecutionHelpersSection
{
    public function __construct(
        private readonly ProjectExecutionSupport $support,
    ) {}

    /**
     * @return array<int, string>
     */
    public function executionWhy(
        AtlasProject $project,
        AtlasTask $task,
        ?AtlasProjectStep $step,
        int $estimatedMinutes,
        int $timebox,
        ?int $availableMinutes,
        ?int $energyLevel,
    ): array {
        $why = [];
        $why[] = $step
            ? "É a etapa ativa {$step->step_order}: {$step->title}."
            : 'É a próxima ação ativa do projeto.';

        if ($availableMinutes !== null && $availableMinutes < $estimatedMinutes) {
            $why[] = "O bloco foi limitado a {$timebox}min porque você informou {$availableMinutes}min disponíveis.";
        } elseif ($energyLevel !== null && $energyLevel <= 2 && $timebox < $estimatedMinutes) {
            $why[] = "O bloco foi reduzido para {$timebox}min por energia baixa.";
        } elseif ($energyLevel !== null && $energyLevel <= 2) {
            $why[] = "Energia baixa foi considerada; a ação já cabe em {$timebox}min.";
        } elseif ((int) $task->friction_level >= 75 && $timebox < $estimatedMinutes) {
            $why[] = "O bloco foi reduzido para {$timebox}min porque a fricção está alta.";
        } else {
            $why[] = "A duração vem da estimativa atual de {$estimatedMinutes}min.";
        }

        $metadata = is_array($task->metadata) ? $task->metadata : [];
        $calibration = is_array($metadata['estimate_calibration'] ?? null) ? $metadata['estimate_calibration'] : [];
        if (($calibration['applied'] ?? false) && isset($calibration['base_minutes'], $calibration['calibrated_minutes'])) {
            $why[] = "A estimativa foi ajustada de {$calibration['base_minutes']}min para {$calibration['calibrated_minutes']}min pelo seu histórico de execução.";
        }

        if ($task->execution_mode === 'recovery') {
            $why[] = 'Modo retomada: a ação foi encurtada para vencer inércia sem replanejar tudo.';
        } elseif ((int) $task->recovery_count > 0) {
            $why[] = "Esta ação já teve {$task->recovery_count} retomada(s), então o Atlas deve manter o próximo passo pequeno.";
        }

        if ($project->next_review_at) {
            $why[] = 'O projeto fica marcado para revisão curta depois da execução.';
        }

        return array_values(array_filter($why));
    }

    public function calibratedEstimateForProject(AtlasProject $project, ?AtlasProjectStep $step, int $baseMinutes): int
    {
        $ratio = null;
        if ($step) {
            $stepMetadata = is_array($step->metadata) ? $step->metadata : [];
            $stepLearning = is_array($stepMetadata['execution_learning'] ?? null) ? $stepMetadata['execution_learning'] : [];
            $ratio = is_numeric($stepLearning['average_estimate_ratio'] ?? null)
                ? (float) $stepLearning['average_estimate_ratio']
                : (is_numeric($stepLearning['last_estimate_ratio'] ?? null) ? (float) $stepLearning['last_estimate_ratio'] : null);
        }

        if ($ratio === null) {
            $projectMetadata = is_array($project->metadata) ? $project->metadata : [];
            $learning = is_array($projectMetadata['execution_learning'] ?? null) ? $projectMetadata['execution_learning'] : [];
            $ratio = is_numeric($learning['average_estimate_ratio'] ?? null)
                ? (float) $learning['average_estimate_ratio']
                : (is_numeric($learning['last_estimate_ratio'] ?? null) ? (float) $learning['last_estimate_ratio'] : null);
        }

        if ($ratio === null || ($ratio > 0.85 && $ratio < 1.15)) {
            return $baseMinutes;
        }

        $factor = $ratio >= 1.15
            ? min(1.65, 1 + (($ratio - 1) * 0.45))
            : max(0.65, 1 - ((1 - $ratio) * 0.35));

        return $this->support->clamp((int) round($baseMinutes * $factor), 5, 480);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function actualMinutesForCompletion(AtlasTask $task, array $data, CarbonImmutable $completedAt): ?int
    {
        if (isset($data['actual_minutes']) && is_numeric($data['actual_minutes'])) {
            return $this->support->clamp((int) $data['actual_minutes'], 1, 1440);
        }

        $metadata = is_array($task->metadata) ? $task->metadata : [];
        $execution = is_array($metadata['execution'] ?? null) ? $metadata['execution'] : [];
        $startedAt = isset($execution['last_started_at']) && is_string($execution['last_started_at'])
            ? CarbonImmutable::parse($execution['last_started_at'])
            : null;
        if (! $startedAt || $completedAt->lte($startedAt)) {
            return null;
        }

        return $this->support->clamp((int) max(1, round($startedAt->diffInMinutes($completedAt))), 1, 1440);
    }

    public function completionQuality(mixed $quality): string
    {
        return in_array($quality, ['complete', 'partial', 'learned', 'blocked'], true)
            ? (string) $quality
            : 'complete';
    }

    public function executionResultEventType(string $quality): string
    {
        return match ($quality) {
            'partial' => 'execution_progress_recorded',
            'blocked' => 'execution_blocked',
            default => 'execution_completed',
        };
    }

    public function completionText(mixed $value, int $limit): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $text = trim(preg_replace('/\s+/u', ' ', $value) ?? '');
        if ($text === '') {
            return null;
        }

        return mb_substr($text, 0, $limit);
    }

    public function estimateBias(?float $ratio): ?string
    {
        if ($ratio === null) {
            return null;
        }
        if ($ratio >= 1.35) {
            return 'underestimated';
        }
        if ($ratio <= 0.7) {
            return 'overestimated';
        }

        return 'calibrated';
    }

    public function executionTimebox(int $estimatedMinutes, ?int $availableMinutes, ?int $energyLevel, int $frictionLevel): int
    {
        $base = $availableMinutes ? min($estimatedMinutes, $availableMinutes) : $estimatedMinutes;

        if ($energyLevel !== null && $energyLevel <= 2) {
            $base = min($base, 15);
        } elseif ($frictionLevel >= 75) {
            $base = min($base, 20);
        } elseif ($estimatedMinutes >= 60) {
            $base = min($base, 45);
        }

        return $this->support->clamp($base, 5, 90);
    }

    public function lowEnergyAction(AtlasProject $project, AtlasTask $task, ?AtlasProjectStep $step): string
    {
        $type = $project->project_type ?: 'personal';

        return match ($type) {
            'study' => 'Abrir a fonte e escrever uma pergunta ou três bullets, sem tentar estudar tudo.',
            'technical_build' => 'Abrir o repositório e escrever o próximo commit pequeno antes de codar.',
            'writing' => 'Escrever cinco linhas ruins e parar com a próxima frase indicada.',
            'tedious', 'admin' => 'Fazer só dois minutos do primeiro pedaço e registrar onde parou.',
            default => $task->starter_step ?: $step?->expected_output ?: 'Abrir o material e deixar a próxima ação visível.',
        };
    }

    public function avoidNow(AtlasProject $project, AtlasTask $task): string
    {
        if ((int) $task->friction_level >= 75) {
            return 'Replanejar o projeto inteiro antes de iniciar a menor ação.';
        }

        return match ($project->project_type ?: 'personal') {
            'study' => 'Colecionar mais fontes antes de responder uma pergunta simples.',
            'technical_build' => 'Aumentar escopo, trocar stack ou refatorar antes do primeiro incremento.',
            'writing' => 'Editar a primeira frase antes de existir rascunho suficiente.',
            'tedious', 'admin' => 'Organizar o ambiente inteiro para evitar começar.',
            default => 'Transformar a próxima ação em uma lista grande demais.',
        };
    }

    /**
     * @return array<string, mixed>
     */
    public function projectCompletionReadiness(AtlasProject $project): array
    {
        $stepCounts = [
            'total' => 0,
            'done' => 0,
            'skipped' => 0,
            'active' => 0,
            'pending' => 0,
            'blocked' => 0,
        ];
        if (DatabaseTableAvailability::has('atlas_project_steps')) {
            foreach ($project->steps()->get(['status']) as $step) {
                $status = (string) $step->status;
                $stepCounts['total']++;
                if (array_key_exists($status, $stepCounts)) {
                    $stepCounts[$status]++;
                }
            }
        }

        $openTaskCount = DatabaseTableAvailability::has('atlas_tasks')
            ? $project->tasks()
                ->whereIn('status', ['open', 'next', 'waiting'])
                ->whereNull('completed_at')
                ->count()
            : 0;
        $openStepCount = $stepCounts['active'] + $stepCounts['pending'] + $stepCounts['blocked'];
        $ready = $openStepCount === 0 && $openTaskCount === 0;

        return [
            'ready' => $ready,
            'step_counts' => $stepCounts,
            'open_step_count' => $openStepCount,
            'blocked_step_count' => $stepCounts['blocked'],
            'open_task_count' => $openTaskCount,
            'active_next_task_id' => $project->active_next_task_id,
            'current_step_id' => $project->current_step_id,
            'definition_of_done' => $project->definition_of_done,
            'minimum_viable_outcome' => $project->minimum_viable_outcome,
        ];
    }
}
