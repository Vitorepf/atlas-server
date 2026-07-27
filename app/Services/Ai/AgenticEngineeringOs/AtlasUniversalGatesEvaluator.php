<?php

declare(strict_types=1);

namespace App\Services\Ai\AgenticEngineeringOs;

use App\Services\Ai\AgenticEngineeringOs\Gates\DomainScorerGateSection;
use App\Services\Ai\AgenticEngineeringOs\Gates\GateObserveSection01;
use App\Services\Ai\AgenticEngineeringOs\Gates\GateObserveSection02;
use App\Services\Ai\AgenticEngineeringOs\Gates\GateObserveSection03;
use App\Services\Ai\AgenticEngineeringOs\Gates\GateObserveSection04;
use App\Services\Ai\AgenticEngineeringOs\Gates\GateObserveSection05;
use App\Services\Ai\AgenticEngineeringOs\Gates\GateObserveSection06;
use App\Services\Ai\AgenticEngineeringOs\Gates\GateObserveSection07;
use App\Services\Ai\AgenticEngineeringOs\Gates\GateObserveSection08;
use App\Services\Ai\AgenticEngineeringOs\Gates\GateObserveSection09;
use App\Services\Ai\AgenticEngineeringOs\Scoring\AtlasMemoryRecallRelevanceScorer;
use App\Services\Ai\AgenticEngineeringOs\Scoring\ContextParetoDominanceFilter;
use App\Services\Ai\AgenticEngineeringOs\Scoring\MemoryFeedbackDecayScorer;
use App\Services\Ai\AgenticEngineeringOs\Scoring\MemoryInjectionBudgetAllocator;
use App\Services\Ai\AgenticEngineeringOs\Scoring\OutcomeCausalityRanker;
use App\Services\Ai\AgenticEngineeringOs\Scoring\SegmentImportanceRanker;
use App\Services\Ai\AgenticEngineeringOs\Scoring\SpecCompletenessScorer;
use App\Services\Ai\AgenticEngineeringOs\Scoring\SummaryFidelityCoverageScorer;
use App\Support\UtcIsoTimestamp;

/**
 * AAEOS/AEOS universal gates façade (TRI-HYGIENE slim).
 * Observe surface lives in UniversalGatesObserveDelegates + UniversalGatesObserveBodies.
 */
final class AtlasUniversalGatesEvaluator
{
    public const SCHEMA_VERSION = 'atlas.aaeos.gate_report.v1';

    // Restored: the tri-hygiene split (d9be5dcf0) moved the observe bodies into
    // UniversalGatesObserveBodies but left these 15 constants behind, so every
    // self::OBSERVE_*_SCHEMA in the trait resolved to nothing.
    public const FIELD_COUNT = 'count';

    public const FIELD_SCHEMA_VERSION = 'schema_version';

    public const OBSERVE_ARRAY_FIELD_READER_SCHEMA = 'atlas.aaeos.array_field_reader.v1';

    public const OBSERVE_BLOCKER_SEVERITY_SCHEMA = 'atlas.aaeos.blocker_severity.v1';

    public const OBSERVE_EVIDENCE_REF_NORMALIZE_SCHEMA = 'atlas.aaeos.evidence_ref_normalize.v1';

    public const OBSERVE_EVIDENCE_STATUSES_SCHEMA = 'atlas.cognition.evidence_statuses.v1';

    public const OBSERVE_EVIDENCE_VISION_SCHEMA = 'atlas.aaeos.evidence_vision_observe.v1';

    public const OBSERVE_LEDGER_ROTATION_SCHEMA = 'atlas.aaeos.ledger_rotation_observe.v1';

    public const OBSERVE_OUTCOME_ATTRIBUTION_TYPES_SCHEMA = 'atlas.aaeos.outcome_attribution_types.v1';

    public const OBSERVE_STRING_LIST_NORMALIZE_SCHEMA = 'atlas.aaeos.string_list_normalize.v1';

    public const OBSERVE_SURPRISE_GATE_BANDS_SCHEMA = 'atlas.cognition.surprise_gate.bands.v1';

    public const OBSERVE_TELEMETRY_COLLECTOR_SURFACES_SCHEMA = 'atlas.telemetry.collector.surfaces.v1';

    public const OBSERVE_THRESHOLD_COMPARATOR_SCHEMA = 'atlas.aaeos.threshold_comparator.v1';

    public const OBSERVE_THRESHOLD_LADDER_SCHEMA = 'atlas.aaeos.threshold_ladder_observe.v1';

    public const OBSERVE_UNIVERSAL_GATES_CATALOGUE_SCHEMA = 'atlas.aaeos.universal_gates_catalogue.v1';

    use UniversalGatesObserveBodies;
    use UniversalGatesObserveDelegates;

    public function __construct(
        private readonly DeliveryPackCompletenessScorer $deliveryPackCompleteness = new DeliveryPackCompletenessScorer,
        private readonly SpecCompletenessScorer $specCompleteness = new SpecCompletenessScorer,
        private readonly AaeosBlockerSeverityGate $blockerSeverity = new AaeosBlockerSeverityGate,
        private readonly PhaseAdvanceVerdictClassifier $phaseAdvance = new PhaseAdvanceVerdictClassifier,
        private readonly AaeosRequiredGateCoverageChecker $requiredGateCoverage = new AaeosRequiredGateCoverageChecker,
        private readonly OutcomeCausalityRanker $outcomeCausality = new OutcomeCausalityRanker,
        private readonly SummaryFidelityCoverageScorer $summaryFidelity = new SummaryFidelityCoverageScorer,
        private readonly MemoryInjectionBudgetAllocator $memoryInjectionBudget = new MemoryInjectionBudgetAllocator,
        private readonly MemoryFeedbackDecayScorer $memoryFeedbackDecay = new MemoryFeedbackDecayScorer,
        private readonly SegmentImportanceRanker $segmentImportance = new SegmentImportanceRanker,
        private readonly ContextParetoDominanceFilter $contextPareto = new ContextParetoDominanceFilter,
        private readonly AtlasMemoryRecallRelevanceScorer $memoryRecallRelevance = new AtlasMemoryRecallRelevanceScorer,
        private readonly GateObserveSection01 $observeSection01 = new GateObserveSection01,
        private readonly GateObserveSection02 $observeSection02 = new GateObserveSection02,
        private readonly GateObserveSection03 $observeSection03 = new GateObserveSection03,
        private readonly GateObserveSection04 $observeSection04 = new GateObserveSection04,
        private readonly GateObserveSection05 $observeSection05 = new GateObserveSection05,
        private readonly GateObserveSection06 $observeSection06 = new GateObserveSection06,
        private readonly GateObserveSection07 $observeSection07 = new GateObserveSection07,
        private readonly GateObserveSection08 $observeSection08 = new GateObserveSection08,
        private readonly GateObserveSection09 $observeSection09 = new GateObserveSection09,
        private readonly DomainScorerGateSection $domainScorerSection = new DomainScorerGateSection,
    ) {}

    public function evaluate(string $intentId, array $signals, array $exceptionReceipts = []): array
    {
        AaeosPhaseHandoffService::requireIntentId($intentId);

        $required = array_keys(UniversalGatesCatalogue::UNIVERSAL_GATES);
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
            'evaluated_at' => UtcIsoTimestamp::now(),
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

    public function catalogue(): array
    {
        return UniversalGatesCatalogue::UNIVERSAL_GATES;
    }
}
