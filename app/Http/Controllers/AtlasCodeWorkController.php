<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\AiThread;
use App\Models\AtlasProject;
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
            'receipt' => null,
            'gates' => [],
            'evidence' => [],
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
}
