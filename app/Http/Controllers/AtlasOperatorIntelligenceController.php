<?php

namespace App\Http\Controllers;

use App\Models\OperatorLearningCandidate;
use App\Models\OperatorProfileItem;
use App\Services\Ai\OperatorIntelligence\OperatorContextComposer;
use App\Services\Ai\OperatorIntelligence\OperatorLearningCandidateService;
use App\Services\Ai\OperatorIntelligence\OperatorProfileDigestService;
use App\Services\Ai\OperatorIntelligence\OperatorProfileProjectionService;
use App\Services\Ai\OperatorIntelligence\OperatorProfileRegistry;
use App\Services\Ai\OperatorIntelligence\OperatorSignalCaptureService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AtlasOperatorIntelligenceController extends Controller
{
    public function capture(Request $request, OperatorSignalCaptureService $capture): JsonResponse
    {
        return response()->json($capture->capture($this->captureInput($request)));
    }

    public function reviewQueue(Request $request, OperatorLearningCandidateService $candidates): JsonResponse
    {
        $operatorId = $this->operatorId($request);

        return response()->json([
            'ok' => true,
            'operator_id' => $operatorId,
            'items' => $candidates->listPending($operatorId, (int) $request->integer('limit', 50))
                ->map(fn (OperatorLearningCandidate $candidate): array => $candidates->payload($candidate))
                ->all(),
        ]);
    }

    public function review(Request $request, OperatorLearningCandidateService $candidates, string $candidate): JsonResponse
    {
        $decision = (string) $request->input('decision', 'approve');
        $operator = $this->operatorId($request);
        $notes = is_string($request->input('notes')) ? (string) $request->input('notes') : null;

        return response()->json($candidates->review($candidate, $decision, $operator, $notes));
    }

    public function profile(Request $request, OperatorProfileRegistry $registry): JsonResponse
    {
        $operatorId = $this->operatorId($request);

        return response()->json([
            'ok' => true,
            'operator_id' => $operatorId,
            'items' => array_map(
                fn (OperatorProfileItem $item): array => $registry->payload($item),
                $registry->activeForOperator($operatorId, ['limit' => (int) $request->integer('limit', 50)]),
            ),
        ]);
    }

    public function context(Request $request, OperatorContextComposer $composer): JsonResponse
    {
        return response()->json($composer->compose([
            'operator_id' => $this->operatorId($request),
            'flow' => is_string($request->input('flow')) ? (string) $request->input('flow') : null,
            'provider_external' => (bool) $request->boolean('provider_external', false),
            'trace_id' => is_string($request->input('trace_id')) ? (string) $request->input('trace_id') : null,
            'session_id' => is_string($request->input('session_id')) ? (string) $request->input('session_id') : null,
            'limit' => (int) $request->integer('limit', (int) config('atlas_operator_intelligence.max_injected_profile_items', 8)),
            'record_usage' => (bool) $request->boolean('record_usage', true),
        ]));
    }

    public function digest(Request $request, OperatorProfileDigestService $digest): JsonResponse
    {
        return response()->json($digest->digest(
            $this->operatorId($request),
            (int) $request->integer('days', (int) config('atlas_operator_intelligence.digest_recent_days', 7)),
        ));
    }

    public function project(Request $request, OperatorProfileProjectionService $projection): JsonResponse
    {
        return response()->json($projection->project($this->operatorId($request)));
    }

    /**
     * @return array<string,mixed>
     */
    private function captureInput(Request $request): array
    {
        return [
            'operator_id' => $this->operatorId($request),
            'claim' => $request->input('claim'),
            'raw_excerpt' => $request->input('raw_excerpt'),
            'taxonomy_item_id' => $request->input('taxonomy_item_id', $request->input('taxonomy')),
            'source_type' => $request->input('source_type', 'api'),
            'source_ref_type' => $request->input('source_ref_type'),
            'source_ref_id' => $request->input('source_ref_id'),
            'trace_id' => $request->input('trace_id'),
            'session_id' => $request->input('session_id'),
            'privacy_class' => $request->input('privacy_class', $request->input('privacy', 'normal')),
            'risk_level' => $request->input('risk_level', $request->input('risk', 'low')),
            'confidence' => $request->input('confidence', 0.5),
            'scope_type' => $request->input('scope_type', 'global'),
            'scope_id' => $request->input('scope_id'),
            'value' => is_array($request->input('value')) ? $request->input('value') : [],
            'dry_run' => (bool) $request->boolean('dry_run', false),
        ];
    }

    private function operatorId(Request $request): string
    {
        $operator = $request->input('operator_id', $request->input('operator'));

        return is_string($operator) && trim($operator) !== ''
            ? trim($operator)
            : (string) config('atlas_operator_intelligence.default_operator_id', 'default');
    }
}
