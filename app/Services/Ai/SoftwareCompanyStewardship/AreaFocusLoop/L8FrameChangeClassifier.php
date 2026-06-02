<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

/**
 * S107 · L8-P1 Frame Evolution — Frame Change Classifier.
 *
 * Classifies a proposed change as a normal `feature_change` or a
 * `structural_frame_change` using the canonical "Distincao Feature x Estrutural"
 * criteria from `atlas-architecture-evolution-proposal-runtime` (the structural
 * redesign runbook). The point is safety-first routing: a structural redesign of
 * the frame itself (a new phase, a new department, a root canonical schema change,
 * the autonomy-ladder promotion contract, the canonical authority of an OS-mae)
 * must NEVER travel the normal Self-Construction OS feature path — it has to go
 * through the gated Architecture Evolution Proposal Runtime instead.
 *
 * Pure decision: every returned field is COMPUTED from the `$change` input via real
 * rules. No I/O, DB, Eloquent, facades, HTTP/provider calls, git/Process,
 * filesystem, clock, or randomness. Identical input yields identical output.
 *
 * Canonical structural criteria (the doc's table — ANY structural row true routes
 * the change through the structural runbook):
 *   - alters a ROOT canonical schema (e.g. `atlas.aaeos.phase.v1`);
 *   - alters the COUNT or IDENTITY of canonical phases / departments / layers;
 *   - alters the autonomy-ladder PROMOTION contract;
 *   - alters the canonical AUTHORITY of an OS-mae.
 *
 * Feature criteria (NONE of the structural rows is true and at least one of these
 * is — the change stays inside an existing boundary / contract):
 *   - adds a feature inside an existing boundary;
 *   - optimizes performance without changing a contract;
 *   - internal refactor of a class without changing a contract.
 *
 * Ordered decision (fail-closed, structural wins):
 *   1. structural — if any structural signal fires, classify structural_frame_change.
 *   2. feature    — else if any feature signal fires, classify feature_change.
 *   3. ambiguous  — else (no structural and no feature signal, or malformed/empty
 *                   input) classify unknown_blocked: undecidable changes are never
 *                   silently routed to the feature path.
 */
final class L8FrameChangeClassifier
{
    public const SCHEMA = 'atlas.aaeos.l8.frame_change_classification.v1';

    public const CLASSIFICATION_STRUCTURAL_FRAME_CHANGE = 'structural_frame_change';

    public const CLASSIFICATION_FEATURE_CHANGE = 'feature_change';

    public const CLASSIFICATION_UNKNOWN_BLOCKED = 'unknown_blocked';

    public const ROUTE_STRUCTURAL_RUNBOOK = 'architecture_evolution_proposal_runtime';

    public const ROUTE_FEATURE_PATH = 'self_construction_os_feature_path';

    public const ROUTE_NONE = 'none';

    public const SIGNAL_ALTERS_ROOT_CANONICAL_SCHEMA = 'alters_root_canonical_schema';

    public const SIGNAL_ALTERS_PHASE_COUNT_OR_IDENTITY = 'alters_phase_count_or_identity';

    public const SIGNAL_ALTERS_DEPARTMENT_COUNT_OR_IDENTITY = 'alters_department_count_or_identity';

    public const SIGNAL_ALTERS_LAYER_COUNT_OR_IDENTITY = 'alters_layer_count_or_identity';

    public const SIGNAL_ALTERS_AUTONOMY_PROMOTION_CONTRACT = 'alters_autonomy_promotion_contract';

    public const SIGNAL_ALTERS_OS_MAE_AUTHORITY = 'alters_os_mae_authority';

    public const SIGNAL_ADDS_FEATURE_IN_EXISTING_BOUNDARY = 'adds_feature_in_existing_boundary';

    public const SIGNAL_OPTIMIZES_WITHOUT_CONTRACT_CHANGE = 'optimizes_without_contract_change';

    public const SIGNAL_INTERNAL_REFACTOR_WITHOUT_CONTRACT_CHANGE = 'internal_refactor_without_contract_change';

    public const REASON_AMBIGUOUS_NO_SIGNAL = 'ambiguous_no_classifiable_signal';

    public const REASON_MALFORMED_INPUT = 'malformed_change_input';

    /**
     * Classify a proposed change.
     *
     * @param  array<string,mixed>  $change
     * @return array{
     *     schema_version: string,
     *     classification: string,
     *     route: string,
     *     requires_structural_runbook: bool,
     *     feature_path_allowed: bool,
     *     structural_signals: list<string>,
     *     feature_signals: list<string>,
     *     blockers: list<string>,
     * }
     */
    public function classify(array $change): array
    {
        $malformed = $this->isMalformed($change);

        $structuralSignals = $malformed ? [] : $this->structuralSignals($change);
        // Feature signals are mutually exclusive with structural ones: when any
        // structural signal fired the change is forced to the gated runbook, so the
        // feature lane is not even computed — a structural redesign can never carry
        // a feature route (DoD: it cannot travel the normal feature path).
        $featureSignals = ($malformed || $structuralSignals !== [])
            ? []
            : $this->featureSignals($change);

        $blockers = [];

        if ($malformed) {
            $classification = self::CLASSIFICATION_UNKNOWN_BLOCKED;
            $route = self::ROUTE_NONE;
            $blockers[] = self::REASON_MALFORMED_INPUT;
        } elseif ($structuralSignals !== []) {
            // Rule 1 — structural wins: ANY structural row routes to the gated runbook.
            $classification = self::CLASSIFICATION_STRUCTURAL_FRAME_CHANGE;
            $route = self::ROUTE_STRUCTURAL_RUNBOOK;
        } elseif ($featureSignals !== []) {
            // Rule 2 — no structural signal and at least one feature signal.
            $classification = self::CLASSIFICATION_FEATURE_CHANGE;
            $route = self::ROUTE_FEATURE_PATH;
        } else {
            // Rule 3 — undecidable: never silently route to the feature path.
            $classification = self::CLASSIFICATION_UNKNOWN_BLOCKED;
            $route = self::ROUTE_NONE;
            $blockers[] = self::REASON_AMBIGUOUS_NO_SIGNAL;
        }

        $requiresStructuralRunbook = $classification === self::CLASSIFICATION_STRUCTURAL_FRAME_CHANGE;
        $featurePathAllowed = $classification === self::CLASSIFICATION_FEATURE_CHANGE;

        return [
            'schema_version' => self::SCHEMA,
            'classification' => $classification,
            'route' => $route,
            'requires_structural_runbook' => $requiresStructuralRunbook,
            'feature_path_allowed' => $featurePathAllowed,
            'structural_signals' => $structuralSignals,
            'feature_signals' => $featureSignals,
            'blockers' => $blockers,
        ];
    }

    /**
     * A change is malformed when it carries no usable evidence at all: an empty
     * payload, or one whose declared change_class is unknown AND which provides no
     * structural and no feature signal fields. Malformed input is fail-closed.
     *
     * @param  array<string,mixed>  $change
     */
    private function isMalformed(array $change): bool
    {
        if ($change === []) {
            return true;
        }

        foreach ($this->signalKeys() as $key) {
            if (array_key_exists($key, $change)) {
                return false;
            }
        }

        // No recognised signal field at all — only an unusable declared class (or
        // nothing). A known declared class is itself a usable signal, so it is not
        // malformed; an unknown/empty class with no other field is malformed.
        return $this->declaredClass($change) === '';
    }

    /**
     * The structural signals present in the change (canonical structural table).
     *
     * @param  array<string,mixed>  $change
     * @return list<string>
     */
    private function structuralSignals(array $change): array
    {
        $declared = $this->declaredClass($change);
        $declaredStructural = in_array(
            $declared,
            ['structural', 'structural_frame_change', 'structural_redesign', 'frame', 'frame_change'],
            true,
        );

        $signals = [];

        if ($this->altersRootSchema($change)) {
            $signals[] = self::SIGNAL_ALTERS_ROOT_CANONICAL_SCHEMA;
        }

        if ($this->changesCountOrIdentity($change, 'phase')) {
            $signals[] = self::SIGNAL_ALTERS_PHASE_COUNT_OR_IDENTITY;
        }

        if ($this->changesCountOrIdentity($change, 'department')) {
            $signals[] = self::SIGNAL_ALTERS_DEPARTMENT_COUNT_OR_IDENTITY;
        }

        if ($this->changesCountOrIdentity($change, 'layer')) {
            $signals[] = self::SIGNAL_ALTERS_LAYER_COUNT_OR_IDENTITY;
        }

        if ($this->flag($change, 'alters_autonomy_promotion_contract', 'alters_promotion_contract', 'changes_autonomy_ladder_contract')) {
            $signals[] = self::SIGNAL_ALTERS_AUTONOMY_PROMOTION_CONTRACT;
        }

        if ($this->flag($change, 'alters_os_mae_authority', 'changes_os_mae_authority', 'alters_canonical_authority')) {
            $signals[] = self::SIGNAL_ALTERS_OS_MAE_AUTHORITY;
        }

        // A change that explicitly declares itself structural but enumerates no
        // concrete structural field still counts as structural (fail-closed): the
        // declaration alone routes it to the gated runbook.
        if ($signals === [] && $declaredStructural) {
            $signals[] = self::SIGNAL_ALTERS_ROOT_CANONICAL_SCHEMA;
        }

        return $signals;
    }

    /**
     * The feature signals present in the change (canonical feature rows). These are
     * only consulted when no structural signal fired; a contract-changing flag voids
     * the "without contract change" feature signals.
     *
     * @param  array<string,mixed>  $change
     * @return list<string>
     */
    private function featureSignals(array $change): array
    {
        $declared = $this->declaredClass($change);
        $declaredFeature = in_array($declared, ['feature', 'feature_change'], true);

        $changesContract = $this->changesContract($change);

        $signals = [];

        if ($this->flag($change, 'adds_feature_in_existing_boundary', 'adds_feature_within_boundary', 'within_existing_boundary')) {
            $signals[] = self::SIGNAL_ADDS_FEATURE_IN_EXISTING_BOUNDARY;
        }

        if (! $changesContract && $this->flag($change, 'optimizes_performance', 'performance_optimization', 'optimizes_without_contract_change')) {
            $signals[] = self::SIGNAL_OPTIMIZES_WITHOUT_CONTRACT_CHANGE;
        }

        if (! $changesContract && $this->flag($change, 'internal_refactor', 'is_internal_refactor', 'internal_refactor_without_contract_change')) {
            $signals[] = self::SIGNAL_INTERNAL_REFACTOR_WITHOUT_CONTRACT_CHANGE;
        }

        // A change that declares itself a plain feature and changes no contract is a
        // feature even if it enumerates no concrete feature field.
        if ($signals === [] && $declaredFeature && ! $changesContract) {
            $signals[] = self::SIGNAL_ADDS_FEATURE_IN_EXISTING_BOUNDARY;
        }

        return $signals;
    }

    /**
     * The canonical structural row: "altera schema canonico raiz".
     *
     * @param  array<string,mixed>  $change
     */
    private function altersRootSchema(array $change): bool
    {
        if ($this->flag($change, 'alters_root_canonical_schema', 'alters_root_schema', 'changes_root_schema', 'changes_canonical_schema')) {
            return true;
        }

        // Union of every candidate list key: a `??` chain would stop at the first
        // key that is merely present-but-empty (an empty array is not null) and
        // never consult a populated sibling, fail-OPENing a real root-schema change
        // into unknown_blocked. Merge instead so any populated list counts.
        $schemas = $this->mergedStringList(
            $change,
            'root_schema_changes',
            'canonical_schema_changes',
            'schema_changes',
        );

        return $schemas !== [];
    }

    /**
     * The canonical structural rows about count/identity of phases, departments and
     * layers. A change qualifies when it declares a count delta (before != after) or
     * names added/removed/renamed canonical entities for that dimension.
     *
     * @param  array<string,mixed>  $change
     */
    private function changesCountOrIdentity(array $change, string $dimension): bool
    {
        if ($this->flag(
            $change,
            'alters_'.$dimension.'_count_or_identity',
            'changes_'.$dimension.'_count',
            'changes_'.$dimension.'s',
            'alters_'.$dimension.'s',
        )) {
            return true;
        }

        if ($this->countDelta($change, $dimension)) {
            return true;
        }

        // Union of every named-entity list key. A `??` chain would let an
        // empty `<dimension>_changes` array shadow a populated `added_/removed_/
        // renamed_` sibling and miss the structural signal (fail-open); merge so
        // any populated list across the dimension counts.
        $names = $this->mergedStringList(
            $change,
            $dimension.'_changes',
            'added_'.$dimension.'s',
            'removed_'.$dimension.'s',
            'renamed_'.$dimension.'s',
        );

        return $names !== [];
    }

    /**
     * True when a `<dimension>_count_before` and `<dimension>_count_after` pair is
     * present and differs (identity/count of a canonical dimension changed).
     *
     * @param  array<string,mixed>  $change
     */
    private function countDelta(array $change, string $dimension): bool
    {
        $beforeKey = $dimension.'_count_before';
        $afterKey = $dimension.'_count_after';

        if (! array_key_exists($beforeKey, $change) || ! array_key_exists($afterKey, $change)) {
            return false;
        }

        return $this->intValue($change, $beforeKey) !== $this->intValue($change, $afterKey);
    }

    /**
     * Whether the change declares that it changes a contract. Used to void the
     * "without contract change" feature signals so a contract-changing optimization
     * or refactor is never miscoded as a benign feature.
     *
     * @param  array<string,mixed>  $change
     */
    private function changesContract(array $change): bool
    {
        return $this->flag($change, 'changes_contract', 'breaks_contract', 'alters_contract');
    }

    /**
     * The declared change class, normalised to lower-case (kind/type/change_class).
     *
     * @param  array<string,mixed>  $change
     */
    private function declaredClass(array $change): string
    {
        $value = $change['change_class']
            ?? $change['classification']
            ?? $change['change_type']
            ?? $change['kind']
            ?? $change['type']
            ?? '';

        // Only a string carries a declared class. A non-string (array/object) is not
        // a usable class label, and casting an array with (string) would emit an
        // "Array to string conversion" warning — an observable side-effect that
        // breaks this kernel's documented purity/determinism contract. Treat any
        // non-string declared-class field as absent (fail-closed: no declared class).
        if (! is_string($value)) {
            return '';
        }

        return strtolower(trim($value));
    }

    /**
     * True when ANY of the given boolean-ish keys is strictly true.
     *
     * @param  array<string,mixed>  $change
     */
    private function flag(array $change, string ...$keys): bool
    {
        foreach ($keys as $key) {
            if (($change[$key] ?? false) === true) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string,mixed>  $change
     */
    private function intValue(array $change, string $key): int
    {
        $value = $change[$key] ?? 0;

        if (is_int($value)) {
            return $value;
        }

        // A float that is non-finite (NAN / ±INF) or outside the platform int range
        // is not a representable count. Casting such a value with (int) emits a PHP
        // warning — an observable side-effect that breaks this kernel's
        // purity/determinism contract — so treat it as the absent count (0). Finite
        // in-range floats still truncate exactly as before.
        if (is_float($value) && ! $this->isIntRepresentableFloat($value)) {
            return 0;
        }

        return (int) $value;
    }

    /**
     * Whether a float can be cast to int without a "not representable" warning:
     * it must be finite and within the platform integer range. Compared as floats
     * because PHP_INT_MAX rounds up when cast to float, so a strict `<` guards the
     * upper edge.
     */
    private function isIntRepresentableFloat(float $value): bool
    {
        return is_finite($value)
            && $value >= (float) PHP_INT_MIN
            && $value < (float) PHP_INT_MAX;
    }

    /**
     * The complete set of recognised signal field keys. Presence of any one means
     * the change carries usable evidence (so it is not malformed).
     *
     * @return list<string>
     */
    private function signalKeys(): array
    {
        return [
            'alters_root_canonical_schema', 'alters_root_schema', 'changes_root_schema', 'changes_canonical_schema',
            'root_schema_changes', 'canonical_schema_changes', 'schema_changes',
            'alters_phase_count_or_identity', 'changes_phase_count', 'changes_phases', 'alters_phases',
            'phase_changes', 'added_phases', 'removed_phases', 'renamed_phases', 'phase_count_before', 'phase_count_after',
            'alters_department_count_or_identity', 'changes_department_count', 'changes_departments', 'alters_departments',
            'department_changes', 'added_departments', 'removed_departments', 'renamed_departments',
            'department_count_before', 'department_count_after',
            'alters_layer_count_or_identity', 'changes_layer_count', 'changes_layers', 'alters_layers',
            'layer_changes', 'added_layers', 'removed_layers', 'renamed_layers', 'layer_count_before', 'layer_count_after',
            'alters_autonomy_promotion_contract', 'alters_promotion_contract', 'changes_autonomy_ladder_contract',
            'alters_os_mae_authority', 'changes_os_mae_authority', 'alters_canonical_authority',
            'adds_feature_in_existing_boundary', 'adds_feature_within_boundary', 'within_existing_boundary',
            'optimizes_performance', 'performance_optimization', 'optimizes_without_contract_change',
            'internal_refactor', 'is_internal_refactor', 'internal_refactor_without_contract_change',
        ];
    }

    /**
     * Union the string lists found under every given key into one strict
     * list<string>. Unlike a `??` chain (which stops at the first present key,
     * even when it is an empty array) this consults ALL keys, so a populated
     * sibling list is never shadowed by an empty-but-present one.
     *
     * @param  array<string,mixed>  $change
     * @return list<string>
     */
    private function mergedStringList(array $change, string ...$keys): array
    {
        $merged = [];
        foreach ($keys as $key) {
            if (! array_key_exists($key, $change)) {
                continue;
            }
            foreach ($this->stringList($change[$key]) as $item) {
                $merged[] = $item;
            }
        }

        return $merged;
    }

    /**
     * Coerce a value into a strict list<string>: drop non-strings and empty strings,
     * and re-index with array_values so no int keys leak into the list.
     *
     * @param  mixed  $value
     * @return list<string>
     */
    private function stringList($value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter(
            array_map(
                static fn ($item): string => is_string($item) ? trim($item) : '',
                $value,
            ),
            static fn (string $item): bool => $item !== '',
        ));
    }
}
