<?php

namespace App\Services\Ai\Programming\Forge;

use App\Models\AiForgeIntake;
use App\Models\AiForgeLongHorizonState;
use App\Models\AiForgeMilestone;
use App\Models\AiForgeWorkPacket;
use App\Models\AtlasLongHorizonContinuationPack;
use App\Services\Ai\Mission\MissionCanonicalHash;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Owns the lifecycle of `atlas.forge.long_horizon_state.v1`.
 *
 * Why this exists: Forge OS lists, as the #1 failure mode, "Forge vira uma
 * coleção de prompts sem estado persistente" — turning Forge into a chat. The
 * long-horizon state is the persistent record that survives across sessions,
 * cycles and operators. Without it, every milestone, gate and blocker is
 * recomputed from prompt context and the Obra cannot be safely resumed.
 *
 * Responsibilities (intentionally narrow):
 *  - {@see initializeForIntake()}      — first persistent state row per Obra.
 *  - {@see recordCycle()}              — append a cycle: register evidence,
 *    blockers, packet transitions; recompute next_action and state_hash.
 *  - {@see advanceMilestone()}         — gate-driven transition, refuses to
 *    advance without gate green.
 *  - {@see completeObra()}             — refuses to complete without
 *    certification gate green AND certification evidence ref.
 *  - {@see emitContinuationPack()}     — TEOS-I1 hook that projects the
 *    current state into a canonical `atlas.long_horizon.continuation_pack.v2`
 *    row via {@see ForgeContinuationPackBuilder}. Read-only over the state;
 *    no row mutation here.
 *
 * Out of scope:
 *  - provider invocation / multi-agent dispatch;
 *  - rivals battery / benchmark;
 *  - cartography publication;
 *  - UX / surface concerns.
 */
class ForgeLongHorizonStateService
{
    public function __construct(
        private readonly ForgeMilestoneGateRunner $gates,
        private readonly ForgeContinuationPackBuilder $continuationPackBuilder = new ForgeContinuationPackBuilder,
    ) {}

    /**
     * Emit a TEOS-I1 `atlas.long_horizon.continuation_pack.v2` row for this
     * Obra. The pack carries `scope_type=obra` by default; override via
     * `$options['scope_type']` for milestone / work_packet projections built
     * by future TEOS-I2 callers.
     *
     * @param  array<string,mixed>  $options
     */
    public function emitContinuationPack(
        AiForgeLongHorizonState $state,
        array $options = [],
    ): AtlasLongHorizonContinuationPack {
        return $this->continuationPackBuilder->build($state, $options);
    }

    public function initializeForIntake(AiForgeIntake $intake): AiForgeLongHorizonState
    {
        if (! $intake->exists || $intake->id === null) {
            throw ForgeLongHorizonException::intakeNotPersisted();
        }

        $existing = AiForgeLongHorizonState::query()
            ->where('intake_id', $intake->id)
            ->first();
        if ($existing !== null) {
            throw ForgeLongHorizonException::stateAlreadyInitialized($intake->id);
        }

        $milestones = $intake->milestones()->orderBy('position')->get();
        $milestoneProgress = [];
        foreach ($milestones as $position => $milestone) {
            $milestoneProgress[$milestone->milestone_id] = [
                'milestone_id' => $milestone->milestone_id,
                'position' => (int) $milestone->position,
                'status' => $position === 0
                    ? ForgeLongHorizonStateCanon::MILESTONE_STATUS_ACTIVE
                    : ForgeLongHorizonStateCanon::MILESTONE_STATUS_PENDING,
                'gates' => [],
                'evidence_present' => [],
                'evidence_missing' => array_values((array) $milestone->required_evidence),
                'failure_reasons' => [],
                'last_evaluated_at' => null,
            ];
        }

        $isBlockedIntake = $intake->status === ForgeIntakeCanon::STATUS_BLOCKED;

        $status = $isBlockedIntake
            ? ForgeLongHorizonStateCanon::STATUS_BLOCKED
            : ForgeLongHorizonStateCanon::STATUS_ACTIVE;
        $currentMilestone = $isBlockedIntake
            ? null
            : ($milestones->first()?->milestone_id);
        $blockers = [];
        $blockerReason = null;

        if ($isBlockedIntake) {
            $blockerReason = (string) ($intake->blocker_reason ?? 'intake_blocked');
            $blockers[] = [
                'scope' => ForgeLongHorizonStateCanon::BLOCKER_SCOPE_INTAKE,
                'target' => $intake->id,
                'reason' => $blockerReason,
                'since' => Carbon::now()->toISOString(),
                'resolved' => false,
            ];
        }

        $nextAction = $this->computeInitialNextAction($intake, $currentMilestone, $blockerReason);

        $uuid = (string) Str::uuid();
        $state = new AiForgeLongHorizonState([
            'schema_version' => ForgeLongHorizonStateCanon::SCHEMA_VERSION,
            'uuid' => $uuid,
            'intake_id' => $intake->id,
            'obra_title' => (string) $intake->obra_title,
            'status' => $status,
            'current_milestone' => $currentMilestone,
            'milestone_progress' => $milestoneProgress,
            'active_work_packets' => [],
            'completed_work_packets' => [],
            'blockers' => $blockers,
            'evidence_refs' => array_values((array) ($intake->evidence_refs ?? [])),
            'next_action' => $nextAction,
            'last_cycle_summary' => null,
            'cycle_count' => 0,
            'continuation_context_hash' => null,
            'blocker_reason' => $blockerReason,
            'completed_at' => null,
        ]);
        $state->state_hash = $this->computeStateHash($state);
        $state->save();

        return $state;
    }

    /**
     * Apply a cycle of work to the state.
     *
     * Accepted cycle keys (all optional):
     *  - cycle_id (string)
     *  - notes (string)
     *  - active_work_packets (list<string>)        replaces active set
     *  - completed_work_packets (list<string>)     merged into completed set
     *  - evidence_refs (list<EvidenceRef>)         merged (dedup by hash|ref)
     *  - blockers (list<Blocker>)                  merged (each carries scope+target+reason)
     *  - resolve_blockers (list<string>)           reasons to mark resolved=true
     *  - continuation_context_hash (string)
     *
     * @param  array<string,mixed>  $cycle
     */
    public function recordCycle(AiForgeLongHorizonState $state, array $cycle): AiForgeLongHorizonState
    {
        if ($state->status === ForgeLongHorizonStateCanon::STATUS_COMPLETED) {
            throw ForgeLongHorizonException::obraAlreadyCompleted($state->intake_id);
        }

        $startedAt = Carbon::now()->toISOString();

        $state->cycle_count = (int) $state->cycle_count + 1;

        if (array_key_exists('active_work_packets', $cycle)) {
            $state->active_work_packets = array_values(array_unique(array_filter(
                (array) $cycle['active_work_packets'],
                static fn ($id): bool => is_string($id) && $id !== '',
            )));
        }

        if (array_key_exists('completed_work_packets', $cycle)) {
            $merged = array_merge(
                (array) ($state->completed_work_packets ?? []),
                array_values(array_filter(
                    (array) $cycle['completed_work_packets'],
                    static fn ($id): bool => is_string($id) && $id !== '',
                )),
            );
            $state->completed_work_packets = array_values(array_unique($merged));

            // Also remove completed ids from active set automatically.
            $state->active_work_packets = array_values(array_diff(
                (array) ($state->active_work_packets ?? []),
                (array) $state->completed_work_packets,
            ));
        }

        if (array_key_exists('evidence_refs', $cycle)) {
            $state->evidence_refs = $this->mergeEvidenceRefs(
                (array) ($state->evidence_refs ?? []),
                (array) $cycle['evidence_refs'],
            );
        }

        if (array_key_exists('blockers', $cycle)) {
            $state->blockers = $this->mergeBlockers(
                (array) ($state->blockers ?? []),
                (array) $cycle['blockers'],
            );
        }

        if (array_key_exists('resolve_blockers', $cycle)) {
            $state->blockers = $this->resolveBlockers(
                (array) ($state->blockers ?? []),
                (array) $cycle['resolve_blockers'],
            );
        }

        $this->recomputeMilestoneProgress($state);

        $unresolvedBlockers = array_values(array_filter(
            (array) ($state->blockers ?? []),
            static fn ($b): bool => is_array($b) && ($b['resolved'] ?? false) !== true,
        ));
        if ($state->status !== ForgeLongHorizonStateCanon::STATUS_COMPLETED) {
            if ($unresolvedBlockers !== []) {
                $state->status = ForgeLongHorizonStateCanon::STATUS_BLOCKED;
                $state->blocker_reason = (string) ($unresolvedBlockers[0]['reason'] ?? 'unresolved_blocker');
            } else {
                $state->status = ForgeLongHorizonStateCanon::STATUS_ACTIVE;
                $state->blocker_reason = null;
            }
        }

        $state->next_action = $this->computeNextAction($state);

        if (array_key_exists('continuation_context_hash', $cycle) && is_string($cycle['continuation_context_hash'])) {
            $state->continuation_context_hash = $cycle['continuation_context_hash'];
        }

        $state->last_cycle_summary = [
            'cycle_id' => (string) ($cycle['cycle_id'] ?? Str::uuid()),
            'cycle_index' => (int) $state->cycle_count,
            'started_at' => $startedAt,
            'ended_at' => Carbon::now()->toISOString(),
            'gates_run' => $this->summariseGatesRun($state),
            'evidence_added' => array_map(
                static fn ($r): array => [
                    'kind' => $r['kind'] ?? null,
                    'ref' => $r['ref'] ?? null,
                ],
                (array) ($cycle['evidence_refs'] ?? []),
            ),
            'blockers_added' => array_map(
                static fn ($b): array => [
                    'scope' => $b['scope'] ?? null,
                    'target' => $b['target'] ?? null,
                    'reason' => $b['reason'] ?? null,
                ],
                (array) ($cycle['blockers'] ?? []),
            ),
            'blockers_resolved' => array_values((array) ($cycle['resolve_blockers'] ?? [])),
            'milestone_advanced' => null,
            'notes' => isset($cycle['notes']) && is_string($cycle['notes']) ? $cycle['notes'] : null,
        ];

        $state->state_hash = $this->computeStateHash($state);
        $state->save();

        return $state;
    }

    /**
     * Try to advance current milestone. If gates fail, the state records a
     * milestone blocker and returns false; if gates pass, the milestone is
     * marked completed, the next milestone is activated, and current_milestone
     * advances. If there is no next milestone (we just passed certification),
     * the caller MUST call {@see completeObra()}.
     *
     * @return array{advanced:bool,gate_result:array<string,mixed>,from:string|null,to:string|null,blocker_reason:string|null}
     */
    public function advanceMilestone(AiForgeLongHorizonState $state): array
    {
        $intake = AiForgeIntake::query()->findOrFail($state->intake_id);
        $milestones = $intake->milestones()->orderBy('position')->get();
        $currentId = $state->current_milestone;
        if ($currentId === null) {
            return [
                'advanced' => false,
                'gate_result' => [],
                'from' => null,
                'to' => null,
                'blocker_reason' => 'no_current_milestone',
            ];
        }

        /** @var AiForgeMilestone|null $current */
        $current = $milestones->firstWhere('milestone_id', $currentId);
        if ($current === null) {
            return [
                'advanced' => false,
                'gate_result' => [],
                'from' => $currentId,
                'to' => null,
                'blocker_reason' => 'milestone_not_found',
            ];
        }

        $gateResult = $this->gates->evaluate($intake, $current, $state);
        $progress = (array) ($state->milestone_progress ?? []);
        $progress[$currentId] = array_merge($progress[$currentId] ?? [], [
            'milestone_id' => $currentId,
            'gates' => $gateResult['gates'],
            'evidence_present' => $gateResult['evidence_present'],
            'evidence_missing' => $gateResult['evidence_missing'],
            'failure_reasons' => $gateResult['failure_reasons'],
            'last_evaluated_at' => Carbon::now()->toISOString(),
        ]);

        if (! $gateResult['all_passed']) {
            $reason = 'milestone_gate_failed:'.$currentId;
            $blockers = (array) ($state->blockers ?? []);
            $blockers[] = [
                'scope' => ForgeLongHorizonStateCanon::BLOCKER_SCOPE_MILESTONE,
                'target' => $currentId,
                'reason' => $reason,
                'detail' => $gateResult['failure_reasons'],
                'since' => Carbon::now()->toISOString(),
                'resolved' => false,
            ];
            $progress[$currentId]['status'] = ForgeLongHorizonStateCanon::MILESTONE_STATUS_BLOCKED;
            $state->milestone_progress = $progress;
            $state->blockers = $blockers;
            $state->status = ForgeLongHorizonStateCanon::STATUS_BLOCKED;
            $state->blocker_reason = $reason;
            $state->next_action = $this->computeNextAction($state);
            $state->state_hash = $this->computeStateHash($state);
            $state->save();

            return [
                'advanced' => false,
                'gate_result' => $gateResult,
                'from' => $currentId,
                'to' => null,
                'blocker_reason' => $reason,
            ];
        }

        // Gate green: mark current completed and move to next.
        $progress[$currentId]['status'] = ForgeLongHorizonStateCanon::MILESTONE_STATUS_COMPLETED;

        $next = $milestones->firstWhere('position', (int) $current->position + 1);
        $nextId = $next?->milestone_id;
        if ($nextId !== null) {
            $progress[$nextId] = array_merge($progress[$nextId] ?? [], [
                'milestone_id' => $nextId,
                'status' => ForgeLongHorizonStateCanon::MILESTONE_STATUS_ACTIVE,
            ]);
        }

        $state->milestone_progress = $progress;
        $state->current_milestone = $nextId;
        $state->blocker_reason = null;
        // Filter out any milestone-scoped blocker that targeted the now-completed milestone.
        $state->blockers = array_values(array_filter(
            (array) ($state->blockers ?? []),
            static fn ($b): bool => ! is_array($b)
                || ! (($b['scope'] ?? null) === ForgeLongHorizonStateCanon::BLOCKER_SCOPE_MILESTONE
                    && ($b['target'] ?? null) === $currentId),
        ));
        $unresolved = array_values(array_filter(
            (array) ($state->blockers ?? []),
            static fn ($b): bool => is_array($b) && ($b['resolved'] ?? false) !== true,
        ));
        $state->status = $unresolved === []
            ? ForgeLongHorizonStateCanon::STATUS_ACTIVE
            : ForgeLongHorizonStateCanon::STATUS_BLOCKED;
        $state->next_action = $this->computeNextAction($state);

        if ($state->last_cycle_summary !== null && is_array($state->last_cycle_summary)) {
            $summary = (array) $state->last_cycle_summary;
            $summary['milestone_advanced'] = ['from' => $currentId, 'to' => $nextId];
            $state->last_cycle_summary = $summary;
        }

        $state->state_hash = $this->computeStateHash($state);
        $state->save();

        return [
            'advanced' => true,
            'gate_result' => $gateResult,
            'from' => $currentId,
            'to' => $nextId,
            'blocker_reason' => null,
        ];
    }

    /**
     * Finalize the Obra. Refuses unless:
     *   1. all 4 preceding canonical milestones (design_context, implementation,
     *      verification, docs) are marked COMPLETED in milestone_progress —
     *      i.e. they each passed their own gate evaluation via {@see advanceMilestone()};
     *   2. the certification milestone gate is passed by the runner; AND
     *   3. evidence_refs contains a ref of kind=certification.
     *
     * Together these enforce the canonical DoD slot
     * `all_canonical_milestones_completed` plus `certification_passed`.
     */
    public function completeObra(AiForgeLongHorizonState $state): AiForgeLongHorizonState
    {
        if ($state->status === ForgeLongHorizonStateCanon::STATUS_COMPLETED) {
            throw ForgeLongHorizonException::obraAlreadyCompleted($state->intake_id);
        }

        $intake = AiForgeIntake::query()->findOrFail($state->intake_id);
        /** @var AiForgeMilestone|null $certMilestone */
        $certMilestone = $intake->milestones()
            ->where('milestone_id', ForgeIntakeCanon::MILESTONE_CERTIFICATION)
            ->first();
        if ($certMilestone === null) {
            throw ForgeLongHorizonException::certificationGateNotPassed($state->intake_id);
        }

        $progress = (array) ($state->milestone_progress ?? []);
        $previousMilestones = [
            ForgeIntakeCanon::MILESTONE_DESIGN_CONTEXT,
            ForgeIntakeCanon::MILESTONE_IMPLEMENTATION,
            ForgeIntakeCanon::MILESTONE_VERIFICATION,
            ForgeIntakeCanon::MILESTONE_DOCS,
        ];
        foreach ($previousMilestones as $milestoneId) {
            $statusEntry = is_array($progress[$milestoneId] ?? null)
                ? ($progress[$milestoneId]['status'] ?? null)
                : null;
            if ($statusEntry !== ForgeLongHorizonStateCanon::MILESTONE_STATUS_COMPLETED) {
                throw ForgeLongHorizonException::certificationGateNotPassed($state->intake_id);
            }
        }

        $hasCertificationEvidence = false;
        foreach ((array) ($state->evidence_refs ?? []) as $ref) {
            if (is_array($ref) && ($ref['kind'] ?? null) === 'certification') {
                $hasCertificationEvidence = true;
                break;
            }
        }
        if (! $hasCertificationEvidence) {
            throw ForgeLongHorizonException::certificationMissing($state->intake_id);
        }

        $gateResult = $this->gates->evaluate($intake, $certMilestone, $state);
        if (! $gateResult['all_passed']) {
            throw ForgeLongHorizonException::certificationGateNotPassed($state->intake_id);
        }

        $progress = (array) ($state->milestone_progress ?? []);
        $progress[ForgeIntakeCanon::MILESTONE_CERTIFICATION] = array_merge(
            $progress[ForgeIntakeCanon::MILESTONE_CERTIFICATION] ?? [],
            [
                'milestone_id' => ForgeIntakeCanon::MILESTONE_CERTIFICATION,
                'status' => ForgeLongHorizonStateCanon::MILESTONE_STATUS_COMPLETED,
                'gates' => $gateResult['gates'],
                'evidence_present' => $gateResult['evidence_present'],
                'evidence_missing' => $gateResult['evidence_missing'],
                'failure_reasons' => $gateResult['failure_reasons'],
                'last_evaluated_at' => Carbon::now()->toISOString(),
            ],
        );

        $state->milestone_progress = $progress;
        $state->current_milestone = null;
        $state->status = ForgeLongHorizonStateCanon::STATUS_COMPLETED;
        $state->blocker_reason = null;
        $state->completed_at = Carbon::now();
        $state->next_action = [
            'kind' => ForgeLongHorizonStateCanon::NEXT_ACTION_OBRA_COMPLETED,
            'target' => $state->intake_id,
            'reason' => 'certification_passed',
            'due_at' => null,
        ];
        $state->state_hash = $this->computeStateHash($state);
        $state->save();

        return $state;
    }

    private function recomputeMilestoneProgress(AiForgeLongHorizonState $state): void
    {
        $intake = AiForgeIntake::query()->find($state->intake_id);
        if ($intake === null) {
            return;
        }
        $milestones = $intake->milestones()->orderBy('position')->get();
        $progress = (array) ($state->milestone_progress ?? []);

        foreach ($milestones as $milestone) {
            $existing = $progress[$milestone->milestone_id] ?? [];
            // Do not touch completed milestones; they are settled.
            if (($existing['status'] ?? null) === ForgeLongHorizonStateCanon::MILESTONE_STATUS_COMPLETED) {
                continue;
            }

            $gateResult = $this->gates->evaluate($intake, $milestone, $state);
            $progress[$milestone->milestone_id] = array_merge($existing, [
                'milestone_id' => $milestone->milestone_id,
                'position' => (int) $milestone->position,
                'gates' => $gateResult['gates'],
                'evidence_present' => $gateResult['evidence_present'],
                'evidence_missing' => $gateResult['evidence_missing'],
                'failure_reasons' => $gateResult['failure_reasons'],
                'last_evaluated_at' => Carbon::now()->toISOString(),
            ]);
        }

        $state->milestone_progress = $progress;
    }

    /**
     * @param  array<int,mixed>  $existing
     * @param  array<int,mixed>  $incoming
     * @return array<int,array<string,mixed>>
     */
    private function mergeEvidenceRefs(array $existing, array $incoming): array
    {
        $byKey = [];
        foreach ($existing as $ref) {
            if (! is_array($ref)) {
                continue;
            }
            $byKey[$this->evidenceKey($ref)] = $ref;
        }
        foreach ($incoming as $ref) {
            if (! is_array($ref) || ! isset($ref['kind'])) {
                continue;
            }
            $normalized = [
                'kind' => (string) $ref['kind'],
                'ref' => $ref['ref'] ?? null,
                'hash' => $ref['hash'] ?? null,
                'source' => $ref['source'] ?? null,
                'attached_at' => $ref['attached_at'] ?? Carbon::now()->toISOString(),
            ];
            $byKey[$this->evidenceKey($normalized)] = $normalized;
        }

        return array_values($byKey);
    }

    /**
     * @param  array<string,mixed>  $ref
     */
    private function evidenceKey(array $ref): string
    {
        $kind = (string) ($ref['kind'] ?? 'unknown');
        $ident = (string) ($ref['hash'] ?? $ref['ref'] ?? '');

        return $kind.'::'.$ident;
    }

    /**
     * @param  array<int,mixed>  $existing
     * @param  array<int,mixed>  $incoming
     * @return array<int,array<string,mixed>>
     */
    private function mergeBlockers(array $existing, array $incoming): array
    {
        $byKey = [];
        foreach ($existing as $b) {
            if (! is_array($b)) {
                continue;
            }
            $byKey[$this->blockerKey($b)] = $b;
        }
        foreach ($incoming as $b) {
            if (! is_array($b) || ! isset($b['reason'])) {
                continue;
            }
            $normalized = [
                'scope' => (string) ($b['scope'] ?? ForgeLongHorizonStateCanon::BLOCKER_SCOPE_MILESTONE),
                'target' => $b['target'] ?? null,
                'reason' => (string) $b['reason'],
                'detail' => $b['detail'] ?? null,
                'since' => $b['since'] ?? Carbon::now()->toISOString(),
                'resolved' => (bool) ($b['resolved'] ?? false),
            ];
            $byKey[$this->blockerKey($normalized)] = $normalized;
        }

        return array_values($byKey);
    }

    /**
     * @param  array<string,mixed>  $blocker
     */
    private function blockerKey(array $blocker): string
    {
        return ($blocker['scope'] ?? 'milestone').'::'.($blocker['target'] ?? '').'::'.($blocker['reason'] ?? '');
    }

    /**
     * @param  array<int,mixed>  $blockers
     * @param  array<int,mixed>  $reasons
     * @return array<int,array<string,mixed>>
     */
    private function resolveBlockers(array $blockers, array $reasons): array
    {
        $reasonSet = array_values(array_filter(
            $reasons,
            static fn ($r): bool => is_string($r) && $r !== '',
        ));
        if ($reasonSet === []) {
            return array_values(array_filter($blockers, 'is_array'));
        }

        $out = [];
        foreach ($blockers as $b) {
            if (! is_array($b)) {
                continue;
            }
            if (in_array((string) ($b['reason'] ?? ''), $reasonSet, true)) {
                $b['resolved'] = true;
                $b['resolved_at'] = Carbon::now()->toISOString();
            }
            $out[] = $b;
        }

        return $out;
    }

    /**
     * @return array<string,mixed>
     */
    private function computeInitialNextAction(
        AiForgeIntake $intake,
        ?string $currentMilestone,
        ?string $blockerReason,
    ): array {
        if ($intake->status === ForgeIntakeCanon::STATUS_BLOCKED) {
            return [
                'kind' => ForgeLongHorizonStateCanon::NEXT_ACTION_RESOLVE_INTAKE_BLOCKER,
                'target' => $intake->id,
                'reason' => $blockerReason ?? 'intake_blocked',
                'due_at' => null,
            ];
        }

        return [
            'kind' => ForgeLongHorizonStateCanon::NEXT_ACTION_ATTACH_EVIDENCE,
            'target' => $currentMilestone,
            'reason' => 'milestone_'.($currentMilestone ?? 'unknown').'_needs_evidence',
            'due_at' => null,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function computeNextAction(AiForgeLongHorizonState $state): array
    {
        if ($state->status === ForgeLongHorizonStateCanon::STATUS_COMPLETED) {
            return [
                'kind' => ForgeLongHorizonStateCanon::NEXT_ACTION_OBRA_COMPLETED,
                'target' => $state->intake_id,
                'reason' => 'certification_passed',
                'due_at' => null,
            ];
        }

        $unresolvedBlockers = array_values(array_filter(
            (array) ($state->blockers ?? []),
            static fn ($b): bool => is_array($b) && ($b['resolved'] ?? false) !== true,
        ));
        if ($unresolvedBlockers !== []) {
            $top = $unresolvedBlockers[0];
            $isIntake = ($top['scope'] ?? null) === ForgeLongHorizonStateCanon::BLOCKER_SCOPE_INTAKE;

            return [
                'kind' => $isIntake
                    ? ForgeLongHorizonStateCanon::NEXT_ACTION_RESOLVE_INTAKE_BLOCKER
                    : ForgeLongHorizonStateCanon::NEXT_ACTION_RESOLVE_BLOCKER,
                'target' => $top['target'] ?? null,
                'reason' => $top['reason'] ?? 'blocked',
                'due_at' => null,
            ];
        }

        $currentId = $state->current_milestone;
        $progress = (array) ($state->milestone_progress ?? []);
        $currentProgress = is_array($progress[$currentId] ?? null) ? $progress[$currentId] : null;

        if ($currentId === null) {
            return [
                'kind' => ForgeLongHorizonStateCanon::NEXT_ACTION_OBRA_COMPLETED,
                'target' => $state->intake_id,
                'reason' => 'no_current_milestone',
                'due_at' => null,
            ];
        }

        $missingEvidence = (array) ($currentProgress['evidence_missing'] ?? []);
        if ($missingEvidence !== []) {
            $first = (string) $missingEvidence[0];

            return [
                'kind' => ForgeLongHorizonStateCanon::NEXT_ACTION_ATTACH_EVIDENCE,
                'target' => $currentId,
                'reason' => 'missing_evidence:'.$first,
                'evidence_kind' => $first,
                'due_at' => null,
            ];
        }

        $failureReasons = (array) ($currentProgress['failure_reasons'] ?? []);
        if (in_array('work_packets_scoped:no_work_packets', $failureReasons, true)
            || in_array('work_packets_scoped', $failureReasons, true)) {
            return [
                'kind' => ForgeLongHorizonStateCanon::NEXT_ACTION_SCOPE_WORK_PACKETS,
                'target' => $currentId,
                'reason' => 'no_work_packets',
                'due_at' => null,
            ];
        }

        $allGatesPassed = collect($currentProgress['gates'] ?? [])->every(
            fn ($g): bool => is_array($g) && ($g['status'] ?? null) === ForgeLongHorizonStateCanon::GATE_STATUS_PASSED,
        );

        if ($allGatesPassed && $missingEvidence === []) {
            if ($currentId === ForgeIntakeCanon::MILESTONE_CERTIFICATION) {
                return [
                    'kind' => ForgeLongHorizonStateCanon::NEXT_ACTION_COMPLETE_OBRA,
                    'target' => $state->intake_id,
                    'reason' => 'certification_gate_passed',
                    'due_at' => null,
                ];
            }

            return [
                'kind' => ForgeLongHorizonStateCanon::NEXT_ACTION_ADVANCE_MILESTONE,
                'target' => $currentId,
                'reason' => 'gates_green',
                'due_at' => null,
            ];
        }

        if ($currentId === ForgeIntakeCanon::MILESTONE_IMPLEMENTATION
            && (array) ($state->active_work_packets ?? []) === []
            && (array) ($state->completed_work_packets ?? []) === []) {
            return [
                'kind' => ForgeLongHorizonStateCanon::NEXT_ACTION_CLAIM_WORK_PACKET,
                'target' => $currentId,
                'reason' => 'no_active_or_completed_packets',
                'due_at' => null,
            ];
        }

        return [
            'kind' => ForgeLongHorizonStateCanon::NEXT_ACTION_ATTACH_EVIDENCE,
            'target' => $currentId,
            'reason' => 'milestone_gate_not_green',
            'due_at' => null,
        ];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function summariseGatesRun(AiForgeLongHorizonState $state): array
    {
        $summary = [];
        foreach ((array) ($state->milestone_progress ?? []) as $milestoneId => $entry) {
            if (! is_array($entry)) {
                continue;
            }
            $summary[] = [
                'milestone_id' => $milestoneId,
                'status' => $entry['status'] ?? null,
                'failure_reasons' => array_values((array) ($entry['failure_reasons'] ?? [])),
            ];
        }

        return $summary;
    }

    private function computeStateHash(AiForgeLongHorizonState $state): string
    {
        return MissionCanonicalHash::sha256([
            'schema' => ForgeLongHorizonStateCanon::SCHEMA_VERSION,
            'uuid' => $state->uuid,
            'intake_id' => $state->intake_id,
            'status' => $state->status,
            'current_milestone' => $state->current_milestone,
            'milestone_progress' => (array) ($state->milestone_progress ?? []),
            'active_work_packets' => array_values((array) ($state->active_work_packets ?? [])),
            'completed_work_packets' => array_values((array) ($state->completed_work_packets ?? [])),
            'blockers' => array_values((array) ($state->blockers ?? [])),
            'evidence_refs' => array_values((array) ($state->evidence_refs ?? [])),
            'next_action' => (array) ($state->next_action ?? []),
            'cycle_count' => (int) $state->cycle_count,
            'continuation_context_hash' => $state->continuation_context_hash,
            'blocker_reason' => $state->blocker_reason,
            'completed_at' => $state->completed_at?->toISOString(),
        ]);
    }

    public function autoInitializeForWorkPackets(AiForgeLongHorizonState $state): AiForgeLongHorizonState
    {
        // Convenience: when caller has not yet supplied active_work_packets,
        // seed them from intake's proposed/ready packets so the next-action
        // logic can move past `claim_work_packet`. Intentionally pure additive
        // — if active already set, leave alone.
        if ((array) ($state->active_work_packets ?? []) !== []) {
            return $state;
        }
        $packets = AiForgeWorkPacket::query()
            ->where('intake_id', $state->intake_id)
            ->whereIn('status', [
                ForgeIntakeCanon::PACKET_STATUS_PROPOSED,
                ForgeIntakeCanon::PACKET_STATUS_READY,
                ForgeIntakeCanon::PACKET_STATUS_CLAIMED,
            ])
            ->orderBy('packet_position')
            ->pluck('packet_id')
            ->all();
        if ($packets === []) {
            return $state;
        }
        $state->active_work_packets = $packets;
        $state->next_action = $this->computeNextAction($state);
        $state->state_hash = $this->computeStateHash($state);
        $state->save();

        return $state;
    }
}
