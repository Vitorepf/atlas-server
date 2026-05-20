<?php

namespace App\Services\Ai\Programming;

use App\Models\AtlasLongHorizonContinuationPack;
use App\Services\Ai\LongHorizon\AtlasLongHorizonCanon;
use Illuminate\Support\Facades\Schema;

class ProgrammingResumeService
{
    public function __construct(
        private readonly ProgrammingStageReceiptValidator $validator,
        private readonly ProgrammingStageReceiptStore $stageReceipts,
    ) {}

    /**
     * @param  array<int,array<string,mixed>>  $previousReceipts
     * @param  array<string,mixed>  $context  Optional TEOS-I1 hints:
     *                                        scope_type, scope_id,
     *                                        missing_required_refs[], stale_refs[].
     * @return array<string,mixed>
     */
    public function state(string $planId, ?string $parentPlanId, array $previousReceipts = [], array $context = []): array
    {
        $loadedFromStore = false;
        if ($previousReceipts === []) {
            $previousReceipts = $this->stageReceipts->timeline($parentPlanId ?: $planId);
            $loadedFromStore = $previousReceipts !== [];
        }

        $validation = $this->validator->validateTimeline($previousReceipts);
        $latest = collect($previousReceipts)->last();
        $latestStage = is_array($latest) ? ($latest['stage'] ?? null) : null;
        $latestStatus = is_array($latest) ? ($latest['status'] ?? null) : null;
        $resumeAllowed = $parentPlanId === null || $validation['valid'];

        $continuationPacket = $this->continuationPacket(
            $planId,
            $parentPlanId,
            $previousReceipts,
            $latestStage,
            $latestStatus,
            $resumeAllowed,
        );

        return [
            'schema_version' => 'atlas.programming.resume_state.v1',
            'plan_id' => $planId,
            'parent_plan_id' => $parentPlanId,
            'resumed' => $parentPlanId !== null,
            'previous_stage_receipt_count' => count($previousReceipts),
            'previous_stage_receipt_source' => $loadedFromStore ? 'stage_receipt_store' : 'provided_payload',
            'latest_stage' => $latestStage,
            'latest_status' => $latestStatus,
            'timeline_validation' => $validation,
            'resume_allowed' => $resumeAllowed,
            // v1 — preserved for back-compat.
            'continuation_packet' => $continuationPacket,
            // TEOS-I1 / M5 adapter — surfaces a continuation_pack.v2-shaped block
            // alongside the legacy packet. Reads from
            // `atlas_long_horizon_continuation_packs` when available; otherwise
            // emits a deterministic synthesised pack with safe defaults.
            'continuation_pack' => $this->continuationPackV2(
                $planId,
                $parentPlanId,
                $continuationPacket,
                $previousReceipts,
                $validation,
                $resumeAllowed,
                $context,
            ),
            'blocks_when_invalid' => true,
            'must_load_open_brain' => true,
            'must_preserve_prior_decisions' => true,
        ];
    }

    /**
     * @param  array<int,array<string,mixed>>  $previousReceipts
     * @return array<string,mixed>
     */
    private function continuationPacket(string $planId, ?string $parentPlanId, array $previousReceipts, mixed $latestStage, mixed $latestStatus, bool $resumeAllowed): array
    {
        $completedStages = collect($previousReceipts)
            ->filter(fn (array $receipt): bool => in_array((string) ($receipt['status'] ?? ''), ['passed', 'completed'], true))
            ->pluck('stage')
            ->filter()
            ->map(fn (mixed $stage): string => (string) $stage)
            ->unique()
            ->values()
            ->all();
        $stageOrder = ['plan', 'review', 'patch', 'test', 'repair'];
        $missingStages = array_values(array_diff($stageOrder, $completedStages));
        $nextStage = $resumeAllowed
            ? $this->nextStage($latestStage, $latestStatus, $missingStages)
            : 'human_review';

        return [
            'schema_version' => 'atlas.programming.continuation_packet.v1',
            'status' => $resumeAllowed ? 'ready' : 'blocked',
            'plan_id' => $planId,
            'parent_plan_id' => $parentPlanId,
            'latest_stage' => $latestStage,
            'latest_status' => $latestStatus,
            'completed_stages' => $completedStages,
            'missing_stage_receipts' => $missingStages,
            'next_stage' => $nextStage,
            'resume_command' => $parentPlanId === null
                ? null
                : 'php artisan atlas:programming:resume '.$parentPlanId.' --plan-id='.$planId.' --json',
            'required_before_next_provider_call' => [
                'load_stage_receipts',
                'load_open_brain_context',
                'preserve_prior_decision_receipts',
                'attach_action_manifest_for_next_write',
            ],
            'stop_rules' => [
                'invalid_timeline_requires_human_review' => true,
                'missing_prior_decision_blocks_write' => true,
                'failed_latest_stage_routes_to_repair' => true,
            ],
        ];
    }

    /**
     * @param  array<int,string>  $missingStages
     */
    private function nextStage(mixed $latestStage, mixed $latestStatus, array $missingStages): string
    {
        if (in_array((string) $latestStatus, ['failed', 'blocked'], true)) {
            return 'repair';
        }

        if ($missingStages !== []) {
            return $missingStages[0];
        }

        return match ((string) $latestStage) {
            'plan' => 'review',
            'review' => 'patch',
            'patch' => 'test',
            'test' => 'finalize',
            'repair' => 'test',
            default => 'plan',
        };
    }

    /**
     * Emit a continuation_pack.v2-shaped block alongside the legacy v1 packet.
     *
     * Behaviour:
     *  - When a persisted `atlas_long_horizon_continuation_packs` row matches
     *    the (scope_type, scope_id) tuple (or the parent/plan id when no scope
     *    hint is provided), surface its canonical id + pack_hash + stale_after
     *    + safe_resume_mode + next_safe_action + human_decisions_required.
     *  - Otherwise synthesise a deterministic pack: stable `pack_hash` over
     *    the same canonical payload the persisted pack would carry, safe
     *    defaults for `stale_after`, `safe_resume_mode`, `next_safe_action`.
     *
     * This is the M5 adapter shape — not a builder and not the enforcement
     * gate. Freshness/recovery/continuity checks are evaluated by the
     * Programming Console long-horizon actions so this read-only resume
     * surface can stay side-effect free.
     *
     * @param  array<int,array<string,mixed>>  $previousReceipts
     * @param  array<string,mixed>  $validation
     * @param  array<string,mixed>  $continuationPacket
     * @param  array<string,mixed>  $context
     * @return array<string,mixed>
     */
    private function continuationPackV2(
        string $planId,
        ?string $parentPlanId,
        array $continuationPacket,
        array $previousReceipts,
        array $validation,
        bool $resumeAllowed,
        array $context,
    ): array {
        $scopeType = $this->stringOrNull($context['scope_type'] ?? null) ?? AtlasLongHorizonCanon::SCOPE_TYPE_DEV_RUN;
        if (! in_array($scopeType, AtlasLongHorizonCanon::ALLOWED_SCOPE_TYPES, true)) {
            $scopeType = AtlasLongHorizonCanon::SCOPE_TYPE_DEV_RUN;
        }
        $scopeId = $this->stringOrNull($context['scope_id'] ?? null) ?? ($parentPlanId ?: $planId);

        $missingRequiredRefs = $this->stringList($context['missing_required_refs'] ?? []);
        $staleRefs = $this->stringList($context['stale_refs'] ?? []);

        $existing = $this->resolveExistingPack($scopeType, $scopeId);

        $safeResumeMode = $this->resolveSafeResumeMode(
            existing: $existing,
            resumeAllowed: $resumeAllowed,
            missingRequiredRefs: $missingRequiredRefs,
            validation: $validation,
        );

        $humanDecisionsRequired = is_array($existing?->human_decisions_required)
            ? array_values($existing->human_decisions_required)
            : $this->humanDecisionsForResumeMode($safeResumeMode, $validation);

        $nextSafeAction = $existing->next_safe_action ?? $this->nextSafeAction(
            $safeResumeMode,
            $continuationPacket,
        );

        $staleAfter = $existing?->stale_after?->toIso8601String();

        // Deterministic pack_hash: when a pack row exists, mirror its hash;
        // otherwise compute over the synthesised canonical payload so callers
        // can rely on stable equality without a write side-effect.
        $packHash = $existing?->pack_hash ?? AtlasLongHorizonContinuationPack::canonicalPackHash([
            'schema_version' => AtlasLongHorizonCanon::CONTINUATION_PACK_SCHEMA_VERSION,
            'scope_type' => $scopeType,
            'scope_id' => $scopeId,
            'plan_id' => $planId,
            'parent_plan_id' => $parentPlanId,
            'completed_stages' => $continuationPacket['completed_stages'] ?? [],
            'missing_stage_receipts' => $continuationPacket['missing_stage_receipts'] ?? [],
            'next_stage' => $continuationPacket['next_stage'] ?? null,
            'missing_required_refs' => $missingRequiredRefs,
            'stale_refs' => $staleRefs,
            'safe_resume_mode' => $safeResumeMode,
            'previous_receipt_count' => count($previousReceipts),
        ]);

        return [
            'schema_version' => AtlasLongHorizonCanon::CONTINUATION_PACK_SCHEMA_VERSION,
            'source' => $existing === null ? 'synthesised' : 'persisted',
            'scope_type' => $scopeType,
            'scope_id' => $scopeId,
            'continuation_pack_id' => $existing?->id,
            'pack_hash' => $packHash,
            'stale_after' => $staleAfter,
            'safe_resume_mode' => $safeResumeMode,
            'missing_required_refs' => $missingRequiredRefs,
            'stale_refs' => $staleRefs,
            'next_safe_action' => $nextSafeAction,
            'human_decisions_required' => $humanDecisionsRequired,
            // No FreshnessGate is evaluated inside this read-only adapter.
            // ProgrammingConsoleService runs the shipped freshness/recovery/
            // continuity gates when the operator asks for long-horizon status
            // or certification.
            'freshness_gate_evaluated' => false,
            'freshness_gate_status' => 'not_evaluated',
        ];
    }

    private function resolveExistingPack(string $scopeType, ?string $scopeId): ?AtlasLongHorizonContinuationPack
    {
        if ($scopeId === null) {
            return null;
        }
        if (! Schema::hasTable('atlas_long_horizon_continuation_packs')) {
            return null;
        }

        try {
            return AtlasLongHorizonContinuationPack::query()
                ->where('scope_type', $scopeType)
                ->where('scope_id', $scopeId)
                ->orderByDesc('created_at')
                ->first();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param  list<string>  $missingRequiredRefs
     * @param  array<string,mixed>  $validation
     */
    private function resolveSafeResumeMode(
        ?AtlasLongHorizonContinuationPack $existing,
        bool $resumeAllowed,
        array $missingRequiredRefs,
        array $validation,
    ): string {
        if ($existing !== null && in_array($existing->safe_resume_mode, AtlasLongHorizonCanon::ALLOWED_SAFE_RESUME_MODES, true)) {
            return $existing->safe_resume_mode;
        }

        if (! $resumeAllowed || ! ($validation['valid'] ?? true)) {
            return AtlasLongHorizonCanon::SAFE_RESUME_ASK_HUMAN;
        }

        if ($missingRequiredRefs !== []) {
            return AtlasLongHorizonCanon::SAFE_RESUME_ASK_HUMAN;
        }

        return AtlasLongHorizonCanon::SAFE_RESUME_EXECUTE;
    }

    /**
     * @param  array<string,mixed>  $validation
     * @return list<string>
     */
    private function humanDecisionsForResumeMode(string $safeResumeMode, array $validation): array
    {
        if ($safeResumeMode === AtlasLongHorizonCanon::SAFE_RESUME_ASK_HUMAN) {
            $reasons = array_values(array_filter(array_map(
                fn (mixed $error): ?string => is_scalar($error) ? (string) $error : null,
                (array) ($validation['errors'] ?? []),
            )));

            return $reasons !== [] ? $reasons : ['review_resume_state_before_next_provider_call'];
        }

        return [];
    }

    /**
     * @param  array<string,mixed>  $continuationPacket
     */
    private function nextSafeAction(string $safeResumeMode, array $continuationPacket): string
    {
        if ($safeResumeMode === AtlasLongHorizonCanon::SAFE_RESUME_ASK_HUMAN) {
            return 'pause_for_human_review';
        }
        if ($safeResumeMode === AtlasLongHorizonCanon::SAFE_RESUME_READ_ONLY) {
            return 'replay_evidence_without_mutation';
        }

        $nextStage = (string) ($continuationPacket['next_stage'] ?? 'plan');

        return 'resume_stage:'.$nextStage;
    }

    private function stringOrNull(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    /**
     * @return list<string>
     */
    private function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $list = [];
        foreach ($value as $item) {
            if (is_scalar($item)) {
                $item = trim((string) $item);
                if ($item !== '') {
                    $list[] = $item;
                }
            }
        }

        return array_values(array_unique($list));
    }
}
