<?php

namespace App\Http\Controllers;

use App\Services\Ai\Kernel\Architecture\AtlasProviderReleaseIntelligenceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AtlasAiProviderReleaseReviewController extends Controller
{
    public function __invoke(Request $request, AtlasProviderReleaseIntelligenceService $intelligence): JsonResponse
    {
        $data = $request->validate([
            'provider' => ['nullable', 'string', 'max:120'],
            'title' => ['required', 'string', 'max:220'],
            'url' => ['nullable', 'string', 'max:500'],
            'type' => ['nullable', 'string', 'max:120'],
            'domain' => ['nullable', 'array'],
            'domain.*' => ['string', 'max:120'],
            'capability' => ['nullable', 'array'],
            'capability.*' => ['string', 'max:160'],
            'connector' => ['nullable', 'array'],
            'connector.*' => ['string', 'max:160'],
        ]);

        $payload = $intelligence->review([
            'provider' => $data['provider'] ?? null,
            'title' => $data['title'],
            'url' => $data['url'] ?? null,
            'type' => $data['type'] ?? null,
            'domains' => $data['domain'] ?? [],
            'capabilities' => $data['capability'] ?? [],
            'connectors' => $data['connector'] ?? [],
        ]);

        return response()->json($payload);
    }
}
