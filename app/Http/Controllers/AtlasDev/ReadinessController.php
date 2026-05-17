<?php

declare(strict_types=1);

namespace App\Http\Controllers\AtlasDev;

use App\Http\Controllers\Controller;
use App\Services\Ai\Programming\AtlasDev\Runtime\AtlasDevReadinessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ReadinessController extends Controller
{
    public function __construct(private readonly AtlasDevReadinessService $readiness) {}

    public function __invoke(Request $request): JsonResponse
    {
        $strict = filter_var($request->query('strict', false), FILTER_VALIDATE_BOOL);

        return response()->json([
            'data' => $this->readiness->inspect(strict: $strict, providerSafe: true),
        ]);
    }
}
