<?php

namespace App\Http\Controllers;

use App\Models\AtlasEngineeringEvidence;
use App\Models\AtlasEngineeringRun;
use App\Models\AtlasProject;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Atlas Code · evidence aggregator per obra.
 *
 * Wraps the existing engineering evidence + run streams into one obra-scoped
 * timeline so the desktop bridge can list "everything that happened on this
 * obra" without pulling 3 separate endpoints.
 *
 *   GET /api/atlas-code/works/{project}/evidence?limit=50
 *
 * Response shape mirrors @atlas/domain · EvidenceDto[].
 */
class AtlasCodeEvidenceController extends Controller
{
    public function indexForWork(Request $request, AtlasProject $project): JsonResponse
    {
        $data = $request->validate([
            'limit' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);
        $limit = (int) ($data['limit'] ?? 50);

        $runs = AtlasEngineeringRun::query()
            ->where('project_id', $project->getKey())
            ->orderByDesc('updated_at')
            ->limit($limit)
            ->get(['id', 'status', 'decision', 'started_at', 'finished_at', 'updated_at']);

        $evidences = AtlasEngineeringEvidence::query()
            ->where('project_id', $project->getKey())
            ->orderByDesc('recorded_at')
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get();

        $items = collect();

        foreach ($runs as $run) {
            $items->push([
                'id' => 'run:' . $run->id,
                'obraId' => (string) $project->getKey(),
                'kind' => 'engineering_run',
                'summary' => sprintf(
                    'engineering run %s · %s',
                    substr((string) $run->id, 0, 8),
                    (string) ($run->status ?? 'unknown')
                ),
                'createdAt' => ($run->finished_at ?? $run->updated_at)?->toJSON(),
            ]);
        }

        foreach ($evidences as $ev) {
            $items->push([
                'id' => 'evidence:' . $ev->id,
                'obraId' => (string) $project->getKey(),
                'kind' => (string) ($ev->evidence_type ?? 'evidence'),
                'summary' => (string) ($ev->summary ?? $ev->output_excerpt ?? 'evidence ' . $ev->id),
                'createdAt' => ($ev->recorded_at ?? $ev->created_at)?->toJSON(),
            ]);
        }

        $sorted = $items
            ->sortByDesc(fn (array $item): string => (string) ($item['createdAt'] ?? ''))
            ->take($limit)
            ->values();

        return response()->json([
            'data' => $sorted->all(),
            'meta' => [
                'total' => $sorted->count(),
                'runs' => $runs->count(),
                'evidence' => $evidences->count(),
            ],
        ]);
    }
}
