<?php

namespace App\Http\Controllers;

use App\Services\Ai\Kernel\Architecture\AtlasProviderReleaseSourceRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AtlasAiProviderReleaseSourcesController extends Controller
{
    public function __invoke(Request $request, AtlasProviderReleaseSourceRegistry $registry): JsonResponse
    {
        $data = $request->validate([
            'provider' => ['nullable', 'string', 'max:120'],
            'tier' => ['nullable', 'string', 'max:120'],
            'cadence' => ['nullable', 'string', 'max:120'],
            'track' => ['nullable', 'string', 'max:120'],
            'url' => ['nullable', 'string', 'max:500'],
            'title' => ['nullable', 'string', 'max:220'],
            'published_at' => ['nullable', 'string', 'max:120'],
            'content_hash' => ['nullable', 'string', 'max:160'],
        ]);

        $summary = $registry->summary([
            'provider' => $data['provider'] ?? null,
            'tier' => $data['tier'] ?? null,
            'cadence' => $data['cadence'] ?? null,
            'track' => $data['track'] ?? null,
        ]);

        if (! isset($data['url'])) {
            return response()->json($summary);
        }

        return response()->json(array_merge($summary, [
            'mode' => 'read_only_candidate_preview',
            'candidate' => $registry->candidateFromDetection(
                url: $data['url'],
                title: $data['title'] ?? 'untitled-provider-release-candidate',
                contentHash: $data['content_hash'] ?? null,
                publishedAt: $data['published_at'] ?? null,
            ),
        ]));
    }
}
