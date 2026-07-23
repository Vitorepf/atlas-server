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
 * target_files / acceptance_gates (AC2) are optional pass-through lists naming the concrete
 * files a spec touches and the concrete gates (test suites, verification commands) it must pass.
 *
 * broken_link (AC3): fires when the caller supplies commit_green=true (the commit landed green)
 * but capability_delta or evidence_after_commit is still blank — a green commit with nothing to
 * show for it is the exact failure mode this trace exists to catch. commit_green omitted or false
 * never triggers broken_link.
 *
 * Pure / deterministic. No I/O.
 */
final class AtlasExternalBrainSpecImpactTrace
{
    public const SCHEMA = 'atlas.external_brain.spec_impact_trace.v1';

    /** Minimum character length for an acceptance_proof to be considered falsifiable. */
    private const MIN_ACCEPTANCE_LENGTH = 20;

    /** @var array<string,string> */
    private const REPAIR_HINTS = [
        'no_evidence_origin' => 'attach at least one evidence_ref (scan/doc/receipt) proving the opportunity is real',
        'no_capability_delta' => 'describe the concrete capability this task grants once shipped',
        'no_downstream_unlocks' => 'name at least one downstream_unlocks consumer, or ground the claim via risk_reduction/simplification/autonomy_gain instead',
        'no_risk_reduction' => 'state what risk this task reduces, or ground the claim via another impact path',
        'no_evidence_after_commit' => 'attach evidence_after_commit describing what will prove the change worked post-merge',
        'no_falsification_signal' => 'add a falsification_signal naming the observable that would prove this hypothesis wrong',
        'no_falsifiable_acceptance' => 'write an acceptance_proof of at least 20 characters naming a runnable, falsifiable check',
        'cosmetic_only_proxy' => 'supply both capability_delta and falsification_signal before marking a cosmetic-only spec as verifiable',
        'metric_only_proxy' => 'supply both capability_delta and falsification_signal before marking a metric-only spec as verifiable',
    ];

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
        $targetFiles = array_values(array_filter(
            array_map('trim', (array) ($input['target_files'] ?? [])),
            static fn (string $f): bool => $f !== '',
        ));
        $acceptanceGates = array_values(array_filter(
            array_map('trim', (array) ($input['acceptance_gates'] ?? [])),
            static fn (string $g): bool => $g !== '',
        ));
        $commitGreen = array_key_exists('commit_green', $input) ? (bool) $input['commit_green'] : null;

        $dimensions = $this->normalizeDimensions((array) ($input['leverage_dimensions'] ?? []));
        $leverageScore = isset($input['leverage_score'])
            ? (float) $input['leverage_score']
            : $this->averageDimensions($dimensions);

        $cosmeticOnly = (bool) ($input['cosmetic_only'] ?? false);
        $metricOnly   = (bool) ($input['metric_only'] ?? false);

        $unverifiableReasons = $this->computeUnverifiableReasons($evidenceRefs, $capDelta, $downstreamUnlocks, $riskReduction, $evidenceAfterCommit, $falsificationSignal, $acceptance, $cosmeticOnly, $metricOnly);

        $simplification = trim((string) ($input['simplification'] ?? ''));
        $autonomyGain   = trim((string) ($input['autonomy_gain'] ?? ''));

        // AC2/AC3: a strategic claim must trace to AT LEAST ONE concrete impact path.
        // Zero paths = ungrounded "strategic" framing with nothing concrete behind it.
        $impactPath = [];
        if ($downstreamUnlocks !== []) {
            $impactPath[] = 'unlocks';
        }
        if ($riskReduction !== '') {
            $impactPath[] = 'risk_reduction';
        }
        if ($simplification !== '') {
            $impactPath[] = 'simplification';
        }
        if ($autonomyGain !== '') {
            $impactPath[] = 'autonomy_gain';
        }
        $insufficientEvidence = $impactPath === [];

        // Confidence: how many of the 4 impact paths are grounded, scaled by leverage_score.
        $confidence = $insufficientEvidence
            ? 0.0
            : round((count($impactPath) / 4) * max(0.25, $leverageScore > 0 ? min(1.0, $leverageScore) : 1.0), 4);

        // AC3: a green commit with no capability or impact evidence is a broken link, regardless
        // of whether every other pillar happens to be filled in.
        $brokenLink = $commitGreen === true && ($capDelta === '' || $evidenceAfterCommit === '');
        $brokenLinkReason = $brokenLink ? 'commit_green_without_capability_or_impact_evidence' : '';

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
            'simplification'        => $simplification,
            'autonomy_gain'         => $autonomyGain,
            'impact_path'           => $impactPath,
            'confidence'            => $confidence,
            'missing_evidence'      => $unverifiableReasons,
            'insufficient_evidence' => $insufficientEvidence,
            'target_files'          => $targetFiles,
            'acceptance_gates'      => $acceptanceGates,
            'broken_link'           => $brokenLink,
            'broken_link_reason'    => $brokenLinkReason,
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

        $reasonCounts = [];
        foreach ($unverifiable as $t) {
            foreach ($t['unverifiable_reasons'] as $r) {
                $reasonCounts[$r] = ($reasonCounts[$r] ?? 0) + 1;
            }
        }

        $total = count($specs);

        // AC4: recommend a concrete repair per untraceable spec, and report batch trace coverage.
        $repairRecommendations = [];
        foreach ($unverifiable as $t) {
            $hints = array_values(array_filter(array_map(
                static fn (string $r): string => self::REPAIR_HINTS[$r] ?? '',
                $t['unverifiable_reasons'],
            ), static fn (string $h): bool => $h !== ''));
            $repairRecommendations[] = [
                'task_id'            => $t['task_id'],
                'unverifiable_reasons' => $t['unverifiable_reasons'],
                'recommended_repair' => $hints,
            ];
        }

        return [
            'verifiable'   => $verifiable,
            'unverifiable' => $unverifiable,
            'stats'        => [
                'total'               => $total,
                'verifiable_count'    => count($verifiable),
                'unverifiable_count'  => count($unverifiable),
                'reason_counts'       => $reasonCounts,
                'coverage_rate'       => $total > 0 ? round(count($verifiable) / $total, 4) : 0.0,
            ],
            'repair_recommendations' => $repairRecommendations,
        ];
    }

    /**
     * @param  string[]  $evidenceRefs
     * @param  string[]  $downstreamUnlocks
     * @return list<string>
     */
    private function computeUnverifiableReasons(
        array $evidenceRefs,
        string $capDelta,
        array $downstreamUnlocks,
        string $riskReduction,
        string $evidenceAfterCommit,
        string $falsificationSignal,
        string $acceptance,
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
        if ($downstreamUnlocks === []) {
            $reasons[] = 'no_downstream_unlocks';
        }
        if ($riskReduction === '') {
            $reasons[] = 'no_risk_reduction';
        }
        if ($evidenceAfterCommit === '') {
            $reasons[] = 'no_evidence_after_commit';
        }
        if ($falsificationSignal === '') {
            $reasons[] = 'no_falsification_signal';
        }
        if (mb_strlen($acceptance) < self::MIN_ACCEPTANCE_LENGTH) {
            $reasons[] = 'no_falsifiable_acceptance';
        }
        // AC3: cosmetic/metric proxies are unverifiable UNLESS explicit cap delta + falsification override both present.
        if ($cosmeticOnly && ($capDelta === '' || $falsificationSignal === '')) {
            $reasons[] = 'cosmetic_only_proxy';
        }
        if ($metricOnly && ($capDelta === '' || $falsificationSignal === '')) {
            $reasons[] = 'metric_only_proxy';
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
