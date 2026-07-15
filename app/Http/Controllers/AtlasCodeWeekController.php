<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\AtlasCode\AtlasCodeWeekService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

/** E5 weekly card projection. */
final class AtlasCodeWeekController extends Controller
{
    public function __construct(private readonly AtlasCodeWeekService $week) {}

    public function __invoke(Request $request): JsonResponse
    {
        $input = $request->validate(['repo' => ['sometimes', 'string', 'max:120']]);
        try {
            return response()->json($this->week->capture((string) ($input['repo'] ?? 'atlas-server')));
        } catch (InvalidArgumentException $exception) {
            return response()->json(['error' => $exception->getMessage()], 422);
        }
    }
}
