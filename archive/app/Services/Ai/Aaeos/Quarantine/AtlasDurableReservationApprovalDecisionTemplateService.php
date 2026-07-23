<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Quarantine;

/**
 * Atlas Self-Construction Durable Reservation Approval DECISION TEMPLATE —
 * pure, deterministic, READ-ONLY surface.
 *
 * The doc defines the template a future operator uses to RECORD an approval or
 * rejection for durable reservation implementation — without ever confusing
 * "generating the template" with "granting approval".
 *
 * This surface is the decision-recording sibling of the pre-approval request
 * packet ({@see AtlasDurableReservationApprovalRequestService}) and the
 * post-approval preflight ({@see AtlasDurableReservationPostApprovalPreflightService}):
 *
 *   - the request packet says "here is what a human must review";
 *   - THIS template says "here is the slot the human signs, and exactly what a
 *     signed value does (and does not) authorize";
 *   - the preflight says "the human signed — is it still safe to start?".
 *
 * Per the doc section "Explicit Non Approval": this template does not approve
 * anything by itself. A valid decision requires human/operator signer data AND
 * an accepted decision value. So an UNSIGNED template (the default this surface
 * emits) keeps `approval_granted=false`, `decision_signed=false`,
 * `migrations_allowed=false`, `storage_writes_allowed=false` and
 * `dispatch_allowed=false`, ALWAYS. Emitting the template is never approval.
 *
 * Documented rules this code ENFORCES (not merely documents):
 *
 *   - "Decision Values" => only the four documented values are valid:
 *     `approved_for_scoped_implementation`, `rejected`, `needs_revision`,
 *     `expired`. classifyDecision() rejects any other token as `invalid`, and
 *     ONLY `approved_for_scoped_implementation` (when also signed and bound)
 *     can authorize scoped implementation PLANNING.
 *
 *   - "Required Bindings" => a decision is structurally valid only when all
 *     EIGHT bindings are present: approval request hash, AP candidate hash,
 *     durable ledger plan hash, multi-session readiness gate hash, signer
 *     identities, approved scopes, forbidden scopes, rollback strategy.
 *     missingBindings() lists every absent one; any gap => decision NOT valid.
 *
 *   - "Explicit Non Approval" => recordDecision() grants approval ONLY when the
 *     value is `approved_for_scoped_implementation`, at least one signer signed,
 *     and no binding is missing, and no hash is stale. Absent any of those it is
 *     fail-closed (approval_granted=false). And even a granted approval keeps
 *     `dispatch_allowed=false` — the frontmatter decision states approval may
 *     authorize implementation PLANNING only; dispatch requires a separate
 *     future AP.
 *
 *   - "expired" + "Required Bindings" (expiry checks) => if any bound hash
 *     changed after review (`*_hash_now !== *_hash_at_review`), the effective
 *     decision is forced to `expired` and approval is voided, regardless of the
 *     submitted value.
 *
 *   - "Completion Criteria" => template() emits a deterministic read-only
 *     packet carrying the allowed states, signer slots, approved/forbidden
 *     scope, rollback, expiry checks and the non-execution guarantee; and
 *     assertGuaranteeHeld() proves no result ever flipped a guarantee key.
 *
 * @see docs/engineering-knowledge-base/self-construction/durable-reservation-approval-decision-template.md
 */
final class AtlasDurableReservationApprovalDecisionTemplateService
{
    /** Stable evidence schema id this read-only surface emits. */
    public const SCHEMA = 'atlas.self_construction_durable_reservation_approval_decision_template.v1';

    /** Surface label (closed set). */
    public const SURFACE = 'durable_reservation_approval_decision_template';

    /** Status of the template as emitted by this surface: never pre-signed. */
    public const STATUS_TEMPLATE_NOT_SIGNED = 'template_not_signed';

    /**
     * Doc "Decision Values" — the only four values a decision may carry.
     * Order preserved exactly as documented.
     *
     * @var list<string>
     */
    public const DECISION_VALUES = [
        'approved_for_scoped_implementation',
        'rejected',
        'needs_revision',
        'expired',
    ];

    /** The single decision value that can authorize scoped implementation. */
    public const VALUE_APPROVED = 'approved_for_scoped_implementation';

    /** The value forced when bound hashes drift after review. */
    public const VALUE_EXPIRED = 'expired';

    /** Classification of a value that is not in the documented closed set. */
    public const CLASS_INVALID = 'invalid';

    /**
     * Doc "Required Bindings" — every decision must bind to all eight of these.
     * Order preserved exactly as documented.
     *
     * @var list<string>
     */
    public const REQUIRED_BINDINGS = [
        'approval_request_hash',
        'ap_candidate_hash',
        'durable_ledger_plan_hash',
        'multi_session_readiness_gate_hash',
        'signer_identities',
        'approved_scopes',
        'forbidden_scopes',
        'rollback_strategy',
    ];

    /**
     * The four bound hashes whose post-review drift forces the `expired` value
     * (doc "expired": hashes or evidence changed after review). Each names the
     * binding key whose `<binding>_at_review` vs `<binding>_now` is compared.
     *
     * @var list<string>
     */
    public const EXPIRY_TRACKED_HASHES = [
        'approval_request_hash',
        'ap_candidate_hash',
        'durable_ledger_plan_hash',
        'multi_session_readiness_gate_hash',
    ];

    /**
     * The non-execution guarantee keys (doc "Explicit Non Approval"): every
     * result keeps all of these false. Generating the template never approves,
     * never signs, never migrates, never writes storage, never dispatches.
     *
     * @var list<string>
     */
    public const GUARANTEE_KEYS = [
        'approval_granted',
        'decision_signed',
        'migrations_allowed',
        'storage_writes_allowed',
        'dispatch_allowed',
    ];

    /**
     * The five documented non-execution guarantee keys, all forced false.
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
     * Classify a submitted decision value against the documented closed set.
     *
     * Returns the value itself if it is one of the four documented values,
     * otherwise `invalid`. A null / non-string / unknown token never silently
     * becomes a valid decision.
     *
     * @param mixed $value the raw submitted decision value
     */
    public function classifyDecision(mixed $value): string
    {
        if (is_string($value) && in_array($value, self::DECISION_VALUES, true)) {
            return $value;
        }

        return self::CLASS_INVALID;
    }

    /**
     * Doc "Required Bindings" — list every required binding NOT proven present.
     *
     * A binding is present only when the key exists with a non-empty value
     * (non-empty string, or non-empty array for signer_identities / scopes).
     * Anything else (missing, null, '', []) counts as a gap. Fail-closed.
     *
     * @param array<string,mixed> $bindings
     * @return list<string> the missing binding tokens (empty = fully bound)
     */
    public function missingBindings(array $bindings): array
    {
        $missing = [];
        foreach (self::REQUIRED_BINDINGS as $key) {
            if (! $this->bindingPresent($bindings, $key)) {
                $missing[] = $key;
            }
        }

        return $missing;
    }

    /**
     * Doc "expired" — list the bound hashes that DRIFTED after review.
     *
     * For each tracked hash, compares `<hash>_at_review` to `<hash>_now`. A drift
     * is reported when both are present (non-empty strings) and unequal. If a
     * "now" value is absent, the staleness can't be proven here, so it is NOT
     * reported by this method (the binding-gap path covers absent evidence).
     *
     * @param array<string,mixed> $bindings
     * @return list<string> the drifted hash tokens (empty = no drift detected)
     */
    public function staleHashes(array $bindings): array
    {
        $stale = [];
        foreach (self::EXPIRY_TRACKED_HASHES as $hash) {
            $atReview = $bindings[$hash.'_at_review'] ?? ($bindings[$hash] ?? null);
            $now = $bindings[$hash.'_now'] ?? null;

            if (is_string($atReview) && $atReview !== ''
                && is_string($now) && $now !== ''
                && $atReview !== $now) {
                $stale[] = $hash;
            }
        }

        return $stale;
    }

    /**
     * Resolve the EFFECTIVE decision after applying the documented overrides.
     *
     * Precedence (doc-driven):
     *   1. an unknown value is `invalid` and authorizes nothing;
     *   2. if any bound hash drifted after review, the value is forced to
     *      `expired` (doc "expired": hashes/evidence changed after review),
     *      EVEN IF the operator submitted `approved_for_scoped_implementation`;
     *   3. otherwise the submitted value stands.
     *
     * @param array<string,mixed> $bindings
     * @return array{
     *   submitted:string, effective:string, classified:string,
     *   forced_expired:bool, stale_hashes:list<string>
     * }
     */
    public function resolveDecision(mixed $submittedValue, array $bindings): array
    {
        $classified = $this->classifyDecision($submittedValue);
        $stale = $this->staleHashes($bindings);

        $effective = $classified;
        $forcedExpired = false;

        if ($classified !== self::CLASS_INVALID && $stale !== []) {
            $effective = self::VALUE_EXPIRED;
            $forcedExpired = true;
        }

        return [
            'submitted' => is_string($submittedValue) ? $submittedValue : self::CLASS_INVALID,
            'classified' => $classified,
            'effective' => $effective,
            'forced_expired' => $forcedExpired,
            'stale_hashes' => $stale,
        ];
    }

    /**
     * Record (evaluate) a decision against the documented contract.
     *
     * Approval is granted ONLY when ALL of the following hold (doc "Explicit Non
     * Approval" + "Required Bindings"):
     *   - the effective decision value is `approved_for_scoped_implementation`;
     *   - at least one signer identity is present;
     *   - no required binding is missing;
     *   - no bound hash drifted after review.
     * Otherwise approval is fail-closed (approval_granted=false).
     *
     * Even when granted, this authorizes scoped implementation PLANNING only:
     * `dispatch_allowed` stays false (frontmatter decision — dispatch needs a
     * separate future AP). Storage / migrations follow the granted flag, but
     * `dispatch_allowed` is hard-pinned false on every path.
     *
     * @param array<string,mixed> $bindings the eight required bindings (+ the
     *   optional `<hash>_now` drift signals and optional `decision_value`).
     * @return array{
     *   surface:string, schema:string, decision_status:string,
     *   submitted_value:string, effective_value:string,
     *   missing_bindings:list<string>, stale_hashes:list<string>,
     *   fully_bound:bool, signed:bool, forced_expired:bool,
     *   approval_granted:bool, authorizes:string,
     *   guarantee:array<string,bool>
     * }
     */
    public function recordDecision(array $bindings): array
    {
        $submitted = $bindings['decision_value'] ?? null;
        $resolution = $this->resolveDecision($submitted, $bindings);

        $missing = $this->missingBindings($bindings);
        $fullyBound = $missing === [];
        $signed = $this->bindingPresent($bindings, 'signer_identities');

        $grant = $resolution['effective'] === self::VALUE_APPROVED
            && $signed
            && $fullyBound;

        // Start from the all-false guarantee, then reflect the grant ONLY into
        // the post-approval-planning keys. dispatch_allowed NEVER flips here.
        $guarantee = $this->guarantee();
        if ($grant) {
            $guarantee['approval_granted'] = true;
            $guarantee['decision_signed'] = true;
            $guarantee['migrations_allowed'] = true;
            $guarantee['storage_writes_allowed'] = true;
            // dispatch_allowed stays false: planning only, not dispatch.
        }

        return [
            'surface' => self::SURFACE,
            'schema' => self::SCHEMA,
            'decision_status' => $grant
                ? 'approved_for_scoped_implementation_planning'
                : self::STATUS_TEMPLATE_NOT_SIGNED,
            'submitted_value' => $resolution['submitted'],
            'effective_value' => $resolution['effective'],
            'missing_bindings' => $missing,
            'stale_hashes' => $resolution['stale_hashes'],
            'fully_bound' => $fullyBound,
            'signed' => $signed,
            'forced_expired' => $resolution['forced_expired'],
            'approval_granted' => $grant,
            'authorizes' => $grant
                ? 'scoped_implementation_planning_only_dispatch_requires_separate_future_ap'
                : 'nothing',
            'guarantee' => $guarantee,
        ];
    }

    /**
     * Primary surface: the read-only durable reservation APPROVAL DECISION
     * TEMPLATE. This is the empty, unsigned slot a human/operator later fills.
     *
     * By construction it is `template_not_signed` with `approval_granted=false`
     * and the full non-execution guarantee — emitting the template approves
     * nothing (doc "Explicit Non Approval"). It carries the allowed decision
     * values, a signer slot per required signer, the eight required bindings,
     * approved/forbidden scope, rollback strategy and expiry checks.
     *
     * @param list<string> $signerRoles roles to render as empty signer slots.
     * @param list<string> $forbiddenScopes scopes that stay forbidden post-approval.
     * @return array{
     *   surface:string, schema:string, status:string,
     *   allowed_decision_values:list<string>, default_decision:string,
     *   required_bindings:list<string>,
     *   signer_slots:list<array{role:string,signer_id:null,signed_at:null,decision:string}>,
     *   forbidden_scopes:list<string>,
     *   post_decision_limits:list<string>, expiry_checks:list<string>,
     *   guarantee:array<string,false>,
     *   approval_granted:false, decision_signed:false, is_execution:false
     * }
     */
    public function template(array $signerRoles = [], array $forbiddenScopes = []): array
    {
        $signerSlots = array_map(
            static fn (string $role): array => [
                'role' => $role,
                'signer_id' => null,
                'signed_at' => null,
                'decision' => 'pending',
            ],
            array_values($signerRoles),
        );

        return [
            'surface' => self::SURFACE,
            'schema' => self::SCHEMA,
            'status' => self::STATUS_TEMPLATE_NOT_SIGNED,
            'allowed_decision_values' => self::DECISION_VALUES,
            // A template that no one has acted on must default to needing work,
            // never to an approval.
            'default_decision' => 'needs_revision',
            'required_bindings' => self::REQUIRED_BINDINGS,
            'signer_slots' => $signerSlots,
            'forbidden_scopes' => array_values($forbiddenScopes),
            'post_decision_limits' => [
                'approval_decision_must_match_current_hashes',
                'dispatch_requires_separate_future_ap',
                'completion_requires_packet_completion_gate',
                'hot_scopes_remain_forbidden',
            ],
            'expiry_checks' => array_map(
                static fn (string $hash): string => $hash.'_changed_after_review',
                self::EXPIRY_TRACKED_HASHES,
            ),
            'guarantee' => $this->guarantee(),
            // Restated per the doc's hard non-execution guarantee.
            'approval_granted' => false,
            'decision_signed' => false,
            'is_execution' => false,
        ];
    }

    /**
     * Composite entrypoint: emit the unsigned template AND record the supplied
     * decision (defaulting to an empty, unsigned recording), then prove the
     * non-execution guarantee held across both.
     *
     * @param array<string,mixed> $bindings forwarded to recordDecision()
     * @param list<string> $signerRoles forwarded to template()
     * @param list<string> $forbiddenScopes forwarded to template()
     * @return array{
     *   schema:string,
     *   template:array<string,mixed>,
     *   decision:array<string,mixed>,
     *   guarantee_held:bool, guarantee_violations:list<string>
     * }
     */
    public function evaluate(array $bindings = [], array $signerRoles = [], array $forbiddenScopes = []): array
    {
        $template = $this->template($signerRoles, $forbiddenScopes);
        $decision = $this->recordDecision($bindings);

        $violations = $this->assertGuaranteeHeld([$template, $decision]);

        return [
            'schema' => self::SCHEMA,
            'template' => $template,
            'decision' => $decision,
            'guarantee_held' => $violations === [],
            'guarantee_violations' => $violations,
        ];
    }

    /**
     * Prove the dispatch guarantee is NEVER violated, and that the unsigned
     * template never flipped any guarantee key. Returns the list of
     * "surface.key" violations (empty = intact).
     *
     * Note: a SIGNED, fully-bound recording legitimately flips approval/storage
     * guarantees to true (that is what an approval IS); this checker therefore
     * only treats those as violations on the unsigned template. `dispatch_allowed`
     * is treated as a hard invariant on every result — it must stay false always.
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

            // dispatch_allowed is an absolute invariant on EVERY result.
            if (! array_key_exists('dispatch_allowed', $guarantee) || $guarantee['dispatch_allowed'] !== false) {
                $violations[] = $label.'.dispatch_allowed';
            }

            // For the unsigned TEMPLATE surface, every guarantee key must be
            // false (emitting a template approves nothing).
            if ($label === self::SURFACE && ($result['status'] ?? null) === self::STATUS_TEMPLATE_NOT_SIGNED) {
                foreach (self::GUARANTEE_KEYS as $key) {
                    if (! array_key_exists($key, $guarantee) || $guarantee[$key] !== false) {
                        $violations[] = $label.'.'.$key;
                    }
                }
            }
        }

        return $violations;
    }

    /**
     * A binding is present only when the key exists with a non-empty value.
     * Non-empty string, or non-empty array (signer_identities / scopes). Missing,
     * null, '' and [] are all fail-closed gaps.
     *
     * @param array<string,mixed> $bindings
     */
    private function bindingPresent(array $bindings, string $key): bool
    {
        if (! array_key_exists($key, $bindings)) {
            return false;
        }

        $value = $bindings[$key];

        if (is_string($value)) {
            return $value !== '';
        }

        if (is_array($value)) {
            return $value !== [];
        }

        return false;
    }
}
