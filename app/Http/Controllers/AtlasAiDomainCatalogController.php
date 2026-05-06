<?php

namespace App\Http\Controllers;

use App\Services\Ai\Kernel\Domain\AtlasAiDomainCatalogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AtlasAiDomainCatalogController extends Controller
{
    public function __invoke(Request $request, AtlasAiDomainCatalogService $catalog): JsonResponse
    {
        $filters = $request->validate([
            'domain' => ['nullable', 'string', 'max:120'],
            'flow' => ['nullable', 'string', 'max:160'],
            'maturity' => ['nullable', 'string', Rule::in(['implemented', 'scaffold', 'planned'])],
            'onboarding_status' => ['nullable', 'string', Rule::in(['ready', 'executable_incomplete', 'scaffold'])],
        ]);

        return response()->json($catalog->inspect($filters));
    }
}
