<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

/**
 * S144 — L9 Sovereign Engineering / scope-creep guard.
 *
 * Rejects L9 candidates that try to expand beyond software engineering into
 * external domains, domain generators or multi-company operation. Doctrine
 * (L9 map line 128 / forbidden_changes line 68): "Somente engenharia de
 * software ... Fora de escopo: outros dominios (marketing, financas, cyber,
 * trading), gerador de dominios e operacao de empresas externas." The leap is
 * to deepen engineering, never to widen into other domains.
 *
 * Ordered rules per the slice acceptance:
 *  1. marketing/finance/cyber/trading/external_company (and any other
 *     non-engineering domain) reject;
 *  2. domain_generator (a generator that fabricates whole new domains) and
 *     multi-company operation reject;
 *  3. AAEOS engineering passes (in_scope=true, no reason, no blockers).
 *
 * Pure: every returned field is computed from the method input via real rules
 * (deterministic slugging, set membership against the shared non-engineering
 * domain lexicon and the engineering-scope lexicon, boolean capability flags,
 * deterministic ordering). No I/O, no DB, no facades, no clock, no randomness,
 * no write authority — this only judges a candidate, it never mutates one.
 */
final class L9EngineeringScopeCreepGuard
{
    private const SCHEMA_VERSION = 'atlas.aaeos.l9.engineering_scope_creep_guard.v1';

    /**
     * The only scope L9 is ever allowed to operate in. Mirrors L9 map line 128:
     * sovereign engineering is software engineering, nothing wider.
     */
    private const ALLOWED_SCOPE = 'software_engineering';

    /**
     * Non-engineering domains that take a candidate out of L9 scope. Mirrors the
     * L9 scope-creep lexicon already established in
     * L9EngineeringMethodCandidateSpecBuilder::NON_ENGINEERING_DOMAINS
     * (marketing/finance/cyber/trading/sales/legal/external company, plus
     * domain generators). A candidate whose resolved domain is any of these is
     * rejected and never in scope.
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
     * Drawn from L9 map line 128: the AAEOS and everything related to it —
     * specs, tests, memory, context, dev, forge, gates, evidence, governance,
     * loop and engineering surfaces.
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
     * Reason emitted when a candidate matches one of the explicitly forbidden
     * non-engineering domains.
     */
    private const REASON_FORBIDDEN_DOMAIN = 'forbidden_domain';

    /**
     * Reason emitted when a candidate is a domain generator (fabricating whole
     * new domains rather than deepening engineering).
     */
    private const REASON_DOMAIN_GENERATOR = 'domain_generator';

    /**
     * Reason emitted when a candidate wants to operate more than one company.
     */
    private const REASON_MULTI_COMPANY = 'multi_company_operation';

    /**
     * Reason emitted when a candidate resolves to some other (unrecognised)
     * non-engineering domain — the guard fails closed.
     */
    private const REASON_OUT_OF_SCOPE = 'out_of_engineering_scope';

    /**
     * @param  array<string, mixed>  $candidate
     * @return array{
     *     schema_version: string,
     *     in_scope: bool,
     *     allowed_scope: string,
     *     rejected_scope_reason: string,
     *     forbidden_domain: string,
     *     blockers: list<string>
     * }
     */
    public function evaluate(array $candidate): array
    {
        $blockers = [];
        $rejectedScopeReason = '';
        $forbiddenDomain = '';

        $domain = $this->resolvedDomain($candidate);

        // Rule 1: an explicitly forbidden non-engineering domain rejects
        // (marketing/finance/cyber/trading/external_company/...). The
        // domain_generator token is handled by rule 2 with its own reason.
        if ($domain !== ''
            && $domain !== self::REASON_DOMAIN_GENERATOR
            && in_array($domain, self::NON_ENGINEERING_DOMAINS, true)
        ) {
            $forbiddenDomain = $domain;
            $blockers[] = self::REASON_FORBIDDEN_DOMAIN . ':' . $domain;
            if ($rejectedScopeReason === '') {
                $rejectedScopeReason = self::REASON_FORBIDDEN_DOMAIN;
            }
        }

        // Rule 2a: a domain generator rejects (resolved as a domain token or as
        // an explicit capability flag).
        if ($domain === self::REASON_DOMAIN_GENERATOR || $this->isDomainGenerator($candidate)) {
            $blockers[] = self::REASON_DOMAIN_GENERATOR;
            if ($rejectedScopeReason === '') {
                $rejectedScopeReason = self::REASON_DOMAIN_GENERATOR;
            }
        }

        // Rule 2b: multi-company / external-company operation rejects.
        if ($this->isMultiCompany($candidate)) {
            $blockers[] = self::REASON_MULTI_COMPANY;
            if ($rejectedScopeReason === '') {
                $rejectedScopeReason = self::REASON_MULTI_COMPANY;
            }
        }

        // Rule 3 (fail closed): a candidate that carries a recognisable domain
        // that is neither engineering nor an already-blocked token is still out
        // of engineering scope. This keys off the RESOLVED DOMAIN itself, not an
        // auxiliary engineering flag: a concrete foreign-domain declaration
        // (e.g. "logistics") can never be laundered into scope by a self-asserted
        // `software_engineering`/`scope_kind` flag. A candidate with no foreign
        // domain signal that is engineering-scoped passes (rule 3 is skipped when
        // no domain is declared, and the flag path is honoured there).
        if ($blockers === [] && $domain !== '' && ! $this->domainIsEngineering($domain)) {
            $forbiddenDomain = $domain;
            $blockers[] = self::REASON_OUT_OF_SCOPE . ':' . $domain;
            $rejectedScopeReason = self::REASON_OUT_OF_SCOPE;
        }

        sort($blockers);

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'in_scope' => $blockers === [],
            'allowed_scope' => self::ALLOWED_SCOPE,
            'rejected_scope_reason' => $rejectedScopeReason,
            'forbidden_domain' => $forbiddenDomain,
            'blockers' => array_values($blockers),
        ];
    }

    /**
     * Resolves the candidate's domain/scope token, or '' when none is declared.
     * The first declared, non-empty token across the recognised keys wins.
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
     * override an explicit foreign-domain declaration, so the fail-closed rule 3
     * judges the declared domain, not the candidate's claim about itself. The
     * flag remains an affirmative signal only when no foreign domain is declared
     * (rule 3 short-circuits on an empty domain, leaving such a candidate in
     * scope).
     */
    private function domainIsEngineering(string $domain): bool
    {
        return $domain !== '' && in_array($domain, self::ENGINEERING_SCOPE_TOKENS, true);
    }

    /**
     * True when the candidate is a domain generator — it fabricates whole new
     * operating domains instead of deepening engineering.
     *
     * @param  array<string, mixed>  $candidate
     */
    private function isDomainGenerator(array $candidate): bool
    {
        foreach (['domain_generator', 'generates_domains', 'is_domain_generator'] as $key) {
            if (($candidate[$key] ?? false) === true) {
                return true;
            }
        }

        $kind = $candidate['kind'] ?? $candidate['candidate_kind'] ?? null;

        if (is_string($kind) && $this->slug($kind) === self::REASON_DOMAIN_GENERATOR) {
            return true;
        }

        return false;
    }

    /**
     * True when the candidate wants to operate more than one company or run an
     * external company.
     *
     * @param  array<string, mixed>  $candidate
     */
    private function isMultiCompany(array $candidate): bool
    {
        foreach (['multi_company', 'operates_external_company', 'multi_company_operation'] as $key) {
            if (($candidate[$key] ?? false) === true) {
                return true;
            }
        }

        $companyCount = $candidate['company_count'] ?? null;

        if ((is_int($companyCount) || is_float($companyCount)) && $companyCount > 1) {
            return true;
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
