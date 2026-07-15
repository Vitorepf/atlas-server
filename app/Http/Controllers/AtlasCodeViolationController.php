<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\AtlasCode\AtlasCodeViolationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

/** Read-only C24 rules-engine projection. */
final class AtlasCodeViolationController extends Controller
{
    public function __construct(private readonly AtlasCodeViolationService $violations) {}

    public function __invoke(Request $request): JsonResponse
    {
        $input = $request->validate(['repo' => ['required', 'string', 'max:120']]);
        try {
            return response()->json($this->violations->capture((string) $input['repo']));
        } catch (InvalidArgumentException $exception) {
            $status = $exception->getMessage() === 'repository_profile_not_found' ? 404 : 422;
            return response()->json(['error' => $exception->getMessage(), 'repo' => $input['repo']], $status);
        }
    }
}
