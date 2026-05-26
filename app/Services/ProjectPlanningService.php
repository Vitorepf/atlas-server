<?php

namespace App\Services;

use App\Models\AtlasProject;
use App\Models\AtlasProjectPlanProposal;
use App\Models\AtlasTask;
use App\Models\Capture;
use App\Models\CaptureLink;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class ProjectPlanningService
{
    private const PLANNER_VERSION = 'atlas_project_planner_v1';

    public function __construct(
        private readonly ProjectExecutionService $execution,
        private readonly AuditLogService $audit,
    ) {}

    /**
     * @return Collection<int, AtlasProjectPlanProposal>
     */
    public function pendingForProject(AtlasProject $project): Collection
    {
        return AtlasProjectPlanProposal::query()
            ->with(['project', 'sourceCapture'])
            ->where('project_id', $project->id)
            ->whereIn('status', ['draft', 'pending_review'])
            ->latest()
            ->get();
    }

    /**
     * @return Collection<int, AtlasProjectPlanProposal>
     */
    public function pendingForCapture(Capture $capture): Collection
    {
        return AtlasProjectPlanProposal::query()
            ->with(['project', 'sourceCapture'])
            ->where('source_capture_id', $capture->id)
            ->whereIn('status', ['draft', 'pending_review'])
            ->latest()
            ->get();
    }

    public function proposeForCapture(Capture $capture, array $data = [], string $source = 'captures.project_plan.propose'): AtlasProjectPlanProposal
    {
        $title = $this->title($data['title'] ?? null, $capture->content_text, 'Projeto a partir de captura');
        $plan = $this->execution->inferPlan($capture, $data, $title);

        return $this->persistProposal(null, $capture, $data, $title, $plan, $source);
    }

    public function proposeForProject(AtlasProject $project, array $data = [], string $source = 'projects.plan.propose'): AtlasProjectPlanProposal
    {
        $capture = $project->sourceCapture;
        $title = $this->title($data['title'] ?? $project->title, $capture?->content_text ?? $project->goal, $project->title);
        $plan = $this->execution->inferPlan($capture, [
            'goal' => $project->goal,
            'description' => $project->description,
            'next_action' => $project->next_action,
            'priority' => $project->priority,
            'project_type' => $project->project_type,
            ...$data,
        ], $title);

        return $this->persistProposal($project, $capture, $data, $title, $plan, $source);
    }

    public function regenerate(AtlasProjectPlanProposal $proposal, array $data = [], string $source = 'project_plan.regenerate'): AtlasProjectPlanProposal
    {
        $metadata = [
            ...(is_array($proposal->metadata) ? $proposal->metadata : []),
            'regenerated_at' => now()->toJSON(),
            'regeneration_instruction' => $data['instruction'] ?? $data['regeneration_instruction'] ?? null,
        ];
        $updates = ['metadata' => $metadata];
        if (in_array($proposal->status, ['draft', 'pending_review'], true)) {
            $updates['status'] = 'superseded';
            $updates['metadata'] = [
                ...$metadata,
                'superseded_by_regeneration_at' => now()->toJSON(),
            ];
        }
        $proposal->forceFill($updates)->save();

        $data = [
            ...$data,
            'title' => $data['title'] ?? $proposal->proposed_title,
            'goal' => $data['goal'] ?? $proposal->desired_outcome,
            'next_action' => $data['next_action'] ?? $proposal->first_next_action,
            'priority' => $data['priority'] ?? $proposal->priority_suggestion,
            'project_type' => $data['project_type'] ?? $proposal->project_type,
            'metadata' => [
                ...($data['metadata'] ?? []),
                'regenerated_from_proposal_id' => $proposal->id,
                'regeneration_instruction' => $data['instruction'] ?? $data['regeneration_instruction'] ?? null,
            ],
        ];

        if ($proposal->project) {
            return $this->proposeForProject($proposal->project, $data, $source);
        }

        if ($proposal->sourceCapture) {
            return $this->proposeForCapture($proposal->sourceCapture, $data, $source);
        }

        throw ValidationException::withMessages([
            'proposal' => 'A proposta nao possui projeto nem captura de origem para regeneracao.',
        ]);
    }

    /**
     * @return array{proposal: AtlasProjectPlanProposal, project: AtlasProject, active_next_task: AtlasTask}
     */
    public function accept(AtlasProjectPlanProposal $proposal, array $data = [], string $source = 'project_plan.accept'): array
    {
        if (! in_array($proposal->status, ['draft', 'pending_review'], true)) {
            throw ValidationException::withMessages([
                'proposal' => 'Apenas propostas pendentes podem ser aceitas.',
            ]);
        }

        return DB::transaction(function () use ($proposal, $data, $source): array {
            $proposal = $proposal->refresh()->load(['project', 'sourceCapture']);
            $capture = $proposal->sourceCapture;
            $project = $proposal->project;
            $plan = $this->planFromProposal($proposal, $data);

            if (! $project && $capture) {
                $project = AtlasProject::query()->firstOrNew(['source_capture_id' => $capture->id]);
            }

            if (! $project) {
                throw ValidationException::withMessages([
                    'project_id' => 'A proposta precisa de projeto ou captura de origem para ser aceita.',
                ]);
            }

            $metadata = is_array($project->metadata) ? $project->metadata : [];
            $project->fill([
                'title' => $this->title($data['title'] ?? $proposal->proposed_title, $capture?->content_text, $proposal->proposed_title),
                'description' => $data['description'] ?? $project->description ?? $capture?->content_text,
                'status' => 'active',
                'domain' => $data['domain'] ?? $project->domain ?? $capture?->domain ?? 'atlas',
                'source_capture_id' => $project->source_capture_id ?? $capture?->id,
                'goal' => $data['goal'] ?? $plan['desired_outcome'],
                'next_action' => $plan['next_action'],
                'project_type' => $plan['project_type'],
                'desired_outcome' => $plan['desired_outcome'],
                'minimum_viable_outcome' => $plan['minimum_viable_outcome'],
                'definition_of_done' => $plan['definition_of_done'],
                'why_now' => $data['why_now'] ?? $project->why_now,
                'deadline_at' => $data['deadline_at'] ?? $project->deadline_at,
                'deadline_kind' => $data['deadline_kind'] ?? $project->deadline_kind ?? 'none',
                'priority' => $plan['priority'],
                'energy_profile' => $plan['energy_profile'],
                'avoidance_reason' => $plan['avoidance_reason'],
                'last_touched_at' => now(),
                'next_review_at' => now()->addDays(3),
                'metadata' => [
                    ...$metadata,
                    'created_from' => $metadata['created_from'] ?? ($capture ? 'capture_project_plan_proposal' : 'project_plan_proposal'),
                    'plan_proposal_id' => $proposal->id,
                    'planner_version' => $proposal->planner_version,
                    'process_steps' => $plan['process_steps'],
                    'plan_rationale' => $proposal->rationale,
                    'last_plan_accepted_at' => now()->toJSON(),
                ],
            ]);
            $project->save();

            $steps = $this->execution->ensureProjectPlan($project->refresh(), $plan, true, $source);
            $task = $this->execution->ensureNextActionTask($project->refresh(), $capture, $data, $plan, $source);

            $proposal->forceFill([
                'project_id' => $project->id,
                'status' => 'accepted',
                'accepted_at' => now(),
                'metadata' => [
                    ...(is_array($proposal->metadata) ? $proposal->metadata : []),
                    'accepted_by' => 'human',
                    'accepted_source' => $source,
                    'accepted_task_id' => $task->id,
                    'accepted_step_count' => $steps->count(),
                ],
            ])->save();

            $this->supersedeSiblingProposals($proposal);
            $this->execution->recordEvent($project->refresh(), 'plan_proposal_accepted', [
                'proposal_id' => $proposal->id,
                'source_capture_id' => $capture?->id,
                'project_type' => $project->project_type,
                'active_next_task_id' => $task->id,
                'step_count' => $steps->count(),
            ], $source);

            if ($capture) {
                $this->linkAcceptedCaptureProject($capture, $project->refresh(), $task->refresh(), $proposal->refresh());
            }

            $this->audit->record('project_plan_proposal_accepted', [
                'subject_type' => 'atlas_project',
                'subject_id' => $project->id,
                'summary' => "Proposta de plano aceita para projeto: {$project->title}.",
                'evidence' => [
                    'proposal_id' => $proposal->id,
                    'project_id' => $project->id,
                    'project_type' => $project->project_type,
                    'first_next_action' => $task->title,
                    'source_capture_id' => $capture?->id,
                ],
                'privacy' => $capture ? $this->capturePrivacy($capture) : ['domain' => $project->domain, 'sensitivity' => 'normal'],
                'refs' => [
                    'proposal_id' => $proposal->id,
                    'project_id' => $project->id,
                    'task_id' => $task->id,
                    'capture_id' => $capture?->id,
                ],
            ]);

            return [
                'proposal' => $proposal->refresh(),
                'project' => $project->refresh(),
                'active_next_task' => $task->refresh(),
            ];
        });
    }

    public function reject(AtlasProjectPlanProposal $proposal, array $data = [], string $source = 'project_plan.reject'): AtlasProjectPlanProposal
    {
        if (! in_array($proposal->status, ['draft', 'pending_review'], true)) {
            throw ValidationException::withMessages([
                'proposal' => 'Apenas propostas pendentes podem ser rejeitadas.',
            ]);
        }

        $proposal->forceFill([
            'status' => 'rejected',
            'rejected_at' => now(),
            'metadata' => [
                ...(is_array($proposal->metadata) ? $proposal->metadata : []),
                'rejected_by' => 'human',
                'rejected_source' => $source,
                'rejection_reason' => $data['reason'] ?? null,
            ],
        ])->save();

        if ($proposal->project) {
            $this->execution->recordEvent($proposal->project, 'plan_proposal_rejected', [
                'proposal_id' => $proposal->id,
                'reason' => $data['reason'] ?? null,
            ], $source);
        }

        return $proposal->refresh();
    }

    private function persistProposal(?AtlasProject $project, ?Capture $capture, array $data, string $title, array $plan, string $source): AtlasProjectPlanProposal
    {
        $this->supersedePending($project, $capture);

        $steps = array_values(array_filter((array) ($plan['project_steps'] ?? []), 'is_array'));
        $firstStep = $steps[0] ?? null;
        $proposal = AtlasProjectPlanProposal::query()->create([
            'project_id' => $project?->id,
            'source_capture_id' => $capture?->id,
            'status' => 'pending_review',
            'proposed_title' => $title,
            'planner_version' => self::PLANNER_VERSION,
            'input_hash' => $this->inputHash($project, $capture, $data, $title),
            'project_type' => (string) $plan['project_type'],
            'avoidance_profile' => (string) ($plan['avoidance_reason'] ?? 'unknown'),
            'desired_outcome' => (string) $plan['desired_outcome'],
            'definition_of_done' => (string) $plan['definition_of_done'],
            'minimum_useful_result' => (string) $plan['minimum_viable_outcome'],
            'first_milestone' => is_array($firstStep) ? (string) ($firstStep['title'] ?? '') : null,
            'first_next_action' => (string) $plan['next_action'],
            'estimated_energy' => (string) ($data['energy_required'] ?? $plan['energy_required'] ?? $plan['energy_profile'] ?? 'medium'),
            'estimated_duration_minutes' => (int) ($data['estimated_minutes'] ?? $plan['estimated_minutes'] ?? 25),
            'priority_suggestion' => (string) ($plan['priority'] ?? 'normal'),
            'confidence' => $this->confidence($data, $capture, $plan),
            'phases_json' => array_values((array) ($plan['process_steps'] ?? [])),
            'steps_json' => $steps,
            'risks_json' => $this->risks($plan),
            'questions_json' => $this->questions($plan),
            'rationale' => $this->rationale($plan, $capture, $data),
            'metadata' => [
                'source' => $source,
                'raw_plan' => Arr::only($plan, [
                    'starter_step',
                    'minimum_viable_action',
                    'if_then_plan',
                    'reward_hint',
                    'execution_mode',
                    'friction_level',
                    'emotional_resistance',
                    'clarity_level',
                    'deadline_at',
                    'deadline_kind',
                ]),
                'human_gate' => 'vitor_ratifies_before_project_state',
                'regeneration_instruction' => $data['instruction'] ?? $data['regeneration_instruction'] ?? null,
            ],
        ]);

        if ($project) {
            $this->execution->recordEvent($project, 'plan_proposal_created', [
                'proposal_id' => $proposal->id,
                'project_type' => $proposal->project_type,
                'first_next_action' => $proposal->first_next_action,
                'confidence' => $proposal->confidence,
            ], $source);
        }

        $this->audit->record('project_plan_proposal_created', [
            'subject_type' => $project ? 'atlas_project' : 'capture',
            'subject_id' => $project?->id ?? $capture?->id,
            'summary' => "Proposta de plano criada: {$title}.",
            'evidence' => [
                'proposal_id' => $proposal->id,
                'project_type' => $proposal->project_type,
                'first_next_action' => $proposal->first_next_action,
                'source_capture_id' => $capture?->id,
                'project_id' => $project?->id,
            ],
            'privacy' => $capture ? $this->capturePrivacy($capture) : ['domain' => $project?->domain, 'sensitivity' => 'normal'],
            'refs' => [
                'proposal_id' => $proposal->id,
                'project_id' => $project?->id,
                'capture_id' => $capture?->id,
            ],
        ]);

        return $proposal->refresh()->load(['project', 'sourceCapture']);
    }

    private function supersedePending(?AtlasProject $project, ?Capture $capture): void
    {
        if (! $project && ! $capture) {
            return;
        }

        AtlasProjectPlanProposal::query()
            ->whereIn('status', ['draft', 'pending_review'])
            ->when($project, fn ($query) => $query->where('project_id', $project->id))
            ->when(! $project && $capture, fn ($query) => $query->where('source_capture_id', $capture->id)->whereNull('project_id'))
            ->update([
                'status' => 'superseded',
                'updated_at' => now(),
            ]);
    }

    private function supersedeSiblingProposals(AtlasProjectPlanProposal $accepted): void
    {
        AtlasProjectPlanProposal::query()
            ->whereKeyNot($accepted->id)
            ->whereIn('status', ['draft', 'pending_review'])
            ->where(function ($query) use ($accepted): void {
                if ($accepted->project_id) {
                    $query->orWhere('project_id', $accepted->project_id);
                }
                if ($accepted->source_capture_id) {
                    $query->orWhere('source_capture_id', $accepted->source_capture_id);
                }
            })
            ->update([
                'status' => 'superseded',
                'updated_at' => now(),
            ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function planFromProposal(AtlasProjectPlanProposal $proposal, array $data): array
    {
        $metadata = is_array($proposal->metadata) ? $proposal->metadata : [];
        $raw = is_array($metadata['raw_plan'] ?? null) ? $metadata['raw_plan'] : [];
        $projectType = (string) ($data['project_type'] ?? $proposal->project_type);
        $priority = (string) ($data['priority'] ?? $proposal->priority_suggestion);
        $firstNextAction = trim((string) ($data['next_action'] ?? $proposal->first_next_action));

        return [
            'project_type' => $projectType,
            'desired_outcome' => (string) ($data['desired_outcome'] ?? $data['goal'] ?? $proposal->desired_outcome),
            'minimum_viable_outcome' => (string) ($data['minimum_viable_outcome'] ?? $proposal->minimum_useful_result),
            'definition_of_done' => (string) ($data['definition_of_done'] ?? $proposal->definition_of_done),
            'why_now' => $data['why_now'] ?? null,
            'deadline_at' => $data['deadline_at'] ?? $raw['deadline_at'] ?? null,
            'deadline_kind' => $data['deadline_kind'] ?? $raw['deadline_kind'] ?? 'none',
            'priority' => in_array($priority, ['low', 'normal', 'high', 'urgent'], true) ? $priority : 'normal',
            'energy_profile' => $this->energyProfile((string) ($proposal->estimated_energy ?? 'medium')),
            'avoidance_reason' => (string) ($proposal->avoidance_profile ?: 'unknown'),
            'next_action' => $firstNextAction !== '' ? $firstNextAction : 'Definir a menor proxima acao executavel',
            'starter_step' => $raw['starter_step'] ?? null,
            'minimum_viable_action' => $raw['minimum_viable_action'] ?? $firstNextAction,
            'if_then_plan' => $raw['if_then_plan'] ?? null,
            'reward_hint' => $raw['reward_hint'] ?? null,
            'execution_mode' => $raw['execution_mode'] ?? 'quick_win',
            'estimated_minutes' => (int) ($data['estimated_minutes'] ?? $proposal->estimated_duration_minutes ?? 25),
            'energy_required' => $this->taskEnergy((string) ($data['energy_required'] ?? $proposal->estimated_energy ?? 'medium')),
            'friction_level' => $raw['friction_level'] ?? 50,
            'emotional_resistance' => $raw['emotional_resistance'] ?? 50,
            'clarity_level' => $raw['clarity_level'] ?? 70,
            'process_steps' => array_values((array) $proposal->phases_json),
            'project_steps' => array_values((array) $proposal->steps_json),
        ];
    }

    private function linkAcceptedCaptureProject(
        Capture $capture,
        AtlasProject $project,
        AtlasTask $task,
        AtlasProjectPlanProposal $proposal,
    ): void {
        $link = null;
        if (Schema::hasTable('capture_links')) {
            $link = CaptureLink::query()->updateOrCreate([
                'capture_id' => $capture->id,
                'target_type' => 'project',
                'target_id' => $project->id,
                'relation_type' => 'triage_destination',
            ], [
                'target_title' => $project->title,
                'metadata' => [
                    'action' => 'accept_project_plan_proposal',
                    'project_type' => $project->project_type,
                    'active_next_task_id' => $task->id,
                    'proposal_id' => $proposal->id,
                    'capture_domain' => $capture->domain,
                    'linked_at' => now()->toJSON(),
                ],
            ]);
        }

        $metadata = is_array($capture->metadata) ? $capture->metadata : [];
        $previousTriage = is_array($metadata['triage'] ?? null) ? $metadata['triage'] : [];
        $history = is_array($metadata['triage_history'] ?? null) ? $metadata['triage_history'] : [];
        $metadata['triage'] = [
            ...$previousTriage,
            'status' => 'action_required',
            'destination' => 'project',
            'last_action' => 'accept_project_plan_proposal',
            'updated_at' => now()->toJSON(),
            'title' => $project->title,
            'goal' => $project->goal,
            'next_action' => $project->next_action,
            'project_type' => $project->project_type,
            'active_next_task_id' => $task->id,
            'active_next_task_title' => $task->title,
            'plan_proposal_id' => $proposal->id,
            'target_type' => 'project',
            'target_id' => $project->id,
            'target_title' => $project->title,
        ];
        $metadata['triage_history'] = array_slice([
            [
                'action' => 'accept_project_plan_proposal',
                'status' => 'action_required',
                'destination' => 'project',
                'at' => now()->toJSON(),
                'proposal_id' => $proposal->id,
                'target_type' => 'project',
                'target_id' => $project->id,
                'target_title' => $project->title,
                'previous_destination' => $previousTriage['destination'] ?? null,
                'previous_target_type' => $previousTriage['target_type'] ?? null,
                'previous_target_id' => $previousTriage['target_id'] ?? null,
                'previous_target_title' => $previousTriage['target_title'] ?? null,
                'changed_destination' => ($previousTriage['target_id'] ?? null) !== $project->id,
                'capture_link_id' => $link?->id,
            ],
            ...$history,
        ], 0, 20);
        $capture->forceFill(['metadata' => $metadata])->save();
    }

    private function title(mixed $candidate, mixed $sourceText, string $fallback): string
    {
        $title = trim((string) $candidate);
        if ($title === '') {
            $title = trim((string) $sourceText);
        }
        if ($title === '') {
            $title = $fallback;
        }

        return mb_substr(preg_replace('/\s+/u', ' ', $title) ?: $fallback, 0, 180);
    }

    private function inputHash(?AtlasProject $project, ?Capture $capture, array $data, string $title): string
    {
        return hash('sha256', json_encode([
            'project_id' => $project?->id,
            'capture_id' => $capture?->id,
            'title' => $title,
            'text' => $capture?->content_text,
            'data' => $data,
            'planner_version' => self::PLANNER_VERSION,
        ], JSON_THROW_ON_ERROR));
    }

    private function confidence(array $data, ?Capture $capture, array $plan): float
    {
        $confidence = 0.72;
        if (trim((string) ($data['goal'] ?? '')) !== '') {
            $confidence += 0.06;
        }
        if (trim((string) ($data['next_action'] ?? '')) !== '') {
            $confidence += 0.04;
        }
        if (trim((string) $capture?->content_text) !== '') {
            $confidence += 0.05;
        }
        if (($plan['project_type'] ?? 'personal') !== 'personal') {
            $confidence += 0.04;
        }

        return min(0.93, round($confidence, 3));
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function risks(array $plan): array
    {
        $risks = [];
        $type = (string) ($plan['project_type'] ?? 'personal');
        $avoidance = (string) ($plan['avoidance_reason'] ?? 'unknown');

        if ($type === 'technical_build') {
            $risks[] = ['code' => 'scope_creep', 'label' => 'Escopo crescer antes do MVP'];
        }
        if ($type === 'study') {
            $risks[] = ['code' => 'passive_study', 'label' => 'Estudo passivo sem recuperacao ativa'];
        }
        if (in_array($avoidance, ['too_large', 'boring', 'scary', 'perfectionism'], true)) {
            $risks[] = ['code' => $avoidance, 'label' => $this->avoidanceLabel($avoidance)];
        }
        if ($risks === []) {
            $risks[] = ['code' => 'unclear_next_step', 'label' => 'Proxima acao pode precisar de ajuste humano'];
        }

        return $risks;
    }

    /**
     * @return array<int, string>
     */
    private function questions(array $plan): array
    {
        $type = (string) ($plan['project_type'] ?? 'personal');
        $questions = [
            'Qual resultado pequeno faria este projeto valer a pena nos proximos 7 dias?',
            'A primeira acao esta pequena o suficiente para caber em um bloco real?',
        ];

        if ($type === 'technical_build') {
            $questions[] = 'Qual decisao tecnica precisa ser tomada antes de implementar?';
        } elseif ($type === 'study') {
            $questions[] = 'Qual pergunta de estudo prova que voce entendeu o assunto?';
        } elseif ($type === 'tedious') {
            $questions[] = 'Qual versao de 2 minutos reduz a resistencia para comecar?';
        }

        return $questions;
    }

    private function rationale(array $plan, ?Capture $capture, array $data): string
    {
        $source = $capture ? 'captura' : 'projeto';
        $type = (string) ($plan['project_type'] ?? 'personal');
        $next = (string) ($plan['next_action'] ?? 'definir proxima acao');

        return "Atlas classificou a {$source} como {$type}, porque o texto aponta para um objetivo que precisa de decomposicao. A proposta privilegia plano minimo, primeira acao executavel e ratificacao humana antes de alterar o estado final. Proxima acao sugerida: {$next}.";
    }

    private function avoidanceLabel(string $avoidance): string
    {
        return match ($avoidance) {
            'too_large' => 'Projeto grande demais para iniciar sem corte',
            'boring' => 'Tarefa tediosa com risco de procrastinacao',
            'scary' => 'Resistencia emocional ou medo de comecar',
            'perfectionism' => 'Risco de polimento antes de entrega minima',
            default => 'Risco de clareza insuficiente',
        };
    }

    private function energyProfile(string $energy): string
    {
        return in_array($energy, ['low', 'medium', 'high', 'mixed'], true) ? $energy : 'mixed';
    }

    private function taskEnergy(string $energy): string
    {
        return in_array($energy, ['low', 'medium', 'high'], true) ? $energy : 'medium';
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
