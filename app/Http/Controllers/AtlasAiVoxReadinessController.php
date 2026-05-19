<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\Ai\Vox\Readiness\VoxReadinessService;
use Illuminate\Http\JsonResponse;

/**
 * Atlas Vox readiness surface · Wave 7.8 (Claude V).
 *
 * One endpoint, one purpose: answer "is the Vox backend ready for real
 * use?" with a deterministic, honest aggregate of 16 checks.
 *
 * Kept in its own controller so neither AtlasAiVoxController (V0–V3
 * runtime) nor AtlasAiVoxMetricsController (metrics/gate/cert) inflates
 * further. The HTTP-level contract here is a single `GET` with no body —
 * the heavy lifting is in `VoxReadinessService`.
 */
final class AtlasAiVoxReadinessController extends Controller
{
    public function __construct(
        private readonly VoxReadinessService $readiness,
    ) {}

    public function readiness(): JsonResponse
    {
        return response()->json($this->readiness->probe());
    }
}
