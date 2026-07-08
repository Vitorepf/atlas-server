<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Quarantine;

/**
 * Atlas Self-Construction Durable Reservation Approval Request — pure,
 * deterministic, READ-ONLY surface.
 *
 * The doc defines the approval payload required BEFORE durable reservation
 * implementation can touch migrations, storage, repository code or claim state.
 *
 * This surface answers exactly one question:
 *
 *     "What exactly must a human/operator review and decide before durable
 *      reservation work may be authorized — and is that review currently clear
 *      of every blocking condition?"
 *
 * It deliberately does NOT answer:
 *
 *     "Is the work approved?"
 *
 * Per the doc section "Approval Is Not Execution": generating this request does
 * not approve work — it only creates a deterministic packet for a human to
 * review. So every result this service emits keeps `approval_granted=false`,
 * `migrations_allowed=false`, `storage_writes_allowed=false` and
 * `dispatch_allowed=false`, ALWAYS. Listing the packet is never authorization.
 *
 * Documented rules this code ENFORCES (not merely documents):
 *   - "Required Signers" => requestPacket() emits the four signer roles in the
 *     exact documented order; a packet missing any signer is structurally
 *     invalid (the signer set is a class constant, not caller-supplied).
 *   - "Approval Must Decide" => the packet carries the five open decisions a
 *     human must resolve, verbatim and ordered.
 *   - "Required Evidence" => the packet names the seven evidence items the
 *     reviewer must have in hand, ordered as documented.
 *   - "Blocking Conditions" => evaluateBlockers() / requestPacket() actually
 *     CHECK the six documented blockers. If ANY blocker fires, the packet's
 *     status is `blocked` and `approval_request_ready=false`; only a fully
 *     clean review yields `approval_request_ready=true` (ready to be reviewed,
 *     never "approved"). Fail-closed: an unproven clean signal counts as a
 *     blocker.
 *   - "Approval Is Not Execution" + "Completion Criteria" => the non-execution
 *     guarantee block stays all-false on every result, and assertGuaranteeHeld()
 *     proves no result ever flipped it.
 *
 * @see docs/engineering-knowledge-base/self-construction/durable-reservation-approval-request.md
 */
final class AtlasDurableReservationApprovalRequestService
{
    /** Stable evidence schema id this read-only surface emits. */
    public const SCHEMA = 'atlas.self_construction_durable_reservation_approval_request.v1';

    /** Surface label (closed set). */
    public const SURFACE = 'durable_reservation_approval_request';

    /** Status when every blocking condition is clear: ready for human review. */
    public const STATUS_REQUEST_READY = 'approval_request_ready_for_review';

    /** Status when at least one documented blocking condition fires. */
    public const STATUS_BLOCKED = 'blocked';

    /**
     * Doc "Required Signers" — the four roles that must sign, in order.
     *
     * @var list<string>
     */
    public const REQUIRED_SIGNERS = [
        'product_governor',
        'architecture_governor',
        'safety_governance_reviewer',
        'implementation_operator',
    ];

    /**
     * Doc "Approval Must Decide" — the five decisions a human must resolve.
     * Order preserved exactly as documented.
     *
     * @var list<string>
     */
    public const REQUIRED_DECISIONS = [
        'migrations_or_storage_allowed',
        'ap_candidate_accepted_as_scoped',
        'dispatch_remains_disabled_after_ledger_activation',
        'rollback_evidence_sufficient',
        'hot_voice_kernel_scopes_remain_forbidden',
    ];

    /**
     * Doc "Required Evidence" — the seven evidence items the reviewer must hold.
     * Order preserved exactly as documented.
     *
     * @var list<string>
     */
    public const REQUIRED_EVIDENCE = [
        'ap_candidate_hash',
        'durable_ledger_plan_hash',
        'multi_session_readiness_gate_hash',
        'docs_health_output',
        'architecture_validate_output',
        'rollback_strategy',
        'explicit_forbidden_scopes',
    ];

    /**
     * Doc "Blocking Conditions" — the six conditions under which approval MUST be
     * blocked. Each maps to a recognised input signal; absence is fail-closed.
     * Key = blocker token (emitted when it fires); value = the input signal that,
     * when proven safe (exact boolean true), clears that blocker.
     *
     * @var array<string,string>
     */
    public const BLOCKING_CONDITIONS = [
        'candidate_hash_changed_after_review' => 'candidate_hash_stable',
        'plan_hash_changed_after_review' => 'plan_hash_stable',
        'docs_or_architecture_validation_failed' => 'docs_and_architecture_validation_passed',
        'hot_scopes_in_allowed_files' => 'no_hot_scopes_in_allowed_files',
        'dispatch_enabled_in_same_ap' => 'dispatch_disabled_in_same_ap',
        'rollback_missing' => 'rollback_present',
    ];

    /**
     * The non-execution guarantee keys (doc "Approval Is Not Execution"): every
     * result keeps all of these false — generating the request never authorizes,
     * never migrates, never writes storage, never dispatches.
     *
     * @var list<string>
     */
    public const GUARANTEE_KEYS = [
        'approval_granted',
        'migrations_allowed',
        'storage_writes_allowed',
        'dispatch_allowed',
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
     * Evaluate the six documented blocking conditions against the review state.
     *
     * A blocker fires UNLESS its clearing signal is proven with an exact boolean
     * true (fail-closed: missing / null / "true" / 1 all keep the blocker
     * active). Returns the ordered list of fired blocker tokens.
     *
     * @param array<string,mixed> $review recognised clearing signals are the
     *   values of self::BLOCKING_CONDITIONS (e.g. candidate_hash_stable=true).
     * @return list<string> fired blocker tokens (empty = review is clean)
     */
    public function evaluateBlockers(array $review = []): array
    {
        $fired = [];
        foreach (self::BLOCKING_CONDITIONS as $blocker => $clearingSignal) {
            if (! $this->proven($review, $clearingSignal)) {
                $fired[] = $blocker;
            }
        }

        return $fired;
    }

    /**
     * Primary surface: the read-only durable reservation APPROVAL REQUEST packet.
     *
     * Always carries the four required signers, the five open decisions and the
     * seven evidence items the reviewer must hold. It evaluates the six blocking
     * conditions: if ANY fire, status is `blocked` and the request is NOT ready
     * for review; only a fully clean review yields the ready-for-review status.
     *
     * Either way the non-execution guarantee holds — nothing is approved,
     * migrated, written or dispatched. Caller may pass `rollback_strategy`
     * (string) to echo it into the packet; if absent the rollback blocker fires.
     *
     * @param array<string,mixed> $review clearing signals (see evaluateBlockers)
     *   plus optional `rollback_strategy` (string).
     * @return array{
     *   surface:string, schema:string, status:string,
     *   required_signers:list<string>,
     *   required_decisions:list<string>,
     *   required_evidence:list<string>,
     *   blocking_conditions:list<string>,
     *   active_blockers:list<string>,
     *   rollback_strategy:?string,
     *   approval_request_ready:bool,
     *   guarantee:array<string,false>,
     *   approval_granted:false, is_execution:false
     * }
     */
    public function requestPacket(array $review = []): array
    {
        $activeBlockers = $this->evaluateBlockers($review);
        $clean = $activeBlockers === [];

        $rollback = isset($review['rollback_strategy']) && is_string($review['rollback_strategy'])
            ? $review['rollback_strategy']
            : null;

        return [
            'surface' => self::SURFACE,
            'schema' => self::SCHEMA,
            'status' => $clean ? self::STATUS_REQUEST_READY : self::STATUS_BLOCKED,
            'required_signers' => self::REQUIRED_SIGNERS,
            'required_decisions' => self::REQUIRED_DECISIONS,
            'required_evidence' => self::REQUIRED_EVIDENCE,
            'blocking_conditions' => array_keys(self::BLOCKING_CONDITIONS),
            'active_blockers' => $activeBlockers,
            'rollback_strategy' => $rollback,
            // "Ready" means ready to be REVIEWED by a human — never "approved".
            'approval_request_ready' => $clean,
            'guarantee' => $this->guarantee(),
            // Restated per the doc's hard non-execution guarantee.
            'approval_granted' => false,
            'is_execution' => false,
        ];
    }

    /**
     * The question this surface explicitly does NOT answer with a yes.
     *
     * Doc "Approval Is Not Execution": generating this request does not approve
     * work. Regardless of input, approval is always false here and authorization
     * is deferred to a human/operator decision outside this read-only surface.
     *
     * @return array{
     *   question:string, approval_granted:false, is_execution:false,
     *   reason:string, guarantee:array<string,false>
     * }
     */
    public function approvalGranted(): array
    {
        return [
            'question' => 'is_the_durable_reservation_work_approved',
            'approval_granted' => false,
            'is_execution' => false,
            'reason' => 'generating_this_request_only_packets_a_review_it_never_authorizes_execution',
            'guarantee' => $this->guarantee(),
        ];
    }

    /**
     * Composite entrypoint: build the packet and the (always-no) approval answer,
     * and prove the non-execution guarantee held across both.
     *
     * @param array<string,mixed> $review forwarded to requestPacket()
     * @return array{
     *   schema:string,
     *   approval_request:array<string,mixed>,
     *   approval_answer:array<string,mixed>,
     *   guarantee_held:bool, guarantee_violations:list<string>
     * }
     */
    public function evaluate(array $review = []): array
    {
        $packet = $this->requestPacket($review);
        $answer = $this->approvalGranted();

        $violations = $this->assertGuaranteeHeld([$packet, $answer]);

        return [
            'schema' => self::SCHEMA,
            'approval_request' => $packet,
            'approval_answer' => $answer,
            'guarantee_held' => $violations === [],
            'guarantee_violations' => $violations,
        ];
    }

    /**
     * Prove that no result ever flipped a non-execution guarantee key to a
     * truthy value. Returns the list of "surface.key" violations (empty = intact).
     *
     * @param list<array<string,mixed>> $results
     * @return list<string>
     */
    public function assertGuaranteeHeld(array $results): array
    {
        $violations = [];
        foreach ($results as $result) {
            $label = is_string($result['surface'] ?? null)
                ? $result['surface']
                : (is_string($result['question'] ?? null) ? $result['question'] : 'unknown');

            $guarantee = is_array($result['guarantee'] ?? null) ? $result['guarantee'] : [];
            foreach (self::GUARANTEE_KEYS as $key) {
                // Missing key OR truthy value both count as a breach.
                if (! array_key_exists($key, $guarantee) || $guarantee[$key] !== false) {
                    $violations[] = $label.'.'.$key;
                }
            }

            // The packet restates two guarantees outside the guarantee array.
            foreach (['approval_granted', 'is_execution'] as $extra) {
                if (array_key_exists($extra, $result) && $result[$extra] !== false) {
                    $violations[] = $label.'.'.$extra;
                }
            }
        }

        return $violations;
    }

    /**
     * Read a clearing signal. Only an exact boolean true clears a blocker;
     * anything else (missing key, null, truthy-string, 1) keeps the blocker
     * active. This makes the approval gate impossible to trip accidentally.
     *
     * @param array<string,mixed> $review
     */
    private function proven(array $review, string $key): bool
    {
        if (! array_key_exists($key, $review)) {
            return false;
        }

        return $review[$key] === true;
    }
}
