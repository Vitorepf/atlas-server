<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\AiThread;
use App\Models\AiDecision;
use App\Models\AiTrace;
use App\Models\AtlasEngineeringEvidence;
use App\Models\AtlasEngineeringRun;
use App\Models\AtlasLedgerEvent;
use App\Models\AtlasProject;
use App\Models\AtlasToolRun;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Atlas Code · normalized "Obras" surface.
 *
 * Atlas Server's legacy `/projects` returns `{ projects: [...] }` with the
 * AtlasProjectResource shape. The desktop bridge expects a flat list of
 * `Obra` (camelCase, slim). This controller wraps:
 *
 *   GET    /api/atlas-code/works            · list flat Obra[]
 *   GET    /api/atlas-code/works/{project}  · show single Obra (joined with summary)
 *   POST   /api/atlas-code/works            · create Obra from intent+objective
 *   GET    /api/atlas-code/works/{project}/state · full snapshot for cockpit
 *
 * The `/projects` controller stays untouched (mobile + legacy still depend on
 * it). This wrapper is the canonical Desktop surface.
 */
final class AtlasCodeWorkController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'status' => ['nullable', 'string', 'max:40'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);
        $limit = (int) ($data['limit'] ?? 50);

        $query = AtlasProject::query()
            ->orderByDesc('updated_at')
            ->limit($limit);
        if (! empty($data['status'])) {
            $query->where('status', $data['status']);
        }

        return response()->json([
            'data' => $query->get()->map(fn (AtlasProject $p): array => $this->shape($p))->all(),
            'meta' => [
                'total' => $query->count(),
            ],
        ]);
    }

    public function show(AtlasProject $project): JsonResponse
    {
        return response()->json([
            'work' => $this->shape($project, withDetail: true),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'intent' => ['required', 'string', 'max:240'],
            'objective' => ['required', 'string', 'max:240'],
            'domain' => ['nullable', 'string', 'max:80'],
            'title' => ['nullable', 'string', 'max:180'],
        ]);

        $title = trim((string) ($data['title'] ?? $data['objective']));
        $project = AtlasProject::query()->create([
            'title' => $title,
            'description' => $data['intent'],
            'status' => 'active',
            'domain' => $data['domain'] ?? 'atlas',
            'goal' => $data['objective'],
            'desired_outcome' => $data['objective'],
            'priority' => 'medium',
            'last_touched_at' => now(),
            'metadata' => [
                'origin' => 'atlas-code',
                'intent' => $data['intent'],
            ],
        ]);

        return response()->json([
            'work' => $this->shape($project, withDetail: true),
        ], 201);
    }

    public function state(AtlasProject $project): JsonResponse
    {
        // Sessions linked to this Obra via AiThread.source_id == project_id
        $threads = AiThread::query()
            ->where(function ($q) use ($project) {
                $q->where('source_id', (string) $project->getKey())
                    ->orWhere('workspace', (string) $project->getKey());
            })
            ->orderByDesc('last_message_at')
            ->limit(20)
            ->get();

        $activeThread = $threads->first(fn (AiThread $t) => in_array($t->status, ['active', 'running', 'streaming'], true))
            ?? $threads->first();

        $messages = $activeThread
            ? $activeThread->messages()->orderBy('position')->limit(120)->get()->map(fn ($m): array => [
                'id' => (string) $m->getKey(),
                'role' => (string) $m->role,
                'body' => $this->renderBody($m->content),
                'occurred_at' => $m->occurred_at?->toJSON() ?? $m->created_at?->toJSON(),
            ])->all()
            : [];

        $traceIds = $this->traceIdsForWork($project, $threads->pluck('id')->map(fn ($id): string => (string) $id)->all());
        $latestDecision = $traceIds === []
            ? null
            : AiDecision::query()->whereIn('trace_id', $traceIds)->latest('created_at')->first();

        return response()->json([
            'work' => $this->shape($project, withDetail: true),
            'sessions' => $threads->map(fn (AiThread $t): array => [
                'id' => (string) $t->getKey(),
                'title' => (string) ($t->title ?? 'Sessão'),
                'status' => $this->mapThreadStatus((string) ($t->status ?? 'idle')),
                'turns' => (int) ($t->message_count ?? 0),
                'last_message_at' => $t->last_message_at?->toJSON(),
            ])->all(),
            'active_thread' => $activeThread ? (string) $activeThread->getKey() : null,
            'messages' => $messages,
            'sdd' => $this->sddSnapshot($project),
            'receipt' => $latestDecision ? $this->receiptShape($latestDecision, $project) : null,
            'gates' => $this->gateRunsForWork($project),
            'evidence' => $this->evidenceForWork($project),
            'repair' => [],
            'learning_proposals' => [],
            'generated_at' => now()->toJSON(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function shape(AtlasProject $project, bool $withDetail = false): array
    {
        $base = [
            'id' => (string) $project->getKey(),
            'title' => (string) ($project->title ?? ''),
            'objective' => (string) ($project->goal ?? $project->desired_outcome ?? $project->title ?? ''),
            'status' => $this->mapStatus((string) ($project->status ?? 'active')),
            'domain' => (string) ($project->domain ?? 'atlas'),
            'workspace_path' => (string) (data_get($project->metadata, 'workspace_path') ?? ''),
            'created_at' => $project->created_at?->toJSON(),
            'updated_at' => $project->updated_at?->toJSON(),
        ];
        if (! $withDetail) {
            return $base;
        }
        return array_merge($base, [
            'description' => (string) ($project->description ?? ''),
            'next_action' => (string) ($project->next_action ?? ''),
            'priority' => (string) ($project->priority ?? 'medium'),
            'definition_of_done' => (string) ($project->definition_of_done ?? ''),
            'metadata' => $project->metadata ?? [],
            'last_touched_at' => $project->last_touched_at?->toJSON(),
            'next_review_at' => $project->next_review_at?->toJSON(),
        ]);
    }

    private function mapStatus(string $status): string
    {
        return match ($status) {
            'active', 'in_progress', 'planning' => 'active',
            'paused', 'snoozed' => 'idle',
            'archived', 'completed', 'done' => 'archived',
            default => 'active',
        };
    }

    private function mapThreadStatus(string $status): string
    {
        return match ($status) {
            'active', 'streaming' => 'running',
            'paused', 'awaiting_input' => 'paused',
            'completed', 'done', 'closed' => 'done',
            'failed', 'error' => 'failed',
            default => 'paused',
        };
    }

    private function renderBody(mixed $content): string
    {
        if (is_string($content)) {
            return $content;
        }
        if (is_array($content)) {
            if (isset($content['text']) && is_string($content['text'])) {
                return $content['text'];
            }
            return (string) json_encode($content, JSON_UNESCAPED_UNICODE);
        }
        return '';
    }

    /**
     * Pipeline OS snapshot · canon stages: context · spec · plan · execute · verify · learn.
     * Stage is inferred from the AtlasProject lifecycle without inventing data.
     *
     * @return array<string, mixed>
     */
    private function sddSnapshot(AtlasProject $project): array
    {
        $status = (string) ($project->status ?? 'active');
        $stage = match ($status) {
            'planning', 'spec' => 'spec',
            'in_progress' => 'execute',
            'review', 'verify' => 'verify',
            'completed', 'archived' => 'learn',
            'idle', 'paused' => 'idle',
            default => 'context',
        };
        $steps = [
            ['key' => 'context', 'label' => 'Context', 'status' => $this->stepStatus('context', $stage)],
            ['key' => 'spec', 'label' => 'Spec', 'status' => $this->stepStatus('spec', $stage)],
            ['key' => 'plan', 'label' => 'Plan', 'status' => $this->stepStatus('plan', $stage)],
            ['key' => 'execute', 'label' => 'Execute', 'status' => $this->stepStatus('execute', $stage)],
            ['key' => 'verify', 'label' => 'Verify', 'status' => $this->stepStatus('verify', $stage)],
            ['key' => 'learn', 'label' => 'Learn', 'status' => $this->stepStatus('learn', $stage)],
        ];
        return [
            'stage' => $stage,
            'steps' => $steps,
            'spec' => null,
            'plan' => null,
        ];
    }

    private function stepStatus(string $step, string $current): string
    {
        $order = ['context', 'spec', 'plan', 'execute', 'verify', 'learn'];
        $stepIdx = array_search($step, $order, true);
        $currentIdx = array_search($current, $order, true);
        if ($currentIdx === false || $stepIdx === false) {
            return 'pending';
        }
        if ($stepIdx < $currentIdx) {
            return 'done';
        }
        if ($stepIdx === $currentIdx) {
            return 'active';
        }
        return 'pending';
    }

    /**
     * @param  array<int,string>  $threadIds
     * @return array<int,string>
     */
    private function traceIdsForWork(AtlasProject $project, array $threadIds): array
    {
        return AiTrace::query()
            ->where(function ($query) use ($project, $threadIds): void {
                $query->where('source_id', (string) $project->getKey());
                if ($threadIds !== []) {
                    $query->orWhereIn('thread_id', $threadIds);
                }
            })
            ->latest('created_at')
            ->limit(50)
            ->pluck('id')
            ->map(fn ($id): string => (string) $id)
            ->all();
    }

    /**
     * @return array<string,mixed>
     */
    private function receiptShape(AiDecision $decision, AtlasProject $project): array
    {
        $score = (int) ($decision->confidence_score ?? 0);
        $confidence = match (true) {
            $score >= 80 => 'high',
            $score >= 50 => 'medium',
            $score > 0 => 'low',
            default => 'unknown',
        };

        $candidates = is_array($decision->candidates) ? $decision->candidates : [];
        $fallback = [];
        foreach ($candidates as $candidate) {
            if (! is_array($candidate)) {
                continue;
            }
            $name = $candidate['provider'] ?? $candidate['name'] ?? null;
            if (is_string($name) && $name !== ($decision->selected_provider ?? null)) {
                $fallback[] = $name;
            }
        }

        $signature = $this->latestSignature($decision);

        return [
            'id' => (string) $decision->getKey(),
            'obraId' => (string) $project->getKey(),
            'traceId' => $decision->trace_id ? (string) $decision->trace_id : null,
            'primary' => (string) ($decision->selected_provider ?? 'unknown'),
            'model' => (string) ($decision->selected_model ?? ''),
            'confidence' => $confidence,
            'confidenceScore' => $score,
            'routeMode' => (string) ($decision->route_mode ?? ''),
            'taskType' => (string) ($decision->task_type ?? ''),
            'riskLevel' => (string) ($decision->risk_level ?? ''),
            'budgetEstUsd' => (float) data_get($decision->constraints, 'budget_est_usd', 0),
            'budgetUsedUsd' => (float) data_get($decision->metrics_snapshot, 'budget_used_usd', 0),
            'fallbackChain' => array_values(array_unique($fallback)),
            'signedBy' => $signature['signed_by'],
            'signature' => $signature['signature'],
            'signedAt' => $signature['signed_at'],
            'reason' => (string) ($decision->reason ?? ''),
            'createdAt' => $decision->created_at?->toJSON(),
        ];
    }

    /**
     * @return array{signed_by: ?string, signature: ?string, signed_at: ?string}
     */
    private function latestSignature(AiDecision $decision): array
    {
        $event = AtlasLedgerEvent::query()
            ->where('receipt_id', (string) $decision->getKey())
            ->where('event_type', 'atlas_code.receipt.signed')
            ->latest('occurred_at')
            ->first();

        if (! $event) {
            return ['signed_by' => null, 'signature' => null, 'signed_at' => null];
        }

        return [
            'signed_by' => is_string(data_get($event->payload, 'signer_id')) ? data_get($event->payload, 'signer_id') : null,
            'signature' => is_string(data_get($event->payload, 'signature')) ? data_get($event->payload, 'signature') : null,
            'signed_at' => is_string(data_get($event->payload, 'signed_at')) ? data_get($event->payload, 'signed_at') : null,
        ];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function gateRunsForWork(AtlasProject $project): array
    {
        $workspace = (string) (data_get($project->metadata, 'workspace_path') ?: '');

        $toolRuns = AtlasToolRun::query()
            ->where(function ($query) use ($project, $workspace): void {
                $query->where(function ($inner) use ($project): void {
                    $inner->where('run_context_type', 'atlas_project')
                        ->where('run_context_id', (string) $project->getKey());
                });

                if ($workspace !== '') {
                    $query->orWhere('workspace', $workspace);
                }
            })
            ->latest('created_at')
            ->limit(30)
            ->get(['id', 'tool_slug', 'status', 'summary_json', 'created_at']);

        $runs = $toolRuns->map(function (AtlasToolRun $run): array {
            return [
                'id' => (string) $run->id,
                'tool_slug' => (string) ($run->tool_slug ?? 'tool_run'),
                'status' => (string) ($run->status ?? 'pending'),
                'message' => (string) (data_get($run->summary_json, 'summary') ?? data_get($run->summary_json, 'message') ?? ''),
            ];
        });

        $engineeringRuns = AtlasEngineeringRun::query()
            ->where('project_id', $project->getKey())
            ->latest('updated_at')
            ->limit(10)
            ->get(['id', 'status', 'decision']);

        foreach ($engineeringRuns as $run) {
            $runs->push([
                'id' => 'engineering:' . $run->id,
                'tool_slug' => 'engineering_run',
                'status' => (string) ($run->status ?? $run->decision ?? 'pending'),
                'message' => (string) ($run->decision ?? ''),
            ]);
        }

        return $runs->values()->all();
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function evidenceForWork(AtlasProject $project): array
    {
        $runs = AtlasEngineeringRun::query()
            ->where('project_id', $project->getKey())
            ->orderByDesc('updated_at')
            ->limit(25)
            ->get(['id', 'status', 'decision', 'finished_at', 'updated_at']);

        $evidence = AtlasEngineeringEvidence::query()
            ->where('project_id', $project->getKey())
            ->orderByDesc('recorded_at')
            ->orderByDesc('created_at')
            ->limit(25)
            ->get();

        return collect()
            ->merge($runs->map(fn (AtlasEngineeringRun $run): array => [
                'id' => 'run:' . $run->id,
                'kind' => 'engineering_run',
                'summary' => sprintf('engineering run %s · %s', substr((string) $run->id, 0, 8), (string) ($run->status ?? 'unknown')),
                'createdAt' => ($run->finished_at ?? $run->updated_at)?->toJSON(),
            ]))
            ->merge($evidence->map(fn (AtlasEngineeringEvidence $item): array => [
                'id' => 'evidence:' . $item->id,
                'kind' => (string) ($item->evidence_type ?? 'evidence'),
                'summary' => (string) ($item->summary ?? $item->output_excerpt ?? 'evidence ' . $item->id),
                'createdAt' => ($item->recorded_at ?? $item->created_at)?->toJSON(),
            ]))
            ->sortByDesc(fn (array $item): string => (string) ($item['createdAt'] ?? ''))
            ->values()
            ->all();
    }
}
