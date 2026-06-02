<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

/**
 * S109 — L8 (Transcendence, P1 frame evolution).
 *
 * Pure scope gate for structural frame proposals. It enforces the immutable
 * invariant registry and the sovereignty-layer review requirement: L8 may
 * change the frame, but it may never weaken a sacred invariant or touch a
 * sovereignty layer silently. Touching the trust-ledger / governance layers
 * mandates an independent human reviewer (independent from the implementation).
 *
 * The class computes every returned field from the method inputs; it performs
 * no I/O, no clock reads and no randomness. Verdict is deterministic.
 */
final class L8FrameInvariantScopeGate
{
    private const SCHEMA_VERSION = 'atlas.aaeos.l8.frame_invariant_scope_gate.v1';

    /**
     * Canonical sovereignty-layer registry (the sacred parents). Mirrors the
     * list in atlas-architecture-evolution-proposal-runtime.md byte-for-byte.
     * Any structural change touching one of these requires a sovereignty-layer
     * review with an independent human reviewer.
     *
     * @var list<string>
     */
    private const SOVEREIGNTY_LAYERS = [
        'atlas-sovereign-operating-system',
        'atlas-epistemic-operating-system',
        'atlas-evidence-certification-runtime',
        'atlas-trust-ledger-canonical',
        'atlas-cartography-nomenclature-contract',
        'atlas-canonical-glossary-and-naming',
        'atlas-cognition-operating-system',
        'atlas-ai-knowledge-governance-system',
    ];

    /**
     * Subset of the sovereignty registry whose mutation is governance of the
     * trust ledger itself; touching these always demands an independent human
     * reviewer, never silent self-approval.
     *
     * @var list<string>
     */
    private const TRUST_GOVERNANCE_LAYERS = [
        'atlas-trust-ledger-canonical',
        'atlas-sovereign-operating-system',
        'atlas-ai-knowledge-governance-system',
    ];

    /**
     * @param array<string, mixed> $proposal   Structural frame proposal under review.
     * @param array<string, mixed> $invariants Immutable invariant registry context.
     *
     * @return array{
     *     schema_version: string,
     *     invariant_scope: string,
     *     sovereignty_layer_review_required: bool,
     *     independent_human_reviewer_required: bool,
     *     sovereignty_layers_touched: list<string>,
     *     weakened_invariants: list<string>,
     *     blockers: list<string>,
     *     cleared: bool
     * }
     */
    public function evaluate(array $proposal, array $invariants): array
    {
        $structuralChanges = $this->extractList($proposal, 'structural_changes');
        $touchedDocs = $this->touchedDocs($proposal, $structuralChanges);

        $sovereigntyLayersTouched = $this->intersectSorted($touchedDocs, self::SOVEREIGNTY_LAYERS);
        $trustGovernanceTouched = $this->intersectSorted($touchedDocs, self::TRUST_GOVERNANCE_LAYERS);

        $touchesTrustGovernance = $trustGovernanceTouched !== []
            || ($proposal['touches_trust_ledger'] ?? false) === true
            || ($proposal['touches_governance'] ?? false) === true;
        // Trust-ledger / governance parents ARE sovereignty layers (the most
        // sacred ones); touching them is, by definition, a sovereignty touch.
        // It must never demand an independent reviewer while reporting that no
        // sovereignty-layer review is required — that would let a sacred gate
        // change pass silently, violating the L8 DoD.
        $touchesSovereignty = $sovereigntyLayersTouched !== []
            || $touchesTrustGovernance
            || ($proposal['touches_sovereignty_layer'] ?? false) === true;

        $immutableIds = $this->immutableInvariantIds($invariants);
        $weakenedInvariants = $this->weakenedImmutableInvariants($proposal, $structuralChanges, $immutableIds);

        $mutatesInvariants = $weakenedInvariants !== []
            || $this->mutatesAnyInvariant($proposal, $structuralChanges);

        $hasIndependentReviewer = $this->hasIndependentHumanReviewer($proposal);

        $blockers = [];

        if ($structuralChanges === []) {
            $blockers[] = 'proposal_missing_structural_changes';
        }

        if ($weakenedInvariants !== []) {
            $blockers[] = 'weakens_immutable_invariant';
        }

        if ($touchesTrustGovernance && ! $hasIndependentReviewer) {
            $blockers[] = 'trust_governance_requires_independent_human_reviewer';
        }

        if ($touchesSovereignty && ! $touchesTrustGovernance && ! $hasIndependentReviewer) {
            $blockers[] = 'sovereignty_layer_requires_independent_human_reviewer';
        }

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'invariant_scope' => $this->resolveScope($touchesSovereignty, $mutatesInvariants),
            'sovereignty_layer_review_required' => $touchesSovereignty,
            'independent_human_reviewer_required' => $touchesSovereignty || $touchesTrustGovernance,
            'sovereignty_layers_touched' => $sovereigntyLayersTouched,
            'weakened_invariants' => $weakenedInvariants,
            'blockers' => $blockers,
            'cleared' => $blockers === [],
        ];
    }

    private function resolveScope(bool $touchesSovereignty, bool $mutatesInvariants): string
    {
        if ($touchesSovereignty && $mutatesInvariants) {
            return 'sovereignty_and_invariant';
        }

        if ($touchesSovereignty) {
            return 'sovereignty_layer';
        }

        if ($mutatesInvariants) {
            return 'invariant_only';
        }

        return 'feature_local';
    }

    /**
     * Collect every canonical document id the proposal touches: the declared
     * sovereignty layers plus each structural change target_doc.
     *
     * @param list<array<string, mixed>> $structuralChanges
     *
     * @return list<string>
     */
    private function touchedDocs(array $proposal, array $structuralChanges): array
    {
        $docs = [];

        foreach ($this->extractStringList($proposal, 'sovereignty_layers_touched') as $doc) {
            $normalized = $this->normalizeDoc($doc);
            if ($normalized !== '') {
                $docs[$normalized] = true;
            }
        }

        foreach ($structuralChanges as $change) {
            if (! is_array($change)) {
                continue;
            }

            $target = $change['target_doc'] ?? null;
            if (is_string($target)) {
                $normalized = $this->normalizeDoc($target);
                if ($normalized !== '') {
                    $docs[$normalized] = true;
                }
            }
        }

        return array_keys($docs);
    }

    /**
     * Canonical doc-id normalization so a sacred-layer touch can never pass
     * silently behind case, surrounding whitespace, or a `.md` suffix. Mirrors
     * the normalization the upstream envelope builder applies, so the gate
     * matches the canonical registry id regardless of the producer's casing.
     */
    private function normalizeDoc(string $doc): string
    {
        $normalized = strtolower(trim($doc));

        return preg_replace('/\.md$/', '', $normalized) ?? $normalized;
    }

    /**
     * Identify immutable invariants weakened by the proposal. A change weakens
     * an invariant when it removes, relaxes, loosens or disables it while the
     * registry marks that invariant immutable; a missing-from-registry change
     * still counts when it is explicitly flagged as weakening an invariant.
     *
     * @param list<array<string, mixed>> $structuralChanges
     * @param list<string>               $immutableIds
     *
     * @return list<string>
     */
    private function weakenedImmutableInvariants(array $proposal, array $structuralChanges, array $immutableIds): array
    {
        $weakeningEffects = ['remove', 'removed', 'relax', 'relaxed', 'loosen', 'loosened', 'disable', 'disabled', 'weaken', 'weakened'];
        $immutableLookup = array_fill_keys($immutableIds, true);

        $weakened = [];

        foreach ($this->invariantOperations($proposal, $structuralChanges) as $operation) {
            $id = $operation['invariant_id'] ?? null;
            if (! is_string($id) || $id === '') {
                continue;
            }

            // Normalize the declared effect before matching the weakening-verb
            // set: lowercase AND trim. A gaming attempt that pads the verb with
            // surrounding whitespace (e.g. " relax ", "remove\n") must NOT defeat
            // the strict in_array() match and let an immutable invariant be
            // weakened silently — fail-closed, mirroring the doc/reviewer-id
            // whitespace normalization the gate already applies elsewhere.
            $effect = $operation['effect'] ?? null;
            $effect = is_string($effect) ? strtolower(trim($effect)) : '';

            $explicitWeaken = ($operation['weakens_invariant'] ?? false) === true;
            $weakeningEffect = in_array($effect, $weakeningEffects, true);

            $targetsImmutable = isset($immutableLookup[$id]) || ($operation['immutable'] ?? false) === true;

            if (($explicitWeaken || $weakeningEffect) && $targetsImmutable) {
                $weakened[$id] = true;
            }
        }

        // array_keys() coerces numeric-string invariant ids (e.g. "10") back to
        // int, and a default sort() would then order them numerically. Cast every
        // id to string and sort with SORT_STRING so weakened_invariants stays a
        // deterministic, lexicographically-ordered list<string> for any id shape.
        $ids = array_map(static fn (int|string $id): string => (string) $id, array_keys($weakened));
        sort($ids, SORT_STRING);

        return array_values($ids);
    }

    /**
     * @param list<array<string, mixed>> $structuralChanges
     */
    private function mutatesAnyInvariant(array $proposal, array $structuralChanges): bool
    {
        foreach ($this->invariantOperations($proposal, $structuralChanges) as $operation) {
            $id = $operation['invariant_id'] ?? null;
            if (is_string($id) && $id !== '') {
                return true;
            }
        }

        return false;
    }

    /**
     * Flatten every invariant operation referenced by the proposal: the top
     * level invariant_operations list plus any invariant_op nested inside a
     * structural change.
     *
     * @param list<array<string, mixed>> $structuralChanges
     *
     * @return list<array<string, mixed>>
     */
    private function invariantOperations(array $proposal, array $structuralChanges): array
    {
        $operations = [];

        foreach ($this->extractList($proposal, 'invariant_operations') as $operation) {
            if (is_array($operation)) {
                $operations[] = $operation;
            }
        }

        foreach ($structuralChanges as $change) {
            if (! is_array($change)) {
                continue;
            }

            $nested = $change['invariant_op'] ?? null;
            if (is_array($nested)) {
                $operations[] = $nested;
            }
        }

        return $operations;
    }

    /**
     * @return list<string>
     */
    private function immutableInvariantIds(array $invariants): array
    {
        $ids = [];

        foreach ($this->extractList($invariants, 'registry') as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $id = $entry['invariant_id'] ?? ($entry['id'] ?? null);
            if (! is_string($id) || $id === '') {
                continue;
            }

            $immutable = $entry['immutable'] ?? true;
            if ($immutable === true) {
                $ids[$id] = true;
            }
        }

        foreach ($this->extractStringList($invariants, 'immutable_ids') as $id) {
            $ids[$id] = true;
        }

        return array_keys($ids);
    }

    private function hasIndependentHumanReviewer(array $proposal): bool
    {
        $reviewer = $proposal['independent_human_reviewer'] ?? null;

        if (is_array($reviewer)) {
            $id = $reviewer['id'] ?? null;
            $independent = ($reviewer['independent_from_implementation'] ?? true) === true;

            // A whitespace-only id is not a named reviewer; trim before the
            // emptiness check so a blank reviewer cannot satisfy the sacred-layer
            // gate (fail-closed: an unnamed reviewer is no reviewer).
            return is_string($id) && trim($id) !== '' && $independent;
        }

        return is_string($reviewer) && trim($reviewer) !== '';
    }

    /**
     * @param list<string> $candidates
     * @param list<string> $registry
     *
     * @return list<string>
     */
    private function intersectSorted(array $candidates, array $registry): array
    {
        $registryLookup = array_fill_keys($registry, true);
        $matched = [];

        foreach ($candidates as $candidate) {
            if (isset($registryLookup[$candidate])) {
                $matched[$candidate] = true;
            }
        }

        $result = array_keys($matched);
        sort($result);

        return array_values($result);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function extractList(array $payload, string $key): array
    {
        $value = $payload[$key] ?? [];

        return is_array($value) ? array_values($value) : [];
    }

    /**
     * @return list<string>
     */
    private function extractStringList(array $payload, string $key): array
    {
        $value = $payload[$key] ?? [];
        if (! is_array($value)) {
            return [];
        }

        $strings = [];
        foreach ($value as $item) {
            if (is_string($item) && $item !== '') {
                $strings[] = $item;
            }
        }

        return $strings;
    }
}
