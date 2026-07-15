<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\AtlasCode\AtlasCodeProvenanceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

/** Read-only C23 commit identity/provenance endpoint. */
final class AtlasCodeProvenanceController extends Controller
{
    public function __construct(private readonly AtlasCodeProvenanceService $provenance) {}

    public function __invoke(Request $request, string $hash): JsonResponse
    {
        $input = $request->validate([
            'repo' => ['sometimes', 'nullable', 'string', 'max:120'],
        ]);

        try {
            return response()->json($this->provenance->capture(
                $hash,
                isset($input['repo']) && is_string($input['repo']) ? $input['repo'] : null,
            ));
        } catch (InvalidArgumentException $exception) {
            $status = in_array($exception->getMessage(), ['repository_profile_not_found', 'commit_not_found'], true)
                ? 404
                : 422;

            return response()->json([
                'error' => $exception->getMessage(),
                'hash' => $hash,
            ], $status);
        }
    }
}
