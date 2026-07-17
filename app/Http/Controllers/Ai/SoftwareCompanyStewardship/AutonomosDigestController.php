<?php

declare(strict_types=1);

namespace App\Http\Controllers\Ai\SoftwareCompanyStewardship;

use App\Http\Controllers\Controller;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AutonomosDigestService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class AutonomosDigestController extends Controller
{
    public function __invoke(Request $request, AutonomosDigestService $digest): JsonResponse
    {
        return response()->json($digest->digest([
            'hours' => $request->query('hours'),
            'limit' => $request->query('limit'),
            'area' => $request->query('area'),
            'focus' => $request->query('focus'),
        ]), 200, ['Cache-Control' => 'private, max-age=60']);
    }
}
