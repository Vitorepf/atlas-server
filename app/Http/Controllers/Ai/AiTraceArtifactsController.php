<?php

declare(strict_types=1);

namespace App\Http\Controllers\Ai;

use App\Models\AiTrace;
use App\Services\Ai\Instrumentation\AiTraceArtifactsProjection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class AiTraceArtifactsController
{
    public function show(AiTrace $trace, AiTraceArtifactsProjection $projection): JsonResponse
    {
        return response()->json($projection->forTrace($trace));
    }

    public function content(
        Request $request,
        AiTrace $trace,
        string $artifactId,
        AiTraceArtifactsProjection $projection,
    ): Response|JsonResponse {
        $data = $request->validate([
            'max_bytes' => ['nullable', 'integer', 'min:1', 'max:10485760'],
        ]);
        $maxBytes = (int) ($data['max_bytes'] ?? 5_242_880);
        $artifact = $projection->contentForTrace($trace, $artifactId, $maxBytes);

        abort_unless($artifact !== null, 404);

        if ($artifact['state'] === 'too_large') {
            return response()->json([
                'error' => 'too_large',
                'byte_size' => $artifact['byte_size'],
            ], 413);
        }

        return response($artifact['data'], 200)
            ->header('Content-Type', $artifact['content_type'])
            ->header('X-Atlas-Sha256', $artifact['sha256']);
    }
}
