<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\Ai\Vox\Audit\VoxV3HardeningAuditService;
use App\Services\Ai\Vox\Gate\VoxV3CertificationPackService;
use App\Services\Ai\Vox\Gate\VoxV3PromotionGateService;
use App\Services\Ai\Vox\Metrics\VoxMetricsService;
use App\Services\Ai\Vox\VoxEvidenceService;
use App\Services\Ai\Vox\VoxSchema;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Atlas Vox Wave 7 (Claude O) — read-only metrics + gate
 * surfaces. Kept separate from `AtlasAiVoxController` so the V0–V3
 * push-to-talk surface stays focused; this controller never executes
 * anything, never calls a provider, never persists audio.
 *
 * Endpoints (all under `atlas.token`):
 *   - GET  /ai/vox/metrics        — snapshot of usage / safety / quality
 *   - GET  /ai/vox/gate-v3        — V3 promotion-gate evaluation
 */
final class AtlasAiVoxMetricsController extends Controller
{
    public function __construct(
        private readonly VoxMetricsService $metrics,
        private readonly VoxV3PromotionGateService $gate,
        private readonly VoxV3CertificationPackService $certPack,
        private readonly VoxEvidenceService $evidence,
        private readonly VoxV3HardeningAuditService $hardeningAudit,
    ) {}

    public function metrics(): JsonResponse
    {
        return response()->json($this->metrics->snapshot());
    }

    public function gateV3(): JsonResponse
    {
        return response()->json($this->gate->evaluate());
    }

    /**
     * GET /ai/vox/gate-v3/certification-pack
     *
     * Deterministic snapshot of V3 readiness for human review. The pack
     * carries a SHA-256 `certification_hash` over its canonical body
     * (everything except `generated_at` + the hash itself); Vitor's
     * review later submits that same hash so the verdict cannot land
     * against a moved target.
     *
     * Records `VOX_V3_CERTIFICATION_PACK_CREATED` for audit. `v4_unlock_allowed`
     * is hard-coded to `false` — this endpoint never promotes V4.
     */
    public function certificationPack(): JsonResponse
    {
        $pack = $this->certPack->build();
        $hardViolations = $this->countHardViolations($pack);

        $event = $this->evidence->v3CertificationPackCreated([
            'certification_hash' => (string) $pack['certification_hash'],
            'gate_status' => (string) $pack['gate_status'],
            'readiness_summary' => (string) $pack['readiness_summary'],
            'hard_gate_violations' => $hardViolations,
            'v4_unlock_allowed' => false,
        ]);

        return response()->json([
            ...$pack,
            'events' => [$event],
        ]);
    }

    /**
     * POST /ai/vox/gate-v3/review
     *
     * Records a human review verdict against a specific certification
     * hash. The hash submitted MUST match the pack that is canonical RIGHT
     * NOW — otherwise the review is rejected (422) and a
     * VOX_V3_CERTIFICATION_PACK_CREATED is NOT emitted, but a
     * VOX_ACTION_BLOCKED is, so the divergence is audit-visible.
     *
     * Decisions:
     *   - approved_for_v4_planning · Vitor declares the program ready for
     *     V4 planning. **Does NOT flip any feature flag.** A separate
     *     wave implements the actual unlock.
     *   - rejected · the operator says V3 is not certifiable.
     *   - needs_more_usage · usage / rivals signal is insufficient; keep
     *     rodando V3.
     *
     * Even on `approved_for_v4_planning`, the response contains
     * `v4_unlock_allowed=false` and `v4_unlocked_by_review=false`.
     */
    public function recordPromotionReview(Request $request): JsonResponse
    {
        $this->rejectAudioFields($request);

        $payload = $request->validate([
            'reviewed_by' => ['required', 'string', 'max:120'],
            'decision' => ['required', 'string', 'in:'.implode(',', VoxV3CertificationPackService::REVIEW_DECISIONS)],
            'certification_hash' => ['required', 'string', 'max:120'],
            'notes' => ['nullable', 'string', 'max:8000'],
        ]);

        $currentPack = $this->certPack->build();
        if (! hash_equals((string) $currentPack['certification_hash'], (string) $payload['certification_hash'])) {
            $blockedEvent = $this->evidence->actionBlocked(
                reasonCode: 'v3_review_hash_mismatch',
                message: 'Submitted certification_hash does not match the current pack — pack has moved since it was generated.',
                payload: [
                    'submitted_hash' => (string) $payload['certification_hash'],
                    'current_hash' => (string) $currentPack['certification_hash'],
                    'reviewed_by' => (string) $payload['reviewed_by'],
                ],
            );
            throw ValidationException::withMessages([
                'certification_hash' => 'hash não confere com o pack atual; gere um novo pack antes de revisar (evento: '.$blockedEvent['event_kind'].')',
            ]);
        }

        $event = $this->evidence->v3PromotionReviewRecorded([
            'certification_hash' => (string) $payload['certification_hash'],
            'reviewed_by' => (string) $payload['reviewed_by'],
            'decision' => (string) $payload['decision'],
            'gate_status' => (string) $currentPack['gate_status'],
        ]);

        return response()->json([
            'schema' => 'atlas.vox.v3_promotion_review.v1',
            'reviewed_by' => (string) $payload['reviewed_by'],
            'decision' => (string) $payload['decision'],
            'certification_hash' => (string) $payload['certification_hash'],
            'gate_status' => (string) $currentPack['gate_status'],
            // Even when decision=approved_for_v4_planning, this endpoint
            // never flips a feature flag. V4 unlock is a separate future
            // wave gated by an explicit ADR.
            'v4_unlock_allowed' => false,
            'v4_unlocked_by_review' => false,
            'reviewed_at' => now('UTC')->toIso8601String(),
            'events' => [$event],
        ], 201);
    }

    /**
     * GET /ai/vox/audit/v3-hardening
     *
     * Read-only re-verification of every V3 hard-safety invariant.
     * Independent from VoxV3CertificationPackService: the pack declares,
     * the audit measures. Used before any GATE V3 decision so a stale
     * cert pack can't smuggle a regression past Vitor's manual review.
     */
    public function v3HardeningAudit(): JsonResponse
    {
        return response()->json($this->hardeningAudit->audit());
    }

    /**
     * @param  array<string,mixed>  $pack
     */
    private function countHardViolations(array $pack): int
    {
        $count = 0;
        foreach ((array) ($pack['blockers'] ?? []) as $blocker) {
            if (is_array($blocker) && (string) ($blocker['severity'] ?? '') === 'hard') {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Reuse Lei-0.75 rule: raw audio fields are 422-rejected even on
     * metrics endpoints. There's no legitimate reason for these
     * payloads to ever contain audio bytes.
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
