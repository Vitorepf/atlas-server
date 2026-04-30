<?php

namespace App\Http\Controllers;

use App\Http\Requests\IndexHealthSnapshotRequest;
use App\Http\Requests\StoreHealthSnapshotRequest;
use App\Http\Requests\UpdateHealthSnapshotRequest;
use App\Http\Resources\HealthSnapshotResource;
use App\Models\HealthSnapshot;
use App\Services\AuditLogService;
use App\Support\Metadata;
use Illuminate\Http\JsonResponse;

class HealthSnapshotController extends Controller
{
    public function index(IndexHealthSnapshotRequest $request, AuditLogService $audit): JsonResponse
    {
        $data = $request->validated();
        $limit = (int) ($data['limit'] ?? 50);
        $since = $data['since'] ?? null;

        $query = HealthSnapshot::query();

        if ($since) {
            $query->withTrashed()->where('updated_at', '>', $since);
        }

        if (isset($data['source'])) {
            $query->where('source', $data['source']);
        }

        if (isset($data['date_from'])) {
            $query->whereDate('snapshot_date', '>=', $data['date_from']);
        }

        if (isset($data['date_to'])) {
            $query->whereDate('snapshot_date', '<=', $data['date_to']);
        }

        if (isset($data['cursor'])) {
            $cursor = HealthSnapshot::withTrashed()->find($data['cursor']);

            if ($cursor) {
                if ($since) {
                    $query->where(function ($query) use ($cursor): void {
                        $query
                            ->where('updated_at', '>', $cursor->updated_at)
                            ->orWhere(function ($query) use ($cursor): void {
                                $query->where('updated_at', $cursor->updated_at)->where('id', '>', $cursor->id);
                            });
                    });
                } else {
                    $query->where(function ($query) use ($cursor): void {
                        $query
                            ->where('snapshot_date', '<', $cursor->snapshot_date)
                            ->orWhere(function ($query) use ($cursor): void {
                                $query->where('snapshot_date', $cursor->snapshot_date)->where('id', '<', $cursor->id);
                            });
                    });
                }
            }
        }

        $query = $since
            ? $query->orderBy('updated_at')->orderBy('id')
            : $query->orderByDesc('snapshot_date')->orderByDesc('id');

        $snapshots = $query->limit($limit + 1)->get();
        $hasMore = $snapshots->count() > $limit;
        $page = $hasMore ? $snapshots->take($limit)->values() : $snapshots;

        $this->recordAudit($audit, 'health_snapshots_listed', 'Health snapshots listed', [
            'count' => $page->count(),
            'limit' => $limit,
            'has_more' => $hasMore,
            'since' => $since,
            'source' => $data['source'] ?? null,
            'date_from' => $data['date_from'] ?? null,
            'date_to' => $data['date_to'] ?? null,
        ]);

        return response()->json([
            'health_snapshots' => HealthSnapshotResource::collection($page)->resolve(),
            'next_cursor' => $hasMore ? $page->last()?->id : null,
            'has_more' => $hasMore,
        ]);
    }

    public function store(StoreHealthSnapshotRequest $request, AuditLogService $audit): JsonResponse
    {
        $snapshot = $this->upsertSnapshot($request->validated());
        $this->recordAudit(
            $audit,
            $snapshot['created'] ? 'health_snapshot_created' : 'health_snapshot_updated',
            $snapshot['created'] ? 'Health snapshot created' : 'Health snapshot updated',
            [
                'client_id' => $snapshot['model']->client_id,
                'snapshot_date' => $snapshot['model']->snapshot_date?->toDateString(),
                'source' => $snapshot['model']->source,
            ],
            $snapshot['model']->id,
        );

        return (new HealthSnapshotResource($snapshot['model']))
            ->response()
            ->setStatusCode($snapshot['created'] ? 201 : 200);
    }

    public function update(UpdateHealthSnapshotRequest $request, HealthSnapshot $healthSnapshot, AuditLogService $audit): HealthSnapshotResource
    {
        $data = $this->normalizePayload($request->validated());
        $healthSnapshot->update($data);
        $this->recordAudit($audit, 'health_snapshot_updated', 'Health snapshot updated', [
            'client_id' => $healthSnapshot->client_id,
            'snapshot_date' => $healthSnapshot->snapshot_date?->toDateString(),
            'source' => $healthSnapshot->source,
        ], $healthSnapshot->id);

        return new HealthSnapshotResource($healthSnapshot->refresh());
    }

    public function destroy(HealthSnapshot $healthSnapshot, AuditLogService $audit): HealthSnapshotResource
    {
        $healthSnapshot->delete();
        $this->recordAudit($audit, 'health_snapshot_deleted', 'Health snapshot deleted', [
            'client_id' => $healthSnapshot->client_id,
            'snapshot_date' => $healthSnapshot->snapshot_date?->toDateString(),
            'source' => $healthSnapshot->source,
        ], $healthSnapshot->id);

        return new HealthSnapshotResource($healthSnapshot->refresh());
    }

    public function upsertSnapshot(array $data): array
    {
        $payload = $this->normalizePayload($data);
        $snapshot = HealthSnapshot::withTrashed()
            ->where('client_id', $data['client_id'])
            ->first();
        $created = false;

        if (! $snapshot) {
            $snapshot = HealthSnapshot::create($payload);
            $created = true;
        } else {
            $snapshot->fill($payload);
            if ($snapshot->isDirty()) {
                $snapshot->save();
            }

            if ($snapshot->trashed()) {
                $snapshot->restore();
            }
        }

        return ['model' => $snapshot->refresh(), 'created' => $created];
    }

    private function normalizePayload(array $data): array
    {
        if (array_key_exists('confidence', $data)) {
            $data['confidence'] = $this->normalizeConfidence($data['confidence']);
        }

        foreach (['metrics', 'readiness', 'sleep', 'recovery', 'load', 'subjective', 'body', 'metadata'] as $key) {
            if (array_key_exists($key, $data)) {
                $data[$key] = Metadata::forStorage($data[$key]);
            }
        }

        return $data;
    }

    private function normalizeConfidence(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        $number = (float) $value;
        if (! is_finite($number)) {
            return null;
        }

        if ($number > 1) {
            $number /= 100;
        }

        return max(0.0, min(1.0, round($number, 3)));
    }

    private function recordAudit(
        AuditLogService $audit,
        string $eventType,
        string $summary,
        array $evidence,
        ?string $subjectId = null,
    ): void {
        $audit->record($eventType, [
            'subject_type' => 'health_snapshot',
            'subject_id' => $subjectId,
            'actor_type' => 'api',
            'severity' => 'info',
            'summary' => $summary,
            'evidence' => $evidence,
            'privacy' => [
                'sensitivity' => 'sensitive',
                'domain' => 'health',
            ],
        ]);
    }
}
