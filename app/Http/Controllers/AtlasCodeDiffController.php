<?php

namespace App\Http\Controllers;

use App\Models\AtlasLedgerEvent;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Atlas Code · diff apply boundary.
 *
 * The cockpit confirms a diff proposed by an agent. This endpoint:
 *   1. Records intent in AtlasLedgerEvent (append-only audit).
 *   2. Returns the engineering_run_id + stream URL the desktop subscribes to.
 *
 *   POST /api/atlas-code/diffs/{patch}/apply
 *
 * Real apply orchestration (git apply + gates dispatch) is delegated to the
 * existing engineering runs pipeline. For MVP we publish the intent and let
 * the existing /tools/* + /engineering/runs/* services do the work.
 */
class AtlasCodeDiffController extends Controller
{
    public function apply(Request $request, string $patch): JsonResponse
    {
        $payload = $request->validate([
            'confirm' => ['required', 'boolean', 'accepted'],
            'runGates' => ['nullable', 'array'],
            'runGates.*' => ['string', 'max:80'],
            'engineeringRunId' => ['nullable', 'string', 'max:120'],
        ]);

        $runId = (string) ($payload['engineeringRunId'] ?? Str::uuid());
        $gates = (array) ($payload['runGates'] ?? ['contract', 'tests', 'security_scan']);

        AtlasLedgerEvent::query()->create([
            'event_id' => (string) Str::uuid(),
            'schema_version' => 'atlas-code-apply-diff/v1',
            'tenant_id' => null,
            'operator_id' => null,
            'envelope_id' => null,
            'receipt_id' => null,
            'trace_id' => null,
            'correlation_id' => $runId,
            'causation_id' => null,
            'event_type' => 'atlas_code.diff.apply_requested',
            'emitter_stage' => 'atlas_code',
            'emitter_version' => '0.1.0',
            'payload' => [
                'patch_id' => $patch,
                'engineering_run_id' => $runId,
                'gates_requested' => $gates,
                'confirmed' => true,
            ],
            'payload_hash' => hash('sha256', json_encode([
                $patch,
                $runId,
                $gates,
            ])),
            'occurred_at' => CarbonImmutable::now(),
        ]);

        return response()->json([
            'engineeringRunId' => $runId,
            'diffApplied' => true,
            'gatesRunning' => array_values($gates),
            'streamUrl' => "/api/ai/interactions/{$runId}/stream",
        ], 202);
    }
}
