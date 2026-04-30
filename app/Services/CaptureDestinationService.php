<?php

namespace App\Services;

use App\Models\AtlasProject;
use App\Models\AtlasTask;
use App\Models\Capture;
use App\Models\CaptureLink;
use App\Models\SemanticCurationProposal;
use App\Models\SemanticNote;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class CaptureDestinationService
{
    public function __construct(
        private readonly AuditLogService $audit,
        private readonly TaskPlanningService $planning,
        private readonly ProjectExecutionService $projects,
    ) {}

    /**
     * @return array{target_type: string|null, target_id: string|null, target_title: string|null, link: CaptureLink|null}
     */
    public function apply(Capture $capture, string $action, array $data, ?SemanticCurationProposal $proposal = null): array
    {
        return match ($action) {
            'attach_note' => $this->attachNote($capture, $data),
            'create_task' => $this->createTask($capture, $data),
            'create_project' => $this->createProject($capture, $data),
            'promote', 'create_hypothesis' => $this->linkProposal($capture, $action, $proposal),
            default => [
                'target_type' => null,
                'target_id' => null,
                'target_title' => null,
                'link' => null,
            ],
        };
    }

    /**
     * @param  array{destination: string|null, target_type: string|null, target_id: string|null, target_title: string|null}  $previous
     * @param  array{target_type: string|null, target_id: string|null, target_title: string|null, link?: CaptureLink|null}  $next
     */
    public function retirePrevious(Capture $capture, array $previous, array $next, string $action): void
    {
        if (! $previous['target_type'] || ! $previous['target_id']) {
            return;
        }

        if ($previous['target_type'] === $next['target_type'] && $previous['target_id'] === $next['target_id']) {
            return;
        }

        $supersededBy = [
            'action' => $action,
            'capture_id' => $capture->id,
            'previous_destination' => $previous['destination'],
            'previous_target_type' => $previous['target_type'],
            'previous_target_id' => $previous['target_id'],
            'new_target_type' => $next['target_type'],
            'new_target_id' => $next['target_id'],
            'new_target_title' => $next['target_title'],
            'changed_at' => now()->toJSON(),
        ];

        $retired = match ($previous['target_type']) {
            'task' => $this->retireTask($capture, $previous['target_id'], $supersededBy),
            'project' => $this->retireProject($capture, $previous['target_id'], $supersededBy),
            default => false,
        };

        if (! $retired) {
            return;
        }

        $this->audit->record('capture_destination_reclassified', [
            'subject_type' => 'capture',
            'subject_id' => $capture->id,
            'summary' => 'Destino da captura reclassificado.',
            'evidence' => $supersededBy,
            'privacy' => $this->capturePrivacy($capture),
            'refs' => [
                'capture_id' => $capture->id,
                'previous_target_id' => $previous['target_id'],
                'new_target_id' => $next['target_id'],
            ],
        ]);
    }

    /**
     * @return array{target_type: string, target_id: string, target_title: string, link: CaptureLink|null}
     */
    private function attachNote(Capture $capture, array $data): array
    {
        $noteId = $data['note_id'] ?? null;
        $note = is_string($noteId) ? SemanticNote::query()->find($noteId) : null;
        if (! $note) {
            throw ValidationException::withMessages([
                'note_id' => 'A valid semantic note is required to attach a capture.',
            ]);
        }

        $link = $this->link($capture, 'semantic_note', $note->id, $note->title, [
            'action' => 'attach_note',
        ]);

        $this->audit->record('capture_attached_to_note', [
            'subject_type' => 'capture',
            'subject_id' => $capture->id,
            'summary' => "Captura anexada a nota viva: {$note->title}.",
            'evidence' => [
                'note_id' => $note->id,
                'note_title' => $note->title,
                'note_path' => $note->path,
            ],
            'privacy' => $this->capturePrivacy($capture),
            'refs' => [
                'capture_id' => $capture->id,
                'note_id' => $note->id,
                'capture_link_id' => $link?->id,
            ],
        ]);

        return [
            'target_type' => 'semantic_note',
            'target_id' => $note->id,
            'target_title' => $note->title,
            'link' => $link,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function createTask(Capture $capture, array $data): array
    {
        $title = $this->title($data, $capture, 'Tarefa a partir de captura');
        $description = $this->description($capture, $data);

        $task = $this->standaloneTaskForCapture($capture);
        $metadata = is_array($task->metadata) ? $task->metadata : [];
        $task->fill([
            'title' => $title,
            'description' => $description,
            'status' => 'open',
            'priority' => $data['priority'] ?? ($task->priority ?: 'normal'),
            'domain' => $capture->domain,
            'project_id' => null,
            'due_at' => $data['due_at'] ?? null,
            ...$this->planning->attributesForCaptureTask($capture, $data, $title, $description),
            'execution_mode' => $data['execution_mode'] ?? 'quick_win',
            'friction_level' => $data['friction_level'] ?? 45,
            'emotional_resistance' => $data['emotional_resistance'] ?? 42,
            'clarity_level' => $data['clarity_level'] ?? 70,
            'starter_step' => $data['starter_step'] ?? null,
            'minimum_viable_action' => $data['minimum_viable_action'] ?? $title,
            'if_then_plan' => $data['if_then_plan'] ?? 'Se travar, fazer a menor versão em 5 minutos.',
            'reward_hint' => $data['reward_hint'] ?? 'Registrar avanço concreto ao concluir.',
            'metadata' => [
                ...$metadata,
                'created_from' => $metadata['created_from'] ?? 'capture_triage',
                'triage_action' => 'create_task',
                'last_triaged_at' => now()->toJSON(),
            ],
        ]);
        $task->save();
        $this->planning->recordEvent($task, $task->wasRecentlyCreated ? 'created_from_capture' : 'updated_from_capture', [
            'capture_id' => $capture->id,
            'title' => $task->title,
            'priority' => $task->priority,
            'priority_score' => $task->priority_score,
            'estimated_minutes' => $task->estimated_minutes,
            'energy_required' => $task->energy_required,
        ], 'capture_triage');

        $link = $this->link($capture, 'task', $task->id, $task->title, [
            'action' => 'create_task',
            'priority' => $task->priority,
        ]);

        $this->audit->record('task_created_from_capture', [
            'subject_type' => 'atlas_task',
            'subject_id' => $task->id,
            'summary' => "Tarefa criada/atualizada a partir da captura: {$task->title}.",
            'evidence' => [
                'task_id' => $task->id,
                'title' => $task->title,
                'priority' => $task->priority,
                'priority_score' => $task->priority_score,
                'estimated_minutes' => $task->estimated_minutes,
                'energy_required' => $task->energy_required,
                'domain' => $task->domain,
                'capture_id' => $capture->id,
            ],
            'privacy' => $this->capturePrivacy($capture),
            'refs' => [
                'capture_id' => $capture->id,
                'task_id' => $task->id,
                'capture_link_id' => $link?->id,
            ],
        ]);

        return [
            'target_type' => 'task',
            'target_id' => $task->id,
            'target_title' => $task->title,
            'estimated_minutes' => $task->estimated_minutes,
            'energy_required' => $task->energy_required,
            'priority_score' => $task->priority_score,
            'link' => $link,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function createProject(Capture $capture, array $data): array
    {
        $title = $this->title($data, $capture, 'Projeto a partir de captura');
        $plan = $this->projects->inferPlan($capture, $data, $title);

        $project = AtlasProject::query()->firstOrNew(['source_capture_id' => $capture->id]);
        $metadata = is_array($project->metadata) ? $project->metadata : [];
        $project->fill([
            'title' => $title,
            'description' => $this->description($capture, $data),
            'status' => 'active',
            'domain' => $capture->domain,
            'goal' => $data['goal'] ?? $plan['desired_outcome'],
            'next_action' => $plan['next_action'],
            'project_type' => $plan['project_type'],
            'desired_outcome' => $plan['desired_outcome'],
            'minimum_viable_outcome' => $plan['minimum_viable_outcome'],
            'definition_of_done' => $plan['definition_of_done'],
            'why_now' => $plan['why_now'],
            'deadline_at' => $plan['deadline_at'],
            'deadline_kind' => $plan['deadline_kind'],
            'priority' => $plan['priority'],
            'energy_profile' => $plan['energy_profile'],
            'avoidance_reason' => $plan['avoidance_reason'],
            'last_touched_at' => now(),
            'next_review_at' => now()->addDays(3),
            'metadata' => [
                ...$metadata,
                'created_from' => $metadata['created_from'] ?? 'capture_triage',
                'triage_action' => 'create_project',
                'last_triaged_at' => now()->toJSON(),
                'process_steps' => $plan['process_steps'],
            ],
        ]);
        $project->save();
        $this->projects->recordEvent($project, $project->wasRecentlyCreated ? 'created_from_capture' : 'updated_from_capture', [
            'capture_id' => $capture->id,
            'title' => $project->title,
            'project_type' => $project->project_type,
            'priority' => $project->priority,
            'avoidance_reason' => $project->avoidance_reason,
        ], 'capture_triage');

        $nextTask = $this->projects->ensureNextActionTask($project, $capture, $data, $plan, 'capture_triage');
        $project->refresh();

        $link = $this->link($capture, 'project', $project->id, $project->title, [
            'action' => 'create_project',
            'project_type' => $project->project_type,
            'active_next_task_id' => $nextTask->id,
        ]);

        $this->audit->record('project_created_from_capture', [
            'subject_type' => 'atlas_project',
            'subject_id' => $project->id,
            'summary' => "Projeto criado a partir da captura: {$project->title}.",
            'evidence' => [
                'project_id' => $project->id,
                'title' => $project->title,
                'domain' => $project->domain,
                'capture_id' => $capture->id,
                'project_type' => $project->project_type,
                'priority' => $project->priority,
                'active_next_task_id' => $nextTask->id,
                'active_next_task_title' => $nextTask->title,
            ],
            'privacy' => $this->capturePrivacy($capture),
            'refs' => [
                'capture_id' => $capture->id,
                'project_id' => $project->id,
                'task_id' => $nextTask->id,
                'capture_link_id' => $link?->id,
            ],
        ]);

        return [
            'target_type' => 'project',
            'target_id' => $project->id,
            'target_title' => $project->title,
            'project_type' => $project->project_type,
            'next_action' => $project->next_action,
            'active_next_task_id' => $nextTask->id,
            'active_next_task_title' => $nextTask->title,
            'link' => $link,
        ];
    }

    /**
     * @param  array<string, mixed>  $supersededBy
     */
    private function retireTask(Capture $capture, string $taskId, array $supersededBy): bool
    {
        $task = AtlasTask::query()
            ->whereKey($taskId)
            ->where('source_capture_id', $capture->id)
            ->first();

        if (! $task) {
            return false;
        }

        $metadata = is_array($task->metadata) ? $task->metadata : [];
        $task->metadata = [
            ...$metadata,
            'superseded_by_capture_triage' => $supersededBy,
        ];
        if ($task->status !== 'done') {
            $task->status = 'archived';
        }
        $task->save();

        return true;
    }

    /**
     * @param  array<string, mixed>  $supersededBy
     */
    private function retireProject(Capture $capture, string $projectId, array $supersededBy): bool
    {
        $project = AtlasProject::query()
            ->whereKey($projectId)
            ->where('source_capture_id', $capture->id)
            ->first();

        if (! $project) {
            return false;
        }

        $metadata = is_array($project->metadata) ? $project->metadata : [];
        $project->metadata = [
            ...$metadata,
            'superseded_by_capture_triage' => $supersededBy,
        ];
        if ($project->status !== 'completed') {
            $project->status = 'archived';
        }
        $project->save();

        if (Schema::hasColumn('atlas_tasks', 'project_id')) {
            AtlasTask::query()
                ->where('project_id', $project->id)
                ->whereIn('status', ['open', 'next', 'waiting'])
                ->update([
                    'status' => 'archived',
                    'updated_at' => now(),
                ]);
        }

        return true;
    }

    /**
     * @return array{target_type: string|null, target_id: string|null, target_title: string|null, link: CaptureLink|null}
     */
    private function linkProposal(Capture $capture, string $action, ?SemanticCurationProposal $proposal): array
    {
        if (! $proposal) {
            return [
                'target_type' => null,
                'target_id' => null,
                'target_title' => null,
                'link' => null,
            ];
        }

        $targetType = $action === 'create_hypothesis' ? 'hypothesis' : 'semantic_curation_proposal';
        $link = $this->link($capture, $targetType, $proposal->id, $proposal->proposed_title, [
            'action' => $action,
            'proposal_status' => $proposal->status,
        ]);

        return [
            'target_type' => $targetType,
            'target_id' => $proposal->id,
            'target_title' => $proposal->proposed_title,
            'proposal_status' => $proposal->status,
            'link' => $link,
        ];
    }

    public function linkAcceptedNote(Capture $capture, SemanticNote $note, SemanticCurationProposal $proposal): ?CaptureLink
    {
        $link = $this->link($capture, 'semantic_note', $note->id, $note->title, [
            'action' => 'curation_proposal_accepted',
            'proposal_id' => $proposal->id,
        ]);

        $metadata = is_array($capture->metadata) ? $capture->metadata : [];
        $previousTriage = is_array($metadata['triage'] ?? null) ? $metadata['triage'] : [];
        $history = is_array($metadata['triage_history'] ?? null) ? $metadata['triage_history'] : [];
        $metadata['triage'] = [
            ...$previousTriage,
            'status' => 'accepted',
            'destination' => 'semantic_note',
            'last_action' => 'curation_proposal_accepted',
            'updated_at' => now()->toJSON(),
            'proposal_id' => $proposal->id,
            'proposal_status' => $proposal->status,
            'knowledge_state' => 'semantic_note_created',
            'human_gate' => 'ratified',
            'next_action' => 'use_note',
            'target_type' => 'semantic_note',
            'target_id' => $note->id,
            'target_title' => $note->title,
        ];
        $metadata['triage_history'] = array_slice([
            [
                'action' => 'curation_proposal_accepted',
                'status' => 'accepted',
                'destination' => 'semantic_note',
                'at' => now()->toJSON(),
                'proposal_id' => $proposal->id,
                'target_type' => 'semantic_note',
                'target_id' => $note->id,
                'target_title' => $note->title,
                'previous_destination' => $previousTriage['destination'] ?? null,
                'previous_target_type' => $previousTriage['target_type'] ?? null,
                'previous_target_id' => $previousTriage['target_id'] ?? null,
                'previous_target_title' => $previousTriage['target_title'] ?? null,
                'changed_destination' => true,
            ],
            ...$history,
        ], 0, 20);
        $capture->forceFill(['metadata' => $metadata])->save();

        return $link;
    }

    private function link(Capture $capture, string $targetType, ?string $targetId, ?string $targetTitle, array $metadata): ?CaptureLink
    {
        if (! Schema::hasTable('capture_links')) {
            return null;
        }

        $attributes = [
            'capture_id' => $capture->id,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'relation_type' => 'triage_destination',
        ];

        return CaptureLink::query()->updateOrCreate($attributes, [
            'target_title' => $targetTitle,
            'metadata' => [
                ...$metadata,
                'capture_domain' => $capture->domain,
                'linked_at' => now()->toJSON(),
            ],
        ]);
    }

    private function title(array $data, Capture $capture, string $fallback): string
    {
        $title = trim((string) ($data['title'] ?? ''));
        if ($title !== '') {
            return mb_substr($title, 0, 180);
        }

        $text = trim((string) $capture->content_text);
        if ($text !== '') {
            return mb_substr($text, 0, 120);
        }

        return $fallback;
    }

    private function standaloneTaskForCapture(Capture $capture): AtlasTask
    {
        $query = AtlasTask::query()->where('source_capture_id', $capture->id);
        if (Schema::hasColumn('atlas_tasks', 'project_id')) {
            $query->whereNull('project_id');
        }

        return $query->first() ?? new AtlasTask(['source_capture_id' => $capture->id]);
    }

    private function description(Capture $capture, array $data): ?string
    {
        $reason = trim((string) ($data['reason'] ?? ''));
        $text = trim((string) $capture->content_text);

        return trim($reason."\n\n".$text) ?: null;
    }

    /**
     * @return array<string, mixed>
     */
    private function capturePrivacy(Capture $capture): array
    {
        $privacy = data_get($capture->metadata, 'privacy', []);

        return is_array($privacy) ? $privacy : ['domain' => $capture->domain, 'sensitivity' => 'normal'];
    }
}
