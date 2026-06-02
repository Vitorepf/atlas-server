<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

/**
 * S118 — L8 Transcendence / P3 (Predictive twin).
 *
 * Builds the forward-model INPUTS for the system-evolution twin from real
 * evolution history. Each historical evolution event (a frame proposal that was
 * proposed, replayed and measured) becomes a twin candidate carrying the
 * measured pre-change baseline, an UNFILLED prediction slot and references to
 * the measured outcomes.
 *
 * Doctrine: "Twin input is grounded in real evolution history." This builder is
 * the INPUT side only — it never predicts. The predicted_metrics_slot is a
 * structured placeholder (every value null, status unfilled) that a downstream
 * scorer fills; emitting a prediction here would both break purity and let the
 * twin trust itself. Events that are not grounded in a measured baseline AND at
 * least one measured outcome ref are dropped, so a fabricated or synthetic
 * "history" can never manufacture a candidate.
 *
 * Pure: every returned field is computed from build() input via real rules
 * (deterministic slugging, numeric coercion of the supplied series, set
 * grounding). No I/O, DB, Eloquent, facades, provider/HTTP, git/process,
 * filesystem, clock, or randomness. No provider call.
 */
final class L8SystemEvolutionTwinInputBuilder
{
    private const SCHEMA_VERSION = 'atlas.aaeos.l8.system_evolution_twin_input.v1';

    /**
     * Sentinel for the prediction slot status. The slot is always emitted
     * unfilled — the twin (S119), not this builder, fills it.
     */
    private const PREDICTION_SLOT_STATUS = 'unfilled';

    /**
     * @param  array<string, mixed>  $history
     * @return array{
     *     schema_version: string,
     *     insufficient_evidence: bool,
     *     no_provider_call: bool,
     *     candidate_count: int,
     *     event_count: int,
     *     rejected_event_ids: list<string>,
     *     candidates: list<array{
     *         evolution_id: string,
     *         frame_proposal_ref: string,
     *         baseline_metrics: array<string, float>,
     *         predicted_metrics_slot: array{
     *             status: string,
     *             filled: bool,
     *             metrics: array<string, null>
     *         },
     *         dm_dt_series: list<float>,
     *         outcome_refs: list<string>,
     *         grounded_in_history: bool
     *     }>
     * }
     */
    public function build(array $history): array
    {
        $events = $this->events($history);

        $candidates = [];
        $rejectedEventIds = [];
        $seen = [];

        foreach ($events as $event) {
            if (! is_array($event)) {
                continue;
            }

            $evolutionId = $this->evolutionId($event);

            if ($evolutionId === '' || isset($seen[$evolutionId])) {
                continue;
            }

            // First occurrence of an id decides its fate (keep-first doctrine), so an
            // id lands in exactly one partition: a later duplicate can neither double
            // a rejection nor flip an already-rejected id into a candidate. Mark it
            // seen before branching to keep accepted/rejected mutually exclusive.
            $seen[$evolutionId] = true;

            $baselineMetrics = $this->baselineMetrics($event);
            $outcomeRefs = $this->outcomeRefs($event);

            // Grounding rule: a candidate exists only when the event carries a
            // real measured baseline AND at least one measured outcome ref.
            // Anything else is "history" the twin must not model from.
            if ($baselineMetrics === [] || $outcomeRefs === []) {
                $rejectedEventIds[] = $evolutionId;

                continue;
            }

            $candidates[] = [
                'evolution_id' => $evolutionId,
                'frame_proposal_ref' => $this->frameProposalRef($event, $evolutionId),
                'baseline_metrics' => $baselineMetrics,
                'predicted_metrics_slot' => $this->predictionSlot($baselineMetrics),
                'dm_dt_series' => $this->dmDtSeries($event),
                'outcome_refs' => $outcomeRefs,
                'grounded_in_history' => true,
            ];
        }

        usort(
            $candidates,
            static fn (array $a, array $b): int => strcmp($a['evolution_id'], $b['evolution_id'])
        );

        sort($rejectedEventIds, SORT_STRING);

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'insufficient_evidence' => $candidates === [],
            'no_provider_call' => true,
            'candidate_count' => count($candidates),
            'event_count' => count($events),
            'rejected_event_ids' => $rejectedEventIds,
            'candidates' => array_values($candidates),
        ];
    }

    /**
     * @param  array<string, mixed>  $history
     * @return list<mixed>
     */
    private function events(array $history): array
    {
        $events = $history['evolution_history']
            ?? $history['events']
            ?? $history['history']
            ?? [];

        if (! is_array($events)) {
            return [];
        }

        return array_values($events);
    }

    /**
     * @param  array<string, mixed>  $event
     */
    private function evolutionId(array $event): string
    {
        $id = $event['evolution_id']
            ?? $event['id']
            ?? $event['frame_proposal_id']
            ?? '';

        if (! is_string($id) && ! is_int($id) && ! is_float($id)) {
            return '';
        }

        return $this->slug((string) $id);
    }

    /**
     * @param  array<string, mixed>  $event
     */
    private function frameProposalRef(array $event, string $evolutionId): string
    {
        foreach (['frame_proposal_ref', 'frame_proposal_id', 'proposal_ref', 'proposal_id'] as $key) {
            $value = $this->stringValue($event, $key);

            if ($value !== '') {
                return $value;
            }
        }

        return 'evolution:' . $evolutionId;
    }

    /**
     * Measured pre-change metrics, normalized to a name => float map. Only
     * finite numeric values survive; everything else is dropped so a fabricated
     * non-numeric "metric" cannot pollute the baseline. Keys are slugged and
     * the map is ordered deterministically (ksort) for stable output.
     *
     * @param  array<string, mixed>  $event
     * @return array<string, float>
     */
    private function baselineMetrics(array $event): array
    {
        $raw = $event['baseline_metrics'] ?? $event['baseline'] ?? null;

        if (! is_array($raw)) {
            return [];
        }

        $metrics = [];

        foreach ($raw as $name => $value) {
            if (! is_string($name) && ! is_int($name)) {
                continue;
            }

            $key = $this->slug((string) $name);

            if ($key === '' || isset($metrics[$key])) {
                continue;
            }

            $number = $this->toFloat($value);

            if ($number === null) {
                continue;
            }

            $metrics[$key] = $number;
        }

        ksort($metrics, SORT_STRING);

        return $metrics;
    }

    /**
     * The prediction slot: a structured placeholder keyed by exactly the
     * baseline metric names, every value null, status unfilled. The builder
     * never predicts — this is the slot the twin will populate.
     *
     * @param  array<string, float>  $baselineMetrics
     * @return array{status: string, filled: bool, metrics: array<string, null>}
     */
    private function predictionSlot(array $baselineMetrics): array
    {
        $metrics = [];

        foreach ($baselineMetrics as $name => $_value) {
            $metrics[$name] = null;
        }

        return [
            'status' => self::PREDICTION_SLOT_STATUS,
            'filled' => false,
            'metrics' => $metrics,
        ];
    }

    /**
     * Real dM/dt series for the event, coerced to finite floats in order.
     *
     * @param  array<string, mixed>  $event
     * @return list<float>
     */
    private function dmDtSeries(array $event): array
    {
        $raw = $event['dm_dt_series'] ?? $event['dm_dt'] ?? null;

        if (! is_array($raw)) {
            return [];
        }

        $series = [];

        foreach ($raw as $value) {
            $number = $this->toFloat($value);

            if ($number === null) {
                continue;
            }

            $series[] = $number;
        }

        return $series;
    }

    /**
     * References to the measured outcomes for the event, de-duplicated and
     * order-preserving as a clean list<string> (no int-key coercion).
     *
     * @param  array<string, mixed>  $event
     * @return list<string>
     */
    private function outcomeRefs(array $event): array
    {
        $raw = $event['outcome_refs']
            ?? $event['measured_outcome_refs']
            ?? $event['outcomes']
            ?? null;

        if (! is_array($raw)) {
            return [];
        }

        $refs = [];

        foreach ($raw as $value) {
            $ref = $this->outcomeRefValue($value);

            if ($ref === '' || in_array($ref, $refs, true)) {
                continue;
            }

            $refs[] = $ref;
        }

        return $refs;
    }

    /**
     * An outcome may be a bare ref string or a measured-outcome record carrying
     * its own ref/id. Only references that point at a real measured outcome
     * survive — a record explicitly flagged not-measured is dropped.
     *
     * @param  mixed  $value
     */
    private function outcomeRefValue(mixed $value): string
    {
        if (is_string($value)) {
            return trim($value);
        }

        if (is_int($value) || is_float($value)) {
            return trim((string) $value);
        }

        if (! is_array($value)) {
            return '';
        }

        if (($value['measured'] ?? true) === false) {
            return '';
        }

        foreach (['ref', 'outcome_ref', 'id', 'outcome_id'] as $key) {
            $ref = $this->stringValue($value, $key);

            if ($ref !== '') {
                return $ref;
            }
        }

        return '';
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function stringValue(array $payload, string $key): string
    {
        $value = $payload[$key] ?? null;

        if (is_string($value)) {
            return trim($value);
        }

        if (is_int($value) || is_float($value)) {
            return trim((string) $value);
        }

        return '';
    }

    /**
     * Coerce a value to a finite float, or null when it is not a real number.
     *
     * @param  mixed  $value
     */
    private function toFloat(mixed $value): ?float
    {
        if (is_int($value) || is_float($value)) {
            $number = (float) $value;

            return is_finite($number) ? $number : null;
        }

        if (is_string($value) && is_numeric($value)) {
            $number = (float) $value;

            return is_finite($number) ? $number : null;
        }

        return null;
    }

    private function slug(string $value): string
    {
        $value = strtolower(trim($value));
        $value = (string) preg_replace('/[^a-z0-9]+/', '_', $value);

        return trim($value, '_');
    }
}
