<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

/**
 * S136 — L9OperatorOverrideLearningLoop (L9 Sovereign Engineering, Q1).
 *
 * Turns operator overrides into measured-or-reverted model learning signals and
 * demotes unsafe divergence. When the operator disagrees with a pre-decision the
 * loop made, that disagreement must TEACH (relearn the judgment model) or DEMOTE
 * (when the divergence is systematic / unsafe). Critically, it must NEVER be
 * hidden: a hidden override is rejected, but the rejection itself is surfaced as
 * an evidence ref so the disagreement is always auditable.
 *
 * The loop is PURE: every returned field is derived from the supplied
 * pre-decisions and overrides with no IO, DB, clock, randomness or provider
 * calls. It mutates NOTHING — it only measures the override surface and emits the
 * learning verdict. (Mirrors the L9 sovereign-engineering Q1 gate: the operator's
 * judgment model is measured-or-reverted, every pre-decision carries a receipt and
 * is auditable, and the system demotes on any unsafe divergence signal.)
 */
final class L9OperatorOverrideLearningLoop
{
    private const SCHEMA_VERSION = 'atlas.loop.l9_operator_override_learning.v1';

    /**
     * Number of visible divergences inside a single cluster at or above which the
     * divergence is treated as systematic / unsafe and the cluster forces a
     * demote. A single isolated disagreement teaches (relearn); a repeated
     * disagreement in the same cluster demotes — "divergence above threshold
     * demotes".
     */
    private const DIVERGENCE_DEMOTE_THRESHOLD = 2;

    private const CLUSTER_FALLBACK = 'unclustered';

    private const KIND_DIVERGENCE = 'visible_divergence';

    private const KIND_AGREEMENT = 'visible_agreement';

    private const KIND_REJECTED_HIDDEN = 'rejected_hidden_override';

    /**
     * @param  array<array-key, mixed>  $preDecisions  list of {id, decision, risk_class|cluster}
     * @param  array<array-key, mixed>  $overrides  list of {pre_decision_id|id, operator_decision|decision, visible|hidden|receipt_ref|evidence_ref, risk_class|cluster}
     * @return array{
     *     schema_version: string,
     *     pre_decision_count: int,
     *     override_count: int,
     *     accepted_override_count: int,
     *     rejected_hidden_count: int,
     *     visible_divergence_count: int,
     *     override_rate: float,
     *     divergence_clusters: list<array{cluster: string, divergence_count: int, demote: bool}>,
     *     demote_required: bool,
     *     relearn_required: bool,
     *     mutated: bool,
     *     evidence_refs: list<string>
     * }
     */
    public function learn(array $preDecisions, array $overrides): array
    {
        $decisionIndex = $this->indexPreDecisions($preDecisions);
        $preDecisionCount = count($decisionIndex);

        $overrideCount = 0;
        $rejectedHiddenCount = 0;
        $acceptedCount = 0;
        $visibleDivergenceCount = 0;

        /** @var array<string, int> $clusterDivergence */
        $clusterDivergence = [];

        /** @var list<string> $evidenceRefs */
        $evidenceRefs = [];

        foreach ($overrides as $position => $rawOverride) {
            if (! is_array($rawOverride)) {
                continue;
            }

            $overrideCount++;

            $overrideId = $this->overrideId($rawOverride, $position);
            $matched = $decisionIndex[$overrideId] ?? null;
            $cluster = $this->resolveCluster($rawOverride, $matched);

            if ($this->isHidden($rawOverride)) {
                // A hidden override is rejected — it never silently mutates the
                // model. The rejection is recorded as evidence so the operator's
                // disagreement is auditable, never hidden.
                $rejectedHiddenCount++;
                $evidenceRefs[] = $this->evidenceRef(
                    self::KIND_REJECTED_HIDDEN,
                    $overrideId,
                    $rawOverride,
                );

                continue;
            }

            $acceptedCount++;

            if ($this->isDivergent($rawOverride, $matched)) {
                $visibleDivergenceCount++;
                $clusterDivergence[$cluster] = ($clusterDivergence[$cluster] ?? 0) + 1;
                $evidenceRefs[] = $this->evidenceRef(self::KIND_DIVERGENCE, $overrideId, $rawOverride);

                continue;
            }

            $evidenceRefs[] = $this->evidenceRef(self::KIND_AGREEMENT, $overrideId, $rawOverride);
        }

        $clusters = $this->buildClusters($clusterDivergence);

        $overrideRate = $preDecisionCount > 0
            ? AreaFocusScalarNormalizer::clampUnit($visibleDivergenceCount / $preDecisionCount)
            : 0.0;

        $clusterDemote = false;
        foreach ($clusters as $cluster) {
            if ($cluster['demote'] === true) {
                $clusterDemote = true;
                break;
            }
        }

        // Unsafe divergence demotes: either a hidden override was attempted
        // (the operator's disagreement was being concealed) or a cluster crossed
        // the systematic-divergence threshold.
        $demoteRequired = $rejectedHiddenCount > 0 || $clusterDemote;

        // Any visible disagreement is a measured learning signal: it teaches the
        // judgment model (relearn) even when it is below the demote threshold.
        $relearnRequired = $visibleDivergenceCount > 0;

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'pre_decision_count' => $preDecisionCount,
            'override_count' => $overrideCount,
            'accepted_override_count' => $acceptedCount,
            'rejected_hidden_count' => $rejectedHiddenCount,
            'visible_divergence_count' => $visibleDivergenceCount,
            'override_rate' => $overrideRate,
            'divergence_clusters' => $clusters,
            'demote_required' => $demoteRequired,
            'relearn_required' => $relearnRequired,
            'mutated' => false,
            'evidence_refs' => array_values($evidenceRefs),
        ];
    }

    /**
     * Build a stable index of pre-decisions keyed by their identifier. Later
     * duplicates do not overwrite an earlier decision for the same id, so the
     * matched baseline is deterministic.
     *
     * @param  array<array-key, mixed>  $preDecisions
     * @return array<string, array<string, mixed>>
     */
    private function indexPreDecisions(array $preDecisions): array
    {
        $index = [];

        foreach ($preDecisions as $position => $rawDecision) {
            if (! is_array($rawDecision)) {
                continue;
            }

            $id = $this->preDecisionId($rawDecision, $position);

            if ($id === '' || array_key_exists($id, $index)) {
                continue;
            }

            $index[$id] = $rawDecision;
        }

        return $index;
    }

    /**
     * Assemble the ordered divergence clusters. Sorted by divergence_count
     * descending then cluster ascending so identical input always yields the
     * same ordered list<…>.
     *
     * @param  array<string, int>  $clusterDivergence
     * @return list<array{cluster: string, divergence_count: int, demote: bool}>
     */
    private function buildClusters(array $clusterDivergence): array
    {
        $clusters = [];

        foreach ($clusterDivergence as $cluster => $count) {
            $clusters[] = [
                'cluster' => (string) $cluster,
                'divergence_count' => $count,
                'demote' => $count >= self::DIVERGENCE_DEMOTE_THRESHOLD,
            ];
        }

        usort($clusters, static function (array $left, array $right): int {
            if ($left['divergence_count'] !== $right['divergence_count']) {
                return $right['divergence_count'] <=> $left['divergence_count'];
            }

            return strcmp($left['cluster'], $right['cluster']);
        });

        return array_values($clusters);
    }

    /**
     * A hidden override is one not surfaced for audit: explicitly flagged hidden,
     * explicitly marked not visible, or lacking any visibility proof (no truthy
     * `visible` flag AND no non-empty receipt/evidence ref).
     *
     * @param  array<string, mixed>  $override
     */
    private function isHidden(array $override): bool
    {
        if (($override['hidden'] ?? false) === true) {
            return true;
        }

        if (array_key_exists('visible', $override)) {
            return $override['visible'] !== true;
        }

        return ! $this->hasVisibilityProof($override);
    }

    /**
     * @param  array<string, mixed>  $override
     */
    private function hasVisibilityProof(array $override): bool
    {
        foreach (['receipt_ref', 'evidence_ref', 'ref', 'receipt_id'] as $field) {
            $candidate = $override[$field] ?? null;
            if (is_string($candidate) && trim($candidate) !== '') {
                return true;
            }
        }

        return false;
    }

    /**
     * The operator override diverges from the loop when the operator's decision
     * differs from the matched pre-decision. With no matched baseline an override
     * is treated as divergent: it changed something the loop never decided, which
     * is itself a disagreement to learn from.
     *
     * @param  array<string, mixed>  $override
     * @param  array<string, mixed>|null  $matched
     */
    private function isDivergent(array $override, ?array $matched): bool
    {
        $operatorDecision = $this->decisionValue($override, ['operator_decision', 'decision', 'verdict', 'action']);

        if ($matched === null) {
            return true;
        }

        $loopDecision = $this->decisionValue($matched, ['decision', 'verdict', 'action']);

        return $operatorDecision !== $loopDecision;
    }

    /**
     * Resolve the divergence cluster: prefer the override's own cluster, fall back
     * to the matched pre-decision's cluster, then to a stable fallback.
     *
     * @param  array<string, mixed>  $override
     * @param  array<string, mixed>|null  $matched
     */
    private function resolveCluster(array $override, ?array $matched): string
    {
        $fromOverride = $this->clusterValue($override);
        if ($fromOverride !== '') {
            return $fromOverride;
        }

        if ($matched !== null) {
            $fromMatched = $this->clusterValue($matched);
            if ($fromMatched !== '') {
                return $fromMatched;
            }
        }

        return self::CLUSTER_FALLBACK;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function clusterValue(array $payload): string
    {
        foreach (['cluster', 'risk_class', 'scope', 'category'] as $field) {
            $candidate = $payload[$field] ?? null;
            if (is_string($candidate) && trim($candidate) !== '') {
                return trim($candidate);
            }
            if (is_int($candidate)) {
                return (string) $candidate;
            }
        }

        return '';
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  list<string>  $fields
     */
    private function decisionValue(array $payload, array $fields): string
    {
        foreach ($fields as $field) {
            if (! array_key_exists($field, $payload)) {
                continue;
            }

            $value = $payload[$field];
            if (is_string($value)) {
                return trim($value);
            }
            if (is_int($value)) {
                return (string) $value;
            }
            if (is_bool($value)) {
                return $value ? 'true' : 'false';
            }
        }

        return '';
    }

    /**
     * @param  array<string, mixed>  $override
     */
    private function overrideId(array $override, int|string $position): string
    {
        return $this->stringId($override, ['pre_decision_id', 'decision_id', 'id'], $position);
    }

    /**
     * @param  array<string, mixed>  $decision
     */
    private function preDecisionId(array $decision, int|string $position): string
    {
        return $this->stringId($decision, ['id', 'decision_id', 'pre_decision_id'], $position);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  list<string>  $fields
     */
    private function stringId(array $payload, array $fields, int|string $position): string
    {
        foreach ($fields as $field) {
            $candidate = $payload[$field] ?? null;
            if (is_string($candidate) && trim($candidate) !== '') {
                return trim($candidate);
            }
            if (is_int($candidate)) {
                return (string) $candidate;
            }
        }

        return 'position:'.(string) $position;
    }

    /**
     * Build a deterministic, audit-stable evidence ref for one processed
     * override. Prefers the override's own evidence/receipt ref; otherwise emits a
     * kind-tagged ref keyed by the override id so the disagreement is never hidden.
     *
     * @param  array<string, mixed>  $override
     */
    private function evidenceRef(string $kind, string $overrideId, array $override): string
    {
        foreach (['evidence_ref', 'receipt_ref', 'ref', 'receipt_id'] as $field) {
            $candidate = $override[$field] ?? null;
            if (is_string($candidate) && trim($candidate) !== '') {
                return $kind.':'.trim($candidate);
            }
        }

        return $kind.':'.$overrideId;
    }
}
