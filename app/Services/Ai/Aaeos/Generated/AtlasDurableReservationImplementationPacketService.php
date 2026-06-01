<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Generated;

/**
 * Atlas Self-Construction Durable Reservation IMPLEMENTATION PACKET — pure,
 * deterministic, READ-ONLY decider that encodes the implementation-packet doc as
 * runtime law. The doc is the read-only packet a FUTURE AI must consume to START
 * durable reservation implementation, but only AFTER approval is signed and the
 * post-approval preflight has passed. Until then the packet stays BLOCKED.
 *
 * This service is the ORDER + GATES + HARD-LIMITS authority for the build, and is
 * distinct from its siblings: {@see AtlasDurableReservationStorageSchemaService}
 * pins the table/column shape; {@see AtlasDurableReservationPostApprovalPreflightService}
 * runs the final "still safe to start?" check; this one pins the SIX ordered
 * packets, the SEVEN required gates, the FOUR hard limits and the single
 * Completion Criterion ("a deterministic read-only packet that remains blocked
 * until approval and preflight pass"). It authorizes NO migration, NO storage
 * write, NO claim completion and NO dispatch.
 *
 * Documented rules this code ENFORCES (not merely documents):
 *
 *   - "Packet Order" => packetOrder() returns the closed SIX-step ordered set
 *     (storage → repository → projection → collision guard → lease lifecycle →
 *     multi-session). nextStep() returns the EARLIEST incomplete step in that
 *     exact order; an out-of-order completion claim (step N done while an earlier
 *     step is open) is rejected, so the build cannot skip ahead.
 *
 *   - "Required Gates" => requiredGates() returns the closed SEVEN-gate set;
 *     gatesSatisfied() is true only when EVERY gate passed and lists every
 *     missing gate otherwise — the packet cannot release with a gate open.
 *
 *   - "Hard Limits" => checkHardLimits() runs the FOUR documented prohibitions
 *     IN ORDER and stops at the EARLIEST violation: no dispatch implementation;
 *     no Voice/Kernel file edits; no claim completion without the packet
 *     completion gate; no migration/storage work unless a signed approval AND a
 *     passed preflight exist. Any violation makes the packet `blocked`.
 *
 *   - "Completion Criteria" + the packet-level block => evaluate() returns
 *     `blocked` whenever approval is unsigned OR preflight has not passed (the
 *     read-only default), regardless of anything else. Only when BOTH hold, no
 *     hard limit is violated AND every gate passed does it return
 *     `ready_to_complete`; if the prerequisites hold but gates/steps remain it
 *     returns `in_progress`.
 *
 * Every decision keeps the doc's read-only guarantee: emitting this packet is
 * never creating a migration, writing storage, completing a claim, dispatching,
 * executing a change, or editing a hot Voice/Kernel scope.
 *
 * @see docs/engineering-knowledge-base/self-construction/durable-reservation-implementation-packet.md
 */
final class AtlasDurableReservationImplementationPacketService
{
    /** Stable evidence schema id this read-only surface emits. */
    public const SCHEMA = 'atlas.self_construction_durable_reservation_implementation_packet.v1';

    /** Surface label (closed set). */
    public const SURFACE = 'durable_reservation_implementation_packet';

    // ---- Doc "Packet Order" — closed six-step ordered set, documented order ----
    public const STEP_STORAGE = 'storage_contract_and_migrations';

    public const STEP_EVENT_REPOSITORY = 'append_only_reservation_event_repository';

    public const STEP_PROJECTION = 'current_reservation_projection';

    public const STEP_COLLISION_GUARD = 'scope_collision_and_hot_scope_guard';

    public const STEP_LEASE_LIFECYCLE = 'lease_renewal_release_and_expiry';

    public const STEP_MULTI_SESSION = 'multi_session_readiness_integration';

    /** @var list<string> */
    public const PACKET_ORDER = [
        self::STEP_STORAGE,
        self::STEP_EVENT_REPOSITORY,
        self::STEP_PROJECTION,
        self::STEP_COLLISION_GUARD,
        self::STEP_LEASE_LIFECYCLE,
        self::STEP_MULTI_SESSION,
    ];

    // ---- Doc "Required Gates" — closed seven-gate set, documented order ----
    public const GATE_FOCUSED_TESTS = 'focused_self_construction_tests';

    public const GATE_DUPLICATE_CLAIM_TESTS = 'duplicate_claim_tests';

    public const GATE_COLLISION_TESTS = 'collision_tests';

    public const GATE_EXPIRY_RELEASE_TESTS = 'expiry_and_release_tests';

    public const GATE_DOCS_HEALTH = 'docs_health';

    public const GATE_ARCHITECTURE_VALIDATE = 'architecture_validate';

    public const GATE_GIT_DIFF_CHECK = 'git_diff_check';

    /** @var list<string> */
    public const REQUIRED_GATES = [
        self::GATE_FOCUSED_TESTS,
        self::GATE_DUPLICATE_CLAIM_TESTS,
        self::GATE_COLLISION_TESTS,
        self::GATE_EXPIRY_RELEASE_TESTS,
        self::GATE_DOCS_HEALTH,
        self::GATE_ARCHITECTURE_VALIDATE,
        self::GATE_GIT_DIFF_CHECK,
    ];

    // ---- Doc "Hard Limits" — closed four-prohibition set, documented order ----
    public const LIMIT_NO_DISPATCH = 'no_dispatch_implementation';

    public const LIMIT_NO_VOICE_KERNEL_EDITS = 'no_voice_or_kernel_file_edits';

    public const LIMIT_NO_COMPLETION_WITHOUT_GATE = 'no_claim_completion_without_packet_completion_gate';

    public const LIMIT_NO_STORAGE_WITHOUT_APPROVAL = 'no_migration_or_storage_without_approval_and_preflight';

    /** @var list<string> */
    public const HARD_LIMITS = [
        self::LIMIT_NO_DISPATCH,
        self::LIMIT_NO_VOICE_KERNEL_EDITS,
        self::LIMIT_NO_COMPLETION_WITHOUT_GATE,
        self::LIMIT_NO_STORAGE_WITHOUT_APPROVAL,
    ];

    /**
     * Hot scopes the packet declares permanently off-limits ("No Voice/Kernel file
     * edits"). A touched file under any of these fires LIMIT_NO_VOICE_KERNEL_EDITS.
     *
     * @var list<string>
     */
    public const HOT_SCOPES = [
        'app/Services/Ai/Voice/',
        'app/Services/Ai/Vox/',
        'app/Services/Ai/Kernel/',
    ];

    /** Status: prerequisites missing OR a hard limit fired — packet stays blocked. */
    public const STATUS_BLOCKED = 'blocked';

    /** Status: approval + preflight hold, no limit fired, but gates/steps remain. */
    public const STATUS_IN_PROGRESS = 'in_progress';

    /** Status: approval + preflight hold, no limit fired, all gates + steps done. */
    public const STATUS_READY_TO_COMPLETE = 'ready_to_complete';

    /**
     * The Non Goal flags every read-only surface forces false. Mirrors the doc
     * "Hard Limits": no dispatch, no storage/migration creation, no claim
     * completion (this surface never performs it), and emitting the packet is not
     * execution.
     *
     * @var list<string>
     */
    public const NON_GOAL_KEYS = [
        'is_execution',
        'grants_dispatch',
        'creates_migration',
        'writes_storage',
        'completes_claim',
        'edits_hot_scope',
    ];

    /**
     * Doc "Packet Order": the closed six-step ordered set.
     *
     * @return list<string>
     */
    public function packetOrder(): array
    {
        return self::PACKET_ORDER;
    }

    /**
     * Doc "Required Gates": the closed seven-gate set.
     *
     * @return list<string>
     */
    public function requiredGates(): array
    {
        return self::REQUIRED_GATES;
    }

    /**
     * Doc "Hard Limits": the closed four-prohibition set.
     *
     * @return list<string>
     */
    public function hardLimits(): array
    {
        return self::HARD_LIMITS;
    }

    /**
     * Doc "Packet Order": resolve the EARLIEST step that is not yet complete, in
     * the documented order. Steps are completed by an unordered set of step ids;
     * the FIRST step in PACKET_ORDER missing from that set is the next one. When
     * every step is present, there is no next step (null) and the order is done.
     *
     * Out-of-order completion is surfaced: if a later step is marked done while an
     * earlier one is still open, `out_of_order` lists the offending later steps so
     * the build cannot quietly skip ahead.
     *
     * @param  list<string>  $completedSteps  step ids reported complete
     * @return array{
     *   surface:string, schema:string,
     *   next_step:?string,
     *   completed_in_order:list<string>,
     *   out_of_order:list<string>,
     *   order_complete:bool,
     *   non_goals:array<string,false>
     * }
     */
    public function nextStep(array $completedSteps): array
    {
        $completed = $this->stringList($completedSteps);

        $next = null;
        $inOrder = [];
        $stalled = false;
        $outOfOrder = [];

        foreach (self::PACKET_ORDER as $step) {
            $isDone = in_array($step, $completed, true);

            if ($isDone && ! $stalled) {
                // contiguous run of completed steps from the front
                $inOrder[] = $step;

                continue;
            }

            if (! $isDone) {
                // first gap: this is the next step; everything after that IS done
                // is out of order
                if ($next === null) {
                    $next = $step;
                }
                $stalled = true;

                continue;
            }

            // $isDone but we are already past a gap => out of order
            $outOfOrder[] = $step;
        }

        return [
            'surface' => self::SURFACE,
            'schema' => self::SCHEMA,
            'next_step' => $next,
            'completed_in_order' => $inOrder,
            'out_of_order' => $outOfOrder,
            'order_complete' => $next === null && $outOfOrder === [],
            'non_goals' => $this->nonGoals(),
        ];
    }

    /**
     * Doc "Required Gates": the packet may not release while any gate is open. A
     * gate is satisfied only by an explicit truthy pass signal; missing / false /
     * wrong-typed is fail-closed.
     *
     * @param  array<string,mixed>  $gateResults  gate id => pass flag
     * @return array{
     *   surface:string, schema:string,
     *   satisfied:bool,
     *   passed:list<string>,
     *   missing:list<string>,
     *   non_goals:array<string,false>
     * }
     */
    public function gatesSatisfied(array $gateResults): array
    {
        $passed = [];
        $missing = [];

        foreach (self::REQUIRED_GATES as $gate) {
            if (($gateResults[$gate] ?? null) === true) {
                $passed[] = $gate;
            } else {
                $missing[] = $gate;
            }
        }

        return [
            'surface' => self::SURFACE,
            'schema' => self::SCHEMA,
            'satisfied' => $missing === [],
            'passed' => $passed,
            'missing' => $missing,
            'non_goals' => $this->nonGoals(),
        ];
    }

    /**
     * Doc "Hard Limits": run the four prohibitions IN ORDER and stop at the
     * EARLIEST violation. Pure and fail-closed.
     *
     * Order mirrors the documented list:
     *   1. LIMIT_NO_DISPATCH                 — dispatch implementation requested
     *   2. LIMIT_NO_VOICE_KERNEL_EDITS       — a touched file is a hot scope
     *   3. LIMIT_NO_COMPLETION_WITHOUT_GATE  — completion sought, gate not passed
     *   4. LIMIT_NO_STORAGE_WITHOUT_APPROVAL — storage/migration without approval
     *                                          AND preflight
     *
     * @param array{
     *   wants_dispatch?:bool,
     *   touched_files?:list<string>,
     *   wants_completion?:bool,
     *   packet_completion_gate_passed?:bool,
     *   wants_storage_or_migration?:bool,
     *   approval_signed?:bool,
     *   preflight_passed?:bool
     * } $intent
     * @return array{
     *   surface:string, schema:string,
     *   ok:bool,
     *   violated_limit:?string,
     *   reason:?string,
     *   hot_scope_matches:list<string>,
     *   non_goals:array<string,false>
     * }
     */
    public function checkHardLimits(array $intent = []): array
    {
        $touched = $this->stringList($intent['touched_files'] ?? null);

        $violated = null;
        $reason = null;
        $hotMatches = [];

        // 1. No dispatch implementation.
        if (($intent['wants_dispatch'] ?? false) === true) {
            $violated = self::LIMIT_NO_DISPATCH;
            $reason = 'dispatch_implementation_is_out_of_scope';
        }

        // 2. No Voice/Kernel file edits.
        if ($violated === null) {
            foreach ($touched as $file) {
                foreach (self::HOT_SCOPES as $scope) {
                    if ($scope !== '' && str_starts_with($file, $scope)) {
                        $hotMatches[] = $file;
                        break;
                    }
                }
            }
            if ($hotMatches !== []) {
                $violated = self::LIMIT_NO_VOICE_KERNEL_EDITS;
                $reason = 'voice_or_kernel_files_are_off_limits';
            }
        }

        // 3. No claim completion without the packet completion gate.
        if ($violated === null
            && ($intent['wants_completion'] ?? false) === true
            && ($intent['packet_completion_gate_passed'] ?? false) !== true) {
            $violated = self::LIMIT_NO_COMPLETION_WITHOUT_GATE;
            $reason = 'completion_requires_packet_completion_gate';
        }

        // 4. No migration/storage work unless signed approval AND preflight pass.
        if ($violated === null && ($intent['wants_storage_or_migration'] ?? false) === true) {
            $approved = ($intent['approval_signed'] ?? false) === true;
            $preflight = ($intent['preflight_passed'] ?? false) === true;
            if (! $approved || ! $preflight) {
                $violated = self::LIMIT_NO_STORAGE_WITHOUT_APPROVAL;
                $reason = 'storage_or_migration_requires_signed_approval_and_passed_preflight';
            }
        }

        return [
            'surface' => self::SURFACE,
            'schema' => self::SCHEMA,
            'ok' => $violated === null,
            'violated_limit' => $violated,
            'reason' => $reason,
            'hot_scope_matches' => $hotMatches,
            'non_goals' => $this->nonGoals(),
        ];
    }

    /**
     * Primary surface — Doc "Completion Criteria": emit the deterministic packet
     * decision. The packet is READ-ONLY by default and stays BLOCKED until BOTH a
     * signed approval AND a passed preflight exist. Even when they do, a fired hard
     * limit keeps it blocked; only with no violated limit AND every gate passed AND
     * the full step order complete is it `ready_to_complete`; otherwise the work is
     * cleared to proceed and is `in_progress`.
     *
     * @param array{
     *   approval_signed?:bool,
     *   preflight_passed?:bool,
     *   completed_steps?:list<string>,
     *   gate_results?:array<string,mixed>,
     *   wants_dispatch?:bool,
     *   touched_files?:list<string>,
     *   wants_completion?:bool,
     *   packet_completion_gate_passed?:bool,
     *   wants_storage_or_migration?:bool
     * } $state
     * @return array{
     *   surface:string, schema:string,
     *   status:string,
     *   blocked:bool,
     *   blocking_reason:?string,
     *   approval_signed:bool,
     *   preflight_passed:bool,
     *   packet_order:list<string>,
     *   next_step:?string,
     *   order:array<string,mixed>,
     *   gates:array<string,mixed>,
     *   hard_limits:array<string,mixed>,
     *   completion_criteria_met:bool,
     *   non_goals:array<string,false>
     * }
     */
    public function evaluate(array $state = []): array
    {
        $approved = ($state['approval_signed'] ?? false) === true;
        $preflight = ($state['preflight_passed'] ?? false) === true;

        $order = $this->nextStep($this->stringList($state['completed_steps'] ?? null));
        $gates = $this->gatesSatisfied(is_array($state['gate_results'] ?? null) ? $state['gate_results'] : []);

        // Hard limits are evaluated against the build intent. The approval/preflight
        // facts feed limit #4 (storage without approval).
        $limits = $this->checkHardLimits([
            'wants_dispatch' => (bool) ($state['wants_dispatch'] ?? false),
            'touched_files' => $this->stringList($state['touched_files'] ?? null),
            'wants_completion' => (bool) ($state['wants_completion'] ?? false),
            'packet_completion_gate_passed' => (bool) ($state['packet_completion_gate_passed'] ?? false),
            'wants_storage_or_migration' => (bool) ($state['wants_storage_or_migration'] ?? false),
            'approval_signed' => $approved,
            'preflight_passed' => $preflight,
        ]);

        $status = self::STATUS_BLOCKED;
        $blockingReason = null;

        if (! $approved || ! $preflight) {
            // Read-only default: the packet is blocked until BOTH hold.
            $blockingReason = ! $approved
                ? 'approval_not_signed'
                : 'preflight_not_passed';
        } elseif ($limits['ok'] !== true) {
            $blockingReason = $limits['violated_limit'];
        } else {
            $status = ($gates['satisfied'] === true && $order['order_complete'] === true)
                ? self::STATUS_READY_TO_COMPLETE
                : self::STATUS_IN_PROGRESS;
        }

        return [
            'surface' => self::SURFACE,
            'schema' => self::SCHEMA,
            'status' => $status,
            'blocked' => $status === self::STATUS_BLOCKED,
            'blocking_reason' => $blockingReason,
            'approval_signed' => $approved,
            'preflight_passed' => $preflight,
            'packet_order' => self::PACKET_ORDER,
            'next_step' => $order['next_step'],
            'order' => $order,
            'gates' => $gates,
            'hard_limits' => $limits,
            'completion_criteria_met' => $status === self::STATUS_READY_TO_COMPLETE,
            'non_goals' => $this->nonGoals(),
        ];
    }

    /**
     * Doc "Completion Criteria": the deterministic, fully specified read-only
     * implementation packet — order, gates and limits — that a future AI consumes.
     * `ready` is true only when all three closed sets are fully specified.
     *
     * @return array{
     *   surface:string, schema:string,
     *   packet_order:list<string>,
     *   required_gates:list<string>,
     *   hard_limits:list<string>,
     *   completion_criterion:string,
     *   ready:bool,
     *   non_goals:array<string,false>
     * }
     */
    public function packet(): array
    {
        return [
            'surface' => self::SURFACE,
            'schema' => self::SCHEMA,
            'packet_order' => self::PACKET_ORDER,
            'required_gates' => self::REQUIRED_GATES,
            'hard_limits' => self::HARD_LIMITS,
            'completion_criterion' => 'deterministic_read_only_packet_blocked_until_approval_and_preflight_pass',
            'ready' => $this->ready(),
            'non_goals' => $this->nonGoals(),
        ];
    }

    /**
     * The packet is COMPLETE (ready to hand to a future AI) only when every closed
     * set is specified: six ordered steps, seven required gates, four hard limits.
     */
    public function ready(): bool
    {
        return count(self::PACKET_ORDER) === 6
            && count(self::REQUIRED_GATES) === 7
            && count(self::HARD_LIMITS) === 4;
    }

    /**
     * The Non Goal guarantee: every read-only flag is false. Mirrors the doc
     * "Hard Limits" — no dispatch, no migration/storage creation, no claim
     * completion, no hot-scope edit, and emitting the packet is not execution.
     *
     * @return array<string,false>
     */
    public function nonGoals(): array
    {
        return array_fill_keys(self::NON_GOAL_KEYS, false);
    }

    /**
     * Fail-closed guarantee check across emitted decisions: no surface may report
     * it executed, created a migration, wrote storage, completed a claim or edited
     * a hot scope.
     *
     * @param  list<array<string,mixed>>  $results
     * @return list<string>  human-readable violations (empty => guarantee held)
     */
    public function assertGuaranteeHeld(array $results): array
    {
        $violations = [];

        foreach ($results as $i => $result) {
            $nonGoals = is_array($result['non_goals'] ?? null) ? $result['non_goals'] : null;
            if ($nonGoals === null) {
                $violations[] = "result[$i] missing non_goals";

                continue;
            }
            foreach (self::NON_GOAL_KEYS as $key) {
                if (($nonGoals[$key] ?? null) !== false) {
                    $violations[] = "result[$i].non_goals.$key is not false";
                }
            }
        }

        return $violations;
    }

    /**
     * Normalise a mixed value to a clean list of non-empty strings.
     *
     * @return list<string>
     */
    private function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $out = [];
        foreach ($value as $item) {
            if (is_string($item) && $item !== '') {
                $out[] = $item;
            }
        }

        return array_values($out);
    }
}
