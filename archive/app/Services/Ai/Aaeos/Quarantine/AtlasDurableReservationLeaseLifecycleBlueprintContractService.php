<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Quarantine;

use App\Services\Ai\Aaeos\Generated\Concerns\AssertGuaranteeHeld;

/**
 * Atlas Self-Construction Durable Reservation Lease Lifecycle Blueprint Contract
 * — pure, deterministic, READ-ONLY surface.
 *
 * This is the future lease lifecycle decider that future runtime can convert
 * into actual state handling "without guessing transitions, timing rules or
 * completion semantics". It does not persist reservation state, write storage,
 * create migrations or dispatch work; per the doc, generating this blueprint is
 * itself read-only. Every result therefore keeps the four non-execution
 * guarantee keys false — deciding a transition is never the act of claiming.
 *
 * It is the lifecycle sibling of {@see AtlasDurableReservationCollisionGuardContractService}
 * (which decides whether a single candidate may be CLAIMED). Here we govern what
 * a packet's lease may do AFTER it exists: claim, renew, release, expire,
 * complete, reclaim and block.
 *
 * Documented rules this code ENFORCES (not merely documents):
 *   - "Required States" => the seven canonical states (preview, claimed,
 *     renewed, released, expired, completed, blocked).
 *   - "Required Transitions" => decide() admits ONLY the ten documented
 *     transitions. Anything else is rejected. In particular:
 *       * preview --claim--> claimed;
 *       * claimed/renewed --renew--> renewed   (renew loops while active);
 *       * claimed/renewed --release--> released;
 *       * claimed/renewed --expire--> expired;
 *       * claimed/renewed --complete--> completed;
 *       * released/expired --reclaim--> claimed BY A NEW OWNER;
 *       * any state --block--> blocked when a guard rejects the action.
 *   - "Timing Rules" (enforced, not described):
 *       * renew requires the SAME owner/session AND an active (non-terminal,
 *         non-expired) lease;
 *       * release requires the SAME owner/session AND an active lease;
 *       * expiry may be system-driven (no owner match required) and always
 *         appends an `expired` event;
 *       * completion requires the SAME owner, an active lease AND a passing
 *         completion gate;
 *       * expired OR released leases cannot complete.
 *   - "Reclaim semantics": a released/expired packet may be reclaimed, and the
 *     reclaim is recorded as a NEW owner taking the lease (owner handover),
 *     never the prior owner silently resuming.
 *   - Completion Criteria => a deterministic read-only lease lifecycle blueprint
 *     plus assertGuaranteeHeld(), which proves no result flipped a guarantee key.
 *
 * The decider is fail-CLOSED: an action that is structurally legal but violates
 * a timing/ownership/gate rule is routed to `blocked` (documented transition
 * `any_to_blocked_when_guard_rejects_action`) with an explicit reason, rather
 * than silently allowed.
 *
 * @see docs/engineering-knowledge-base/self-construction/durable-reservation-lease-lifecycle-blueprint-contract.md
 */
final class AtlasDurableReservationLeaseLifecycleBlueprintContractService
{
    use AssertGuaranteeHeld;

    /** Stable evidence schema id this read-only surface emits. */
    public const SCHEMA = 'atlas.self_construction_durable_reservation_lease_lifecycle_blueprint_contract.v1';

    /** Surface label (closed set). */
    public const SURFACE = 'durable_reservation_lease_lifecycle_blueprint_contract';

    // --- Doc "Required States" ----------------------------------------------
    public const STATE_PREVIEW = 'preview';
    public const STATE_CLAIMED = 'claimed';
    public const STATE_RENEWED = 'renewed';
    public const STATE_RELEASED = 'released';
    public const STATE_EXPIRED = 'expired';
    public const STATE_COMPLETED = 'completed';
    public const STATE_BLOCKED = 'blocked';

    /**
     * The seven canonical states, in documented order.
     *
     * @var list<string>
     */
    public const STATES = [
        self::STATE_PREVIEW,
        self::STATE_CLAIMED,
        self::STATE_RENEWED,
        self::STATE_RELEASED,
        self::STATE_EXPIRED,
        self::STATE_COMPLETED,
        self::STATE_BLOCKED,
    ];

    /**
     * Terminal states: no further lifecycle action is admitted from these
     * EXCEPT reclaim (from released/expired). `completed` and `blocked` are
     * absorbing.
     *
     * @var list<string>
     */
    public const TERMINAL_STATES = [
        self::STATE_RELEASED,
        self::STATE_EXPIRED,
        self::STATE_COMPLETED,
        self::STATE_BLOCKED,
    ];

    /**
     * States from which an owner may still renew/release/expire/complete — the
     * "active lease" set used by the timing rules.
     *
     * @var list<string>
     */
    public const ACTIVE_STATES = [
        self::STATE_CLAIMED,
        self::STATE_RENEWED,
    ];

    /**
     * States a released/expired lease may be reclaimed from.
     *
     * @var list<string>
     */
    public const RECLAIMABLE_STATES = [
        self::STATE_RELEASED,
        self::STATE_EXPIRED,
    ];

    // --- Lifecycle actions ---------------------------------------------------
    public const ACTION_CLAIM = 'claim';
    public const ACTION_RENEW = 'renew';
    public const ACTION_RELEASE = 'release';
    public const ACTION_EXPIRE = 'expire';
    public const ACTION_COMPLETE = 'complete';
    public const ACTION_RECLAIM = 'reclaim';
    public const ACTION_BLOCK = 'block';

    /**
     * Doc "Required Transitions" as the canonical allow-list:
     * from_state => [action => to_state]. `block` is handled separately because
     * it is admissible from ANY state (`any_to_blocked_when_guard_rejects_action`).
     *
     * @var array<string,array<string,string>>
     */
    public const TRANSITIONS = [
        self::STATE_PREVIEW => [
            self::ACTION_CLAIM => self::STATE_CLAIMED,        // preview_to_claimed
        ],
        self::STATE_CLAIMED => [
            self::ACTION_RENEW => self::STATE_RENEWED,        // claimed_to_renewed
            self::ACTION_RELEASE => self::STATE_RELEASED,     // claimed_to_released
            self::ACTION_EXPIRE => self::STATE_EXPIRED,       // claimed_to_expired
            self::ACTION_COMPLETE => self::STATE_COMPLETED,   // claimed_to_completed
        ],
        self::STATE_RENEWED => [
            self::ACTION_RENEW => self::STATE_RENEWED,        // renewed loops while active
            self::ACTION_RELEASE => self::STATE_RELEASED,     // renewed_to_released
            self::ACTION_EXPIRE => self::STATE_EXPIRED,       // renewed_to_expired
            self::ACTION_COMPLETE => self::STATE_COMPLETED,   // renewed_to_completed
        ],
        self::STATE_RELEASED => [
            self::ACTION_RECLAIM => self::STATE_CLAIMED,      // released_..._to_claimed_by_new_owner
        ],
        self::STATE_EXPIRED => [
            self::ACTION_RECLAIM => self::STATE_CLAIMED,      // expired_..._to_claimed_by_new_owner
        ],
    ];

    /** The only completion gate status that admits completion. */
    public const COMPLETION_GATE_GREEN = 'green';

    /** Decision verdicts. */
    public const VERDICT_ALLOW = 'allow';
    public const VERDICT_BLOCK = 'block';

    /** Reject reasons (closed set) used when an action is routed to blocked. */
    public const REASON_UNKNOWN_STATE = 'unknown_state';
    public const REASON_UNKNOWN_ACTION = 'unknown_action';
    public const REASON_TRANSITION_NOT_ALLOWED = 'transition_not_allowed';
    public const REASON_OWNER_MISMATCH = 'owner_mismatch';
    public const REASON_LEASE_NOT_ACTIVE = 'lease_not_active';
    public const REASON_COMPLETION_GATE_NOT_GREEN = 'completion_gate_not_green';
    public const REASON_RECLAIM_REQUIRES_NEW_OWNER = 'reclaim_requires_new_owner';

    /**
     * Non-execution guarantee keys (doc: blueprint generation is read-only and
     * cannot create runtime files, write storage, persist claims or dispatch
     * work). Every result keeps all of these false.
     *
     * @var list<string>
     */
    public const GUARANTEE_KEYS = [
        'runtime_files_created',
        'storage_writes_performed',
        'claims_persisted',
        'work_dispatched',
    ];

    /**
     * The four documented non-execution guarantee keys, all forced false.
     *
     * @return array<string,false>
     */
    public function guarantee(): array
    {
        $out = [];
        foreach (self::GUARANTEE_KEYS as $key) {
            $out[$key] = false;
        }

        return $out;
    }

    /**
     * Primary surface: decide a single lease lifecycle transition.
     *
     * Validates the action against the documented transition allow-list, then
     * enforces the timing/ownership/gate rules. An admissible transition that
     * violates a rule is fail-closed to `blocked` (the documented
     * `any_to_blocked_when_guard_rejects_action`) carrying the rejected target
     * and reason. No claim, storage write, migration or dispatch is produced.
     *
     * @param array{
     *   from_state?:string,
     *   action?:string,
     *   current_owner_session_id?:string,
     *   actor_session_id?:string,
     *   lease_expired?:bool,
     *   completion_gate_status?:string,
     *   system_driven?:bool
     * } $input
     * @return array{
     *   surface:string, schema:string,
     *   from_state:?string, action:?string,
     *   verdict:string, to_state:string,
     *   intended_to_state:?string,
     *   appended_event:?string,
     *   owner_handover:bool,
     *   reasons:list<string>,
     *   guarantee:array<string,false>,
     *   claim_persisted:false, is_execution:false
     * }
     */
    public function decide(array $input = []): array
    {
        $from = is_string($input['from_state'] ?? null) ? $input['from_state'] : null;
        $action = is_string($input['action'] ?? null) ? $input['action'] : null;

        $currentOwner = is_string($input['current_owner_session_id'] ?? null) ? $input['current_owner_session_id'] : null;
        $actor = is_string($input['actor_session_id'] ?? null) ? $input['actor_session_id'] : null;
        $leaseExpired = ($input['lease_expired'] ?? false) === true;
        $systemDriven = ($input['system_driven'] ?? false) === true;
        $gate = is_string($input['completion_gate_status'] ?? null) ? $input['completion_gate_status'] : null;

        $reasons = [];
        $intendedTo = null;

        // --- structural validation against the transition allow-list ---------
        if ($from === null || ! in_array($from, self::STATES, true)) {
            $reasons[] = self::REASON_UNKNOWN_STATE;
        }
        if ($action === null || ! in_array($action, $this->knownActions(), true)) {
            $reasons[] = self::REASON_UNKNOWN_ACTION;
        }

        // `block` is admissible from ANY known state.
        $structurallyOk = $reasons === [];
        if ($structurallyOk && $action === self::ACTION_BLOCK) {
            return $this->result($from, $action, self::VERDICT_BLOCK, self::STATE_BLOCKED, self::STATE_BLOCKED, self::STATE_BLOCKED, false, []);
        }

        if ($structurallyOk) {
            /** @var string $from */
            /** @var string $action */
            $intendedTo = self::TRANSITIONS[$from][$action] ?? null;
            if ($intendedTo === null) {
                $reasons[] = self::REASON_TRANSITION_NOT_ALLOWED;
            }
        }

        // A structurally-illegal request is blocked outright (no event appended).
        if ($reasons !== [] || $intendedTo === null) {
            return $this->result($from, $action, self::VERDICT_BLOCK, self::STATE_BLOCKED, $intendedTo, null, false, $reasons);
        }

        // --- timing / ownership / gate rules (fail-closed) -------------------
        /** @var string $from */
        /** @var string $action */
        $ruleReasons = $this->timingViolations($from, $action, $currentOwner, $actor, $leaseExpired, $systemDriven, $gate);
        if ($ruleReasons !== []) {
            // Documented transition: any_to_blocked_when_guard_rejects_action.
            return $this->result($from, $action, self::VERDICT_BLOCK, self::STATE_BLOCKED, $intendedTo, null, false, $ruleReasons);
        }

        // --- allowed: derive appended event + owner handover -----------------
        $appended = $this->appendedEvent($action, $intendedTo);
        $ownerHandover = $action === self::ACTION_RECLAIM;

        return $this->result($from, $action, self::VERDICT_ALLOW, $intendedTo, $intendedTo, $appended, $ownerHandover, []);
    }

    /**
     * Enforce the documented Timing Rules for an already structurally-valid
     * transition. Returns the list of violated rule reasons (empty = clean).
     *
     * @return list<string>
     */
    public function timingViolations(
        string $from,
        string $action,
        ?string $currentOwner,
        ?string $actor,
        bool $leaseExpired,
        bool $systemDriven,
        ?string $gate,
    ): array {
        $reasons = [];

        // Owner-bound, active-lease actions: renew, release, complete.
        if (in_array($action, [self::ACTION_RENEW, self::ACTION_RELEASE, self::ACTION_COMPLETE], true)) {
            if (! $this->isActiveLease($from, $leaseExpired)) {
                $reasons[] = self::REASON_LEASE_NOT_ACTIVE;
            }
            if (! $this->sameOwner($currentOwner, $actor)) {
                $reasons[] = self::REASON_OWNER_MISMATCH;
            }
        }

        // Completion additionally requires a green completion gate.
        if ($action === self::ACTION_COMPLETE && $gate !== self::COMPLETION_GATE_GREEN) {
            $reasons[] = self::REASON_COMPLETION_GATE_NOT_GREEN;
        }

        // Expiry may be system-driven; if an actor drives it, no owner match is
        // required, but it must operate on an active lease (you cannot expire a
        // released/completed/blocked lease — that path is not in TRANSITIONS and
        // would already have been rejected structurally). The flag is accepted
        // so future runtime records provenance.
        unset($systemDriven);

        // Reclaim must hand the lease to a DIFFERENT owner than the prior one;
        // the prior owner silently resuming is rejected.
        if ($action === self::ACTION_RECLAIM && $this->sameOwner($currentOwner, $actor) && $currentOwner !== null) {
            $reasons[] = self::REASON_RECLAIM_REQUIRES_NEW_OWNER;
        }

        return $reasons;
    }

    /**
     * Whether a state counts as an active (renewable/releasable/completable)
     * lease for the timing rules: it must be in the active set AND not expired.
     */
    public function isActiveLease(string $state, bool $leaseExpired): bool
    {
        return in_array($state, self::ACTIVE_STATES, true) && ! $leaseExpired;
    }

    /**
     * Whether a packet in the given state can be completed by the given actor
     * under the given gate. Encodes the doc rule "expired or released leases
     * cannot complete" and "completion requires same owner, active lease and
     * passing completion gate".
     */
    public function canComplete(string $state, ?string $currentOwner, ?string $actor, bool $leaseExpired, ?string $gate): bool
    {
        return $this->timingViolations(
            $state,
            self::ACTION_COMPLETE,
            $currentOwner,
            $actor,
            $leaseExpired,
            false,
            $gate,
        ) === [] && (self::TRANSITIONS[$state][self::ACTION_COMPLETE] ?? null) === self::STATE_COMPLETED;
    }

    /**
     * Whether a packet can be reclaimed from the given state by a new owner.
     * Encodes "expired or released leases can be reclaimed by a new owner".
     */
    public function canReclaim(string $state, ?string $priorOwner, ?string $newOwner): bool
    {
        if (! in_array($state, self::RECLAIMABLE_STATES, true)) {
            return false;
        }

        return $this->timingViolations($state, self::ACTION_RECLAIM, $priorOwner, $newOwner, false, false, null) === [];
    }

    /**
     * The read-only lifecycle BLUEPRINT: the full state/transition/timing
     * specification future runtime converts into handling — emitted as data so
     * it is checkable and never guessed.
     *
     * @return array{
     *   surface:string, schema:string,
     *   states:list<string>,
     *   active_states:list<string>,
     *   terminal_states:list<string>,
     *   reclaimable_states:list<string>,
     *   transitions:list<array{from:string,action:string,to:string}>,
     *   timing_rules:list<string>,
     *   completion_gate_required:string,
     *   guarantee:array<string,false>,
     *   claim_persisted:false, is_execution:false
     * }
     */
    public function blueprint(): array
    {
        $transitions = [];
        foreach (self::TRANSITIONS as $from => $actions) {
            foreach ($actions as $action => $to) {
                $transitions[] = ['from' => $from, 'action' => $action, 'to' => $to];
            }
        }
        // any-state block transition (documented).
        $transitions[] = ['from' => '*', 'action' => self::ACTION_BLOCK, 'to' => self::STATE_BLOCKED];

        return [
            'surface' => self::SURFACE,
            'schema' => self::SCHEMA,
            'states' => self::STATES,
            'active_states' => self::ACTIVE_STATES,
            'terminal_states' => self::TERMINAL_STATES,
            'reclaimable_states' => self::RECLAIMABLE_STATES,
            'transitions' => $transitions,
            'timing_rules' => [
                'default_lease_duration_from_config_or_policy',
                'renew_requires_same_owner_and_active_lease',
                'release_requires_same_owner_and_active_lease',
                'expiry_may_be_system_driven_and_appends_event',
                'completion_requires_same_owner_active_lease_and_green_gate',
                'expired_or_released_lease_cannot_complete',
                'reclaim_requires_new_owner',
            ],
            'completion_gate_required' => self::COMPLETION_GATE_GREEN,
            'guarantee' => $this->guarantee(),
            'claim_persisted' => false,
            'is_execution' => false,
        ];
    }

    /**
     * All known lifecycle actions (transition actions plus the any-state block).
     *
     * @return list<string>
     */
    private function knownActions(): array
    {
        return [
            self::ACTION_CLAIM,
            self::ACTION_RENEW,
            self::ACTION_RELEASE,
            self::ACTION_EXPIRE,
            self::ACTION_COMPLETE,
            self::ACTION_RECLAIM,
            self::ACTION_BLOCK,
        ];
    }

    /** Same-owner check that treats two missing owners as NOT a match. */
    private function sameOwner(?string $currentOwner, ?string $actor): bool
    {
        return $currentOwner !== null && $actor !== null && $currentOwner === $actor;
    }

    /**
     * The event appended for an allowed transition (doc: expiry appends an
     * event; every accepted transition records one so runtime has an audit
     * trail). Returns the destination-state event token.
     */
    private function appendedEvent(string $action, string $to): string
    {
        return match ($action) {
            self::ACTION_CLAIM => 'lease_claimed',
            self::ACTION_RENEW => 'lease_renewed',
            self::ACTION_RELEASE => 'lease_released',
            self::ACTION_EXPIRE => 'lease_expired',
            self::ACTION_COMPLETE => 'lease_completed',
            self::ACTION_RECLAIM => 'lease_reclaimed',
            default => $to,
        };
    }

    /**
     * Assemble a uniform decision result.
     *
     * @param list<string> $reasons
     * @return array{
     *   surface:string, schema:string,
     *   from_state:?string, action:?string,
     *   verdict:string, to_state:string,
     *   intended_to_state:?string,
     *   appended_event:?string,
     *   owner_handover:bool,
     *   reasons:list<string>,
     *   guarantee:array<string,false>,
     *   claim_persisted:false, is_execution:false
     * }
     */
    private function result(
        ?string $from,
        ?string $action,
        string $verdict,
        string $toState,
        ?string $intendedTo,
        ?string $appended,
        bool $ownerHandover,
        array $reasons,
    ): array {
        return [
            'surface' => self::SURFACE,
            'schema' => self::SCHEMA,
            'from_state' => $from,
            'action' => $action,
            'verdict' => $verdict,
            'to_state' => $toState,
            'intended_to_state' => $intendedTo,
            'appended_event' => $appended,
            'owner_handover' => $ownerHandover,
            'reasons' => $reasons,
            'guarantee' => $this->guarantee(),
            // Restated per the doc's hard non-execution guarantee.
            'claim_persisted' => false,
            'is_execution' => false,
        ];
    }
}
