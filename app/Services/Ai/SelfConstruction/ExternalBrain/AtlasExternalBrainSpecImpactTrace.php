<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Value-object builder for the spec impact trace.
 *
 * Every originated task carries one trace that proves its value before
 * a muscle spends tokens. The trace links:
 *   evidence_refs      — where the opportunity evidence comes from
 *   thesis             — why this task will deliver real value
 *   leverage_dimensions — which leverage axes are scored
 *   leverage_score     — aggregate (caller-supplied or computed average)
 *   capability_delta   — what capability the system gains when the task ships
 *   acceptance_proof   — a falsifiable acceptance criterion
 *   task_id            — the task being traced
 *
 * A trace is "verifiable" only when all three evidence pillars are present:
 *   1. evidence_origin    — at least one non-empty evidence_ref
 *   2. capability_delta   — non-blank description of the gained capability
 *   3. falsifiable_acceptance — acceptance_proof ≥ MIN_ACCEPTANCE_LENGTH chars
 *
 * Traces that fail any pillar are marked unverifiable=true with the reasons
 * listed so the audit layer can reject or quarantine them before dispatch.
 *
 * Pure / deterministic. No I/O.
 */
final class AtlasExternalBrainSpecImpactTrace
{
    public const SCHEMA = 'atlas.external_brain.spec_impact_trace.v1';

    /** Minimum character length for an acceptance_proof to be considered falsifiable. */
    private const MIN_ACCEPTANCE_LENGTH = 20;

    /**
     * Build and validate a spec impact trace from raw input.
     *
     * Expected input keys (all optional; missing → marked unverifiable):
     *   task_id             string
     *   evidence_refs       string[]
     *   thesis              string
     *   leverage_dimensions array<string,float>
     *   leverage_score      float      (if omitted, computed as average of leverage_dimensions)
     *   capability_delta    string
     *   acceptance_proof    string
     *
     * @param  array<string,mixed>  $input
     * @return array{
     *     schema:                string,
     *     task_id:               string,
     *     verifiable:            bool,
     *     unverifiable_reasons:  list<string>,
     *     evidence_refs:         list<string>,
     *     thesis:                string,
     *     leverage_dimensions:   array<string,float>,
     *     leverage_score:        float,
     *     capability_delta:      string,
     *     acceptance_proof:      string,
     * }
     */
    public function trace(array $input): array
    {
        $taskId             = trim((string) ($input['task_id'] ?? ''));
        $thesis             = trim((string) ($input['thesis'] ?? ''));
        $capDelta           = trim((string) ($input['capability_delta'] ?? ''));
        $acceptance         = trim((string) ($input['acceptance_proof'] ?? ''));
        $evidenceAfterCommit = trim((string) ($input['evidence_after_commit'] ?? ''));
        $riskReduction      = trim((string) ($input['risk_reduction'] ?? ''));
        $falsificationSignal = trim((string) ($input['falsification_signal'] ?? ''));
        $downstreamUnlocks  = array_values(array_filter(
            array_map('trim', (array) ($input['downstream_unlocks'] ?? [])),
            static fn (string $u): bool => $u !== '',
        ));

        $evidenceRefs = array_values(array_filter(
            array_map('trim', (array) ($input['evidence_refs'] ?? [])),
            static fn (string $r): bool => $r !== '',
        ));

        $dimensions = $this->normalizeDimensions((array) ($input['leverage_dimensions'] ?? []));
        $leverageScore = isset($input['leverage_score'])
            ? (float) $input['leverage_score']
            : $this->averageDimensions($dimensions);

        $cosmeticOnly = (bool) ($input['cosmetic_only'] ?? false);
        $metricOnly   = (bool) ($input['metric_only'] ?? false);

        $unverifiableReasons = $this->computeUnverifiableReasons($evidenceRefs, $capDelta, $acceptance, $evidenceAfterCommit, $cosmeticOnly, $metricOnly);

        return [
            'schema'                => self::SCHEMA,
            'task_id'               => $taskId,
            'verifiable'            => $unverifiableReasons === [],
            'unverifiable_reasons'  => $unverifiableReasons,
            'evidence_refs'         => $evidenceRefs,
            'thesis'                => $thesis,
            'leverage_dimensions'   => $dimensions,
            'leverage_score'        => round($leverageScore, 4),
            'capability_delta'      => $capDelta,
            'acceptance_proof'      => $acceptance,
            'downstream_unlocks'    => $downstreamUnlocks,
            'risk_reduction'        => $riskReduction,
            'evidence_after_commit' => $evidenceAfterCommit,
            'falsification_signal'  => $falsificationSignal,
        ];
    }

    /**
     * Validate a batch of raw spec inputs and return them partitioned into
     * verifiable and unverifiable groups.
     *
     * @param  list<array<string,mixed>>  $specs
     * @return array{
     *     verifiable:   list<array<string,mixed>>,
     *     unverifiable: list<array<string,mixed>>,
     *     stats:        array<string,int>,
     * }
     */
    public function audit(array $specs): array
    {
        $verifiable   = [];
        $unverifiable = [];

        foreach ($specs as $spec) {
            $traced = $this->trace($spec);
            if ($traced['verifiable']) {
                $verifiable[] = $traced;
            } else {
                $unverifiable[] = $traced;
            }
        }

        return [
            'verifiable'   => $verifiable,
            'unverifiable' => $unverifiable,
            'stats'        => [
                'total'           => count($specs),
                'verifiable_count' => count($verifiable),
                'unverifiable_count' => count($unverifiable),
            ],
        ];
    }

    /**
     * @param  string[]  $evidenceRefs
     * @return list<string>
     */
    private function computeUnverifiableReasons(
        array $evidenceRefs,
        string $capDelta,
        string $acceptance,
        string $evidenceAfterCommit,
        bool $cosmeticOnly,
        bool $metricOnly,
    ): array {
        $reasons = [];

        if ($evidenceRefs === []) {
            $reasons[] = 'no_evidence_origin';
        }

        if ($capDelta === '') {
            $reasons[] = 'no_capability_delta';
        }

        if (mb_strlen($acceptance) < self::MIN_ACCEPTANCE_LENGTH) {
            $reasons[] = 'no_falsifiable_acceptance';
        }

        if ($evidenceAfterCommit === '') {
            $reasons[] = 'no_evidence_after_commit';
        }

        if ($cosmeticOnly) {
            $reasons[] = 'cosmetic_only_impact';
        }

        if ($metricOnly) {
            $reasons[] = 'metric_only_impact';
        }

        return $reasons;
    }

    /**
     * @param  array<mixed,mixed>  $raw
     * @return array<string,float>
     */
    private function normalizeDimensions(array $raw): array
    {
        $out = [];
        foreach ($raw as $key => $value) {
            $out[(string) $key] = (float) $value;
        }

        return $out;
    }

    /** @param array<string,float> $dimensions */
    private function averageDimensions(array $dimensions): float
    {
        if ($dimensions === []) {
            return 0.0;
        }

        return array_sum($dimensions) / count($dimensions);
    }
}
