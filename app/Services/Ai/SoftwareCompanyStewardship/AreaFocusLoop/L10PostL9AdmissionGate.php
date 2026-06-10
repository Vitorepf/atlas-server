<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

/**
 * S146 — L10PostL9AdmissionGate (block: L10 Generative Engineering Guard).
 *
 * Admits L10 ("generative engineering asymptote") work into the loop only after
 * L9 is certified real by S145 (`AaeosL9SovereignEngineeringCertificationService`)
 * and only for the read-only GUARDRAIL kinds that the L10 backlog is allowed to
 * start with — precondition work, design-only spec work and read-only
 * certification work. It NEVER admits generative runtime execution: recursive
 * self-improvement execution, generative paradigm execution or any "capability
 * claim" that would actually run L10 rather than guard it.
 *
 * The generative-engineering map is explicit (sec. "Pre-condicoes inegociaveis"
 * and "Riscos"): bound + sovereignty precede every L10 pillar, and "runaway" is
 * exactly recursion/generativity executed without a proven bound. So this gate
 * fails closed: an L10 candidate is admitted only when (a) L9 is certified, (b)
 * the scope is software engineering, and (c) the candidate's kind is one of the
 * three guardrail kinds — anything that asks to EXECUTE L10 capability is a
 * blocker, never an admission (DoD: "L10 backlog starts with guardrails, not
 * capability claims").
 *
 * Pure decision function: no I/O, DB, Eloquent, facade, provider, git,
 * filesystem, clock or randomness. Every returned field is computed from the
 * method inputs via the rules below; identical inputs always yield an identical
 * admission. The gate has NO side effect and never starts an L10 runtime.
 *
 * Mirrors the fail-closed admission shape of the sibling S126
 * `L9PostL8AdmissionGate`, on the `atlas.aaeos.l10.*` schema family.
 *
 * @see docs/engineering-knowledge-base/atlas-aaeos-l10-generative-engineering-map.md
 * @see docs/engineering-knowledge-base/atlas-aaeos-l7-l10-governed-ladder-backlog.md
 */
final class L10PostL9AdmissionGate
{
    /** Schema identifier for the admission envelope (slice row, byte-for-byte). */
    private const SCHEMA_VERSION = 'atlas.aaeos.l10.admission.v1';

    /** The L10 level token this gate governs. */
    private const L10_LEVEL = 'L10';

    /** The slice whose L9 certification is the hard predecessor of all L10 work. */
    private const REQUIRED_PREDECESSOR = 'S145';

    /** The engineering scope L10 is locked to. */
    private const ALLOWED_SCOPE = 'engineering_only';

    /** Sentinel `allowed_kind` when nothing is admitted. */
    private const KIND_NONE = 'none';

    /**
     * The read-only GUARDRAIL kinds the L10 backlog is allowed to start with.
     * These are the only kinds this gate may admit. The map's L10 queue is built
     * exclusively from these: preconditions, design-only proof/method specs and
     * read-only certification services.
     *
     * @var list<string>
     */
    private const GUARDRAIL_KINDS = [
        'precondition',
        'spec',
        'certification',
    ];

    /**
     * Capability-claim kinds: an L10 candidate that asks to EXECUTE generative
     * engineering or recursion rather than guard it. Each of these is a hard
     * blocker — admitting one would be the "runaway"/"vapor de assintota" risk.
     *
     * Maps the recognised execution token to its blocker reason so the rejection
     * is specific (the row names `runtime_execution` explicitly).
     *
     * @var array<string, string>
     */
    private const CAPABILITY_KINDS = [
        'runtime_execution' => 'runtime_execution_not_admissible',
        'generative_execution' => 'generative_execution_not_admissible',
        'recursion_execution' => 'recursion_execution_not_admissible',
        'capability_execution' => 'capability_execution_not_admissible',
        'capability_claim' => 'capability_claim_not_admissible',
        'capability' => 'capability_claim_not_admissible',
    ];

    /**
     * Non-engineering scope tokens that are rejected outright. L10 is the
     * asymptote of software engineering only — never other domains, a domain
     * generator or multi-company operation (map, "Escopo (travado)").
     *
     * @var list<string>
     */
    private const NON_ENGINEERING_SCOPES = [
        'MARKETING',
        'FINANCE',
        'CYBER',
        'TRADING',
        'EXTERNAL_COMPANY',
        'MULTI_COMPANY',
        'DOMAIN_GENERATOR',
    ];

    /**
     * Admit (or block) an L10 candidate against the L9 certification.
     *
     * Recognised `$candidate` keys:
     *   - `level` (string) or a combined `id`/`candidate_id`/`candidate` such as
     *     "L10-precondition" / "l10_spec" — the leading token sets the level;
     *   - `kind` / `work_kind` / `phase` (string) — the kind of L10 work, e.g.
     *     `precondition`, `spec`, `certification` (guardrail) or
     *     `runtime_execution` (capability claim, blocked). When absent it is also
     *     derived from the trailing token of a combined id;
     *   - `scope` / `domain` (string) — the candidate's scope; an explicit
     *     non-engineering scope is rejected (engineering / software-engineering /
     *     AAEOS scopes pass).
     *
     * Recognised `$l9Certification` keys: `certified` / `l9_certified` (bool).
     *
     * @param  array<string, mixed>  $candidate
     * @param  array<string, mixed>  $l9Certification
     * @return array{
     *     schema_version: string,
     *     candidate: string,
     *     level: string,
     *     kind: string,
     *     required_predecessor: string,
     *     l9_certified: bool,
     *     is_l10_candidate: bool,
     *     scope: string,
     *     scope_in_engineering: bool,
     *     is_guardrail_kind: bool,
     *     is_capability_claim: bool,
     *     allowed_kind: string,
     *     status: string,
     *     admitted: bool,
     *     blockers: list<string>,
     *     reason: string
     * }
     */
    public function admit(array $candidate, array $l9Certification): array
    {
        $level = AreaFocusAdmissionCandidateNormalizer::level($candidate);
        $kind = AreaFocusAdmissionCandidateNormalizer::kind($candidate);
        $candidateId = AreaFocusAdmissionCandidateNormalizer::candidateId($level, $kind, $candidate);
        $l9Certified = $this->isL9Certified($l9Certification);
        $isL10 = $level === self::L10_LEVEL;
        $scope = $this->scope($candidate);
        $scopeInEngineering = $this->scopeInEngineering($scope);
        $isGuardrail = in_array($kind, self::GUARDRAIL_KINDS, true);
        $isCapability = array_key_exists($kind, self::CAPABILITY_KINDS);

        // 1. Candidates outside L10 are not this gate's concern: pass through.
        if (! $isL10) {
            return $this->result(
                $candidateId,
                $level,
                $kind,
                $l9Certified,
                false,
                $scope,
                $scopeInEngineering,
                $isGuardrail,
                $isCapability,
                self::KIND_NONE,
                'not_l10_candidate',
                true,
                [],
                'candidate_outside_l10_not_gated',
            );
        }

        // 2. L9 must be certified real by S145 before ANY L10 work is admissible.
        if (! $l9Certified) {
            return $this->result(
                $candidateId,
                $level,
                $kind,
                false,
                true,
                $scope,
                $scopeInEngineering,
                $isGuardrail,
                $isCapability,
                self::KIND_NONE,
                'blocked_l10_pre_l9',
                false,
                ['l9_not_certified'],
                'l10_never_starts_before_certified_l9',
            );
        }

        // 3. L10 is the asymptote of software engineering only: a non-engineering
        //    scope is rejected before the kind is considered.
        if (! $scopeInEngineering) {
            return $this->result(
                $candidateId,
                $level,
                $kind,
                true,
                true,
                $scope,
                false,
                $isGuardrail,
                $isCapability,
                self::KIND_NONE,
                'blocked_non_engineering_scope',
                false,
                ['non_engineering_scope'],
                'l10_is_engineering_asymptote_only',
            );
        }

        // 4. Generative runtime execution / capability claims are never admitted:
        //    admitting one would be exactly the runaway risk the bound guards.
        if ($isCapability) {
            return $this->result(
                $candidateId,
                $level,
                $kind,
                true,
                true,
                $scope,
                true,
                false,
                true,
                self::KIND_NONE,
                'blocked_capability_claim',
                false,
                [self::CAPABILITY_KINDS[$kind]],
                'l10_backlog_starts_with_guardrails_not_capability',
            );
        }

        // 5. Fail-closed: an L10 candidate whose kind is neither a known guardrail
        //    nor a recognised capability claim is rejected, not admitted.
        if (! $isGuardrail) {
            return $this->result(
                $candidateId,
                $level,
                $kind,
                true,
                true,
                $scope,
                true,
                false,
                false,
                self::KIND_NONE,
                'blocked_unknown_l10_kind',
                false,
                ['unknown_l10_kind'],
                'unknown_l10_kind_fails_closed',
            );
        }

        // Admit: certified L9, engineering scope, a guardrail kind.
        return $this->result(
            $candidateId,
            $level,
            $kind,
            true,
            true,
            $scope,
            true,
            true,
            false,
            $kind,
            'admitted_l10_guardrail_work',
            true,
            [],
            'l10_guardrail_kind_admitted_after_certified_l9',
        );
    }

    /**
     * The complete ordered list of guardrail kinds this gate may admit. Stable,
     * computed from the constant; useful to callers enumerating L10 backlog work.
     *
     * @return list<string>
     */
    public function guardrailKinds(): array
    {
        return self::GUARDRAIL_KINDS;
    }

    /**
     * @param  list<string>  $blockers
     * @return array{
     *     schema_version: string,
     *     candidate: string,
     *     level: string,
     *     kind: string,
     *     required_predecessor: string,
     *     l9_certified: bool,
     *     is_l10_candidate: bool,
     *     scope: string,
     *     scope_in_engineering: bool,
     *     is_guardrail_kind: bool,
     *     is_capability_claim: bool,
     *     allowed_kind: string,
     *     status: string,
     *     admitted: bool,
     *     blockers: list<string>,
     *     reason: string
     * }
     */
    private function result(
        string $candidateId,
        string $level,
        string $kind,
        bool $l9Certified,
        bool $isL10Candidate,
        string $scope,
        bool $scopeInEngineering,
        bool $isGuardrailKind,
        bool $isCapabilityClaim,
        string $allowedKind,
        string $status,
        bool $admitted,
        array $blockers,
        string $reason,
    ): array {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'candidate' => $candidateId,
            'level' => $level,
            'kind' => $kind,
            'required_predecessor' => self::REQUIRED_PREDECESSOR,
            'l9_certified' => $l9Certified,
            'is_l10_candidate' => $isL10Candidate,
            'scope' => $scope,
            'scope_in_engineering' => $scopeInEngineering,
            'is_guardrail_kind' => $isGuardrailKind,
            'is_capability_claim' => $isCapabilityClaim,
            'allowed_kind' => $allowedKind,
            'status' => $status,
            'admitted' => $admitted,
            'blockers' => array_values($blockers),
            'reason' => $reason,
        ];
    }

    /**
     * @param  array<string, mixed>  $l9Certification
     */
    private function isL9Certified(array $l9Certification): bool
    {
        return ($l9Certification['certified'] ?? false) === true
            || ($l9Certification['l9_certified'] ?? false) === true;
    }

    /**
     * The candidate's declared scope, normalized. Absent scope defaults to the
     * locked engineering scope (an L10 candidate is engineering unless it
     * explicitly declares otherwise).
     *
     * @param  array<string, mixed>  $candidate
     */
    private function scope(array $candidate): string
    {
        $raw = $candidate['scope'] ?? $candidate['domain'] ?? null;
        $token = AreaFocusAdmissionCandidateNormalizer::upperToken($raw);

        return $token === '' ? strtoupper(self::ALLOWED_SCOPE) : $token;
    }

    /**
     * A scope is in engineering unless it is one of the explicit non-engineering
     * domains (other domains / domain generator / multi-company).
     */
    private function scopeInEngineering(string $scope): bool
    {
        return ! in_array($scope, self::NON_ENGINEERING_SCOPES, true);
    }
}
