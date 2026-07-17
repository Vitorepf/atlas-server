<?php

declare(strict_types=1);

namespace App\Services\Ai\AgenticEngineeringOs;

use App\Services\Ai\Aaeos\Cores\SpecCompletenessScorer;
use App\Services\Ai\AcosMax\PredictedImpactBand;
use App\Services\Ai\AcosMax\PreReviewAdvisoryBand;
use App\Services\Ai\AcosMax\Esp09IndependentChallengerService;
use App\Services\Ai\AcosMax\DogfoodingFrictionLeadMiner;
use App\Services\Ai\AcosMax\ReactiveSaturationSignal;
use App\Services\Ai\Support\AiValueNormalizer;

/**
 * Atlas Universal Gates Evaluator — produces `atlas.aaeos.gate_report.v1`
 * covering the 15 universal gates of Phase 11 (`gate_evaluation`) in the
 * AAEOS runbook.
 *
 * Each universal gate corresponds to a real signal already produced by a
 * dedicated runtime service. The evaluator does NOT re-implement the
 * checks; it consumes a `signals` map (gate_id => bool|null|"exception")
 * and synthesizes a deterministic report with required/passed/blocked
 * lists, an overall `outcome` (green|red|exception), and the provider-safe
 * receipt hash.
 *
 * Exception semantics: a gate may report `exception` when a department
 * documents a justified bypass (e.g. doc-only change skipping `tests_green`).
 * Exceptions require `exception_receipt_id` in the signal payload.
 */
final class AtlasUniversalGatesEvaluator
{
    public const SCHEMA_VERSION = 'atlas.aaeos.gate_report.v1';

    public function __construct(
        private readonly DeliveryPackCompletenessScorer $deliveryPackCompleteness = new DeliveryPackCompletenessScorer,
        private readonly SpecCompletenessScorer $specCompleteness = new SpecCompletenessScorer,
        private readonly AaeosBlockerSeverityGate $blockerSeverity = new AaeosBlockerSeverityGate,
        private readonly PhaseAdvanceVerdictClassifier $phaseAdvance = new PhaseAdvanceVerdictClassifier,
        private readonly AaeosRequiredGateCoverageChecker $requiredGateCoverage = new AaeosRequiredGateCoverageChecker,
    ) {}

    /**
     * The 15 canonical universal gates of AAEOS Phase 11.
     *
     * Each gate maps to:
     *   - description (provider-safe summary)
     *   - canonical_source (service or evidence schema that produces the signal)
     *
     * @var array<string,array{description:string, canonical_source:string}>
     */
    public const UNIVERSAL_GATES = [
        'lint_green' => [
            'description' => 'Linter passes on all changed files.',
            'canonical_source' => 'tools.lint_runner',
        ],
        'typecheck_green' => [
            'description' => 'Static type check passes (PHPStan/tsc/mypy).',
            'canonical_source' => 'tools.typecheck_runner',
        ],
        'tests_green' => [
            'description' => 'Selected + regression test suite is green.',
            'canonical_source' => 'tools.test_runner',
        ],
        'coverage_min_threshold' => [
            'description' => 'Coverage meets the project floor for the touched scope.',
            'canonical_source' => 'tools.coverage_reporter',
        ],
        'scope_guard_ok' => [
            'description' => 'No file outside declared scope was modified.',
            'canonical_source' => 'governance.scope_guard',
        ],
        'security_scan_clean' => [
            'description' => 'Security scanner (SAST / OWASP) reports no findings above threshold.',
            'canonical_source' => 'security.scan_runner',
        ],
        'dependency_audit_clean' => [
            'description' => 'Dependency CVE audit reports no unacknowledged vulns.',
            'canonical_source' => 'security.dependency_audit',
        ],
        'secret_scan_clean' => [
            'description' => 'No new secrets committed; existing secrets remain quarantined.',
            'canonical_source' => 'security.secret_scan',
        ],
        'sovereignty_boundary_respected' => [
            'description' => 'No sensitive/secret/cyber payload crossed local-first boundary.',
            'canonical_source' => 'security.sovereignty_gate',
        ],
        'decision_receipt_v2_signed' => [
            'description' => 'Decision Receipt v2 is signed and persisted before execution.',
            'canonical_source' => 'governance.decision_receipt_v2',
        ],
        'evidence_traceable' => [
            'description' => 'Every claim links to a hash-addressable evidence artifact.',
            'canonical_source' => 'evidence.ledger',
        ],
        'rollback_plan_present' => [
            'description' => 'Rollback plan exists for every breaking change.',
            'canonical_source' => 'delivery.rollback_planner',
        ],
        'review_packet_signed' => [
            'description' => 'Review department signed the review packet for this delivery.',
            'canonical_source' => 'review.packet_signer',
        ],
        'delivery_pack_completeness_min_0_95' => [
            'description' => 'Delivery pack completeness score >= 0.95.',
            'canonical_source' => DeliveryPackCompletenessScorer::class,
        ],
        'learning_capsule_registered' => [
            'description' => 'A learning_capsule was registered with ACOS for compounding.',
            'canonical_source' => 'memory.learning_capsule_registry',
        ],
    ];

    /**
     * Evaluate the 15 universal gates against a signals map.
     *
     * @param  array<string,bool|string|null>  $signals  gate_id => true|false|null|'exception'
     * @param  array<string,string>            $exceptionReceipts  gate_id => receipt_id for `exception`
     * @return array<string,mixed>
     */
    public function evaluate(string $intentId, array $signals, array $exceptionReceipts = []): array
    {
        AaeosPhaseHandoffService::requireIntentId($intentId);

        $required = array_keys(self::UNIVERSAL_GATES);
        $passed = [];
        $blocked = [];
        $exception = [];
        $missing = [];

        foreach ($required as $gateId) {
            $signal = $signals[$gateId] ?? null;
            if ($signal === true) {
                $passed[] = $gateId;
                continue;
            }
            if ($signal === false) {
                $blocked[] = $gateId;
                continue;
            }
            if ($signal === 'exception') {
                if (! isset($exceptionReceipts[$gateId]) || $exceptionReceipts[$gateId] === '') {
                    $blocked[] = $gateId;

                    continue;
                }
                $exception[] = ['gate' => $gateId, 'receipt_id' => $exceptionReceipts[$gateId]];

                continue;
            }
            $missing[] = $gateId;
        }

        $outcome = $this->computeOutcome($passed, $blocked, $exception, $missing);

        $report = [
            'schema' => self::SCHEMA_VERSION,
            'intent_id' => $intentId,
            'gate_count' => count($required),
            'required' => $required,
            'passed' => $passed,
            'blocked' => $blocked,
            'exception' => $exception,
            'missing' => $missing,
            'outcome' => $outcome,
            'pass_rate' => $required === [] ? 0.0 : round((count($passed) + count($exception)) / count($required), 4),
            'provider_safe' => true,
            'evaluated_at' => gmdate('c'),
        ];
        $report['report_hash'] = 'sha256:'.hash('sha256', json_encode([
            $report['intent_id'],
            $report['passed'],
            $report['blocked'],
            $report['exception'],
            $report['missing'],
        ]) ?: '');

        return $report;
    }

    /**
     * @param  list<string>  $passed
     * @param  list<string>  $blocked
     * @param  list<array<string,string>>  $exception
     * @param  list<string>  $missing
     */
    private function computeOutcome(array $passed, array $blocked, array $exception, array $missing): string
    {
        if ($blocked !== []) {
            return 'red';
        }
        if ($missing !== []) {
            return 'pending';
        }
        if ($exception !== []) {
            return 'exception';
        }

        return 'green';
    }

    /**
     * Derive the boolean signal for `delivery_pack_completeness_min_0_95`
     * from a delivery-pack composition via {@see DeliveryPackCompletenessScorer}.
     *
     * Passed only when status is `passed` and ratio >= 0.95 (gate floor).
     *
     * @param  array<string,mixed>  $composition
     */
    public function deliveryPackCompletenessSignal(
        array $composition,
        ?DeliveryPackCompletenessScorer $scorer = null,
        float $minRatio = 0.95,
    ): bool {
        return ($scorer ?? $this->deliveryPackCompleteness)->passesMin($composition, $minRatio);
    }

    /**
     * Observe-only full delivery-pack completeness score projection.
     * Does not add a universal-gate id (catalogue stays 15).
     *
     * @param  array<string,mixed>  $composition
     * @return array<string,mixed>
     */
    public function deliveryPackCompletenessScoreObserve(array $composition): array
    {
        return $this->deliveryPackCompleteness->score($composition);
    }

    /**
     * Derive a boolean signal from a compiled-spec shape via
     * {@see SpecCompletenessScorer}. Observe helper for callers that already
     * hold a spec map — does not add a new universal gate id.
     *
     * @param  array<string,mixed>  $spec
     */
    public function specCompletenessSignal(
        array $spec,
        ?SpecCompletenessScorer $scorer = null,
        int $minScore = SpecCompletenessScorer::COMPLETE_THRESHOLD,
    ): bool {
        return ($scorer ?? $this->specCompleteness)->passesMin($spec, $minScore);
    }

    /**
     * Observe-only projection of a quality-bar telemetry payload into the
     * M5 contract shape. Does not add a universal-gate id (catalogue stays 15).
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function qualityBarTelemetryObserve(array $input): array
    {
        return QualityBarTelemetryContract::fromArray($input)->toArray();
    }

    /**
     * Observe-only projection of an architect-agent spec-pack gate payload
     * into the M1 contract shape. Does not add a universal-gate id.
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function architectSpecPackObserve(array $input): array
    {
        return ArchitectAgentSpecPackGateContract::fromArray($input)->toArray();
    }

    /**
     * Observe-only projection of a predicted-impact candidate into the
     * MULTN predicted-impact band shape. Does not add a universal-gate id.
     *
     * @param  array<string,mixed>  $candidate
     * @return array<string,mixed>
     */
    public function predictedImpactBandObserve(array $candidate): array
    {
        return PredictedImpactBand::classify($candidate);
    }

    /**
     * Observe-only MULTN predicted-impact band calibration projection.
     * Accepts a list of rows or `{rows:[...]}`. Catalogue stays 15.
     *
     * @param  array<mixed>  $input
     * @return array<string,mixed>
     */
    public function predictedImpactCalibrationObserve(array $input): array
    {
        $rows = array_is_list($input)
            ? $input
            : AiValueNormalizer::arrayOrEmpty($input['rows'] ?? null);

        /** @var list<array<string,mixed>> $rows */
        return PredictedImpactBand::calibration($rows);
    }

    /**
     * Observe-only projection of pre-review advisory features into the
     * MULTN15-08 band shape. Does not add a universal-gate id.
     *
     * @param  array<string,mixed>  $features
     * @return array<string,mixed>
     */
    public function preReviewAdvisoryObserve(array $features): array
    {
        return PreReviewAdvisoryBand::judge($features);
    }

    /**
     * Observe-only projection of a Reality Compiler slice map into the
     * contract shape. Does not add a universal-gate id.
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function realityCompilerSliceObserve(array $input): array
    {
        return RealityCompilerSlice::fromArray($input)->toArray();
    }

    /**
     * Observe-only ESP-09 independent challenger advisory projection.
     * Does not add a universal-gate id (catalogue stays 15).
     *
     * @param  array<string,mixed>  $context
     * @return array<string,mixed>
     */
    public function esp09ChallengerObserve(array $context): array
    {
        return Esp09IndependentChallengerService::evaluate($context);
    }

    /**
     * Observe-only ESP-09 promotion-gate delay signal.
     * Does not add a universal-gate id (catalogue stays 15).
     *
     * @param  array<string,mixed>  $context
     * @return array<string,mixed>
     */
    public function esp09PromotionGateObserve(array $context): array
    {
        return Esp09IndependentChallengerService::promotionGate($context);
    }

    /**
     * Observe-only ESP-09 challenger refutation series death-review projection.
     * Accepts a list of events or `{events:[...], min_windows?:int, min_per_window?:int}`.
     *
     * @param  array<mixed>  $input
     * @return array<string,mixed>
     */
    public function esp09RefutationSeriesObserve(array $input): array
    {
        if (array_is_list($input)) {
            /** @var list<array<string,mixed>> $input */
            return Esp09IndependentChallengerService::refutationSeries($input);
        }

        $events = AiValueNormalizer::arrayOrEmpty($input['events'] ?? null);
        $minWindows = max(1, (int) ($input['min_windows'] ?? 2));
        $minPerWindow = max(1, (int) ($input['min_per_window'] ?? 2));

        /** @var list<array<string,mixed>> $events */
        return Esp09IndependentChallengerService::refutationSeries($events, $minWindows, $minPerWindow);
    }

    /**
     * Observe-only MULTN17-08 dogfooding friction lead mine.
     * Accepts a list of events or `{events:[...]}`. Catalogue stays 15.
     *
     * @param  array<mixed>  $input
     * @return array<string,mixed>
     */
    public function dogfoodingFrictionLeadsObserve(array $input): array
    {
        $events = array_is_list($input)
            ? $input
            : AiValueNormalizer::arrayOrEmpty($input['events'] ?? null);

        /** @var list<array<string,mixed>> $events */
        return DogfoodingFrictionLeadMiner::mine($events);
    }

    /**
     * Observe-only MULTN reactive saturation classification.
     * Accepts `{windows:[...], context?:{...}}` or a bare windows list.
     * Does not add a universal-gate id.
     *
     * @param  array<mixed>  $input
     * @return array<string,mixed>
     */
    public function reactiveSaturationObserve(array $input): array
    {
        if (array_is_list($input)) {
            /** @var list<array<string,mixed>> $input */
            return ReactiveSaturationSignal::classify($input);
        }

        $windows = AiValueNormalizer::arrayOrEmpty($input['windows'] ?? null);
        $context = AiValueNormalizer::arrayOrEmpty($input['context'] ?? null);

        /** @var list<array<string,mixed>> $windows */
        return ReactiveSaturationSignal::classify($windows, $context);
    }

    /**
     * Observe-only AAEOS blocker-severity gate assessment.
     * Accepts a list of blockers or `{blockers:[...]}`. Catalogue stays 15.
     *
     * @param  array<mixed>  $input
     * @return array<string,mixed>
     */
    public function blockerSeverityObserve(array $input): array
    {
        $blockers = array_is_list($input)
            ? $input
            : AiValueNormalizer::arrayOrEmpty($input['blockers'] ?? null);

        return $this->blockerSeverity->assess($blockers);
    }

    /**
     * Observe-only phase-advance verdict over an atlas.aaeos.phase.v1 envelope.
     * Does not add a universal-gate id.
     *
     * @param  array<string,mixed>  $envelope
     * @return array<string,mixed>
     */
    public function phaseAdvanceVerdictObserve(array $envelope): array
    {
        return $this->phaseAdvance->classify($envelope);
    }

    /**
     * Observe-only required-vs-passed gate coverage projection.
     * Accepts `{required:[...], passed:[...]}`. Catalogue stays 15.
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function requiredGateCoverageObserve(array $input): array
    {
        return $this->requiredGateCoverage->check(
            AiValueNormalizer::arrayOrEmpty($input['required'] ?? null),
            AiValueNormalizer::arrayOrEmpty($input['passed'] ?? null),
        );
    }

    /**
     * Return the universal gate catalogue (provider-safe — descriptions only).
     *
     * @return array<string,array{description:string, canonical_source:string}>
     */
    public function catalogue(): array
    {
        return self::UNIVERSAL_GATES;
    }
}
