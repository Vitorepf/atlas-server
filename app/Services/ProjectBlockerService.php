<?php

namespace App\Services;

use App\Models\AtlasProject;
use App\Models\AtlasProjectBlocker;
use App\Models\AtlasProjectEvent;
use App\Models\AtlasProjectStep;
use App\Models\AtlasTask;
use App\Services\Ai\Support\DatabaseTableAvailability;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ProjectBlockerService
{
    public function __construct(
        private readonly TaskPlanningService $planning,
    ) {}

    public function openForTask(AtlasTask $task, array $data = [], string $source = 'tasks.blocked'): ?AtlasProjectBlocker
    {
        if (! DatabaseTableAvailability::has('atlas_project_blockers') || ! $task->project_id) {
            return null;
        }

        $task->loadMissing(['project', 'projectStep']);
        $project = $task->project ?: AtlasProject::query()->find($task->project_id);
        if (! $project) {
            return null;
        }

        return $this->open($project, $task, $task->projectStep, $data, $source);
    }

    public function openForStep(
        AtlasProject $project,
        AtlasProjectStep $step,
        ?AtlasTask $task = null,
        array $data = [],
        string $source = 'projects.step.block',
    ): ?AtlasProjectBlocker {
        if (! DatabaseTableAvailability::has('atlas_project_blockers')) {
            return null;
        }
        if ($step->project_id !== $project->id) {
            throw ValidationException::withMessages(['project_step_id' => 'A etapa nao pertence ao projeto informado.']);
        }
        if ($task && $task->project_id !== $project->id) {
            throw ValidationException::withMessages(['task_id' => 'A tarefa nao pertence ao projeto informado.']);
        }

        return $this->open($project, $task, $step, $data, $source);
    }

    public function createManual(AtlasProject $project, array $data, string $source = 'projects.blockers.store'): AtlasProjectBlocker
    {
        $task = null;
        if (! empty($data['task_id'])) {
            $task = AtlasTask::query()
                ->where('project_id', $project->id)
                ->findOrFail($data['task_id']);
        }

        $step = null;
        if (! empty($data['project_step_id'])) {
            $step = AtlasProjectStep::query()
                ->where('project_id', $project->id)
                ->findOrFail($data['project_step_id']);
        } elseif ($task?->project_step_id) {
            $step = $task->projectStep;
        }

        return $this->open($project, $task, $step, $data, $source);
    }

    /**
     * @return array{blocker: AtlasProjectBlocker, unblock_task: AtlasTask|null}
     */
    public function resolve(AtlasProjectBlocker $blocker, array $data = [], string $source = 'projects.blockers.resolve'): array
    {
        if ($blocker->status !== 'open') {
            throw ValidationException::withMessages(['blocker' => 'Apenas bloqueios abertos podem ser resolvidos.']);
        }

        return DB::transaction(function () use ($blocker, $data, $source): array {
            $blocker = $blocker->refresh()->load(['project', 'task', 'projectStep', 'unblockTask']);
            $project = $blocker->project;
            $task = $blocker->task;
            $step = $blocker->projectStep;

            $blocker->forceFill([
                'status' => 'resolved',
                'resolved_at' => now(),
                'resolution_note' => $this->text($data['resolution_note'] ?? $data['note'] ?? null, 1000),
                'metadata' => [
                    ...(is_array($blocker->metadata) ? $blocker->metadata : []),
                    'resolved_by' => 'human',
                    'resolved_source' => $source,
                ],
            ])->save();

            if ($task && ! $this->hasOpenTaskBlocker($task)) {
                $task->forceFill([
                    'status' => in_array($task->status, ['waiting', 'open', 'next'], true) ? 'open' : $task->status,
                    'planning_status' => in_array($task->planning_status, ['deferred', 'unscheduled'], true) ? 'suggested' : $task->planning_status,
                    'failure_reason_last' => null,
                ])->save();
            }

            if ($step && $step->status === 'blocked' && ! $this->hasOpenStepBlocker($step)) {
                $step->forceFill([
                    'status' => $task && ! in_array($task->status, ['done', 'archived'], true) ? 'active' : 'pending',
                    'active_task_id' => $task && ! in_array($task->status, ['done', 'archived'], true) ? $task->id : null,
                ])->save();
            }

            if ($project && ! $this->hasOpenProjectBlocker($project)) {
                $nextTask = $task && ! in_array($task->status, ['done', 'archived'], true) ? $task->refresh() : null;
                $unblockTaskWasActive = $blocker->unblockTask && $project->active_next_task_id === $blocker->unblockTask->id;
                $project->forceFill([
                    'status' => $project->status === 'blocked' ? 'active' : $project->status,
                    'active_next_task_id' => $unblockTaskWasActive ? $nextTask?->id : $project->active_next_task_id,
                    'current_step_id' => $step?->id ?? $project->current_step_id,
                    'next_action' => $unblockTaskWasActive
                        ? ($nextTask?->title ?? $step?->title ?? $project->next_action)
                        : ($project->activeNextTask?->title ?? $project->next_action),
                    'last_touched_at' => now(),
                    'next_review_at' => now()->addDays(3),
                ])->save();
            }

            $this->recordProjectEvent($project, 'blocker_resolved', [
                'blocker_id' => $blocker->id,
                'task_id' => $task?->id,
                'project_step_id' => $step?->id,
                'reason_code' => $blocker->reason_code,
                'resolution_note' => $blocker->resolution_note,
            ], $source);
            if ($task) {
                $this->planning->recordEvent($task->refresh(), 'blocker_resolved', [
                    'blocker_id' => $blocker->id,
                    'project_id' => $project?->id,
                    'reason_code' => $blocker->reason_code,
                ], $source);
            }

            return [
                'blocker' => $blocker->refresh()->load(['project', 'task', 'projectStep', 'unblockTask']),
                'unblock_task' => $blocker->unblockTask,
            ];
        });
    }

    /**
     * @return array{blocker: AtlasProjectBlocker, unblock_task: AtlasTask}
     */
    public function convertToTask(AtlasProjectBlocker $blocker, array $data = [], string $source = 'projects.blockers.task'): array
    {
        if ($blocker->status !== 'open') {
            throw ValidationException::withMessages(['blocker' => 'Apenas bloqueios abertos podem virar tarefa.']);
        }

        return DB::transaction(function () use ($blocker, $data, $source): array {
            $blocker = $blocker->refresh()->load(['project', 'task', 'projectStep', 'unblockTask']);
            $project = $blocker->project;
            if (! $project) {
                throw ValidationException::withMessages(['project_id' => 'Bloqueio sem projeto associado.']);
            }

            $task = $blocker->unblockTask;
            $title = $this->title($data['title'] ?? $blocker->unblock_next_action, 'Desbloquear projeto');
            $payload = [
                'title' => $title,
                'description' => $data['description'] ?? "Desbloqueio: {$blocker->description}",
                'status' => 'open',
                'priority' => $data['priority'] ?? $this->priorityForSeverity($blocker->severity),
                'domain' => $project->domain,
                'project_id' => $project->id,
                'project_step_id' => $blocker->project_step_id,
                'estimated_minutes' => $data['estimated_minutes'] ?? $this->minutesForReason($blocker->reason_code),
                'energy_required' => $data['energy_required'] ?? 'low',
                'planning_status' => 'suggested',
                'execution_mode' => 'recovery',
                'friction_level' => 35,
                'emotional_resistance' => 35,
                'clarity_level' => 85,
                'starter_step' => $data['starter_step'] ?? 'Abrir só o ponto que travou e executar a menor ação física.',
                'minimum_viable_action' => $data['minimum_viable_action'] ?? $blocker->unblock_next_action,
                'if_then_plan' => 'Se eu travar de novo, registro a dependência real e reduzo para 2 minutos.',
                'reward_hint' => 'Registrar o desbloqueio, mesmo que o projeto ainda não avance.',
            ];

            if (! $task) {
                $task = AtlasTask::query()->create([
                    ...$payload,
                    'metadata' => [
                        'role' => 'unblock_action',
                        'blocker_id' => $blocker->id,
                        'reason_code' => $blocker->reason_code,
                        'created_from' => $source,
                    ],
                ]);
            } else {
                $metadata = is_array($task->metadata) ? $task->metadata : [];
                $task->forceFill([
                    ...$payload,
                    'metadata' => [
                        ...$metadata,
                        'role' => 'unblock_action',
                        'blocker_id' => $blocker->id,
                        'reason_code' => $blocker->reason_code,
                        'updated_from' => $source,
                    ],
                ])->save();
            }

            $task->forceFill($this->planning->attributesForTaskUpdate($task->refresh(), $payload))->save();
            $blocker->forceFill([
                'unblock_task_id' => $task->id,
                'metadata' => [
                    ...(is_array($blocker->metadata) ? $blocker->metadata : []),
                    'unblock_task_created_at' => now()->toJSON(),
                    'unblock_task_source' => $source,
                ],
            ])->save();

            $project->forceFill([
                'status' => 'blocked',
                'active_next_task_id' => $task->id,
                'next_action' => $task->title,
                'last_touched_at' => now(),
                'next_review_at' => now()->addDay(),
            ])->save();

            if ($blocker->projectStep) {
                $blocker->projectStep->forceFill([
                    'status' => 'blocked',
                    'active_task_id' => $task->id,
                ])->save();
            }

            $this->recordProjectEvent($project->refresh(), 'blocker_converted_to_task', [
                'blocker_id' => $blocker->id,
                'task_id' => $task->id,
                'reason_code' => $blocker->reason_code,
                'unblock_next_action' => $task->title,
            ], $source);
            $this->planning->recordEvent($task->refresh(), 'created_from_blocker', [
                'blocker_id' => $blocker->id,
                'project_id' => $project->id,
                'reason_code' => $blocker->reason_code,
            ], $source);

            return [
                'blocker' => $blocker->refresh()->load(['project', 'task', 'projectStep', 'unblockTask']),
                'unblock_task' => $task->refresh()->load(['project', 'projectStep']),
            ];
        });
    }

    public function cancelOpenForProject(AtlasProject $project, string $reason, string $source = 'projects.status'): int
    {
        if (! DatabaseTableAvailability::has('atlas_project_blockers')) {
            return 0;
        }

        $count = 0;
        AtlasProjectBlocker::query()
            ->where('project_id', $project->id)
            ->where('status', 'open')
            ->get()
            ->each(function (AtlasProjectBlocker $blocker) use (&$count, $reason, $source, $project): void {
                $blocker->forceFill([
                    'status' => 'cancelled',
                    'resolved_at' => now(),
                    'resolution_note' => $reason,
                    'metadata' => [
                        ...(is_array($blocker->metadata) ? $blocker->metadata : []),
                        'cancelled_source' => $source,
                    ],
                ])->save();
                $count++;
            });

        if ($count > 0) {
            $this->recordProjectEvent($project, 'blockers_cancelled', [
                'count' => $count,
                'reason' => $reason,
            ], $source);
        }

        return $count;
    }

    private function open(
        AtlasProject $project,
        ?AtlasTask $task,
        ?AtlasProjectStep $step,
        array $data,
        string $source,
    ): AtlasProjectBlocker {
        return DB::transaction(function () use ($project, $task, $step, $data, $source): AtlasProjectBlocker {
            $description = $this->description($data, $task, $step);
            $reasonCode = $this->reasonCode($data['reason_code'] ?? $data['blocker_reason_code'] ?? null, $description, $task, $step);
            $severity = $this->severity($data['severity'] ?? null, $project, $task, $reasonCode);
            $unblockNextAction = $this->text($data['unblock_next_action'] ?? $data['next_hint'] ?? null, 500)
                ?? $this->suggestedUnblockAction($reasonCode, $task, $step, $description);

            $query = AtlasProjectBlocker::query()
                ->where('project_id', $project->id)
                ->where('status', 'open');
            if ($task) {
                $query->where('task_id', $task->id);
            } elseif ($step) {
                $query->where('project_step_id', $step->id);
            } else {
                $query->whereNull('task_id')->whereNull('project_step_id');
            }

            $blocker = $query->latest('updated_at')->first();
            $wasRecentlyCreated = false;
            if (! $blocker) {
                $blocker = new AtlasProjectBlocker();
                $wasRecentlyCreated = true;
            }

            $metadata = is_array($blocker->metadata) ? $blocker->metadata : [];
            $blocker->forceFill([
                'project_id' => $project->id,
                'task_id' => $task?->id,
                'project_step_id' => $step?->id,
                'status' => 'open',
                'severity' => $severity,
                'reason_code' => $reasonCode,
                'description' => $description,
                'unblock_next_action' => $unblockNextAction,
                'waiting_on' => $this->text($data['waiting_on'] ?? null, 180),
                'due_at' => $data['due_at'] ?? null,
                'metadata' => [
                    ...$metadata,
                    'open_count' => (int) ($metadata['open_count'] ?? 0) + 1,
                    'last_opened_at' => now()->toJSON(),
                    'last_source' => $source,
                    'last_task_title' => $task?->title,
                    'last_step_title' => $step?->title,
                ],
            ])->save();

            if ($task) {
                $taskMetadata = is_array($task->metadata) ? $task->metadata : [];
                $task->forceFill([
                    'status' => in_array($task->status, ['done', 'archived'], true) ? $task->status : 'waiting',
                    'planning_status' => 'deferred',
                    'failure_reason_last' => $description,
                    'metadata' => [
                        ...$taskMetadata,
                        'blocker' => [
                            'id' => $blocker->id,
                            'reason_code' => $reasonCode,
                            'severity' => $severity,
                            'unblock_next_action' => $unblockNextAction,
                            'updated_at' => now()->toJSON(),
                        ],
                    ],
                ])->save();
                $this->planning->recordEvent($task->refresh(), $wasRecentlyCreated ? 'blocker_opened' : 'blocker_updated', [
                    'blocker_id' => $blocker->id,
                    'project_id' => $project->id,
                    'reason_code' => $reasonCode,
                    'severity' => $severity,
                    'unblock_next_action' => $unblockNextAction,
                ], $source);
            }

            if ($step) {
                $step->forceFill([
                    'status' => 'blocked',
                    'active_task_id' => null,
                ])->save();
            }

            $projectMetadata = is_array($project->metadata) ? $project->metadata : [];
            $project->forceFill([
                'status' => 'blocked',
                'active_next_task_id' => null,
                'next_action' => $unblockNextAction,
                'last_touched_at' => now(),
                'next_review_at' => now()->addDay(),
                'metadata' => [
                    ...$projectMetadata,
                    'last_blocker' => [
                        'id' => $blocker->id,
                        'reason_code' => $reasonCode,
                        'severity' => $severity,
                        'description' => $description,
                        'unblock_next_action' => $unblockNextAction,
                        'updated_at' => now()->toJSON(),
                    ],
                ],
            ])->save();

            $event = $this->recordProjectEvent($project->refresh(), $wasRecentlyCreated ? 'blocker_opened' : 'blocker_updated', [
                'blocker_id' => $blocker->id,
                'task_id' => $task?->id,
                'project_step_id' => $step?->id,
                'reason_code' => $reasonCode,
                'severity' => $severity,
                'description' => $description,
                'unblock_next_action' => $unblockNextAction,
            ], $source);
            if ($wasRecentlyCreated && $event) {
                $blocker->forceFill(['created_from_event_id' => $event->id])->save();
            }

            return $blocker->refresh()->load(['project', 'task', 'projectStep', 'unblockTask']);
        });
    }

    private function recordProjectEvent(?AtlasProject $project, string $eventType, array $payload, string $source): ?AtlasProjectEvent
    {
        if (! $project || ! DatabaseTableAvailability::has('atlas_project_events')) {
            return null;
        }

        return AtlasProjectEvent::query()->create([
            'project_id' => $project->id,
            'event_type' => $eventType,
            'source' => $source,
            'payload' => $payload,
            'occurred_at' => now(),
        ]);
    }

    private function hasOpenProjectBlocker(AtlasProject $project): bool
    {
        return DatabaseTableAvailability::has('atlas_project_blockers')
            && AtlasProjectBlocker::query()->where('project_id', $project->id)->where('status', 'open')->exists();
    }

    private function hasOpenTaskBlocker(AtlasTask $task): bool
    {
        return DatabaseTableAvailability::has('atlas_project_blockers')
            && AtlasProjectBlocker::query()->where('task_id', $task->id)->where('status', 'open')->exists();
    }

    private function hasOpenStepBlocker(AtlasProjectStep $step): bool
    {
        return DatabaseTableAvailability::has('atlas_project_blockers')
            && AtlasProjectBlocker::query()->where('project_step_id', $step->id)->where('status', 'open')->exists();
    }

    private function description(array $data, ?AtlasTask $task, ?AtlasProjectStep $step): string
    {
        return $this->text(
            $data['description']
                ?? $data['blocker']
                ?? $data['outcome']
                ?? $data['reason']
                ?? $data['note']
                ?? $task?->failure_reason_last
                ?? $step?->title
                ?? $task?->title
                ?? null,
            1000,
        ) ?? 'Bloqueio sem descricao detalhada.';
    }

    private function reasonCode(mixed $candidate, string $description, ?AtlasTask $task, ?AtlasProjectStep $step): string
    {
        $candidate = (string) $candidate;
        $mapped = match ($candidate) {
            'low_energy' => 'energy',
            'waiting' => 'waiting_external',
            'blocked', 'avoidance', 'not_now', 'calendar' => 'other',
            default => $candidate,
        };
        if (in_array($mapped, AtlasProjectBlocker::REASON_CODES, true)) {
            return $mapped;
        }

        $text = mb_strtolower(trim($description.' '.($task?->title ?? '').' '.($step?->title ?? '')));
        if (preg_match('/\b(sem clareza|nao sei|não sei|por onde|confus[oa]|unclear)\b/u', $text)) return 'unclear';
        if (preg_match('/\b(grande|enorme|complexo|demais|muito)\b/u', $text)) return 'too_large';
        if (preg_match('/\b(chato|chata|tedioso|tediosa|burocracia)\b/u', $text)) return 'boring';
        if (preg_match('/\b(esperando|depende|algu[eé]m|cliente|resposta)\b/u', $text)) return 'waiting_external';
        if (preg_match('/\b(falta|recurso|arquivo|senha|acesso|material)\b/u', $text)) return 'missing_resource';
        if (preg_match('/\b(medo|ansiedade|assusta|receio)\b/u', $text)) return 'fear';
        if (preg_match('/\b(energia|cansado|cansada|sono|exausto)\b/u', $text)) return 'energy';
        if (preg_match('/\b(tecnic|bug|erro|api|stack|codigo|c[oó]digo)\b/u', $text)) return 'technical_unknown';
        if (preg_match('/\b(decidir|decis[aã]o|op[cç][aã]o|escolher)\b/u', $text)) return 'decision_needed';

        return 'other';
    }

    private function severity(mixed $candidate, AtlasProject $project, ?AtlasTask $task, string $reasonCode): string
    {
        if (in_array($candidate, AtlasProjectBlocker::SEVERITIES, true)) {
            return (string) $candidate;
        }
        if (in_array($project->priority, ['urgent', 'high'], true) || in_array($task?->priority, ['urgent', 'high'], true)) {
            return 'high';
        }
        if (in_array($reasonCode, ['waiting_external', 'technical_unknown', 'decision_needed'], true)) {
            return 'medium';
        }

        return 'medium';
    }

    private function suggestedUnblockAction(string $reasonCode, ?AtlasTask $task, ?AtlasProjectStep $step, string $description): string
    {
        $target = $step?->title ?? $task?->title ?? mb_substr($description, 0, 80);

        return match ($reasonCode) {
            'unclear' => "Escrever em uma frase o que seria pronto em {$target}",
            'too_large' => "Quebrar {$target} em uma ação de 10 minutos",
            'boring' => "Fazer 2 minutos de {$target} sem organizar o ambiente",
            'waiting_external' => "Enviar ou registrar a mensagem que destrava {$target}",
            'missing_resource' => "Listar o recurso que falta e onde obter para {$target}",
            'fear' => "Escrever o medo real e escolher uma versão segura de 5 minutos",
            'energy' => "Criar uma versão de baixa energia para {$target}",
            'technical_unknown' => "Isolar a dúvida técnica em um teste pequeno",
            'decision_needed' => "Listar 2 opções e escolher o próximo teste reversível",
            default => "Definir a menor ação física para destravar {$target}",
        };
    }

    private function priorityForSeverity(string $severity): string
    {
        return match ($severity) {
            'high' => 'high',
            'low' => 'normal',
            default => 'normal',
        };
    }

    private function minutesForReason(string $reasonCode): int
    {
        return match ($reasonCode) {
            'too_large', 'boring', 'energy' => 10,
            'waiting_external', 'missing_resource', 'decision_needed' => 15,
            'technical_unknown' => 25,
            default => 15,
        };
    }

    private function title(mixed $value, string $fallback): string
    {
        $title = $this->text($value, 180) ?? $fallback;

        return preg_replace('/\s+/u', ' ', $title) ?: $fallback;
    }

    private function text(mixed $value, int $limit): ?string
    {
        $text = trim((string) $value);
        if ($text === '') {
            return null;
        }

        return mb_substr(preg_replace('/\s+/u', ' ', $text) ?: $text, 0, $limit);
    }
}
