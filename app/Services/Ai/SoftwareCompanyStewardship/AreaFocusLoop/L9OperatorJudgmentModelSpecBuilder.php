<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

/**
 * S134 — L9 Q1 operator-judgment amplification.
 *
 * Builds a governed, design-only specification for a model of the operator's
 * engineering judgment (code taste, architecture patterns, quality bar,
 * approve/reject reasons). The spec is the safety contract that precedes any
 * runtime: it enumerates the feature set, the operator decision labels, the
 * delegated risk classes (bounded exactly by the proven Q2 boundary), the
 * anti-Goodhart anchors that keep the model from gaming its own metric, and the
 * operator override contract.
 *
 * The judgment model is a governed spec before runtime: it amplifies operator
 * judgment, never replaces it, and the produced spec never enables mutation.
 *
 * Pure and deterministic: every returned field is computed from the inputs. No
 * I/O, persistence, clock or randomness.
 */
final class L9OperatorJudgmentModelSpecBuilder
{
    private const SCHEMA_VERSION = 'atlas.aaeos.l9.operator_judgment_model_spec.v1';

    /**
     * Operator decision labels, mirrored from the review/approval gate corpus
     * (allow / confirmation / review / block). Canonical, ordered, deduplicated.
     */
    private const DECISION_LABELS = ['allow', 'confirmation', 'review', 'block'];

    /**
     * Anti-Goodhart anchors: ground-truth bindings that prevent the judgment
     * model from optimising its own proxy metric. Ordered and stable.
     */
    private const ANTI_GOODHART_ANCHORS = [
        'measured_or_reverted',
        'operator_override_is_ground_truth',
        'held_out_operator_decisions',
        'divergence_triggers_relearn',
    ];

    /**
     * @param  array{
     *     decision_count?: mixed,
     *     labeled_examples?: mixed,
     *     rationale_refs?: mixed,
     *     override_refs?: mixed,
     *     features?: mixed,
     *     feature_signals?: mixed,
     *     status?: mixed
     * }  $corpus  Governed corpus of operator engineering decisions (S133 shape).
     * @param  array{
     *     allowed_decision_classes?: mixed,
     *     allowed_risk_classes?: mixed,
     *     max_risk_level?: mixed,
     *     override_channel?: mixed,
     *     override_channel_present?: mixed,
     *     proof_refs?: mixed
     * }  $q2Boundary  The proven Q2 delegation boundary.
     * @return array{
     *     schema_version: string,
     *     model_spec_id: string,
     *     feature_set: list<string>,
     *     labels: list<string>,
     *     delegated_risk_classes: list<string>,
     *     anti_goodhart_anchors: list<string>,
     *     override_contract: array{
     *         override_channel: string,
     *         operator_can_override: bool,
     *         override_is_ground_truth: bool,
     *         every_pre_decision_carries_receipt: bool,
     *         divergence_action: string
     *     },
     *     enables_mutation: bool,
     *     status: string,
     *     blockers: list<string>
     * }
     */
    public function build(array $corpus, array $q2Boundary): array
    {
        $featureSet = $this->featureSet($corpus);
        $delegatedRiskClasses = $this->delegatedRiskClasses($q2Boundary);
        $overrideChannel = $this->overrideChannel($q2Boundary);

        $blockers = [];

        if ($delegatedRiskClasses === [] && ! $this->hasBoundedRiskLevel($q2Boundary)) {
            $blockers[] = 'no_q2_boundary';
        }

        if ($overrideChannel === '') {
            $blockers[] = 'no_override_channel';
        }

        $hasOverride = $overrideChannel !== '';
        $status = $blockers === [] ? 'spec_ready' : 'blocked';

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'model_spec_id' => $this->modelSpecId($featureSet, $delegatedRiskClasses, $overrideChannel),
            'feature_set' => $featureSet,
            'labels' => self::DECISION_LABELS,
            'delegated_risk_classes' => $delegatedRiskClasses,
            'anti_goodhart_anchors' => self::ANTI_GOODHART_ANCHORS,
            'override_contract' => [
                'override_channel' => $overrideChannel,
                'operator_can_override' => $hasOverride,
                'override_is_ground_truth' => $hasOverride,
                'every_pre_decision_carries_receipt' => true,
                'divergence_action' => 'revert_and_relearn',
            ],
            // Design-only spec: it describes the model, it never enables runtime mutation.
            'enables_mutation' => false,
            'status' => $status,
            'blockers' => $blockers,
        ];
    }

    /**
     * Derive the engineering-judgment feature set from the governed corpus.
     *
     * Caller-supplied feature names are honoured (cleaned, deduplicated, ordered);
     * otherwise a canonical judgment feature set is derived from corpus signals.
     *
     * @param  array<string, mixed>  $corpus
     * @return list<string>
     */
    private function featureSet(array $corpus): array
    {
        $explicit = $this->stringList($corpus['features'] ?? $corpus['feature_signals'] ?? null);

        if ($explicit !== []) {
            return $explicit;
        }

        $features = [];

        if ($this->intValue($corpus['decision_count'] ?? null) > 0) {
            $features[] = 'operator_decision_class';
        }

        // labeled_examples in the S133 corpus shape is a list<string>, not a scalar
        // count. countValue() honours both (count() for the list, scalar fallback
        // for a plain int) so the judgment-quality signals are not silently dropped
        // when the real upstream corpus is wired in.
        if ($this->countValue($corpus['labeled_examples'] ?? null) > 0) {
            $features[] = 'code_taste_signal';
            $features[] = 'architecture_pattern_signal';
            $features[] = 'quality_bar_signal';
        }

        if ($this->countValue($corpus['rationale_refs'] ?? null) > 0) {
            $features[] = 'approve_reject_rationale';
        }

        if ($this->countValue($corpus['override_refs'] ?? null) > 0) {
            $features[] = 'historical_override_signal';
        }

        return $this->normaliseStringList($features);
    }

    /**
     * Delegated risk classes are exactly the classes proven safe by the Q2
     * boundary — never wider. The spec inherits the boundary, it never expands it.
     *
     * @param  array<string, mixed>  $q2Boundary
     * @return list<string>
     */
    private function delegatedRiskClasses(array $q2Boundary): array
    {
        return $this->stringList(
            $q2Boundary['allowed_decision_classes']
                ?? $q2Boundary['allowed_risk_classes']
                ?? null
        );
    }

    /**
     * @param  array<string, mixed>  $q2Boundary
     */
    private function hasBoundedRiskLevel(array $q2Boundary): bool
    {
        if (! array_key_exists('max_risk_level', $q2Boundary)) {
            return false;
        }

        return $this->stringValue($q2Boundary['max_risk_level']) !== '';
    }

    /**
     * @param  array<string, mixed>  $q2Boundary
     */
    private function overrideChannel(array $q2Boundary): string
    {
        $channel = $this->stringValue($q2Boundary['override_channel'] ?? null);

        if ($channel !== '') {
            return $channel;
        }

        return ($q2Boundary['override_channel_present'] ?? false) === true
            ? 'operator_override'
            : '';
    }

    /**
     * Deterministic spec identity from the load-bearing spec inputs.
     *
     * @param  list<string>  $featureSet
     * @param  list<string>  $delegatedRiskClasses
     */
    private function modelSpecId(array $featureSet, array $delegatedRiskClasses, string $overrideChannel): string
    {
        $seed = implode('|', [
            'operator_judgment_model',
            implode(',', $featureSet),
            implode(',', $delegatedRiskClasses),
            $overrideChannel,
        ]);

        return 'ojms_'.substr(hash('sha256', $seed), 0, 16);
    }

    /**
     * @return list<string>
     */
    private function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $strings = [];

        foreach ($value as $item) {
            if (is_string($item)) {
                $strings[] = $item;
            } elseif (is_int($item) || is_float($item)) {
                $strings[] = (string) $item;
            }
        }

        return $this->normaliseStringList($strings);
    }

    /**
     * Trim, drop empties, deduplicate, and re-index to a clean list<string>.
     *
     * @param  list<string>  $values
     * @return list<string>
     */
    private function normaliseStringList(array $values): array
    {
        $result = [];

        foreach ($values as $value) {
            $trimmed = trim($value);

            if ($trimmed === '') {
                continue;
            }

            // Dedupe on the value itself (strict) so numeric-looking strings such
            // as '7' keep their string type — array keys would coerce them to int
            // and silently break the list<string> contract.
            if (in_array($trimmed, $result, true)) {
                continue;
            }

            $result[] = $trimmed;
        }

        return $result;
    }

    private function intValue(mixed $value): int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_float($value)) {
            return (int) $value;
        }

        if (is_string($value) && is_numeric($value)) {
            return (int) $value;
        }

        return 0;
    }

    private function countValue(mixed $value): int
    {
        if (is_array($value)) {
            return count($value);
        }

        return $this->intValue($value);
    }

    private function stringValue(mixed $value): string
    {
        if (is_string($value)) {
            return trim($value);
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        return '';
    }
}
