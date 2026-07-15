<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\AtlasCode\AtlasCodeGraphService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

/** Read-only C22 topology endpoint consumed by Atlas Código mobile. */
final class AtlasCodeGraphController extends Controller
{
    public function __construct(private readonly AtlasCodeGraphService $graphs) {}

    public function __invoke(Request $request): JsonResponse
    {
        $input = $request->validate([
            'repo' => ['required', 'string', 'max:120'],
            'before' => ['sometimes', 'nullable', 'string', 'max:128'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:500'],
        ]);

        try {
            return response()->json($this->graphs->capture(
                (string) $input['repo'],
                isset($input['before']) ? (string) $input['before'] : null,
                isset($input['limit']) ? (int) $input['limit'] : null,
            ));
        } catch (InvalidArgumentException $exception) {
            $status = $exception->getMessage() === 'repository_profile_not_found' ? 404 : 422;

            return response()->json([
                'error' => $exception->getMessage(),
                'repo' => $input['repo'],
            ], $status);
        }
    }
}
