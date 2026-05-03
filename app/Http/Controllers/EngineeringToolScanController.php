<?php

namespace App\Http\Controllers;

use App\Services\Engineering\EngineeringApiContractService;
use App\Services\Engineering\EngineeringQualityScanService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class EngineeringToolScanController extends Controller
{
    public function apiContract(Request $request, EngineeringApiContractService $contracts): JsonResponse
    {
        $data = $request->validate([
            'workspace' => ['required', 'string', 'max:1000'],
            'spec' => ['nullable', 'string', 'max:1000'],
            'strict' => ['nullable', 'boolean'],
            'run_context_type' => ['nullable', 'string', 'max:120'],
            'run_context_id' => ['nullable', 'string', 'max:180'],
        ]);

        return response()->json($contracts->validate($data['workspace'], [
            'spec' => $data['spec'] ?? null,
            'strict' => (bool) ($data['strict'] ?? false),
            'run_context_type' => $data['run_context_type'] ?? null,
            'run_context_id' => $data['run_context_id'] ?? null,
        ]));
    }

    public function securityScan(Request $request, EngineeringQualityScanService $qualityScan): JsonResponse
    {
        $data = $this->validatedScanPayload($request, ['standard', 'release', 'deep'], 'release');

        return response()->json($qualityScan->scan($data['workspace'], [
            'profile' => $data['profile'],
            'changed_only' => $data['changed_only'] ?? false,
            'timeout' => $data['timeout'] ?? 300,
            'run_context_type' => $data['run_context_type'] ?? null,
            'run_context_id' => $data['run_context_id'] ?? null,
            'include_tools' => ['gitleaks', 'semgrep', 'osv_scanner', 'trivy', 'grype'],
        ]));
    }

    public function sbom(Request $request, EngineeringQualityScanService $qualityScan): JsonResponse
    {
        $data = $this->validatedScanPayload($request, ['release', 'deep'], 'release');

        return response()->json($qualityScan->scan($data['workspace'], [
            'profile' => $data['profile'],
            'timeout' => $data['timeout'] ?? 300,
            'run_context_type' => $data['run_context_type'] ?? null,
            'run_context_id' => $data['run_context_id'] ?? null,
            'include_tools' => ['syft'],
        ]));
    }

    /**
     * @param  array<int,string>  $profiles
     * @return array<string,mixed>
     */
    private function validatedScanPayload(Request $request, array $profiles, string $defaultProfile): array
    {
        $data = $request->validate([
            'workspace' => ['required', 'string', 'max:1000'],
            'profile' => ['nullable', Rule::in($profiles)],
            'changed_only' => ['nullable', 'boolean'],
            'timeout' => ['nullable', 'integer', 'min:10', 'max:3600'],
            'run_context_type' => ['nullable', 'string', 'max:120'],
            'run_context_id' => ['nullable', 'string', 'max:180'],
        ]);

        $data['profile'] = $data['profile'] ?? $defaultProfile;

        return $data;
    }
}
