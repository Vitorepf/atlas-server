<?php

declare(strict_types=1);

namespace App\Services\Ai\AgenticEngineeringOs;

use App\Services\Ai\AgenticEngineeringOs\Scoring\OutcomeCausalityRanker;
use App\Services\Ai\Support\AiValueNormalizer;

/**
 * Atlas Mission Control Cockpit Service — Phase 14 surface.
 *
 * Aggregates the AAEOS state for an `intent_id` and exposes a single
 * provider-safe envelope (`atlas.aaeos.mission_control_cockpit.v1`) the
 * desktop surface and HTTP endpoint can render. The operator uses this
 * cockpit to (a) inspect every phase of the 17-step runbook, (b) review
 * the universal-gate report, and (c) sign approvals at autonomy >= L4.
 *
 * The service is composition-only: it consumes data from the phase
 * handoff service + the universal gates evaluator + the department
 * runtime. It never executes phase work and never accepts raw operator
 * input — only intent_id + hashed evidence references.
 */
final class AtlasMissionControlCockpitService
{
    public const FIELD_ID = 'id';
    public const FIELD_KIND = 'kind';
    public const SCHEMA_VERSION = 'atlas.aaeos.mission_control_cockpit.v1';

    public const STATUS_PENDING = 'pending';

    public const STATUS_SKIPPED = 'skipped';

    public const STATUS_BLOCKED = 'blocked';

    public const STATUS_COMPLETE = 'complete';

    public const STATUS_IN_PROGRESS = 'in_progress';

    public const STATUS_SUCCEEDED = 'succeeded';

    public const STATUS_FAILED = 'failed';

    public const OUTCOME_GREEN = 'green';

    public const OUTCOME_RED = 'red';

    public const OUTCOME_EXCEPTION = 'exception';

    public const FIELD_BLOCKED = 'blocked';

    public const FIELD_PASSED = 'passed';

    public const FIELD_MISSING = 'missing';
    public const FIELD_PHASE = 'phase';
    public const FIELD_INDEX = 'index';
    public const FIELD_STATUS = 'status';
    public const FIELD_GATES_PASSED = 'gates_passed';
    public const FIELD_GATES_BLOCKED = 'gates_blocked';
    public const FIELD_GATE_COVERAGE = 'gate_coverage';
    public const FIELD_ACTOR_KIND = 'actor_kind';
    public const FIELD_OPERATOR_SIGNATURE = 'operator_signature';
    public const FIELD_ACTIVE_LEASES = 'active_leases';
    public const FIELD_ACTOR = 'actor';
    public const FIELD_AUTONOMY_LEVEL = 'autonomy_level';
    public const FIELD_BLOCKED_OR_QUARANTINED_COUNT = 'blocked_or_quarantined_count';
    public const FIELD_BLOCKER_SIGNAL = 'blocker_signal';
    public const FIELD_BLOCKERS = 'blockers';
    public const FIELD_CURRENT_PHASE = 'current_phase';
    public const FIELD_DEPARTMENT_COUNT = 'department_count';
    public const FIELD_ENDED_AT = 'ended_at';
    public const FIELD_GATE_REPORT = 'gate_report';
    public const FIELD_GATES = 'gates';
    public const FIELD_GENERATED_AT = 'generated_at';
    public const FIELD_IMPLEMENTABLE_SUPPLY = 'implementable_supply';
    public const FIELD_INTENT_ID = 'intent_id';
    public const FIELD_MALFORMED = 'malformed';
    public const FIELD_MALFORMED_COUNT = 'malformed_count';
    public const FIELD_NEXT_PHASE = 'next_phase';
    public const FIELD_OPERATOR_SIGNATURE_REQUIRED = 'operator_signature_required';
    public const FIELD_PHASE_OUT = 'phase_out';
    public const FIELD_SERVABLE_NOW = 'servable_now';
    public const FIELD_PHASE_COUNT = 'phase_count';
    public const FIELD_PHASE_ADVANCE = 'phase_advance';
    public const FIELD_PHASES = 'phases';
    public const FIELD_OUTCOME_CAUSALITY = 'outcome_causality';
    public const FIELD_OUTCOME = 'outcome';
    public const FIELD_PROVIDER_SAFE = 'provider_safe';
    public const FIELD_QUARANTINED = 'quarantined';
    public const FIELD_QUEUE_HEALTH = 'queue_health';
    public const FIELD_RECOMMENDED_OPERATOR_ACTION = 'recommended_operator_action';
    public const FIELD_RECOVERABLE = 'recoverable';
    public const FIELD_RECOVERABLE_COUNT = 'recoverable_count';
    public const FIELD_REPORT_HASH = 'report_hash';
    public const FIELD_REQUIRED = 'required';
    public const FIELD_SCHEMA = 'schema';
    public const FIELD_SKIP_REASON = 'skip_reason';
    public const FIELD_SNAPSHOT_HASH = 'snapshot_hash';
    public const FIELD_TESTS_GREEN = 'tests_green';
    public const FIELD_DECISION_RECEIPT_V2_SIGNED = 'decision_receipt_v2_signed';
    public const FIELD_EVIDENCE_TRACEABLE = 'evidence_traceable';
    public const FIELD_REVIEW_PACKET_SIGNED = 'review_packet_signed';
    public const FIELD_MONITOR = 'monitor';
    public const FIELD_ORIGINATE_MORE_WORK = 'originate_more_work';
    public const FIELD_RECOVER_BLOCKED_BACKLOG = 'recover_blocked_backlog';
    public const FIELD_REPAIR_MALFORMED_PACKETS = 'repair_malformed_packets';
    public const FIELD_SHA256 = 'sha256';
    public const FIELD_L1 = 'L1';
    public const INT_4 = 4;

    public function __construct(
        private readonly AaeosPhaseHandoffService $phases,
        private readonly AtlasUniversalGatesEvaluator $gates,
        private readonly DepartmentContractRuntime $departments,
        private readonly AaeosBlockerSeverityGate $blockerSeverity = new AaeosBlockerSeverityGate,
        private readonly PhaseAdvanceVerdictClassifier $phaseAdvance = new PhaseAdvanceVerdictClassifier,
        private readonly OutcomeCausalityRanker $outcomeCausality = new OutcomeCausalityRanker,
        private readonly AaeosRequiredGateCoverageChecker $gateCoverage = new AaeosRequiredGateCoverageChecker,
    ) {}

    /**
     * Build the cockpit snapshot for a given intent.
     *
     * @param  list<array<string,mixed>>  $phaseEnvelopes  Emitted phase envelopes for this intent, in order.
     * @param  array<string,bool|string|null>  $gateSignals  Universal-gate signals (gate_id => true|false|null|'exception').
     * @param  array<string,string>  $exceptionReceipts  gate_id => receipt_id for exceptions.
     * @return array<string,mixed>
     */
    /**
     * @param  array<string,mixed>  $queueSignals  Caller-supplied queue signals (servable_now,
     *   active_leases, blocked, quarantined, recoverable, malformed). Any raw task ids or target
     *   paths the caller includes are ignored — only the bounded numeric fields are read, keeping
     *   the snapshot provider-safe.
     */
    public function snapshot(
        string $intentId,
        array $phaseEnvelopes,
        array $gateSignals = [],
        array $exceptionReceipts = [],
        string $autonomyLevel = self::FIELD_L1,
        array $queueSignals = [],
    ): array {
        AaeosPhaseHandoffService::requireIntentId($intentId);

        $journey = $this->buildJourney($phaseEnvelopes);
        $gateReport = $this->gates->evaluate($intentId, $gateSignals, $exceptionReceipts);
        $departmentsCount = $this->departments->catalogue()[self::FIELD_DEPARTMENT_COUNT];
        $currentPhase = $this->currentPhase($journey);
        $blockers = $this->collectBlockers($phaseEnvelopes);
        $signatureRequired = $this->signatureRequired($currentPhase, $autonomyLevel);
        $queueHealth = $this->buildQueueHealth($queueSignals);

        $payload = [
            self::FIELD_SCHEMA => self::SCHEMA_VERSION,
            self::FIELD_INTENT_ID => $intentId,
            self::FIELD_AUTONOMY_LEVEL => $autonomyLevel,
            self::FIELD_PHASE_COUNT => count(AaeosPhaseHandoffService::PHASES),
            self::FIELD_PHASES => $journey,
            self::FIELD_CURRENT_PHASE => $currentPhase,
            self::FIELD_NEXT_PHASE => $currentPhase === null ? AaeosPhaseHandoffService::PHASES[0] : $this->phases->canonicalNextPhase($currentPhase),
            self::FIELD_GATE_REPORT => $gateReport,
            self::FIELD_DEPARTMENT_COUNT => $departmentsCount,
            self::FIELD_BLOCKERS => $blockers,
            // WIRE-OBSERVE (Obra #7): severity reduction over the raw blocker
            // list — tells the operator whether the intent is blocked/warning/
            // clear. Observe-only: never changes blockers or phase statuses.
            self::FIELD_BLOCKER_SIGNAL => $this->blockerSeverity->assess($blockers),
            // Observe-only advance verdict over the latest phase envelope
            // (same classifier the HTTP policy gate now uses live).
            self::FIELD_PHASE_ADVANCE => $this->latestPhaseAdvance($phaseEnvelopes),
            // Observe-only causality ranking when the journey is not clear green.
            self::FIELD_OUTCOME_CAUSALITY => $this->outcomeCausalityFor($blockers, $gateReport),
            self::FIELD_OPERATOR_SIGNATURE_REQUIRED => $signatureRequired,
            self::FIELD_PROVIDER_SAFE => true,
            self::FIELD_GENERATED_AT => gmdate('c'),
        ];
        if ($queueHealth !== null) {
            $payload[self::FIELD_QUEUE_HEALTH] = $queueHealth;
        }
        $payload[self::FIELD_SNAPSHOT_HASH] = 'sha256:'.hash(self::FIELD_SHA256, json_encode([
            $intentId,
            array_column($journey, self::FIELD_PHASE),
            array_column($journey, self::FIELD_STATUS),
            $gateReport[self::FIELD_REPORT_HASH] ?? null,
        ]) ?: '');

        return $payload;
    }

    /**
     * Reads only the six bounded numeric queue signals a caller may supply — any raw task ids,
     * target paths, or other identifying detail the caller includes elsewhere in $signals is
     * never read, keeping the snapshot provider-safe. Returns null when the caller supplies no
     * recognised numeric signal, so the key is omitted entirely rather than emitted empty.
     *
     * blocked/quarantined work is reported for visibility but NEVER folded into
     * implementable_supply — only servable_now counts as work a worker can actually claim now.
     *
     * @param  array<string,mixed>  $signals
     * @return array<string,mixed>|null
     */
    private function buildQueueHealth(array $signals): ?array
    {
        $numericKeys = [self::FIELD_SERVABLE_NOW, self::FIELD_ACTIVE_LEASES, self::STATUS_BLOCKED, self::FIELD_QUARANTINED, self::FIELD_RECOVERABLE, self::FIELD_MALFORMED];
        $hasAnySignal = false;
        foreach ($numericKeys as $key) {
            if (isset($signals[$key]) && AiValueNormalizer::finiteFloatOrNull($signals[$key]) !== null) {
                $hasAnySignal = true;
                break;
            }
        }
        if (! $hasAnySignal) {
            return null;
        }

        $servableNow = max(0, (int) (AiValueNormalizer::finiteFloatOrNull($signals[self::FIELD_SERVABLE_NOW] ?? 0) ?? 0));
        $activeLeases = max(0, (int) (AiValueNormalizer::finiteFloatOrNull($signals[self::FIELD_ACTIVE_LEASES] ?? 0) ?? 0));
        $blocked = max(0, (int) (AiValueNormalizer::finiteFloatOrNull($signals[self::FIELD_BLOCKED] ?? 0) ?? 0));
        $quarantined = max(0, (int) (AiValueNormalizer::finiteFloatOrNull($signals[self::FIELD_QUARANTINED] ?? 0) ?? 0));
        $recoverable = max(0, (int) (AiValueNormalizer::finiteFloatOrNull($signals[self::FIELD_RECOVERABLE] ?? 0) ?? 0));
        $malformed = max(0, (int) (AiValueNormalizer::finiteFloatOrNull($signals[self::FIELD_MALFORMED] ?? 0) ?? 0));

        $recommendedAction = match (true) {
            $servableNow === 0 && $recoverable > 0 => self::FIELD_RECOVER_BLOCKED_BACKLOG,
            $malformed > 0 => self::FIELD_REPAIR_MALFORMED_PACKETS,
            $servableNow === 0 && $activeLeases > 0 => self::FIELD_ORIGINATE_MORE_WORK,
            default => self::FIELD_MONITOR,
        };

        return [
            self::FIELD_SERVABLE_NOW => $servableNow,
            self::FIELD_ACTIVE_LEASES => $activeLeases,
            self::FIELD_BLOCKED_OR_QUARANTINED_COUNT => $blocked + $quarantined,
            self::FIELD_RECOVERABLE_COUNT => $recoverable,
            self::FIELD_MALFORMED_COUNT => $malformed,
            self::FIELD_IMPLEMENTABLE_SUPPLY => $servableNow,
            self::FIELD_RECOMMENDED_OPERATOR_ACTION => $recommendedAction,
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $envelopes
     * @return array{
     *     schema_version: string,
     *     verdict: string,
     *     reason: string,
     *     missing_gates: list<string>,
     *     blocked_gates: list<string>,
     *     high_blocker_ids: list<string>
     * }|null
     */
    private function latestPhaseAdvance(array $envelopes): ?array
    {
        for ($i = count($envelopes) - 1; $i >= 0; $i--) {
            $env = $envelopes[$i];
            if (! is_array($env) || ! isset($env[self::FIELD_PHASE_OUT])) {
                continue;
            }

            return $this->phaseAdvance->classify($env);
        }

        return null;
    }

    /**
     * Observe-only causality ranking for non-green gate reports / open blockers.
     *
     * @param  list<array<string,mixed>>  $blockers
     * @param  array<string,mixed>  $gateReport
     * @return array<string,mixed>|null
     */
    private function outcomeCausalityFor(array $blockers, array $gateReport): ?array
    {
        $outcome = AiValueNormalizer::trimmedStringOrNull($gateReport[self::FIELD_OUTCOME] ?? null) ?? '';
        if ($blockers === [] && $outcome === self::OUTCOME_GREEN) {
            return null;
        }

        $blocked = array_merge(
            AiValueNormalizer::arrayOrEmpty($gateReport[self::FIELD_BLOCKED] ?? null),
            AiValueNormalizer::arrayOrEmpty($gateReport[self::FIELD_MISSING] ?? null),
        );
        $hasEvidenceRefs = ! in_array(self::FIELD_EVIDENCE_TRACEABLE, $blocked, true);
        $testsPassed = in_array(self::FIELD_TESTS_GREEN, AiValueNormalizer::arrayOrEmpty($gateReport[self::FIELD_PASSED] ?? null), true)
            ? true
            : (in_array(self::FIELD_TESTS_GREEN, $blocked, true) ? false : null);
        $missingRequiredSources = in_array(self::FIELD_DECISION_RECEIPT_V2_SIGNED, $blocked, true)
            || in_array(self::FIELD_REVIEW_PACKET_SIGNED, $blocked, true);
        $status = match ($outcome) {
            self::OUTCOME_GREEN, self::OUTCOME_EXCEPTION => self::STATUS_SUCCEEDED,
            self::OUTCOME_RED => self::STATUS_FAILED,
            default => self::STATUS_BLOCKED,
        };

        return $this->outcomeCausality->rank(
            hasEvidenceRefs: $hasEvidenceRefs,
            status: $status,
            missingRequiredSources: $missingRequiredSources,
            testsPassed: $testsPassed,
        );
    }

    /**
     * @param  list<array<string,mixed>>  $envelopes
     * @return list<array{phase:string,index:int,status:string,gates_passed:int,gates_blocked:int,gate_coverage:?array{coverage:string,missing:list<string>,satisfied:bool,extra_passed_gates:list<string>},actor_kind:?string,operator_signature:?string}>
     */
    private function buildJourney(array $envelopes): array
    {
        $byPhase = [];
        foreach ($envelopes as $env) {
            if (! is_array($env) || ! isset($env[self::FIELD_PHASE_OUT])) {
                continue;
            }
            $byPhase[$env[self::FIELD_PHASE_OUT]] = $env;
        }

        // WIRE-OBSERVE (Obra #7): required-vs-passed gate diff per emitted phase
        // row — the journey previously only COUNTED gates_passed/gates_blocked
        // and never checked required coverage. Observe-only: never changes the
        // phase status. Pending phases (no envelope) carry an explicit null.
        $out = [];
        foreach (AaeosPhaseHandoffService::PHASES as $idx => $phase) {
            $env = $byPhase[$phase] ?? null;
            if ($env === null) {
                $out[] = [
                    self::FIELD_PHASE => $phase,
                    self::FIELD_INDEX => $idx,
                    self::FIELD_STATUS => self::STATUS_PENDING,
                    self::FIELD_GATES_PASSED => 0,
                    self::FIELD_GATES_BLOCKED => 0,
                    self::FIELD_GATE_COVERAGE => null,
                    self::FIELD_ACTOR_KIND => null,
                    self::FIELD_OPERATOR_SIGNATURE => null,
                ];
                continue;
            }
            $gates = AiValueNormalizer::arrayOrEmpty($env[self::FIELD_GATES] ?? null);
            $blocked = AiValueNormalizer::arrayOrEmpty($gates[self::FIELD_BLOCKED] ?? null);
            $passed = AiValueNormalizer::arrayOrEmpty($gates[self::FIELD_PASSED] ?? null);
            $required = AiValueNormalizer::arrayOrEmpty($gates[self::FIELD_REQUIRED] ?? null);
            $actor = AiValueNormalizer::arrayOrEmpty($env[self::FIELD_ACTOR] ?? null);
            $skipped = ! empty($env[self::FIELD_SKIP_REASON]);
            $status = $skipped ? self::STATUS_SKIPPED : (count($blocked) > 0 ? self::STATUS_BLOCKED : ($env[self::FIELD_ENDED_AT] ?? null ? self::STATUS_COMPLETE : self::STATUS_IN_PROGRESS));
            $out[] = [
                self::FIELD_PHASE => $phase,
                self::FIELD_INDEX => $idx,
                self::FIELD_STATUS => $status,
                self::FIELD_GATES_PASSED => count($passed),
                self::FIELD_GATES_BLOCKED => count($blocked),
                self::FIELD_GATE_COVERAGE => $this->gateCoverage->check($required, $passed),
                self::FIELD_ACTOR_KIND => $actor[self::FIELD_KIND] ?? null,
                self::FIELD_OPERATOR_SIGNATURE => $env[self::FIELD_OPERATOR_SIGNATURE] ?? null,
            ];
        }

        return $out;
    }

    /** @param list<array<string,mixed>> $journey */
    private function currentPhase(array $journey): ?string
    {
        $last = null;
        foreach ($journey as $row) {
            if (in_array($row[self::FIELD_STATUS] ?? null, [self::STATUS_COMPLETE, self::STATUS_SKIPPED], true)) {
                $last = $row[self::FIELD_PHASE];
                continue;
            }
            if (in_array($row[self::FIELD_STATUS] ?? null, [self::STATUS_IN_PROGRESS, self::STATUS_BLOCKED], true)) {
                return $row[self::FIELD_PHASE];
            }
        }

        return $last;
    }

    /**
     * @param  list<array<string,mixed>>  $envelopes
     * @return list<array<string,mixed>>
     */
    private function collectBlockers(array $envelopes): array
    {
        $out = [];
        foreach ($envelopes as $env) {
            foreach (AiValueNormalizer::arrayOrEmpty($env[self::FIELD_BLOCKERS] ?? null) as $blocker) {
                if (! is_array($blocker)) {
                    continue;
                }
                if (AiValueNormalizer::trimmedStringOrNull($blocker[self::FIELD_ID] ?? null) === null) {
                    continue;
                }
                $out[] = $blocker;
            }
        }

        return $out;
    }

    private function signatureRequired(?string $currentPhase, string $autonomyLevel): bool
    {
        if ($currentPhase === null) {
            return false;
        }
        $level = AaeosPhaseHandoffService::autonomyLevelInt($autonomyLevel);
        if ($level < self::INT_4) {
            return false;
        }

        return in_array($currentPhase, AaeosPhaseHandoffService::PHASES_REQUIRING_SIGNATURE_AT_L4, true);
    }
}
