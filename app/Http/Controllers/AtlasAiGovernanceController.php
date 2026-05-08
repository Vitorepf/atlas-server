<?php

namespace App\Http\Controllers;

use App\Services\Ai\Kernel\Architecture\AtlasArchitectureReadinessService;
use App\Services\Ai\Kernel\Architecture\AtlasDocumentationSplitPlanService;
use App\Services\Ai\Kernel\Architecture\AtlasFeaturePlacementService;
use App\Services\Ai\Kernel\Architecture\AtlasGovernanceGateService;
use App\Services\Ai\Kernel\Architecture\AtlasSessionBootstrapService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AtlasAiGovernanceController extends Controller
{
    public function sessionBootstrap(
        Request $request,
        AtlasSessionBootstrapService $bootstrap,
        AtlasGovernanceGateService $gate,
    ): JsonResponse {
        $data = $request->validate([
            'task' => ['nullable', 'string', 'max:500'],
            'workspace' => ['nullable', 'string', 'max:500'],
            'strict' => ['nullable', 'boolean'],
        ]);

        $payload = $bootstrap->bootstrap((string) ($data['task'] ?? ''), [
            'workspace' => $data['workspace'] ?? base_path(),
        ]);

        return response()->json($payload, $gate->httpStatus($payload, (bool) ($data['strict'] ?? false)));
    }

    public function placeFeature(
        Request $request,
        AtlasFeaturePlacementService $placement,
        AtlasGovernanceGateService $gate,
    ): JsonResponse {
        $data = $request->validate([
            'feature' => ['required', 'string', 'max:500'],
            'hint' => ['nullable', 'array'],
            'hint.*' => ['string', 'max:220'],
            'strict' => ['nullable', 'boolean'],
        ]);

        $payload = $placement->place($data['feature'], $this->hints((array) ($data['hint'] ?? [])));

        return response()->json($payload, $gate->httpStatus($payload, (bool) ($data['strict'] ?? false)));
    }

    public function docsSplitPlan(Request $request, AtlasDocumentationSplitPlanService $splitPlan): JsonResponse
    {
        $data = $request->validate([
            'owner' => ['nullable', 'string', 'max:120'],
            'owner_area' => ['nullable', 'string', 'max:120'],
            'severity' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', 'string', 'max:120'],
        ]);

        return response()->json($splitPlan->plan($data));
    }

    public function architectureReadiness(Request $request, AtlasArchitectureReadinessService $readiness): JsonResponse
    {
        $data = $request->validate([
            'workspace' => ['nullable', 'string', 'max:500'],
            'owner' => ['nullable', 'string', 'max:120'],
        ]);

        return response()->json($readiness->snapshot($data));
    }

    /**
     * @param  array<int|string,string>  $rawHints
     * @return array<string,string>
     */
    private function hints(array $rawHints): array
    {
        $hints = [];
        foreach ($rawHints as $key => $value) {
            if (is_string($key) && $key !== '') {
                $hints[$key] = trim((string) $value);

                continue;
            }

            if (! is_string($value) || ! str_contains($value, '=')) {
                continue;
            }

            [$hintKey, $hintValue] = explode('=', $value, 2);
            $hintKey = trim($hintKey);
            if ($hintKey !== '') {
                $hints[$hintKey] = trim($hintValue);
            }
        }

        return $hints;
    }
}
