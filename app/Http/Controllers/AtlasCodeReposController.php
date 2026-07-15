<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\AtlasCode\AtlasCodeWorkspaceScanner;
use Illuminate\Http\JsonResponse;

/**
 * Read-only M3 radar: o workspace real do operador — RECENTES no topo,
 * PASTAS de produto embaixo. Pasta não é repositório quebrado.
 */
final class AtlasCodeReposController extends Controller
{
    public function __construct(private readonly AtlasCodeWorkspaceScanner $scanner) {}

    public function __invoke(): JsonResponse
    {
        return response()->json($this->scanner->capture());
    }
}
