<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\AtlasCode\AtlasCodeReposService;
use Illuminate\Http\JsonResponse;

/** Read-only M3 radar endpoint: a frota de repositórios, por exceção. */
final class AtlasCodeReposController extends Controller
{
    public function __construct(private readonly AtlasCodeReposService $repos) {}

    public function __invoke(): JsonResponse
    {
        return response()->json($this->repos->capture());
    }
}
