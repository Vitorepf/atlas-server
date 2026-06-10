<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

/**
 * Pure L9-Q1 pre-decision gate.
 *
 * It allows the system to pre-decide an engineering action (collapsing
 * intent->validated-result latency) ONLY inside the proven Q2 delegation
 * boundary, with sufficient confidence and explicit evidence, and always with
 * an operator override path open. Outside the proven limits the gate refuses to
 * auto-decide and routes the request to human review.
 *
 * The gate is read-only and deterministic: it performs no persistence, no
 * provider call and no mutation. The Q2 boundary, the request decision class,
 * the confidence and the evidence references are all consumed as
 * already-computed inputs. The decision-class vocabulary mirrors the operator
 * approval gates (allow_auto / require_review).
 */
final class L9OperatorPreDecisionGate
{
    private const SCHEMA_VERSION = 'atlas.aaeos.l9.operator_pre_decision_gate.v1';

    private const CONFIDENCE_THRESHOLD = 0.70;

    private const DECISION_ALLOW = 'allow_auto';

    private const DECISION_REVIEW = 'require_review';

    /**
     * Risk ladder, lowest to highest. An index is the proven ceiling.
     *
     * @var list<string>
     */
    private const RISK_LADDER = ['low', 'medium', 'high', 'critical'];

    /**
     * @param  array<string,mixed>  $request
     * @param  array<string,mixed>  $modelSpec
     * @param  array<string,mixed>  $q2Boundary
     * @return array<string,mixed>
     */
    public function decide(array $request, array $modelSpec, array $q2Boundary): array
    {
        $requestedClass = AreaFocusScalarNormalizer::payloadTrimmedString($request, 'decision_class');
        $allowedClasses = $this->allowedDecisionClasses($q2Boundary);
        $evidenceRefs = $this->evidenceRefs($request);

        $blockers = [];

        $withinClass = $requestedClass !== '' && in_array($requestedClass, $allowedClasses, true);
        $withinRisk = $this->riskWithinBoundary($request, $q2Boundary);
        $modelGoverned = $this->modelSpecGoverned($modelSpec);

        if (! $modelGoverned) {
            $blockers[] = 'model_spec_not_governed';
        }

        if (! $withinClass) {
            $blockers[] = 'decision_class_outside_boundary';
        }

        if (! $withinRisk) {
            $blockers[] = 'risk_above_proven_boundary';
        }

        $confidence = $this->confidence($request);
        $deficit = $this->confidenceDeficit($confidence);

        // Decide on the true threshold, not the rounded deficit: a confidence in
        // the sub-rounding band just below the threshold (e.g. 0.69999) still has
        // a real deficit and must block, even though it rounds to 0.0 for display.
        if ($confidence < self::CONFIDENCE_THRESHOLD) {
            $blockers[] = 'confidence_below_threshold';
        }

        if ($evidenceRefs === []) {
            $blockers[] = 'evidence_missing';
        }

        $insideBoundary = $modelGoverned && $withinClass && $withinRisk;
        $allowed = $blockers === [];
        $requiredHumanReview = ! $allowed;

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'allowed' => $allowed,
            'decision_class' => $allowed ? self::DECISION_ALLOW : self::DECISION_REVIEW,
            'required_human_review' => $requiredHumanReview,
            'evidence_refs' => $evidenceRefs,
            'blockers' => $blockers,
            'inside_boundary' => $insideBoundary,
            'confidence' => $confidence,
            'confidence_threshold' => self::CONFIDENCE_THRESHOLD,
            'confidence_deficit' => $deficit,
            'operator_override_available' => true,
        ];
    }

    /**
     * @param  array<string,mixed>  $q2Boundary
     * @return list<string>
     */
    private function allowedDecisionClasses(array $q2Boundary): array
    {
        $classes = $q2Boundary['allowed_decision_classes'] ?? [];

        if (! is_array($classes)) {
            return [];
        }

        $normalized = [];

        foreach ($classes as $class) {
            // Store the trimmed class so the membership test is normalized on
            // BOTH sides: the requested class is already trimmed (stringValue),
            // so a whitespace-padded boundary entry like " low_risk_refactor "
            // must not silently fail the strict in_array(..., true) match and
            // route a legitimately in-boundary request to human review.
            if (is_string($class) && trim($class) !== '') {
                $normalized[] = trim($class);
            }
        }

        return AreaFocusStringListNormalizer::uniqueStringValues($normalized);
    }

    /**
     * @param  array<string,mixed>  $request
     * @return list<string>
     */
    private function evidenceRefs(array $request): array
    {
        $refs = $request['evidence_refs'] ?? [];

        if (is_string($refs)) {
            $refs = trim($refs) === '' ? [] : [$refs];
        }

        if (! is_array($refs)) {
            return [];
        }

        $normalized = [];

        foreach ($refs as $ref) {
            if (is_string($ref) && trim($ref) !== '') {
                $normalized[] = $ref;
            }
        }

        return AreaFocusStringListNormalizer::uniqueStringValues($normalized);
    }

    /**
     * @param  array<string,mixed>  $request
     * @param  array<string,mixed>  $q2Boundary
     */
    private function riskWithinBoundary(array $request, array $q2Boundary): bool
    {
        $maxIndex = $this->riskIndex(AreaFocusScalarNormalizer::payloadTrimmedString($q2Boundary, 'max_risk_level'));

        if ($maxIndex < 0) {
            return false;
        }

        $requestedIndex = $this->riskIndex(AreaFocusScalarNormalizer::payloadTrimmedString($request, 'risk_level'));

        if ($requestedIndex < 0) {
            return false;
        }

        return $requestedIndex <= $maxIndex;
    }

    private function riskIndex(string $level): int
    {
        $index = array_search($level, self::RISK_LADDER, true);

        return $index === false ? -1 : $index;
    }

    /** @param array<string,mixed> $modelSpec */
    private function modelSpecGoverned(array $modelSpec): bool
    {
        $hasId = AreaFocusScalarNormalizer::payloadTrimmedString($modelSpec, 'model_spec_id') !== '';

        return $hasId && $this->hasOperatorOverridePath($modelSpec);
    }

    /**
     * The L9 gate may only pre-decide when the operator override path is
     * genuinely open. An override contract counts as present only when it is a
     * non-empty array or a non-blank string; falsy scalars (false, 0, "0") and
     * whitespace-only strings carry no usable override and must not unlock
     * auto-decision.
     *
     * @param  array<string,mixed>  $modelSpec
     */
    private function hasOperatorOverridePath(array $modelSpec): bool
    {
        $contract = $modelSpec['override_contract'] ?? null;

        if (is_array($contract)) {
            return $contract !== [];
        }

        if (is_string($contract)) {
            return trim($contract) !== '';
        }

        return false;
    }

    /** @param array<string,mixed> $request */
    private function confidence(array $request): float
    {
        $value = $request['confidence'] ?? 0.0;

        if (! is_numeric($value)) {
            return 0.0;
        }

        $confidence = (float) $value;

        // A non-finite confidence (NAN or ±INF) is not a real measurement and
        // must never unlock auto-decision: NAN slips past every comparison
        // (NAN < threshold is false, so the low-confidence blocker would not
        // fire) and +INF would clamp up to 1.0 and read as maximum confidence.
        // Fail closed: treat any non-finite value as zero confidence.
        if (! is_finite($confidence)) {
            return 0.0;
        }

        if ($confidence < 0.0) {
            return 0.0;
        }

        if ($confidence > 1.0) {
            return 1.0;
        }

        return $confidence;
    }

    private function confidenceDeficit(float $confidence): float
    {
        $deficit = self::CONFIDENCE_THRESHOLD - $confidence;

        return $deficit > 0.0 ? round($deficit, 4) : 0.0;
    }
}
