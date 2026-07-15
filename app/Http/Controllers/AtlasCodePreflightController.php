<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\AtlasCode\AtlasCodePreflightService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

/** E5 preflight; rejected actions are recorded, notifications stay opt-in. */
final class AtlasCodePreflightController extends Controller
{
    public function __construct(private readonly AtlasCodePreflightService $preflight) {}

    public function __invoke(Request $request): JsonResponse
    {
        $input = $request->validate([
            'repo' => ['required', 'string', 'max:120'],
            'action' => ['required', 'string', 'max:120'],
            'target' => ['required', 'string', 'max:1000'],
        ]);
        try {
            return response()->json($this->preflight->guard(
                (string) $input['repo'],
                (string) $input['action'],
                (string) $input['target'],
            ));
        } catch (InvalidArgumentException $exception) {
            return response()->json(['error' => $exception->getMessage()], 422);
        }
    }
}
