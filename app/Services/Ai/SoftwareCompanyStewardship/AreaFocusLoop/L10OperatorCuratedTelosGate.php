<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

/**
 * S154 — L10OperatorCuratedTelosGate (block: L10 Generative Engineering Guard).
 *
 * Admits a long-horizon engineering telos (the multi-year "what this software
 * universe must BE" produced by S153 `L10LongHorizonTelosProposalSpecBuilder`)
 * into the loop ONLY under explicit operator curation, a sovereignty receipt and
 * a scope proof. The generative-engineering map is explicit (sec. "R2"): the
 * telos is proposed by the system but **curated and approved by the operator**;
 * the system executes the strategy, it never chooses the ends. The DoD is the
 * load-bearing rule: "L10 strategy is operator-curated, never self-authorized".
 *
 * Three rejection rules apply, in the order the slice enumerates them:
 *   1. a missing operator receipt rejects (no sovereignty receipt -> no admit);
 *   2. a non-engineering scope rejects (L10 is software-engineering-only);
 *   3. an implicit approval rejects (curation must be EXPLICIT, never inferred,
 *      auto-granted or defaulted — that would be self-authorization).
 *
 * Pure decision function: no I/O, DB, Eloquent, facade, provider, git,
 * filesystem, clock or randomness. Every returned field is computed from the
 * two method inputs via the rules below, so identical inputs always yield an
 * identical admission. The gate NEVER curates a telos itself and NEVER fabricates
 * a receipt — a receipt id only appears in the output when the operator decision
 * actually carried one. It mirrors the scope lexicon (`engineering_only`,
 * non-engineering domain list) and the fail-closed, ordered-blocker shape of the
 * sibling S126 `L9PostL8AdmissionGate`, on the `atlas.aaeos.l10.*` schema family.
 *
 * @see docs/engineering-knowledge-base/atlas-aaeos-l10-generative-engineering-map.md
 * @see docs/engineering-knowledge-base/atlas-ai-operator-review-approval-gates.md
 * @see docs/engineering-knowledge-base/atlas-aaeos-l7-l10-governed-ladder-backlog.md
 */
final class L10OperatorCuratedTelosGate
{
    private const SCHEMA_VERSION = 'atlas.aaeos.l10.operator_curated_telos_gate.v1';

    /** The engineering scope L10 strategy is locked to. */
    private const ALLOWED_SCOPE = 'engineering_only';

    /** The approval mode that counts as explicit operator curation. */
    private const EXPLICIT_APPROVAL_MODE = 'explicit';

    /** Reported approval mode when the operator decision is not explicit curation. */
    private const IMPLICIT_APPROVAL_MODE = 'implicit';

    /**
     * Non-engineering scope tokens that are rejected outright. L10 generative
     * engineering is software engineering only — never another domain, a domain
     * generator or multi-company operation (generative-engineering map, "engenharia-only").
     *
     * @var list<string>
     */
    private const NON_ENGINEERING_SCOPES = [
        'marketing',
        'finance',
        'cyber',
        'trading',
        'sales',
        'external_company',
        'multi_company',
        'domain_generator',
    ];

    /**
     * Approval-mode tokens that are NOT explicit operator curation. An approval
     * granted implicitly, automatically, by inference or by default is
     * self-authorization in disguise and is rejected.
     *
     * @var list<string>
     */
    private const NON_CURATION_APPROVAL_MODES = [
        'implicit',
        'auto',
        'automatic',
        'inferred',
        'default',
        'assumed',
        'silent',
    ];

    /**
     * Admit (or reject) a long-horizon telos proposal against an operator
     * decision.
     *
     * Recognised `$proposal` keys:
     *   - `curated_telos_id` / `telos_proposal_id` / `telos_id` (string) — the
     *     identity of the telos under consideration;
     *   - `scope` / `domain` (string) — the proposal's scope; an explicit
     *     non-engineering scope is rejected (engineering scopes / an absent scope
     *     default to `engineering_only`).
     *
     * Recognised `$operatorDecision` keys:
     *   - `operator_receipt_id` / `receipt_id` / `sovereignty_receipt_id`
     *     (string) — the sovereignty receipt; a missing/blank one rejects;
     *   - `approval_mode` (string) — `explicit` admits, any non-curation mode
     *     (implicit/auto/inferred/default/...) rejects;
     *   - `operator_curated` / `curated` (bool) and `approved` (bool) — both must
     *     be true for the decision to be explicit operator curation. When
     *     `approval_mode` is absent it is derived from these booleans (curated and
     *     approved -> explicit; otherwise implicit).
     *
     * @param  array<string, mixed>  $proposal
     * @param  array<string, mixed>  $operatorDecision
     * @return array{
     *     schema_version: string,
     *     admitted: bool,
     *     operator_receipt_id: string,
     *     curated_telos_id: string,
     *     scope: string,
     *     scope_in_engineering: bool,
     *     approval_mode: string,
     *     operator_curated: bool,
     *     blockers: list<string>,
     *     reason: string
     * }
     */
    public function admit(array $proposal, array $operatorDecision): array
    {
        $telosId = $this->telosId($proposal);
        $receiptId = $this->receiptId($operatorDecision);
        $scope = $this->scope($proposal);
        $scopeInEngineering = $this->scopeInEngineering($scope);
        $approvalMode = $this->approvalMode($operatorDecision);
        $operatorCurated = $approvalMode === self::EXPLICIT_APPROVAL_MODE;

        $blockers = [];

        // 1. A sovereignty receipt is mandatory: without an explicit operator
        //    receipt the telos is self-authorized and is rejected.
        if ($receiptId === '') {
            $blockers[] = 'operator_receipt_missing';
        }

        // 2. L10 strategy is software-engineering only: a declared non-engineering
        //    scope is rejected (scope proof).
        if (! $scopeInEngineering) {
            $blockers[] = 'non_engineering_scope';
        }

        // 3. Curation must be EXPLICIT: an implicit / auto / inferred / defaulted
        //    approval is not curation and is rejected.
        if (! $operatorCurated) {
            $blockers[] = 'implicit_approval_not_curation';
        }

        $admitted = $blockers === [];

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'admitted' => $admitted,
            // The receipt id is only ever the one the operator decision carried;
            // it is never fabricated when admission fails.
            'operator_receipt_id' => $receiptId,
            'curated_telos_id' => $telosId,
            'scope' => $scope,
            'scope_in_engineering' => $scopeInEngineering,
            'approval_mode' => $approvalMode,
            'operator_curated' => $operatorCurated,
            'blockers' => $blockers,
            'reason' => $admitted
                ? 'telos_operator_curated_and_admitted'
                : 'telos_rejected_not_operator_curated',
        ];
    }

    /**
     * The proposal's telos identity, normalized to a trimmed string. Absent or
     * non-string identities collapse to the empty string.
     *
     * @param  array<string, mixed>  $proposal
     */
    private function telosId(array $proposal): string
    {
        $raw = $proposal['curated_telos_id']
            ?? $proposal['telos_proposal_id']
            ?? $proposal['telos_id']
            ?? null;

        return is_string($raw) ? trim($raw) : '';
    }

    /**
     * The operator decision's sovereignty receipt id, normalized to a trimmed
     * string. Absent, non-string or blank receipts collapse to the empty string,
     * which drives the `operator_receipt_missing` rejection.
     *
     * @param  array<string, mixed>  $operatorDecision
     */
    private function receiptId(array $operatorDecision): string
    {
        $raw = $operatorDecision['operator_receipt_id']
            ?? $operatorDecision['receipt_id']
            ?? $operatorDecision['sovereignty_receipt_id']
            ?? null;

        return is_string($raw) ? trim($raw) : '';
    }

    /**
     * The proposal's declared scope, normalized to a lowercase token. An absent
     * scope defaults to the locked engineering scope (a telos is engineering
     * unless it explicitly declares another domain).
     *
     * @param  array<string, mixed>  $proposal
     */
    private function scope(array $proposal): string
    {
        $raw = $proposal['scope'] ?? $proposal['domain'] ?? null;
        $token = $this->normalizeToken($raw);

        return $token === '' ? self::ALLOWED_SCOPE : $token;
    }

    /**
     * A scope is in engineering unless it is one of the explicit non-engineering
     * domains (other domains / domain generator / multi-company).
     */
    private function scopeInEngineering(string $scope): bool
    {
        return ! in_array($scope, self::NON_ENGINEERING_SCOPES, true);
    }

    /**
     * The operator decision's approval mode. An explicit `approval_mode` token is
     * honoured when recognised; otherwise the mode is derived from the curation
     * booleans (`operator_curated`/`curated` AND `approved` both true -> explicit;
     * anything else -> implicit). Any non-curation token also normalizes to
     * implicit so callers see a single, stable rejection mode.
     *
     * @param  array<string, mixed>  $operatorDecision
     */
    private function approvalMode(array $operatorDecision): string
    {
        $declared = $this->normalizeToken($operatorDecision['approval_mode'] ?? null);

        if ($declared === self::EXPLICIT_APPROVAL_MODE) {
            return self::EXPLICIT_APPROVAL_MODE;
        }

        if (in_array($declared, self::NON_CURATION_APPROVAL_MODES, true)) {
            return self::IMPLICIT_APPROVAL_MODE;
        }

        // No (or unrecognised) explicit mode token: derive from the curation
        // booleans. Both an explicit curation flag and an approval flag are
        // required for the decision to count as explicit operator curation.
        $curated = ($operatorDecision['operator_curated'] ?? $operatorDecision['curated'] ?? false) === true;
        $approved = ($operatorDecision['approved'] ?? false) === true;

        return $curated && $approved
            ? self::EXPLICIT_APPROVAL_MODE
            : self::IMPLICIT_APPROVAL_MODE;
    }

    private function normalizeToken(mixed $value): string
    {
        if (is_int($value)) {
            $value = (string) $value;
        }

        if (! is_string($value)) {
            return '';
        }

        return strtolower(trim($value));
    }
}
