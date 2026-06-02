<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

/**
 * L9 Q2 — Sacred engineering invariant set builder.
 *
 * AAEOS L9/Q2 (atlas-aaeos-l9-sovereign-engineering-map:137) starts the
 * sovereignty leap from EXPLICIT invariants, never from vague trust. This
 * pure builder materialises the sacred set of engineering invariants whose
 * non-violation Q2 must later prove: merge truth, engineering scope,
 * sensitive-class containment, sacred-gate integrity, provider-claim truth
 * and canonical-doc authority.
 *
 * It is engineering-only by construction. Any requested invariant outside the
 * sacred engineering set, or one declared with a non-engineering scope, is
 * REJECTED and never admitted. The two load-bearing safety invariants
 * (merge_truth, provider_claim_truth) are required: their absence blocks the
 * set from being built.
 *
 * PURE: every field is computed from the method inputs via real rules. No I/O,
 * DB, facade, provider, clock or randomness.
 */
final class L9EngineeringInvariantSetBuilder
{
    public const SCHEMA_VERSION = 'atlas.aaeos.l9.engineering_invariant_set.v1';

    public const SCOPE_ENGINEERING_ONLY = 'engineering_only';

    public const INVARIANT_MERGE_TRUTH = 'merge_truth';

    public const INVARIANT_SCOPE_TRUTH = 'scope_truth';

    public const INVARIANT_SENSITIVE_CLASS_CONTAINMENT = 'sensitive_class_containment';

    public const INVARIANT_SACRED_GATE_INTEGRITY = 'sacred_gate_integrity';

    public const INVARIANT_PROVIDER_CLAIM_TRUTH = 'provider_claim_truth';

    public const INVARIANT_CANONICAL_DOC_AUTHORITY = 'canonical_doc_authority';

    /**
     * The sacred engineering invariant set in canonical order. Each invariant
     * declares the evidence types Q2 must bind before its proof can pass.
     *
     * @var array<string, list<string>>
     */
    private const SACRED_ENGINEERING_INVARIANTS = [
        self::INVARIANT_MERGE_TRUTH => ['merge_ref_diff', 'governed_base_merge'],
        self::INVARIANT_SCOPE_TRUTH => ['changed_file_set', 'scope_declaration'],
        self::INVARIANT_SENSITIVE_CLASS_CONTAINMENT => ['data_class_label', 'local_first_route'],
        self::INVARIANT_SACRED_GATE_INTEGRITY => ['gate_signature', 'invariant_lock_receipt'],
        self::INVARIANT_PROVIDER_CLAIM_TRUTH => ['provider_call_receipt', 'attribution_ledger'],
        self::INVARIANT_CANONICAL_DOC_AUTHORITY => ['canonical_doc_ref', 'authoring_source_proof'],
    ];

    /**
     * Invariants that may NEVER be absent from the sacred set. Their absence in
     * the requested input blocks the build (the row's named safety floor).
     *
     * @var list<string>
     */
    private const REQUIRED_INVARIANTS = [
        self::INVARIANT_MERGE_TRUTH,
        self::INVARIANT_PROVIDER_CLAIM_TRUTH,
    ];

    /**
     * Stable sentinel for a malformed (non-scalar) declared id or scope. It is
     * guaranteed not to collide with any sacred invariant id or with the
     * engineering-only scope, so a malformed declaration deterministically lands in
     * the rejected set instead of emitting a string-conversion warning.
     */
    private const MALFORMED_DECLARATION = '__malformed_non_engineering_declaration__';

    /**
     * @param  array<string, mixed>  $inputs  {requested_invariants: list<array{id?:string, scope?:string}|string>}
     * @return array{
     *     schema_version: string,
     *     built: bool,
     *     scope: string,
     *     immovable: bool,
     *     invariant_ids: list<string>,
     *     required_evidence_types: list<string>,
     *     rejected_invariants: list<string>,
     *     blockers: list<string>
     * }
     */
    public function build(array $inputs): array
    {
        $requested = $this->normalizeRequested($inputs);

        // First pass: any declaration that is not a sacred engineering invariant,
        // OR is a sacred id asserted under a non-engineering scope, is rejected.
        // Rejection is collected up front so that a contradictory non-engineering
        // declaration of an otherwise-sacred id POISONS that id: it can never be
        // laundered into the trusted set by a parallel clean record. An invariant
        // that is simultaneously "immovable sacred floor" and "rejected
        // non-engineering claim" is incoherent and unsafe, so the rejected
        // declaration wins.
        $rejected = [];

        foreach ($requested as $invariant) {
            $id = $invariant['id'];
            $scope = $invariant['scope'];

            if ($this->isSacredEngineeringInvariant($id) && $this->isEngineeringScope($scope)) {
                continue;
            }

            if (! in_array($id, $rejected, true)) {
                $rejected[] = $id;
            }
        }

        // Second pass: an id is admitted only if it is a sacred engineering id
        // requested under an engineering scope AND it was never rejected under any
        // of its declarations (no contradictory non-engineering scope poisoning it).
        $admitted = [];

        foreach ($requested as $invariant) {
            $id = $invariant['id'];
            $scope = $invariant['scope'];

            if (! $this->isSacredEngineeringInvariant($id) || ! $this->isEngineeringScope($scope)) {
                continue;
            }

            if (in_array($id, $rejected, true)) {
                continue;
            }

            if (! in_array($id, $admitted, true)) {
                $admitted[] = $id;
            }
        }

        // Canonical ordering: emit admitted invariants in sacred-set order so the
        // result is deterministic regardless of input ordering.
        $invariantIds = [];
        foreach (array_keys(self::SACRED_ENGINEERING_INVARIANTS) as $sacredId) {
            if (in_array($sacredId, $admitted, true)) {
                $invariantIds[] = $sacredId;
            }
        }

        $requiredEvidenceTypes = $this->evidenceTypesFor($invariantIds);

        $blockers = [];
        foreach (self::REQUIRED_INVARIANTS as $requiredId) {
            if (! in_array($requiredId, $invariantIds, true)) {
                $blockers[] = $requiredId.'_invariant_missing';
            }
        }
        if ($rejected !== []) {
            $blockers[] = 'non_engineering_invariant_rejected';
        }

        $built = $blockers === [];

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'built' => $built,
            'scope' => self::SCOPE_ENGINEERING_ONLY,
            'immovable' => true,
            'invariant_ids' => $invariantIds,
            'required_evidence_types' => $requiredEvidenceTypes,
            'rejected_invariants' => $rejected,
            'blockers' => $blockers,
        ];
    }

    /**
     * Normalise the requested invariants into clean {id, scope} records. A bare
     * string is treated as an id with an unspecified (engineering-defaulted)
     * scope; a record may override the scope to assert a non-engineering claim,
     * which the build then rejects — and a non-engineering declaration of an id
     * poisons that id even if a parallel engineering-scoped record exists. Empty
     * ids are dropped.
     *
     * @param  array<string, mixed>  $inputs
     * @return list<array{id: string, scope: string}>
     */
    private function normalizeRequested(array $inputs): array
    {
        $raw = $inputs['requested_invariants']
            ?? $inputs['invariants']
            ?? null;

        if (! is_array($raw)) {
            return [];
        }

        $records = [];

        foreach ($raw as $entry) {
            if (is_string($entry)) {
                $id = trim($entry);
                $scope = self::SCOPE_ENGINEERING_ONLY;
            } elseif (is_array($entry)) {
                $id = $this->scalarId($entry['id'] ?? $entry['invariant_id'] ?? '');

                $rawScope = $entry['scope'] ?? self::SCOPE_ENGINEERING_ONLY;
                $scope = $this->scalarScope($rawScope);
            } else {
                continue;
            }

            if ($id === '') {
                continue;
            }

            $records[] = ['id' => $id, 'scope' => $scope];
        }

        return $records;
    }

    /**
     * Coerce a declared invariant id to a trimmed string. A scalar (string / int /
     * float / bool) is cast directly; any non-scalar shape (array, object) is a
     * malformed declaration that cannot be a sacred engineering id, so it is mapped
     * to a stable, deterministic sentinel that is guaranteed not to match the
     * sacred set — it is therefore recorded as a rejected non-engineering claim
     * rather than silently passing through (and never emits an "Array to string
     * conversion" warning, keeping the builder pure for malformed input).
     */
    private function scalarId(mixed $value): string
    {
        if (is_string($value)) {
            return trim($value);
        }

        if (is_int($value) || is_float($value) || is_bool($value)) {
            return trim((string) $value);
        }

        return self::MALFORMED_DECLARATION;
    }

    /**
     * Coerce a declared scope to a trimmed string. An absent or blank scope means
     * "unspecified", which defaults to the engineering-only scope (a bare id is
     * engineering by construction). A scalar scope is compared verbatim. A
     * non-scalar scope (array, object) is an explicit but malformed non-engineering
     * assertion: it is mapped to a stable non-engineering sentinel so the id is
     * rejected (fail-closed), never laundered into the engineering set.
     */
    private function scalarScope(mixed $value): string
    {
        if (is_string($value)) {
            $scope = trim($value);

            return $scope === '' ? self::SCOPE_ENGINEERING_ONLY : $scope;
        }

        if (is_int($value) || is_float($value) || is_bool($value)) {
            return trim((string) $value);
        }

        return self::MALFORMED_DECLARATION;
    }

    /**
     * Union of evidence types for the given invariant ids, de-duplicated and
     * order-preserving as a clean list<string> (no int-key coercion).
     *
     * @param  list<string>  $invariantIds
     * @return list<string>
     */
    private function evidenceTypesFor(array $invariantIds): array
    {
        $types = [];

        foreach ($invariantIds as $id) {
            foreach (self::SACRED_ENGINEERING_INVARIANTS[$id] ?? [] as $type) {
                if (in_array($type, $types, true)) {
                    continue;
                }

                $types[] = $type;
            }
        }

        return $types;
    }

    private function isSacredEngineeringInvariant(string $id): bool
    {
        return array_key_exists($id, self::SACRED_ENGINEERING_INVARIANTS);
    }

    private function isEngineeringScope(string $scope): bool
    {
        return $scope === self::SCOPE_ENGINEERING_ONLY;
    }
}
