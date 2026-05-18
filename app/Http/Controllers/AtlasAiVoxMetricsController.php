<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\Ai\Vox\Gate\VoxV3PromotionGateService;
use App\Services\Ai\Vox\Metrics\VoxMetricsService;
use App\Services\Ai\Vox\Rivals\VoxRivalsRunner;
use App\Services\Ai\Vox\VoxSchema;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Atlas Vox Wave 7 (Claude O) — read-only metrics + rivals + gate
 * surfaces. Kept separate from `AtlasAiVoxController` so the V0–V3
 * push-to-talk surface stays focused; this controller never executes
 * anything, never calls a provider, never persists audio.
 *
 * Endpoints (all under `atlas.token`):
 *   - GET  /ai/vox/metrics        — snapshot of usage / safety / quality
 *   - POST /ai/vox/rivals/case    — record one human-evaluated rivals case
 *   - GET  /ai/vox/rivals/report  — aggregated rivals report
 *   - GET  /ai/vox/gate-v3        — V3 promotion-gate evaluation
 */
final class AtlasAiVoxMetricsController extends Controller
{
    public function __construct(
        private readonly VoxMetricsService $metrics,
        private readonly VoxRivalsRunner $rivals,
        private readonly VoxV3PromotionGateService $gate,
    ) {}

    public function metrics(): JsonResponse
    {
        return response()->json($this->metrics->snapshot());
    }

    public function rivalsReport(): JsonResponse
    {
        return response()->json([
            'schema' => 'atlas.vox.rivals_report.v1',
            'report' => $this->rivals->report(),
            'generated_at' => now('UTC')->toIso8601String(),
        ]);
    }

    public function recordRivalsCase(Request $request): JsonResponse
    {
        $this->rejectAudioFields($request);

        $payload = $request->validate([
            'kind' => ['required', 'string', 'in:'.implode(',', VoxRivalsRunner::allowedKinds())],
            'mode' => ['required', 'string', 'in:'.implode(',', VoxRivalsRunner::allowedModes())],
            'vox_session_id' => ['nullable', 'string', 'max:120'],
            'vox_intent_id' => ['nullable', 'string', 'max:120'],
            'baseline_label' => ['required', 'string', 'max:240'],
            'baseline_duration_ms' => ['nullable', 'integer', 'min:0'],
            'vox_duration_ms' => ['nullable', 'integer', 'min:0'],
            'baseline_score' => ['nullable', 'integer', 'between:1,5'],
            'vox_score' => ['nullable', 'integer', 'between:1,5'],
            'preference' => ['required', 'string', 'in:'.implode(',', VoxRivalsRunner::allowedPreferences())],
            'prompt_quality_vote' => ['nullable', 'integer', 'in:-1,0,1'],
            'regret_flag' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string', 'max:8000'],
            'metadata' => ['nullable', 'array'],
        ]);

        try {
            $result = $this->rivals->record($payload);
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['rivals' => $e->getMessage()]);
        }

        /** @var \App\Models\AtlasVoxRivalsCase $case */
        $case = $result['case'];

        return response()->json([
            'schema' => 'atlas.vox.rivals_case_recorded.v1',
            'case' => [
                'case_id' => $case->case_id,
                'kind' => $case->kind,
                'mode' => $case->mode,
                'vox_session_id' => $case->vox_session_id,
                'vox_intent_id' => $case->vox_intent_id,
                'baseline_label' => $case->baseline_label,
                'baseline_duration_ms' => $case->baseline_duration_ms,
                'vox_duration_ms' => $case->vox_duration_ms,
                'baseline_score' => $case->baseline_score,
                'vox_score' => $case->vox_score,
                'preference' => $case->preference,
                'prompt_quality_vote' => $case->prompt_quality_vote,
                'regret_flag' => $case->regret_flag,
                'notes' => $case->notes,
                'metadata' => $case->metadata,
                'created_at' => $case->created_at?->toIso8601String(),
            ],
            'events' => [$result['event']],
        ], 201);
    }

    public function gateV3(): JsonResponse
    {
        return response()->json($this->gate->evaluate());
    }

    /**
     * Reuse Lei-0.75 rule: raw audio fields are 422-rejected even on
     * metrics endpoints. There's no legitimate reason for the rivals
     * payload to ever contain audio bytes.
     */
    private function rejectAudioFields(Request $request): void
    {
        $forbidden = VoxSchema::prohibitedAudioFields();
        $all = $request->all();
        foreach ($forbidden as $field) {
            if (array_key_exists($field, $all)) {
                throw ValidationException::withMessages([
                    $field => "Field '{$field}' is forbidden — raw audio must never reach the Kernel (Lei 0.75)",
                ]);
            }
        }
    }
}
