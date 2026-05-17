<?php

namespace App\Http\Controllers;

use App\Services\Ai\Router\AtlasAiHyperflowRivalsBatteryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AtlasAiHyperflowRivalsBatteryController extends Controller
{
    public function show(AtlasAiHyperflowRivalsBatteryService $battery): JsonResponse
    {
        $payload = $battery->status();

        return response()->json($payload, ($payload['ready'] ?? false) === true ? 200 : 503);
    }

    public function externalEvidenceTemplate(AtlasAiHyperflowRivalsBatteryService $battery): JsonResponse
    {
        return response()->json($battery->externalEvidencePackTemplate());
    }

    public function externalEvidencePreflight(Request $request, AtlasAiHyperflowRivalsBatteryService $battery): JsonResponse
    {
        $payload = $request->validate([
            'forge_run_id' => ['nullable', 'string', 'max:128'],
            'forge_run_ids' => ['nullable'],
        ]);
        $result = $battery->externalEvidencePreflight($payload);

        return response()->json($result, ($result['status'] ?? null) === 'ready' ? 200 : 503);
    }

    public function externalEvidenceCandidates(Request $request, AtlasAiHyperflowRivalsBatteryService $battery): JsonResponse
    {
        $payload = $request->validate([
            'provider' => ['nullable', 'string', 'max:80'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:250'],
            'only_ready' => ['nullable', 'boolean'],
        ]);

        return response()->json($battery->externalEvidenceCandidates($payload));
    }

    public function externalEvidenceRunbook(Request $request, AtlasAiHyperflowRivalsBatteryService $battery): JsonResponse
    {
        $payload = $request->validate([
            'limit' => ['nullable', 'integer', 'min:1', 'max:250'],
            'approved_by' => ['nullable', 'string', 'max:120'],
        ]);

        return response()->json($battery->externalEvidenceRunbook($payload));
    }

    public function prepare(Request $request, AtlasAiHyperflowRivalsBatteryService $battery): JsonResponse
    {
        $request->validate([
            'confirm_prepare' => ['accepted'],
        ]);

        $payload = $battery->prepare();

        return response()->json($payload, ($payload['status'] ?? null) === 'blocked' ? 503 : 201);
    }

    public function run(Request $request, AtlasAiHyperflowRivalsBatteryService $battery): JsonResponse
    {
        $payload = $request->validate([
            'confirm_run' => ['accepted'],
            'triggered_by' => ['nullable', 'string', 'max:120'],
        ]);

        $result = $battery->runAndPersist($payload);

        return response()->json($result, ($result['writes'] ?? false) === true ? 201 : 503);
    }

    public function externalEvidence(Request $request, AtlasAiHyperflowRivalsBatteryService $battery): JsonResponse
    {
        $payload = $request->validate([
            'confirm_external_evidence' => ['accepted'],
            'external_provider_call' => ['accepted'],
            'approved_by' => ['required', 'string', 'max:120'],
            'provider_tokens_spent' => ['nullable', 'integer', 'min:0'],
            'protocol_valid' => ['accepted'],
            'comparable' => ['accepted'],
            'operator_approved' => ['accepted'],
            'evidence_receipt_hash' => ['required', 'regex:/^[a-f0-9]{64}$/i'],
            'provider_results' => ['required', 'array'],
            'provider_results.claude_code' => ['required', 'array'],
            'provider_results.claude_code.status' => ['required', Rule::in(['passed'])],
            'provider_results.claude_code.score' => ['required', 'integer', 'min:0', 'max:100'],
            'provider_results.claude_code.duration_ms' => ['nullable', 'integer', 'min:0'],
            'provider_results.claude_code.evidence_hash' => ['required', 'regex:/^[a-f0-9]{64}$/i'],
            'provider_results.claude_code.receipt_ref' => ['nullable', 'string', 'max:180'],
            'provider_results.codex' => ['required_without:provider_results.codex_cli', 'array'],
            'provider_results.codex.status' => ['required_with:provider_results.codex', Rule::in(['passed'])],
            'provider_results.codex.score' => ['required_with:provider_results.codex', 'integer', 'min:0', 'max:100'],
            'provider_results.codex.duration_ms' => ['nullable', 'integer', 'min:0'],
            'provider_results.codex.evidence_hash' => ['required_with:provider_results.codex', 'regex:/^[a-f0-9]{64}$/i'],
            'provider_results.codex.receipt_ref' => ['nullable', 'string', 'max:180'],
            'provider_results.codex_cli' => ['required_without:provider_results.codex', 'array'],
            'provider_results.codex_cli.status' => ['required_with:provider_results.codex_cli', Rule::in(['passed'])],
            'provider_results.codex_cli.score' => ['required_with:provider_results.codex_cli', 'integer', 'min:0', 'max:100'],
            'provider_results.codex_cli.duration_ms' => ['nullable', 'integer', 'min:0'],
            'provider_results.codex_cli.evidence_hash' => ['required_with:provider_results.codex_cli', 'regex:/^[a-f0-9]{64}$/i'],
            'provider_results.codex_cli.receipt_ref' => ['nullable', 'string', 'max:180'],
        ]);

        $result = $battery->recordExternalEvidence($payload);

        return response()->json($result, ($result['writes'] ?? false) === true ? 201 : 503);
    }

    public function importExternalEvidence(Request $request, AtlasAiHyperflowRivalsBatteryService $battery): JsonResponse
    {
        $payload = $request->validate([
            'confirm_external_evidence_import' => ['accepted'],
            'operator_approved' => ['accepted'],
            'approved_by' => ['required', 'string', 'max:120'],
            'evidence_pack_path' => ['required', 'string', 'max:500'],
        ]);

        $result = $battery->importExternalEvidencePack($payload);

        return response()->json($result, ($result['writes'] ?? false) === true ? 201 : 422);
    }

    public function exportExternalEvidence(Request $request, AtlasAiHyperflowRivalsBatteryService $battery): JsonResponse
    {
        $payload = $request->validate([
            'confirm_external_evidence_export' => ['accepted'],
            'operator_approved' => ['accepted'],
            'approved_by' => ['required', 'string', 'max:120'],
            'forge_run_id' => ['nullable', 'string', 'max:128'],
            'forge_run_ids' => ['nullable'],
            'output_path' => ['nullable', 'string', 'max:500'],
        ]);

        $result = $battery->exportExternalEvidencePack($payload);

        return response()->json($result, ($result['ready'] ?? false) === true ? 201 : 422);
    }
}
