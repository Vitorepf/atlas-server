<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

/**
 * S164 — L10 Generative Engineering Guard / scope + runaway guard.
 *
 * Rejects L10 candidates on the three failure families the L10 map fixes as
 * the limit of the asymptote (atlas-aaeos-l10-generative-engineering-map.md
 * lines 113 and 237):
 *
 *  - SCOPE CREEP: leaving software engineering for another domain
 *    (marketing/finance/cyber/trading/...). Map line 113: "Somente engenharia
 *    de software ... Expansao para marketing/financas/cyber/trading e fora de
 *    escopo, por decisao explicita." The leap deepens engineering, never widens.
 *  - RUNAWAY: unbounded recursion or unbounded generative execution without a
 *    proven convergence bound. Map line 124/226: "Auto-melhoria recursiva (R3)
 *    sem um bound formal de convergencia ... e a definicao de runaway." Line
 *    237: "nenhuma capacidade antes do bound e da soberania."
 *  - VALUE CAPTURE / SOVEREIGNTY: the system choosing engineering ends for
 *    itself or bypassing operator sovereignty. Map line 237: "nenhum fim de
 *    engenharia escolhido pelo sistema." Sovereignty of values is the invariant
 *    that keeps L10 the operator's, not a runaway.
 *
 * Ordered rules per the slice acceptance:
 *  1. an external (non-engineering) domain rejects -> scope creep;
 *  2. unbounded recursion / unbounded generative execution rejects -> runaway;
 *  3. system-chosen ends or bypassed sovereignty rejects -> value capture;
 *  4. fail closed: any other recognisable foreign domain is still out of scope.
 * A candidate clean on all rules is in scope with no runaway and no sovereignty
 * violation.
 *
 * Pure: every returned field is computed from the method input via real rules
 * (deterministic slugging, set membership against the shared non-engineering
 * domain lexicon, integer recursion-depth comparison, boolean sovereignty
 * flags, deterministic ordering). No I/O, no DB, no facades, no clock, no
 * randomness, no write authority — this only judges a candidate, it never
 * mutates one or executes it.
 */
final class L10EngineeringScopeAndRunawayGuard
{
    private const SCHEMA_VERSION = 'atlas.aaeos.l10.engineering_scope_and_runaway_guard.v1';

    /**
     * The only scope L10 is ever allowed to operate in. Mirrors L10 map line 113:
     * the asymptote deepens software engineering, it never widens to other domains.
     */
    private const ALLOWED_SCOPE = 'software_engineering';

    /**
     * Non-engineering domains that take a candidate out of L10 scope. Mirrors the
     * scope-creep lexicon already established in
     * L9EngineeringScopeCreepGuard::NON_ENGINEERING_DOMAINS and
     * L9EngineeringMethodCandidateSpecBuilder::NON_ENGINEERING_DOMAINS
     * (marketing/finance/cyber/trading/sales/legal/external company, plus
     * domain generators). A candidate whose resolved domain is any of these
     * leaves software engineering and is rejected.
     *
     * @var list<string>
     */
    private const NON_ENGINEERING_DOMAINS = [
        'marketing',
        'finance',
        'cyber',
        'trading',
        'sales',
        'legal',
        'external_company',
        'domain_generator',
    ];

    /**
     * Tokens that affirmatively place a candidate inside software engineering.
     * Mirrors L9EngineeringScopeCreepGuard::ENGINEERING_SCOPE_TOKENS: the AAEOS
     * and everything related to it — specs, tests, memory, context, dev, forge,
     * gates, evidence, governance, loop and engineering surfaces.
     *
     * @var list<string>
     */
    private const ENGINEERING_SCOPE_TOKENS = [
        'software_engineering',
        'engineering',
        'aaeos',
        'spec',
        'test',
        'memory',
        'context',
        'dev',
        'forge',
        'gate',
        'evidence',
        'governance',
        'loop',
    ];

    /**
     * Blocker emitted when a candidate leaves software engineering for an
     * explicitly forbidden non-engineering domain.
     */
    private const BLOCKER_EXTERNAL_DOMAIN = 'external_domain';

    /**
     * Blocker emitted when a candidate is out of engineering scope but its
     * foreign domain is not one of the explicitly enumerated ones (fail closed).
     */
    private const BLOCKER_OUT_OF_SCOPE = 'out_of_engineering_scope';

    /**
     * Blocker emitted when recursion / generative execution is unbounded — no
     * proven convergence bound, or requested depth beyond the proven bound.
     */
    private const BLOCKER_UNBOUNDED_RECURSION = 'unbounded_recursion';

    /**
     * Blocker emitted when unbounded generative execution is requested (a
     * generative paradigm executed at runtime without a proven bound).
     */
    private const BLOCKER_UNBOUNDED_GENERATIVE_EXECUTION = 'unbounded_generative_execution';

    /**
     * Blocker emitted when the system chooses engineering ends for itself —
     * value capture. Map line 237: "nenhum fim de engenharia escolhido pelo
     * sistema."
     */
    private const BLOCKER_SYSTEM_CHOSEN_ENDS = 'system_chosen_ends';

    /**
     * Blocker emitted when operator sovereignty is bypassed (no sovereignty,
     * unsigned, self-authorized).
     */
    private const BLOCKER_SOVEREIGNTY_BYPASS = 'sovereignty_bypass';

    /**
     * Rejected-reason families, in the order the rules are evaluated. The first
     * matched rule names the rejected_reason; '' means in scope.
     */
    private const REASON_SCOPE_CREEP = 'scope_creep';
    private const REASON_RUNAWAY = 'runaway';
    private const REASON_VALUE_CAPTURE = 'value_capture';

    /**
     * @param  array<string, mixed>  $candidate
     * @return array{
     *     schema_version: string,
     *     in_scope: bool,
     *     allowed_scope: string,
     *     runaway_risk: bool,
     *     sovereignty_violation: bool,
     *     rejected_reason: string,
     *     blockers: list<string>
     * }
     */
    public function evaluate(array $candidate): array
    {
        $blockers = [];
        $rejectedReason = '';
        $runawayRisk = false;
        $sovereigntyViolation = false;

        $domain = $this->resolvedDomain($candidate);

        // Rule 1: an external (non-engineering) domain rejects — scope creep.
        // The candidate left software engineering for another domain.
        if ($domain !== '' && in_array($domain, self::NON_ENGINEERING_DOMAINS, true)) {
            $blockers[] = self::BLOCKER_EXTERNAL_DOMAIN . ':' . $domain;
            if ($rejectedReason === '') {
                $rejectedReason = self::REASON_SCOPE_CREEP;
            }
        }

        // Rule 1b (fail closed): any other recognisable foreign domain that is
        // not engineering-scoped is still out of scope — scope creep. This keys
        // off the RESOLVED DOMAIN itself, not an auxiliary engineering flag: a
        // concrete foreign-domain declaration (e.g. "logistics"/"healthcare")
        // can never be laundered into scope by a self-asserted
        // `software_engineering`/`scope_kind`/`engineering_scope` flag. The flag
        // remains an affirmative signal only when no foreign domain is declared
        // (this rule short-circuits on an empty domain, leaving such a candidate
        // in scope).
        if ($domain !== ''
            && ! $this->domainIsEngineering($domain)
            && ! in_array($domain, self::NON_ENGINEERING_DOMAINS, true)
        ) {
            $blockers[] = self::BLOCKER_OUT_OF_SCOPE . ':' . $domain;
            if ($rejectedReason === '') {
                $rejectedReason = self::REASON_SCOPE_CREEP;
            }
        }

        // Rule 2: unbounded recursion rejects — runaway. Recursion is only
        // permitted up to the proven convergence bound; beyond it, or with no
        // proven bound at all, it is the definition of runaway.
        if ($this->hasUnboundedRecursion($candidate)) {
            $runawayRisk = true;
            $blockers[] = self::BLOCKER_UNBOUNDED_RECURSION;
            if ($rejectedReason === '') {
                $rejectedReason = self::REASON_RUNAWAY;
            }
        }

        // Rule 2b: unbounded generative execution rejects — runaway. A
        // generative paradigm executed at runtime with no proven bound is the
        // same runaway family.
        if ($this->hasUnboundedGenerativeExecution($candidate)) {
            $runawayRisk = true;
            $blockers[] = self::BLOCKER_UNBOUNDED_GENERATIVE_EXECUTION;
            if ($rejectedReason === '') {
                $rejectedReason = self::REASON_RUNAWAY;
            }
        }

        // Rule 3: system-chosen ends reject — value capture. The system must
        // never be the source of engineering ends; the operator curates them.
        if ($this->hasSystemChosenEnds($candidate)) {
            $sovereigntyViolation = true;
            $blockers[] = self::BLOCKER_SYSTEM_CHOSEN_ENDS;
            if ($rejectedReason === '') {
                $rejectedReason = self::REASON_VALUE_CAPTURE;
            }
        }

        // Rule 3b: bypassing operator sovereignty rejects — value capture.
        if ($this->bypassesSovereignty($candidate)) {
            $sovereigntyViolation = true;
            $blockers[] = self::BLOCKER_SOVEREIGNTY_BYPASS;
            if ($rejectedReason === '') {
                $rejectedReason = self::REASON_VALUE_CAPTURE;
            }
        }

        sort($blockers);

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'in_scope' => $blockers === [],
            'allowed_scope' => self::ALLOWED_SCOPE,
            'runaway_risk' => $runawayRisk,
            'sovereignty_violation' => $sovereigntyViolation,
            'rejected_reason' => $rejectedReason,
            'blockers' => array_values($blockers),
        ];
    }

    /**
     * Resolves the candidate's domain/scope token, or '' when none is declared.
     * The first declared, non-empty token across the recognised keys wins.
     * Mirrors L9EngineeringScopeCreepGuard::resolvedDomain.
     *
     * @param  array<string, mixed>  $candidate
     */
    private function resolvedDomain(array $candidate): string
    {
        foreach (['scope', 'domain', 'target_domain', 'area', 'expansion_domain'] as $key) {
            $value = $candidate[$key] ?? null;

            if (! is_string($value) && ! is_int($value) && ! is_float($value)) {
                continue;
            }

            $slug = $this->slug((string) $value);

            if ($slug !== '') {
                return $slug;
            }
        }

        return '';
    }

    /**
     * True when the RESOLVED DOMAIN itself is one of the engineering-scope
     * tokens. This is intentionally domain-only: a self-asserted engineering
     * flag (`software_engineering`/`scope_kind`/`engineering_scope`) must never
     * override an explicit foreign-domain declaration, so the fail-closed
     * out-of-scope rule judges the declared domain, not the candidate's claim
     * about itself. The flag remains an affirmative signal only when no foreign
     * domain is declared (the out-of-scope rule short-circuits on an empty
     * domain, leaving such a candidate in scope). Mirrors
     * L9EngineeringScopeCreepGuard::domainIsEngineering.
     */
    private function domainIsEngineering(string $domain): bool
    {
        return $domain !== '' && in_array($domain, self::ENGINEERING_SCOPE_TOKENS, true);
    }

    /**
     * True when the candidate requests recursion beyond what a proven
     * convergence bound covers. Runaway if: explicitly unbounded, no proven
     * bound while requesting any recursion depth, or a requested depth that
     * exceeds the proven bound.
     *
     * @param  array<string, mixed>  $candidate
     */
    private function hasUnboundedRecursion(array $candidate): bool
    {
        if (($candidate['unbounded_recursion'] ?? false) === true) {
            return true;
        }

        // A non-finite (INF/NAN) requested depth is the canonical unbounded
        // recursion request: it must NOT be silently coerced to ~0 (which would
        // read as "no recursion requested" and slip through — a fail-open). It is
        // runaway by definition, and a proven bound cannot cover an infinite/NaN
        // depth.
        if ($this->isNonFiniteNumber($candidate['requested_recursion_depth'] ?? null)) {
            return true;
        }

        $requestedDepth = $this->intOrNull($candidate, 'requested_recursion_depth');
        $provenBound = $this->intOrNull($candidate, 'proven_convergence_bound');
        $boundProven = ($candidate['convergence_bound_proven'] ?? null) === true;

        // No recursion requested at all -> not a runaway on this axis.
        if ($requestedDepth === null || $requestedDepth <= 0) {
            return false;
        }

        // Recursion requested but no proven bound (neither a numeric bound nor
        // an explicit proven flag) -> runaway.
        if ($provenBound === null && ! $boundProven) {
            return true;
        }

        // Recursion requested past the proven numeric bound -> runaway.
        if ($provenBound !== null && $requestedDepth > $provenBound) {
            return true;
        }

        return false;
    }

    /**
     * True when the candidate requests generative paradigm execution at runtime
     * without a proven bound. L10 generativity is proposal-only until proven;
     * unbounded generative execution is the runaway family.
     *
     * @param  array<string, mixed>  $candidate
     */
    private function hasUnboundedGenerativeExecution(array $candidate): bool
    {
        if (($candidate['unbounded_generative_execution'] ?? false) === true) {
            return true;
        }

        $executesGeneratively = ($candidate['executes_generative_paradigm'] ?? false) === true
            || ($candidate['generative_runtime_execution'] ?? false) === true;

        if (! $executesGeneratively) {
            return false;
        }

        // Generative execution requested: it is unbounded unless a proven bound
        // is explicitly present.
        $provenBound = $this->intOrNull($candidate, 'proven_convergence_bound');
        $boundProven = ($candidate['convergence_bound_proven'] ?? null) === true;

        return $provenBound === null && ! $boundProven;
    }

    /**
     * True when the system chooses engineering ends for itself — value capture.
     *
     * @param  array<string, mixed>  $candidate
     */
    private function hasSystemChosenEnds(array $candidate): bool
    {
        foreach (['system_chosen_ends', 'system_authored_ends', 'self_chosen_ends'] as $key) {
            if (($candidate[$key] ?? false) === true) {
                return true;
            }
        }

        $endsAuthor = $candidate['ends_authored_by'] ?? $candidate['ends_source'] ?? null;

        if (is_string($endsAuthor)) {
            $slug = $this->slug($endsAuthor);

            return in_array($slug, ['system', 'atlas', 'self'], true);
        }

        return false;
    }

    /**
     * True when the candidate bypasses operator sovereignty (no sovereignty
     * receipt, unsigned, or explicitly self-authorized).
     *
     * @param  array<string, mixed>  $candidate
     */
    private function bypassesSovereignty(array $candidate): bool
    {
        foreach (['bypass_sovereignty', 'sovereignty_bypassed', 'self_authorized'] as $key) {
            if (($candidate[$key] ?? false) === true) {
                return true;
            }
        }

        // An explicit false sovereignty receipt is a bypass; absent key is not
        // asserted here (only an affirmative violation flips this), so the guard
        // stays usable for partial candidates while still failing on an explicit
        // denial.
        if (($candidate['operator_sovereignty'] ?? null) === false) {
            return true;
        }

        if (($candidate['sovereignty_receipt_present'] ?? null) === false) {
            return true;
        }

        return false;
    }

    /**
     * Returns the integer at $key, or null when absent or non-numeric. A bare
     * bool is treated as absent so flag keys cannot masquerade as depths.
     *
     * A numeric string (e.g. "9", a shape a JSON-decoded candidate routinely
     * carries) is coerced through the SAME path as a float, so a requested
     * recursion depth supplied as a string is still compared against the proven
     * bound rather than read as "no recursion requested" — coercing it to null
     * would be a fail-open that lets a string depth bypass the runaway guard
     * entirely. This mirrors the numeric coercion contract of the sibling L10
     * kernels (L10GenerativeParadigmOutcomeValidator::intValue) and the way
     * resolvedDomain() already accepts numeric values.
     *
     * A non-finite (INF/NAN) float — or a numeric string whose float value is
     * non-finite, e.g. "1e400" — yields null: it is not a usable finite bound
     * or depth here. Non-finite depth is handled fail-closed upstream (it counts
     * as unbounded recursion via isNonFiniteNumber), while a non-finite "proven
     * bound" is correctly treated as no proven bound. A magnitude at or beyond
     * 2^63 is saturated to PHP_INT_MIN/MAX instead of being (int)-cast, which
     * would emit a runtime warning and wrap to a platform-dependent value
     * (breaking purity and, on a depth, potentially wrapping negative and
     * slipping under the bound).
     *
     * @param  array<string, mixed>  $payload
     */
    private function intOrNull(array $payload, string $key): ?int
    {
        $value = $payload[$key] ?? null;

        if (is_int($value)) {
            return $value;
        }

        // A numeric string carries the same magnitude as the equivalent float;
        // route it through the float clamp so "9" reads as depth 9, not as an
        // absent depth. (float) of an out-of-double-range numeric string yields
        // INF, which the non-finite guard below maps to null (no usable bound),
        // matching the float-INF contract.
        if (is_string($value) && is_numeric($value)) {
            $value = (float) $value;
        }

        if (is_float($value)) {
            if (! is_finite($value)) {
                return null;
            }

            if ($value >= 9223372036854775808.0) {
                return PHP_INT_MAX;
            }

            if ($value < -9223372036854775808.0) {
                return PHP_INT_MIN;
            }

            return (int) $value;
        }

        return null;
    }

    /**
     * True when the value is a non-finite (INF/-INF/NAN) float, or a numeric
     * string whose float value is non-finite (e.g. "1e400" -> INF). Such a value
     * can never be a finite recursion depth, so on the depth axis it is unbounded
     * recursion by definition — and a numeric-string depth must take that same
     * fail-closed path, never be coerced to null and slip through as "no
     * recursion requested".
     */
    private function isNonFiniteNumber(mixed $value): bool
    {
        if (is_float($value)) {
            return ! is_finite($value);
        }

        if (is_string($value) && is_numeric($value)) {
            return ! is_finite((float) $value);
        }

        return false;
    }

    private function slug(string $value): string
    {
        $value = strtolower(trim($value));
        $value = (string) preg_replace('/[^a-z0-9]+/', '_', $value);

        return trim($value, '_');
    }
}
