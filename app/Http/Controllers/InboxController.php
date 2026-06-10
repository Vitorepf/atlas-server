<?php

namespace App\Http\Controllers;

use App\Http\Resources\CaptureResource;
use App\Models\Capture;
use App\Models\InboxHealthSnapshot;
use App\Services\AtlasDomainRegistry;
use App\Services\CaptureService;
use App\Services\Semantic\CurationProposalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use App\Services\Ai\Support\DatabaseTableAvailability;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class InboxController extends Controller
{
    // Cap defensivo no fetch "ilimitado" usado pra computar health/filtragem
    // em PHP. Pra um inbox saudável (<2k abertos) é mais do que suficiente;
    // se o usuário acumular >2k, o caminho ideal é refator de health pra
    // aggregação SQL. Antes não havia limite e 5k+ captures derrubavam o
    // PHP em OOM.
    private const MAX_BASE_CAPTURES = 2000;

    public function index(Request $request, AtlasDomainRegistry $domains): JsonResponse
    {
        $data = $request->validate([
            'q' => ['nullable', 'string', 'max:200'],
            'domain' => ['nullable', 'string', 'max:80', Rule::in($domains->activeSlugs())],
            'status' => ['nullable', Rule::in(['open', 'pending', 'transcribed', 'failed', 'no_destination', 'candidate', 'routed', 'archived', 'snoozed', 'all'])],
            'sort' => ['nullable', Rule::in(['recent', 'oldest', 'updated', 'needs_triage'])],
            'limit' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $limit = (int) ($data['limit'] ?? 80);
        $baseCaptures = $this->baseCaptures([...$data, 'limit' => null])->get();
        $captures = $baseCaptures
            ->filter(fn (Capture $capture): bool => $this->matchesStatus($capture, $data['status'] ?? 'open'))
            ->take($limit)
            ->values();
        $active = $baseCaptures
            ->filter(fn (Capture $capture): bool => $this->isOpenCapture($capture))
            ->values();

        return response()->json([
            'captures' => CaptureResource::collection($captures)->resolve(),
            'health' => $this->healthFor($active),
            'generated_at' => now()->toJSON(),
        ]);
    }

    public function health(Request $request, AtlasDomainRegistry $domains): JsonResponse
    {
        $data = $request->validate([
            'domain' => ['nullable', 'string', 'max:80', Rule::in($domains->activeSlugs())],
        ]);

        $captures = $this->baseCaptures([
            'domain' => $data['domain'] ?? null,
            'limit' => null,
            'sort' => 'recent',
        ])->get();

        $active = $captures
            ->filter(fn (Capture $capture): bool => $this->isOpenCapture($capture))
            ->values();

        $health = $this->healthFor($active);
        $snapshot = InboxHealthSnapshot::query()->updateOrCreate(
            [
                'snapshot_date' => now()->toDateString(),
                'domain' => $data['domain'] ?? null,
            ],
            [
                'open_count' => $health['open_count'],
                'no_destination_count' => $health['no_destination_count'],
                'failed_count' => $health['failed_count'],
                'curation_candidate_count' => $health['curation_candidate_count'],
                'average_age_hours' => $health['average_age_hours'],
                'oldest_capture_at' => $health['oldest_capture_at'],
                'metrics' => $health,
            ],
        );

        return response()->json([
            'health' => $health,
            'snapshot' => [
                'id' => $snapshot->id,
                'snapshot_date' => $snapshot->snapshot_date?->toDateString(),
                'domain' => $snapshot->domain,
                'created_at' => $snapshot->created_at?->toJSON(),
                'updated_at' => $snapshot->updated_at?->toJSON(),
            ],
            'generated_at' => now()->toJSON(),
        ]);
    }

    public function bulk(Request $request, CaptureService $captures, CurationProposalService $curation): JsonResponse
    {
        $data = $request->validate([
            'capture_ids' => ['required', 'array', 'min:1', 'max:100'],
            'capture_ids.*' => ['uuid', 'exists:captures,id'],
            'action' => ['required', Rule::in(['promote', 'archive', 'snooze', 'create_task', 'create_project', 'create_hypothesis'])],
            'title' => ['nullable', 'string', 'max:180'],
            'snoozed_until' => ['nullable', 'date', 'after:now'],
            'due_at' => ['nullable', 'date'],
            'priority' => ['nullable', Rule::in(['low', 'normal', 'high', 'urgent'])],
            'planned_for_date' => ['nullable', 'date'],
            'planned_start_at' => ['nullable', 'date'],
            'planned_end_at' => ['nullable', 'date'],
            'estimated_minutes' => ['nullable', 'integer', 'min:5', 'max:480'],
            'energy_required' => ['nullable', Rule::in(['low', 'medium', 'high'])],
            'urgency_score' => ['nullable', 'integer', 'min:0', 'max:100'],
            'impact_score' => ['nullable', 'integer', 'min:0', 'max:100'],
            'effort_score' => ['nullable', 'integer', 'min:0', 'max:100'],
            'priority_score' => ['nullable', 'integer', 'min:0', 'max:100'],
            'goal' => ['nullable', 'string', 'max:500'],
            'next_action' => ['nullable', 'string', 'max:300'],
            'reason' => ['nullable', 'string', 'max:500'],
            'metadata' => ['nullable', 'array'],
        ]);

        if ($data['action'] === 'snooze' && empty($data['snoozed_until'])) {
            abort(422, 'A snooze date is required.');
        }

        $updated = DB::transaction(function () use ($data, $captures, $curation): Collection {
            $updated = collect();
            foreach ($data['capture_ids'] as $captureId) {
                $capture = Capture::query()->findOrFail($captureId);
                $result = $captures->triage($capture, [
                    'action' => $data['action'],
                    'title' => $data['title'] ?? null,
                    'snoozed_until' => $data['snoozed_until'] ?? null,
                    'due_at' => $data['due_at'] ?? null,
                    'priority' => $data['priority'] ?? null,
                    'planned_for_date' => $data['planned_for_date'] ?? null,
                    'planned_start_at' => $data['planned_start_at'] ?? null,
                    'planned_end_at' => $data['planned_end_at'] ?? null,
                    'estimated_minutes' => $data['estimated_minutes'] ?? null,
                    'energy_required' => $data['energy_required'] ?? null,
                    'urgency_score' => $data['urgency_score'] ?? null,
                    'impact_score' => $data['impact_score'] ?? null,
                    'effort_score' => $data['effort_score'] ?? null,
                    'priority_score' => $data['priority_score'] ?? null,
                    'goal' => $data['goal'] ?? null,
                    'next_action' => $data['next_action'] ?? null,
                    'reason' => $data['reason'] ?? 'Ação em lote do Inbox.',
                    'metadata' => [
                        ...($data['metadata'] ?? []),
                        'bulk_action' => true,
                    ],
                ], $curation);
                $updated->push($result['capture']);
            }

            return $updated;
        });

        return response()->json([
            'captures' => CaptureResource::collection($updated)->resolve(),
            'updated_count' => $updated->count(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function baseCaptures(array $data)
    {
        $limit = array_key_exists('limit', $data) ? $data['limit'] : 80;
        $query = Capture::query();
        if (DatabaseTableAvailability::has('capture_links')) {
            $query->with('links');
        }

        if (! empty($data['domain'])) {
            $query->where('domain', $data['domain']);
        }

        if (! empty($data['q'])) {
            $q = mb_strtolower((string) $data['q']);
            $query->whereRaw('LOWER(COALESCE(content_text, \'\')) LIKE ?', ['%'.$q.'%']);
        }

        match ($data['sort'] ?? 'recent') {
            'oldest' => $query->orderBy('captured_at')->orderBy('id'),
            'updated' => $query->orderByDesc('updated_at')->orderByDesc('id'),
            'needs_triage' => $query->orderBy('captured_at')->orderBy('id'),
            default => $query->orderByDesc('captured_at')->orderByDesc('id'),
        };

        if ($limit === null) {
            return $query->limit(self::MAX_BASE_CAPTURES);
        }

        return $query->limit(max(1, (int) $limit));
    }

    /**
     * @param  Collection<int, Capture>  $captures
     * @return array<string, mixed>
     */
    private function healthFor(Collection $captures): array
    {
        $now = now();
        $ages = $captures
            ->map(fn (Capture $capture): ?float => $capture->captured_at ? $capture->captured_at->diffInHours($now) : null)
            ->filter(fn (?float $age): bool => $age !== null && $age >= 0)
            ->values();

        $byDomain = $captures
            ->groupBy('domain')
            ->map(fn (Collection $domainCaptures): array => [
                'open_count' => $domainCaptures->count(),
                'no_destination_count' => $domainCaptures->filter(fn (Capture $capture): bool => $this->hasNoDestination($capture))->count(),
                'failed_count' => $domainCaptures->filter(fn (Capture $capture): bool => $this->isFailed($capture))->count(),
                'curation_candidate_count' => $domainCaptures->filter(fn (Capture $capture): bool => $this->isCandidate($capture))->count(),
            ]);

        return [
            'open_count' => $captures->count(),
            'no_destination_count' => $captures->filter(fn (Capture $capture): bool => $this->hasNoDestination($capture))->count(),
            'failed_count' => $captures->filter(fn (Capture $capture): bool => $this->isFailed($capture))->count(),
            'curation_candidate_count' => $captures->filter(fn (Capture $capture): bool => $this->isCandidate($capture))->count(),
            'average_age_hours' => round($ages->avg() ?? 0, 2),
            'oldest_capture_at' => $captures->sortBy('captured_at')->first()?->captured_at?->toJSON(),
            'by_domain' => (object) $byDomain->all(),
        ];
    }

    private function matchesStatus(Capture $capture, string $status): bool
    {
        return match ($status) {
            'all' => true,
            'archived' => $this->isArchived($capture),
            'snoozed' => $this->isSnoozedForFuture($capture),
            'pending' => in_array($capture->transcription_status, ['pending', 'processing'], true),
            'transcribed' => $capture->kind !== 'audio' || $capture->transcription_status === 'done',
            'failed' => $this->isFailed($capture),
            'no_destination' => $this->hasNoDestination($capture),
            'candidate' => $this->isCandidate($capture),
            'routed' => $this->hasResolvedDestination($capture) && ! $this->isArchived($capture) && ! $this->isSnoozedForFuture($capture),
            default => $this->isOpenCapture($capture),
        };
    }

    private function isOpenCapture(Capture $capture): bool
    {
        return ! $this->isArchived($capture)
            && ! $this->isSnoozedForFuture($capture)
            && ! $this->hasResolvedDestination($capture);
    }

    private function isArchived(Capture $capture): bool
    {
        return $this->triageStatus($capture) === 'archived';
    }

    private function isSnoozedForFuture(Capture $capture): bool
    {
        $triage = $this->triage($capture);
        if (($triage['status'] ?? null) !== 'snoozed' || empty($triage['snoozed_until'])) {
            return false;
        }

        return strtotime((string) $triage['snoozed_until']) > time();
    }

    private function isExpiredSnooze(Capture $capture): bool
    {
        $triage = $this->triage($capture);
        if (($triage['status'] ?? null) !== 'snoozed' || empty($triage['snoozed_until'])) {
            return false;
        }

        return strtotime((string) $triage['snoozed_until']) <= time();
    }

    private function isFailed(Capture $capture): bool
    {
        if ($capture->transcription_status === 'failed') {
            return true;
        }

        return (bool) $capture->content_file_path
            && ! Storage::disk('atlas')->exists($capture->content_file_path);
    }

    private function isCandidate(Capture $capture): bool
    {
        return $this->hasNoDestination($capture)
            && ! $this->isFailed($capture)
            && trim((string) $capture->content_text) !== '';
    }

    private function hasNoDestination(Capture $capture): bool
    {
        return (! $this->triageStatus($capture) || $this->isExpiredSnooze($capture))
            && ! $this->hasResolvedDestination($capture);
    }

    private function hasResolvedDestination(Capture $capture): bool
    {
        $triage = $this->triage($capture);
        $destination = $triage['destination'] ?? null;
        if (is_string($destination) && in_array($destination, [
            'semantic_note',
            'existing_note',
            'task',
            'project',
            'hypothesis',
        ], true)) {
            return true;
        }

        $targetType = $triage['target_type'] ?? null;
        if (is_string($targetType) && in_array($targetType, [
            'semantic_note',
            'semantic_curation_proposal',
            'task',
            'project',
            'hypothesis',
        ], true)) {
            return true;
        }

        $links = $capture->relationLoaded('links') ? $capture->links : collect();

        return $links->contains(fn ($link): bool => $link->relation_type === 'triage_destination'
            && in_array($link->target_type, [
                'semantic_note',
                'semantic_curation_proposal',
                'task',
                'project',
                'hypothesis',
            ], true));
    }

    /**
     * @return array<string, mixed>
     */
    private function triage(Capture $capture): array
    {
        $triage = data_get($capture->metadata, 'triage', []);

        return is_array($triage) ? $triage : [];
    }

    private function triageStatus(Capture $capture): ?string
    {
        $status = $this->triage($capture)['status'] ?? null;

        return is_string($status) && $status !== '' ? $status : null;
    }
}
