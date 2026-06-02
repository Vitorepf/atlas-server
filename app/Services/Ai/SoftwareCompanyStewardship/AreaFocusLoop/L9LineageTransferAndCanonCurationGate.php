<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

/**
 * S142 — L9LineageTransferAndCanonCurationGate (block: L9 Sovereign
 * Engineering, phase Q3).
 *
 * Gates the transfer of an engineering evolution from a parallel sandbox
 * lineage (S141) INTO the canonical engineering practice (the canon). L9-Q3
 * lets the AAEOS explore multiple engineering lineages in parallel and select
 * the best ones — but the canonical safety gate (L9 map, "Gate de seguranca")
 * is unconditional: "linhagens paralelas sao sandboxed e nunca dao merge sem o
 * gate; o operador cura quais avancos de disciplina entram no canon." The
 * invariant that never transcends (L9 map) keeps objectives, values, taste and
 * engineering sovereignty with the operator: the operator — not the system —
 * curates the canon.
 *
 * Therefore a lineage transfer is admitted into canonical engineering practice
 * ONLY when all three hold, evaluated in this canonical fail-closed order:
 *   1. operator curation — the operator explicitly approved this transfer into
 *      the canon. Missing/implicit/rejected operator curation rejects (the
 *      operator curates the canon; the system never self-admits).
 *   2. validated outcome — the transferred evolution carries a validated,
 *      measured outcome (S140 measured-or-reverted). No validated outcome
 *      rejects (a transfer survives by retained outcome, never by style).
 *   3. Q2 proof boundary — the transfer stays inside the proven Q2 invariant
 *      boundary. Outside / missing Q2 boundary rejects (Q3 depends on Q2 — the
 *      proven sandbox — to be safe).
 *
 * Pure function: no I/O, DB, Eloquent, facades, HTTP/provider calls,
 * git/Process, filesystem, clock/now() or randomness. Every returned field is
 * computed from the `$transfer` / `$validation` / `$operatorDecision` arguments
 * via real rules. The `canon_change_proposal.proposal_id` is a DETERMINISTIC
 * sha256 content digest of the normalized transfer identity, so identical
 * input always yields an identical proposal id.
 */
final class L9LineageTransferAndCanonCurationGate
{
    public const SCHEMA_VERSION = 'atlas.aaeos.l9.lineage_transfer_canon_curation.v1';

    public const PHASE = 'L9-Q3';

    /**
     * The only scope L9 may operate in: AAEOS software engineering. Mirrors the
     * L9 map invariant "tudo dentro de engenharia, sem tocar outros dominios".
     */
    public const ALLOWED_SCOPE = 'aaeos_engineering';

    /**
     * Ordered rejection blockers (canonical fail-closed order).
     */
    public const BLOCKER_NO_OPERATOR_CURATION = 'no_operator_curation';

    public const BLOCKER_NO_VALIDATED_OUTCOME = 'no_validated_outcome';

    public const BLOCKER_OUTSIDE_Q2_BOUNDARY = 'outside_q2_boundary';

    /**
     * The signature the operator must provide to curate the canon. This gate
     * always requires it — a lineage transfer never enters the canon without an
     * explicit operator curation signature, admitted or not.
     */
    public const SIGNATURE_OPERATOR_CURATION = 'operator_canon_curation';

    /**
     * Admit a lineage transfer into canonical engineering practice only with a
     * validated outcome, an in-bounds Q2 proof boundary and explicit operator
     * curation.
     *
     * @param  array<string, mixed>  $transfer  The proposed transfer of a sandbox
     *                                           lineage evolution into the canon.
     * @param  array<string, mixed>  $validation  The measured-outcome validation
     *                                             (S140) of the transferred evolution.
     * @param  array<string, mixed>  $operatorDecision  The operator's explicit
     *                                                   curation decision.
     * @return array{
     *     schema_version: string,
     *     phase: string,
     *     admitted: bool,
     *     operator_curated: bool,
     *     outcome_validated: bool,
     *     within_q2_boundary: bool,
     *     canon_change_proposal: array{
     *         proposal_id: string,
     *         lineage_id: string,
     *         transfer_id: string,
     *         scope: string,
     *         status: string
     *     }|null,
     *     required_signatures: list<string>,
     *     blockers: list<string>
     * }
     */
    public function admit(array $transfer, array $validation, array $operatorDecision): array
    {
        $operatorCurated = $this->operatorCurated($operatorDecision);
        $outcomeValidated = $this->outcomeValidated($validation);
        $withinQ2Boundary = $this->withinQ2Boundary($transfer);

        $blockers = [];

        // Rule 1 — the operator must explicitly curate the canon.
        if (! $operatorCurated) {
            $blockers[] = self::BLOCKER_NO_OPERATOR_CURATION;
        }

        // Rule 2 — the transferred evolution must carry a validated outcome.
        if (! $outcomeValidated) {
            $blockers[] = self::BLOCKER_NO_VALIDATED_OUTCOME;
        }

        // Rule 3 — the transfer must stay inside the proven Q2 boundary.
        if (! $withinQ2Boundary) {
            $blockers[] = self::BLOCKER_OUTSIDE_Q2_BOUNDARY;
        }

        $admitted = $blockers === [];

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'phase' => self::PHASE,
            'admitted' => $admitted,
            'operator_curated' => $operatorCurated,
            'outcome_validated' => $outcomeValidated,
            'within_q2_boundary' => $withinQ2Boundary,
            // A canon change is proposed only when the transfer is admitted; an
            // un-admitted transfer never produces a canon change proposal.
            'canon_change_proposal' => $admitted
                ? $this->canonChangeProposal($transfer)
                : null,
            // The operator curation signature is ALWAYS required to move a
            // lineage transfer into the canon — the operator curates the canon.
            'required_signatures' => [self::SIGNATURE_OPERATOR_CURATION],
            'blockers' => $blockers,
        ];
    }

    /**
     * The operator curated the canon when an explicit operator decision
     * approves this transfer. A missing decision, a non-operator decision, or
     * any verdict that is not an explicit approval does not curate the canon.
     *
     * @param  array<string, mixed>  $operatorDecision
     */
    private function operatorCurated(array $operatorDecision): bool
    {
        if (! $this->isOperatorAuthored($operatorDecision)) {
            return false;
        }

        // An explicit boolean approval flag, when present, is authoritative.
        foreach (['approved', 'curated', 'operator_curated'] as $flag) {
            $value = $operatorDecision[$flag] ?? null;
            if ($value === false) {
                return false;
            }
            if ($value === true) {
                return true;
            }
        }

        // Otherwise fall back to an explicit verdict token.
        $verdict = $this->normalizeToken(
            $this->stringValue($operatorDecision, ['decision', 'verdict', 'curation', 'action'], ''),
        );

        return in_array($verdict, ['approve', 'approved', 'admit', 'admitted', 'curate', 'curated'], true);
    }

    /**
     * A decision is operator-authored when it is explicitly attributed to the
     * operator (and not, e.g., to a provider or the system itself).
     *
     * @param  array<string, mixed>  $operatorDecision
     */
    private function isOperatorAuthored(array $operatorDecision): bool
    {
        if (($operatorDecision['operator_signed'] ?? false) === true) {
            return true;
        }

        $actor = $this->normalizeToken(
            $this->stringValue($operatorDecision, ['actor', 'author', 'decided_by', 'role', 'source'], ''),
        );

        if ($actor === '') {
            // No attribution at all: cannot prove operator authorship.
            return false;
        }

        return in_array($actor, ['operator', 'human_operator', 'owner'], true);
    }

    /**
     * The transferred evolution carries a validated outcome when its validation
     * envelope is explicitly validated (S140 measured-or-reverted). A reverted
     * or non-validated outcome does not count.
     *
     * @param  array<string, mixed>  $validation
     */
    private function outcomeValidated(array $validation): bool
    {
        if ($validation === []) {
            return false;
        }

        // A reverted outcome never counts as validated.
        if (($validation['reverted'] ?? false) === true) {
            return false;
        }

        if (($validation['validated'] ?? null) === false
            || ($validation['outcome_validated'] ?? null) === false) {
            return false;
        }

        return ($validation['validated'] ?? false) === true
            || ($validation['outcome_validated'] ?? false) === true;
    }

    /**
     * The transfer stays inside the proven Q2 boundary when it declares an
     * in-bounds Q2 proof boundary. A transfer that is explicitly out of bounds,
     * or that carries no Q2 boundary at all, is outside the boundary.
     *
     * @param  array<string, mixed>  $transfer
     */
    private function withinQ2Boundary(array $transfer): bool
    {
        $boundary = $transfer['q2_boundary'] ?? null;
        $boundary = is_array($boundary) ? $boundary : $transfer;

        // An explicit out-of-bounds / un-certified marker wins (fail closed).
        foreach (['within_q2_boundary', 'q2_within_boundary', 'q2_certified', 'q2_boundary_present'] as $flag) {
            if (($boundary[$flag] ?? null) === false) {
                return false;
            }
        }
        if (($boundary['outside_q2_boundary'] ?? false) === true) {
            return false;
        }

        foreach (['within_q2_boundary', 'q2_within_boundary', 'q2_certified', 'q2_boundary_present'] as $flag) {
            if (($boundary[$flag] ?? false) === true) {
                return true;
            }
        }

        // A concrete proof-boundary anchor proves the transfer is in-bounds.
        $anchor = $this->stringValue(
            $boundary,
            ['q2_boundary_id', 'boundary_id', 'proof_boundary_id', 'delegation_boundary'],
            '',
        );

        return $anchor !== '';
    }

    /**
     * Build the canon change proposal for an admitted transfer. Pure and
     * deterministic: the proposal id is a sha256 content digest of the
     * normalized transfer identity — no uuid, no clock, no randomness.
     *
     * @param  array<string, mixed>  $transfer
     * @return array{
     *     proposal_id: string,
     *     lineage_id: string,
     *     transfer_id: string,
     *     scope: string,
     *     status: string
     * }
     */
    private function canonChangeProposal(array $transfer): array
    {
        $lineageId = $this->stringValue($transfer, ['lineage_id', 'lineage', 'source_lineage'], '');
        $transferId = $this->stringValue($transfer, ['transfer_id', 'id', 'name'], '');

        return [
            'proposal_id' => $this->proposalId($lineageId, $transferId),
            'lineage_id' => $lineageId,
            'transfer_id' => $transferId,
            'scope' => self::ALLOWED_SCOPE,
            // The canon change is PROPOSED, never auto-applied: it still awaits
            // the operator curation signature before it enters the canon.
            'status' => 'proposed',
        ];
    }

    /**
     * Deterministic content-addressed proposal identifier.
     */
    private function proposalId(string $lineageId, string $transferId): string
    {
        $digest = implode('|', [
            'l9_canon_change',
            $this->normalizeToken($lineageId),
            $this->normalizeToken($transferId),
        ]);

        return 'canon_'.substr(hash('sha256', $digest), 0, 16);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  list<string>  $keys
     */
    private function stringValue(array $payload, array $keys, string $default): string
    {
        foreach ($keys as $key) {
            $value = $payload[$key] ?? null;
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return $default;
    }

    private function normalizeToken(string $value): string
    {
        $normalized = strtolower(trim($value));
        $normalized = preg_replace('/[\s\-]+/', '_', $normalized) ?? $normalized;

        return trim($normalized, '_');
    }
}
