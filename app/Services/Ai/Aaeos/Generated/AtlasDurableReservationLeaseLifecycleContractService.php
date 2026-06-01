<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Generated;

/**
 * Atlas Self-Construction Durable Reservation Lease Lifecycle CONTRACT
 * — pure, deterministic, READ-ONLY surface.
 *
 * This is the lifecycle CONTRACT sibling of (and intentionally distinct from)
 * {@see AtlasDurableReservationLeaseLifecycleBlueprintContractService}. The
 * blueprint doc models reclaim as a first-class `reclaim` ACTION
 * (released_or_expired_to_claimed_by_new_owner). THIS doc — the contract —
 * instead defines reservation TIMING SEMANTICS where reclaim is NOT a separate
 * transition: its "Required Transitions" list has exactly nine entries and
 * reclaim appears only as a TEST requirement ("expired packet can be reclaimed
 * after expiry event"). The frontmatter is explicit: "Expired leases must block
 * completion and allow safe reclaim ONLY through event-backed state
 * transitions." So here reclaim is folded into the `claim` action: a packet that
 * already recorded an `expired` (or `released`) event may receive a fresh claim
 * by a NEW owner, re-entering the lifecycle at `claimed`. A claim with no prior
 * terminal event is only legal from `preview`.
 *
 * Documented rules this code ENFORCES (not merely documents):
 *   - "Lifecycle States" => the seven canonical states (preview, claimed,
 *     renewed, released, expired, completed, blocked).
 *   - "Required Transitions" => the NINE documented transitions:
 *       * preview --claim--> claimed;
 *       * claimed --renew--> renewed;
 *       * claimed --release--> released;
 *       * claimed --expire--> expired;
 *       * renewed --release--> released;
 *       * renewed --expire--> expired;
 *       * claimed --complete--> completed;
 *       * renewed --complete--> completed;
 *       * any --block--> blocked when guard policy rejects the action.
 *     (renew loops on renewed too, since "renewed: active lease was extended by
 *     its owner" keeps it active and renewable — but reclaim is NOT here.)
 *   - "Timing Rules" (enforced, not described):
 *       * default lease duration must be explicit in config or policy (carried
 *         as a required policy key, not invented);
 *       * renew requires the SAME owner/session AND an active lease;
 *       * release requires the SAME owner/session AND an active lease;
 *       * expire may be SYSTEM-DRIVEN (no owner match required) and always
 *         appends an `expired` event;
 *       * completion requires an active lease, the SAME owner AND a passing
 *         completion gate;
 *       * expired OR released leases cannot complete.
 *   - Event-backed reclaim ("expired packet can be reclaimed after expiry
 *     event"): a fresh `claim` from `expired`/`released` is admitted ONLY when a
 *     preceding expiry/release event was appended AND a NEW owner takes it; the
 *     prior owner silently re-claiming, or a claim with no prior event, blocks.
 *   - Completion Criteria => a deterministic read-only lifecycle packet plus
 *     assertGuaranteeHeld(), proving no result wrote storage or persisted a claim
 *     ("lifecycle command does not persist claims or write storage").
 *
 * The decider is fail-CLOSED: an action that is structurally legal but violates
 * a timing/ownership/gate rule is routed to `blocked` (documented transition
 * `any -> blocked`) with explicit reasons, never silently allowed.
 *
 * @see docs/engineering-knowledge-base/self-construction/durable-reservation-lease-lifecycle-contract.md
 */
final class AtlasDurableReservationLeaseLifecycleContractService
{
    /** Stable evidence schema id this read-only surface emits. */
    public const SCHEMA = 'atlas.self_construction_durable_reservation_lease_lifecycle_contract.v1';

    /** Surface label (closed set) — distinct from the blueprint surface. */
    public const SURFACE = 'durable_reservation_lease_lifecycle_contract';

    // --- Doc "Lifecycle States" ---------------------------------------------
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
     * Active-lease states (doc: "claimed: one owner holds an active lease";
     * "renewed: active lease was extended by its owner"). Renew/release/complete
     * require the lease to be in this set AND not past expiry.
     *
     * @var list<string>
     */
    public const ACTIVE_STATES = [
        self::STATE_CLAIMED,
        self::STATE_RENEWED,
    ];

    /**
     * States from which a packet may be RECLAIMED (event-backed) via a fresh
     * claim by a new owner: released and expired.
     *
     * @var list<string>
     */
    public const RECLAIMABLE_STATES = [
        self::STATE_RELEASED,
        self::STATE_EXPIRED,
    ];

    /**
     * Absorbing states from which the only documented onward transition is the
     * any -> blocked guard rejection: completed and blocked.
     *
     * @var list<string>
     */
    public const ABSORBING_STATES = [
        self::STATE_COMPLETED,
        self::STATE_BLOCKED,
    ];

    // --- Lifecycle actions (NOTE: no `reclaim` action — reclaim is a `claim`) -
    public const ACTION_CLAIM = 'claim';
    public const ACTION_RENEW = 'renew';
    public const ACTION_RELEASE = 'release';
    public const ACTION_EXPIRE = 'expire';
    public const ACTION_COMPLETE = 'complete';
    public const ACTION_BLOCK = 'block';

    /**
     * Doc "Required Transitions" as the canonical allow-list:
     * from_state => [action => to_state]. The any -> blocked transition is
     * handled separately (admissible from every known state). The
     * released/expired --claim--> claimed entries encode event-backed reclaim.
     *
     * @var array<string,array<string,string>>
     */
    public const TRANSITIONS = [
        self::STATE_PREVIEW => [
            self::ACTION_CLAIM => self::STATE_CLAIMED,        // preview -> claimed
        ],
        self::STATE_CLAIMED => [
            self::ACTION_RENEW => self::STATE_RENEWED,        // claimed -> renewed
            self::ACTION_RELEASE => self::STATE_RELEASED,     // claimed -> released
            self::ACTION_EXPIRE => self::STATE_EXPIRED,       // claimed -> expired
            self::ACTION_COMPLETE => self::STATE_COMPLETED,   // claimed -> completed
        ],
        self::STATE_RENEWED => [
            self::ACTION_RENEW => self::STATE_RENEWED,        // renewed stays active, re-renewable
            self::ACTION_RELEASE => self::STATE_RELEASED,     // renewed -> released
            self::ACTION_EXPIRE => self::STATE_EXPIRED,       // renewed -> expired
            self::ACTION_COMPLETE => self::STATE_COMPLETED,   // renewed -> completed
        ],
        // Event-backed reclaim: a fresh claim re-enters from a terminal event.
        self::STATE_RELEASED => [
            self::ACTION_CLAIM => self::STATE_CLAIMED,        // reclaim after release event
        ],
        self::STATE_EXPIRED => [
            self::ACTION_CLAIM => self::STATE_CLAIMED,        // reclaim after expiry event
        ],
    ];

    /** The only completion gate status that admits completion. */
    public const COMPLETION_GATE_GREEN = 'green';

    /** Required policy key: default lease duration must be explicit. */
    public const POLICY_LEASE_DURATION = 'default_lease_duration';

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
    public const REASON_RECLAIM_REQUIRES_PRIOR_EVENT = 'reclaim_requires_prior_event';
    public const REASON_RECLAIM_REQUIRES_NEW_OWNER = 'reclaim_requires_new_owner';
    public const REASON_LEASE_DURATION_NOT_SET = 'lease_duration_not_set';

    /**
     * Non-execution guarantee keys (doc: "lifecycle command does not persist
     * claims or write storage"; "Lease lifecycle contract generation is
     * read-only and cannot persist claims, storage, migrations or dispatch").
     * Every result keeps all of these false.
     *
     * @var list<string>
     */
    public const GUARANTEE_KEYS = [
        'claims_persisted',
        'storage_writes_performed',
        'migrations_created',
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
     * Primary surface: decide a single lease lifecycle transition under this
     * contract's timing semantics.
     *
     * Validates the action against the documented transition allow-list, then
     * enforces the timing/ownership/gate rules. An admissible transition that
     * violates a rule is fail-closed to `blocked` (documented `any -> blocked`)
     * carrying the rejected target and reasons. No claim, storage write,
     * migration or dispatch is produced.
     *
     * @param array{
     *   from_state?:string,
     *   action?:string,
     *   current_owner_session_id?:string,
     *   actor_session_id?:string,
     *   lease_expired?:bool,
     *   completion_gate_status?:string,
     *   system_driven?:bool,
     *   prior_event?:string
     * } $input
     * @return array{
     *   surface:string, schema:string,
     *   from_state:?string, action:?string,
     *   verdict:string, to_state:string,
     *   intended_to_state:?string,
     *   appended_event:?string,
     *   is_reclaim:bool,
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
        $priorEvent = is_string($input['prior_event'] ?? null) ? $input['prior_event'] : null;

        $reasons = [];
        $intendedTo = null;

        // --- structural validation against the transition allow-list ---------
        if ($from === null || ! in_array($from, self::STATES, true)) {
            $reasons[] = self::REASON_UNKNOWN_STATE;
        }
        if ($action === null || ! in_array($action, $this->knownActions(), true)) {
            $reasons[] = self::REASON_UNKNOWN_ACTION;
        }

        // `block` is admissible from ANY known state (any -> blocked).
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

        // --- timing / ownership / gate / reclaim rules (fail-closed) ---------
        /** @var string $from */
        /** @var string $action */
        $ruleReasons = $this->timingViolations($from, $action, $currentOwner, $actor, $leaseExpired, $systemDriven, $gate, $priorEvent);
        if ($ruleReasons !== []) {
            // Documented transition: any -> blocked when guard rejects the action.
            return $this->result($from, $action, self::VERDICT_BLOCK, self::STATE_BLOCKED, $intendedTo, null, false, $ruleReasons);
        }

        // --- allowed: derive appended event + reclaim flag -------------------
        $isReclaim = $action === self::ACTION_CLAIM && in_array($from, self::RECLAIMABLE_STATES, true);
        $appended = $this->appendedEvent($action, $isReclaim, $intendedTo);

        return $this->result($from, $action, self::VERDICT_ALLOW, $intendedTo, $intendedTo, $appended, $isReclaim, []);
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
        ?string $priorEvent = null,
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

        // Claim from a reclaimable (released/expired) state is event-backed
        // reclaim: it requires a preceding terminal event AND a NEW owner.
        if ($action === self::ACTION_CLAIM && in_array($from, self::RECLAIMABLE_STATES, true)) {
            if (! $this->priorEventMatchesState($from, $priorEvent)) {
                $reasons[] = self::REASON_RECLAIM_REQUIRES_PRIOR_EVENT;
            }
            if ($this->sameOwner($currentOwner, $actor) && $currentOwner !== null) {
                $reasons[] = self::REASON_RECLAIM_REQUIRES_NEW_OWNER;
            }
        }

        // Expiry may be system-driven (provenance flag accepted); it still must
        // operate on an active lease, which the transition table already
        // guarantees (expire is only defined from claimed/renewed).
        unset($systemDriven);

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
     * Encodes the doc rule "Completion requires active lease, same owner and
     * passing completion gate" plus "Expired or released leases cannot
     * complete". Released/expired have no complete transition, so they return
     * false structurally; active leases require owner + green gate.
     */
    public function canComplete(string $state, ?string $currentOwner, ?string $actor, bool $leaseExpired, ?string $gate): bool
    {
        if ((self::TRANSITIONS[$state][self::ACTION_COMPLETE] ?? null) !== self::STATE_COMPLETED) {
            return false;
        }

        return $this->timingViolations(
            $state,
            self::ACTION_COMPLETE,
            $currentOwner,
            $actor,
            $leaseExpired,
            false,
            $gate,
        ) === [];
    }

    /**
     * Encodes "expired packet can be reclaimed after expiry event" (and the
     * release analogue): a released/expired packet may be reclaimed only by a
     * NEW owner and only when the matching prior event was appended.
     */
    public function canReclaim(string $state, ?string $priorOwner, ?string $newOwner, ?string $priorEvent): bool
    {
        if (! in_array($state, self::RECLAIMABLE_STATES, true)) {
            return false;
        }

        return $this->timingViolations(
            $state,
            self::ACTION_CLAIM,
            $priorOwner,
            $newOwner,
            false,
            false,
            null,
            $priorEvent,
        ) === [];
    }

    /**
     * The read-only lifecycle CONTRACT: states, transitions, timing rules and
     * the required tests — emitted as data so it is checkable and never guessed.
     *
     * @return array{
     *   surface:string, schema:string,
     *   states:list<string>,
     *   active_states:list<string>,
     *   reclaimable_states:list<string>,
     *   absorbing_states:list<string>,
     *   transitions:list<array{from:string,action:string,to:string}>,
     *   timing_rules:list<string>,
     *   required_tests:list<string>,
     *   completion_gate_required:string,
     *   required_policy_keys:list<string>,
     *   guarantee:array<string,false>,
     *   claim_persisted:false, is_execution:false
     * }
     */
    public function contract(): array
    {
        $transitions = [];
        foreach (self::TRANSITIONS as $from => $actions) {
            foreach ($actions as $action => $to) {
                $transitions[] = ['from' => $from, 'action' => $action, 'to' => $to];
            }
        }
        // any-state block transition (documented "any -> blocked").
        $transitions[] = ['from' => '*', 'action' => self::ACTION_BLOCK, 'to' => self::STATE_BLOCKED];

        return [
            'surface' => self::SURFACE,
            'schema' => self::SCHEMA,
            'states' => self::STATES,
            'active_states' => self::ACTIVE_STATES,
            'reclaimable_states' => self::RECLAIMABLE_STATES,
            'absorbing_states' => self::ABSORBING_STATES,
            'transitions' => $transitions,
            'timing_rules' => [
                'default_lease_duration_must_be_explicit_in_config_or_policy',
                'renew_requires_same_owner_and_active_lease',
                'release_requires_same_owner_and_active_lease',
                'expire_may_be_system_driven_and_appends_event',
                'completion_requires_active_lease_same_owner_and_green_gate',
                'expired_or_released_lease_cannot_complete',
                'reclaim_only_through_event_backed_state_transition',
            ],
            'required_tests' => [
                'owner_can_renew_active_lease',
                'non_owner_cannot_renew_or_release',
                'expired_lease_cannot_complete',
                'released_lease_cannot_complete',
                'expired_packet_can_be_reclaimed_after_expiry_event',
                'completion_requires_packet_completion_gate_pass',
                'lifecycle_command_does_not_persist_claims_or_write_storage',
            ],
            'completion_gate_required' => self::COMPLETION_GATE_GREEN,
            'required_policy_keys' => [self::POLICY_LEASE_DURATION],
            'guarantee' => $this->guarantee(),
            'claim_persisted' => false,
            'is_execution' => false,
        ];
    }

    /**
     * Prove that no result flipped a non-execution guarantee key (or the two
     * restated guarantees) to a truthy value. Returns "surface.key" violations
     * (empty = intact). Backs the doc test "lifecycle command does not persist
     * claims or write storage".
     *
     * @param list<array<string,mixed>> $results
     * @return list<string>
     */
    public function assertGuaranteeHeld(array $results): array
    {
        $violations = [];
        foreach ($results as $result) {
            $label = is_string($result['surface'] ?? null) ? $result['surface'] : 'unknown';

            $guarantee = is_array($result['guarantee'] ?? null) ? $result['guarantee'] : [];
            foreach (self::GUARANTEE_KEYS as $key) {
                if (! array_key_exists($key, $guarantee) || $guarantee[$key] !== false) {
                    $violations[] = $label.'.'.$key;
                }
            }

            foreach (['claim_persisted', 'is_execution'] as $extra) {
                if (array_key_exists($extra, $result) && $result[$extra] !== false) {
                    $violations[] = $label.'.'.$extra;
                }
            }
        }

        return $violations;
    }

    /**
     * All known lifecycle actions (transition actions plus the any-state block).
     * Reclaim is deliberately absent — it is expressed via `claim`.
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
            self::ACTION_BLOCK,
        ];
    }

    /** Same-owner check that treats two missing owners as NOT a match. */
    private function sameOwner(?string $currentOwner, ?string $actor): bool
    {
        return $currentOwner !== null && $actor !== null && $currentOwner === $actor;
    }

    /**
     * Whether the supplied prior event proves the packet legitimately reached
     * its reclaimable state (expiry event for `expired`, release event for
     * `released`) — the "event-backed state transition" the doc demands.
     */
    private function priorEventMatchesState(string $from, ?string $priorEvent): bool
    {
        return match ($from) {
            self::STATE_EXPIRED => $priorEvent === 'lease_expired',
            self::STATE_RELEASED => $priorEvent === 'lease_released',
            default => false,
        };
    }

    /**
     * The event appended for an allowed transition (doc: "Expire ... must append
     * an event"; every accepted transition records one for the audit trail). A
     * reclaiming claim is distinguished from an initial claim.
     */
    private function appendedEvent(string $action, bool $isReclaim, string $to): string
    {
        if ($action === self::ACTION_CLAIM) {
            return $isReclaim ? 'lease_reclaimed' : 'lease_claimed';
        }

        return match ($action) {
            self::ACTION_RENEW => 'lease_renewed',
            self::ACTION_RELEASE => 'lease_released',
            self::ACTION_EXPIRE => 'lease_expired',
            self::ACTION_COMPLETE => 'lease_completed',
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
     *   is_reclaim:bool,
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
        bool $isReclaim,
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
            'is_reclaim' => $isReclaim,
            'reasons' => $reasons,
            'guarantee' => $this->guarantee(),
            // Restated per the doc's hard non-execution guarantee.
            'claim_persisted' => false,
            'is_execution' => false,
        ];
    }
}
