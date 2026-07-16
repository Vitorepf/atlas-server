<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\AtlasCode\AtlasCodeWhyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

/** H1 · file biography endpoint for Atlas Código mobile. */
final class AtlasCodeWhyController extends Controller
{
    public function __construct(private readonly AtlasCodeWhyService $why) {}

    public function __invoke(Request $request): JsonResponse
    {
        $input = $request->validate([
            'repo' => ['required', 'string', 'max:120'],
            'file' => ['required', 'string', 'max:500'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'line' => ['sometimes', 'integer', 'min:1'],
        ]);

        try {
            return response()->json($this->why->capture(
                (string) $input['repo'],
                (string) $input['file'],
                isset($input['limit']) ? (int) $input['limit'] : null,
                isset($input['line']) ? (int) $input['line'] : null,
            ));
        } catch (InvalidArgumentException $exception) {
            return match ($exception->getMessage()) {
                'repository_profile_not_found', 'repository_path_missing_or_unreadable' => response()->json([], 404),
                'invalid_file' => response()->json(['error' => 'invalid_file'], 422),
                default => response()->json(['error' => $exception->getMessage()], 503),
            };
        }
    }
}
