<?php

namespace App\Services\Ai\Programming\Forge;

use App\Models\AiForgeIntake;
use App\Models\AiForgeLongHorizonState;
use App\Models\AiForgeMilestone;
use App\Models\AiForgeWorkPacket;
use App\Models\AtlasLongHorizonContinuationPack;
use App\Services\Ai\LongHorizon\AtlasLongHorizonCanon;
use App\Services\Ai\Mission\MissionCanonicalHash;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Builds and persists `atlas.long_horizon.continuation_pack.v2` rows for an
 * Atlas Forge Obra.
 *
 * Why a separate builder rather than inlining inside
 * {@see ForgeLongHorizonStateService}: the projection logic must stay free of
 * lifecycle mutations (no state_hash recompute, no blocker merging, no save
 * on the state row). Concentrating the projection here keeps the state
 * service focused on its existing responsibilities and makes the pack
 * payload independently testable.
 *
 * Hard rules:
 *  - never mutates the Forge state row;
 *  - never calls a provider, never runs a benchmark;
 *  - resolves `safe_resume_mode` deterministically from state + intake;
 *  - composes `pack_hash` via {@see AtlasLongHorizonContinuationPack::canonicalPackHash}.
 */
class ForgeContinuationPackBuilder
{
    /**
     * Render a continuation pack payload from the live long-horizon state.
     *
     * @param  array<string,mixed>  $options  optional overrides: stale_after, scope_type, scope_id
     * @return array<string,mixed> canonical payload (no DB write)
     */
    public function payload(AiForgeLongHorizonState $state, array $options = []): array
    {
        $intake = $this->resolveIntake($state);
        $milestones = $intake !== null
            ? $intake->milestones()->orderBy('position')->get()
            : collect();
        $workPackets = $intake !== null
            ? $intake->workPackets()->orderBy('packet_position')->get()
            : collect();

        $unresolvedBlockers = $this->unresolvedBlockers((array) ($state->blockers ?? []));
        $humanDecisions = $this->humanDecisionsRequired($state, $intake, $unresolvedBlockers);
        $safeResumeMode = $this->resolveSafeResumeMode(
            $state,
            $intake,
            $unresolvedBlockers,
            $humanDecisions,
            $workPackets,
        );
        $nextSafeAction = $this->resolveNextSafeAction($state, $safeResumeMode);
        $risks = $this->risksProjection($intake, $workPackets);

        $stateSummary = $this->stateSummary($state, $milestones->count(), $workPackets->count());

        $payload = [
            'uuid' => (string) Str::uuid(),
            'scope_type' => (string) ($options['scope_type'] ?? AtlasLongHorizonCanon::SCOPE_TYPE_OBRA),
            'scope_id' => (string) ($options['scope_id'] ?? $state->intake_id),
            'objective' => (string) ($intake->obra_title ?? $state->obra_title ?? 'forge_obra_unknown'),
            'current_phase' => $state->current_milestone,
            'state_summary' => $stateSummary,
            'decisions' => $this->decisions($state, $intake),
            'superseded_decisions' => [],
            'open_tasks' => $this->openTasks($workPackets, $state),
            'completed_tasks' => array_values((array) ($state->completed_work_packets ?? [])),
            'blockers' => array_values((array) ($state->blockers ?? [])),
            'risks' => $risks,
            'evidence_refs' => array_values((array) ($state->evidence_refs ?? [])),
            'context_manifest' => $this->contextManifest($state, $milestones, $workPackets),
            'context_pack_hash' => $state->continuation_context_hash,
            'summary_hash' => MissionCanonicalHash::sha256([
                'state_summary' => $stateSummary,
                'state_hash' => $state->state_hash,
            ]),
            'source_receipts' => $this->sourceReceipts($state),
            'stale_after' => $this->normalizeStaleAfter($options['stale_after'] ?? null),
            'safe_resume_mode' => $safeResumeMode,
            'next_safe_action' => $nextSafeAction,
            'human_decisions_required' => array_values($humanDecisions),
            'confidence' => $this->confidence($state, $unresolvedBlockers, $humanDecisions),
        ];

        return $payload;
    }

    /**
     * Build the payload AND persist it as a new row. Idempotency is not
     * guaranteed here — callers that need at-most-once emission should
     * dedupe by `pack_hash` themselves.
     *
     * @param  array<string,mixed>  $options
     */
    public function build(AiForgeLongHorizonState $state, array $options = []): AtlasLongHorizonContinuationPack
    {
        $payload = $this->payload($state, $options);
        $packHash = AtlasLongHorizonContinuationPack::canonicalPackHash($payload);

        return AtlasLongHorizonContinuationPack::query()->create(
            array_merge($payload, ['pack_hash' => $packHash])
        );
    }

    private function resolveIntake(AiForgeLongHorizonState $state): ?AiForgeIntake
    {
        if ($state->intake_id === null) {
            return null;
        }

        return AiForgeIntake::query()->find($state->intake_id);
    }

    /**
     * @param  array<int,mixed>  $blockers
     * @return array<int,array<string,mixed>>
     */
    private function unresolvedBlockers(array $blockers): array
    {
        return array_values(array_filter(
            $blockers,
            static fn ($b): bool => is_array($b) && ($b['resolved'] ?? false) !== true,
        ));
    }

    /**
     * @param  array<int,array<string,mixed>>  $unresolvedBlockers
     * @return array<int,array<string,mixed>>
     */
    private function humanDecisionsRequired(
        AiForgeLongHorizonState $state,
        ?AiForgeIntake $intake,
        array $unresolvedBlockers,
    ): array {
        $decisions = [];

        foreach ($unresolvedBlockers as $blocker) {
            $scope = (string) ($blocker['scope'] ?? 'unknown');
            $reason = (string) ($blocker['reason'] ?? 'unresolved_blocker');
            $decisions[] = [
                'kind' => 'resolve_blocker',
                'scope' => $scope,
                'target' => $blocker['target'] ?? null,
                'reason' => $reason,
                'detail' => $blocker['detail'] ?? null,
            ];
        }

        if ($intake !== null && $intake->status === ForgeIntakeCanon::STATUS_BLOCKED) {
            $decisions[] = [
                'kind' => 'resolve_intake_blocker',
                'scope' => 'intake',
                'target' => $intake->id,
                'reason' => (string) ($intake->blocker_reason ?? 'intake_blocked'),
                'detail' => null,
            ];
        }

        if ($state->current_milestone === ForgeIntakeCanon::MILESTONE_CERTIFICATION
            && $state->status !== ForgeLongHorizonStateCanon::STATUS_COMPLETED
            && ! $this->hasEvidenceKind('certification', (array) ($state->evidence_refs ?? []))
        ) {
            $decisions[] = [
                'kind' => 'attach_certification_evidence',
                'scope' => 'milestone',
                'target' => $state->current_milestone,
                'reason' => 'certification_milestone_missing_certification_evidence',
                'detail' => null,
            ];
        }

        return $decisions;
    }

    /**
     * @param  array<int,array<string,mixed>>  $unresolvedBlockers
     * @param  array<int,array<string,mixed>>  $humanDecisions
     * @param  Collection<int,AiForgeWorkPacket>  $workPackets
     */
    private function resolveSafeResumeMode(
        AiForgeLongHorizonState $state,
        ?AiForgeIntake $intake,
        array $unresolvedBlockers,
        array $humanDecisions,
        $workPackets,
    ): string {
        if ($intake !== null && $intake->status === ForgeIntakeCanon::STATUS_BLOCKED) {
            return AtlasLongHorizonCanon::SAFE_RESUME_BLOCKED;
        }

        // Any intake-scope unresolved blocker locks the Obra.
        foreach ($unresolvedBlockers as $blocker) {
            if (($blocker['scope'] ?? null) === ForgeLongHorizonStateCanon::BLOCKER_SCOPE_INTAKE) {
                return AtlasLongHorizonCanon::SAFE_RESUME_BLOCKED;
            }
        }

        if ($state->status === ForgeLongHorizonStateCanon::STATUS_COMPLETED) {
            return AtlasLongHorizonCanon::SAFE_RESUME_READ_ONLY;
        }

        if ($state->status === ForgeLongHorizonStateCanon::STATUS_BLOCKED && $unresolvedBlockers !== []) {
            return AtlasLongHorizonCanon::SAFE_RESUME_BLOCKED;
        }

        if ($humanDecisions !== []) {
            return AtlasLongHorizonCanon::SAFE_RESUME_ASK_HUMAN;
        }

        // Certification milestone without certification evidence cannot
        // execute — even if state.status is `active`. Honour the invariant
        // declared in MissionLifecycleService:121-131.
        if ($state->current_milestone === ForgeIntakeCanon::MILESTONE_CERTIFICATION
            && ! $this->hasEvidenceKind('certification', (array) ($state->evidence_refs ?? []))
        ) {
            return AtlasLongHorizonCanon::SAFE_RESUME_REVIEW;
        }

        // Implementation phase without any active or completed work packets:
        // operator needs to claim a packet before we can execute.
        if ($state->current_milestone === ForgeIntakeCanon::MILESTONE_IMPLEMENTATION
            && (array) ($state->active_work_packets ?? []) === []
            && (array) ($state->completed_work_packets ?? []) === []
            && $workPackets->isEmpty()
        ) {
            return AtlasLongHorizonCanon::SAFE_RESUME_REVIEW;
        }

        return AtlasLongHorizonCanon::SAFE_RESUME_EXECUTE;
    }

    private function resolveNextSafeAction(AiForgeLongHorizonState $state, string $safeResumeMode): ?string
    {
        $existing = (array) ($state->next_action ?? []);
        if (isset($existing['kind']) && is_string($existing['kind'])) {
            return $existing['kind'];
        }

        return match ($safeResumeMode) {
            AtlasLongHorizonCanon::SAFE_RESUME_BLOCKED => 'resolve_blocker',
            AtlasLongHorizonCanon::SAFE_RESUME_ASK_HUMAN => 'await_human_decision',
            AtlasLongHorizonCanon::SAFE_RESUME_READ_ONLY => 'obra_completed',
            AtlasLongHorizonCanon::SAFE_RESUME_REVIEW => 'review_milestone_state',
            default => 'advance_or_execute_milestone',
        };
    }

    /**
     * @param  Collection<int,AiForgeWorkPacket>  $workPackets
     * @return array<int,string>
     */
    private function risksProjection(?AiForgeIntake $intake, $workPackets): array
    {
        $risks = [];
        if ($intake !== null) {
            foreach ((array) ($intake->constraints ?? []) as $constraint) {
                if (is_string($constraint) && $constraint !== '') {
                    $risks[] = $constraint;
                }
            }
            if (is_string($intake->risk_assessment) && $intake->risk_assessment !== '') {
                $risks[] = 'intake_risk:'.$intake->risk_assessment;
            }
        }
        foreach ($workPackets as $packet) {
            foreach ((array) ($packet->risks ?? []) as $packetRisk) {
                if (is_string($packetRisk) && $packetRisk !== '') {
                    $risks[] = 'wp:'.$packet->packet_id.':'.$packetRisk;
                }
            }
        }

        return array_values(array_unique($risks));
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function decisions(AiForgeLongHorizonState $state, ?AiForgeIntake $intake): array
    {
        $decisions = [];
        if ($intake !== null) {
            $decisions[] = [
                'decision_id' => 'forge_intake:'.$intake->id,
                'kind' => 'forge_intake',
                'detail' => 'recommended_mode='.$intake->recommended_forge_mode,
                'evidence_refs' => ['forge_intake:'.$intake->id],
            ];
        }
        $progress = (array) ($state->milestone_progress ?? []);
        foreach ($progress as $milestoneId => $entry) {
            if (! is_array($entry)) {
                continue;
            }
            if (($entry['status'] ?? null) === ForgeLongHorizonStateCanon::MILESTONE_STATUS_COMPLETED) {
                $decisions[] = [
                    'decision_id' => 'milestone:'.$milestoneId.':completed',
                    'kind' => 'milestone_advance',
                    'detail' => 'milestone_'.$milestoneId.'_completed',
                    'evidence_refs' => array_values((array) ($entry['evidence_present'] ?? [])),
                ];
            }
        }

        return $decisions;
    }

    /**
     * @param  Collection<int,AiForgeWorkPacket>  $workPackets
     * @return array<int,array<string,mixed>>
     */
    private function openTasks($workPackets, AiForgeLongHorizonState $state): array
    {
        $completed = array_values((array) ($state->completed_work_packets ?? []));

        return $workPackets
            ->filter(fn (AiForgeWorkPacket $packet): bool => ! in_array($packet->packet_id, $completed, true)
                && $packet->status !== ForgeIntakeCanon::PACKET_STATUS_DONE)
            ->map(fn (AiForgeWorkPacket $packet): array => [
                'task_id' => $packet->packet_id,
                'title' => $packet->title,
                'status' => $packet->status,
                'risk_band' => $packet->risk_band,
            ])
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int,AiForgeMilestone>  $milestones
     * @param  Collection<int,AiForgeWorkPacket>  $workPackets
     * @return array<string,mixed>
     */
    private function contextManifest(
        AiForgeLongHorizonState $state,
        $milestones,
        $workPackets,
    ): array {
        return [
            'intake_id' => $state->intake_id,
            'current_milestone' => $state->current_milestone,
            'milestone_progress' => (array) ($state->milestone_progress ?? []),
            'active_work_packets' => array_values((array) ($state->active_work_packets ?? [])),
            'completed_work_packets' => array_values((array) ($state->completed_work_packets ?? [])),
            'cycle_count' => (int) ($state->cycle_count ?? 0),
            'last_cycle_summary' => $state->last_cycle_summary,
            'milestones' => $milestones
                ->map(fn (AiForgeMilestone $milestone): array => [
                    'milestone_id' => $milestone->milestone_id,
                    'position' => (int) $milestone->position,
                    'title' => $milestone->title,
                    'status' => $milestone->status,
                    'milestone_hash' => $milestone->milestone_hash,
                ])
                ->values()
                ->all(),
            'work_packets' => $workPackets
                ->map(fn (AiForgeWorkPacket $packet): array => [
                    'packet_id' => $packet->packet_id,
                    'title' => $packet->title,
                    'status' => $packet->status,
                    'risk_band' => $packet->risk_band,
                    'packet_hash' => $packet->packet_hash,
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function sourceReceipts(AiForgeLongHorizonState $state): array
    {
        return [
            [
                'kind' => 'forge_long_horizon_state',
                'ref' => $state->uuid,
                'hash' => $state->state_hash,
            ],
        ];
    }

    /**
     * @param  array<int,array<string,mixed>>  $unresolvedBlockers
     * @param  array<int,array<string,mixed>>  $humanDecisions
     */
    private function confidence(
        AiForgeLongHorizonState $state,
        array $unresolvedBlockers,
        array $humanDecisions,
    ): float {
        if ($state->status === ForgeLongHorizonStateCanon::STATUS_COMPLETED) {
            return 0.95;
        }
        if ($unresolvedBlockers !== []) {
            return 0.35;
        }
        if ($humanDecisions !== []) {
            return 0.55;
        }
        $cycleCount = (int) ($state->cycle_count ?? 0);
        if ($cycleCount === 0) {
            return 0.60;
        }

        return min(0.90, 0.65 + 0.05 * min($cycleCount, 5));
    }

    private function stateSummary(AiForgeLongHorizonState $state, int $milestoneCount, int $packetCount): string
    {
        return sprintf(
            'forge_obra=%s status=%s current_milestone=%s milestones=%d work_packets=%d cycle_count=%d blockers=%d',
            $state->intake_id,
            $state->status,
            $state->current_milestone ?? 'none',
            $milestoneCount,
            $packetCount,
            (int) ($state->cycle_count ?? 0),
            count(array_filter(
                (array) ($state->blockers ?? []),
                static fn ($b): bool => is_array($b) && ($b['resolved'] ?? false) !== true,
            )),
        );
    }

    /**
     * @param  array<int,mixed>  $evidenceRefs
     */
    private function hasEvidenceKind(string $kind, array $evidenceRefs): bool
    {
        foreach ($evidenceRefs as $ref) {
            if (is_array($ref) && ($ref['kind'] ?? null) === $kind) {
                return true;
            }
        }

        return false;
    }

    private function normalizeStaleAfter(mixed $value): ?Carbon
    {
        if ($value instanceof Carbon) {
            return $value;
        }
        if (is_string($value) && trim($value) !== '') {
            return Carbon::parse($value);
        }

        return null;
    }
}
