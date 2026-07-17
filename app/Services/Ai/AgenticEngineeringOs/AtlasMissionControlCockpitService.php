<?php

declare(strict_types=1);

namespace App\Services\Ai\AgenticEngineeringOs;

use App\Services\Ai\Aaeos\Cores\OutcomeCausalityRanker;
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
    public const SCHEMA_VERSION = 'atlas.aaeos.mission_control_cockpit.v1';

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
        string $autonomyLevel = 'L1',
        array $queueSignals = [],
    ): array {
        AaeosPhaseHandoffService::requireIntentId($intentId);

        $journey = $this->buildJourney($phaseEnvelopes);
        $gateReport = $this->gates->evaluate($intentId, $gateSignals, $exceptionReceipts);
        $departmentsCount = $this->departments->catalogue()['department_count'];
        $currentPhase = $this->currentPhase($journey);
        $blockers = $this->collectBlockers($phaseEnvelopes);
        $signatureRequired = $this->signatureRequired($currentPhase, $autonomyLevel);
        $queueHealth = $this->buildQueueHealth($queueSignals);

        $payload = [
            'schema' => self::SCHEMA_VERSION,
            'intent_id' => $intentId,
            'autonomy_level' => $autonomyLevel,
            'phase_count' => count(AaeosPhaseHandoffService::PHASES),
            'phases' => $journey,
            'current_phase' => $currentPhase,
            'next_phase' => $currentPhase === null ? AaeosPhaseHandoffService::PHASES[0] : $this->phases->canonicalNextPhase($currentPhase),
            'gate_report' => $gateReport,
            'department_count' => $departmentsCount,
            'blockers' => $blockers,
            // WIRE-OBSERVE (Obra #7): severity reduction over the raw blocker
            // list — tells the operator whether the intent is blocked/warning/
            // clear. Observe-only: never changes blockers or phase statuses.
            'blocker_signal' => $this->blockerSeverity->assess($blockers),
            // Observe-only advance verdict over the latest phase envelope
            // (same classifier the HTTP policy gate now uses live).
            'phase_advance' => $this->latestPhaseAdvance($phaseEnvelopes),
            // Observe-only causality ranking when the journey is not clear green.
            'outcome_causality' => $this->outcomeCausalityFor($blockers, $gateReport),
            'operator_signature_required' => $signatureRequired,
            'provider_safe' => true,
            'generated_at' => gmdate('c'),
        ];
        if ($queueHealth !== null) {
            $payload['queue_health'] = $queueHealth;
        }
        $payload['snapshot_hash'] = 'sha256:'.hash('sha256', json_encode([
            $intentId,
            array_column($journey, 'phase'),
            array_column($journey, 'status'),
            $gateReport['report_hash'] ?? null,
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
        $numericKeys = ['servable_now', 'active_leases', 'blocked', 'quarantined', 'recoverable', 'malformed'];
        $hasAnySignal = false;
        foreach ($numericKeys as $key) {
            if (isset($signals[$key]) && is_numeric($signals[$key])) {
                $hasAnySignal = true;
                break;
            }
        }
        if (! $hasAnySignal) {
            return null;
        }

        $servableNow = max(0, (int) ($signals['servable_now'] ?? 0));
        $activeLeases = max(0, (int) ($signals['active_leases'] ?? 0));
        $blocked = max(0, (int) ($signals['blocked'] ?? 0));
        $quarantined = max(0, (int) ($signals['quarantined'] ?? 0));
        $recoverable = max(0, (int) ($signals['recoverable'] ?? 0));
        $malformed = max(0, (int) ($signals['malformed'] ?? 0));

        $recommendedAction = match (true) {
            $servableNow === 0 && $recoverable > 0 => 'recover_blocked_backlog',
            $malformed > 0 => 'repair_malformed_packets',
            $servableNow === 0 && $activeLeases > 0 => 'originate_more_work',
            default => 'monitor',
        };

        return [
            'servable_now' => $servableNow,
            'active_leases' => $activeLeases,
            'blocked_or_quarantined_count' => $blocked + $quarantined,
            'recoverable_count' => $recoverable,
            'malformed_count' => $malformed,
            'implementable_supply' => $servableNow,
            'recommended_operator_action' => $recommendedAction,
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
            if (! is_array($env) || ! isset($env['phase_out'])) {
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
        $outcome = (string) ($gateReport['outcome'] ?? '');
        if ($blockers === [] && $outcome === 'green') {
            return null;
        }

        $blocked = array_merge(
            (array) ($gateReport['blocked'] ?? []),
            (array) ($gateReport['missing'] ?? []),
        );
        $hasEvidenceRefs = ! in_array('evidence_traceable', $blocked, true);
        $testsPassed = in_array('tests_green', (array) ($gateReport['passed'] ?? []), true)
            ? true
            : (in_array('tests_green', $blocked, true) ? false : null);
        $missingRequiredSources = in_array('decision_receipt_v2_signed', $blocked, true)
            || in_array('review_packet_signed', $blocked, true);
        $status = match ($outcome) {
            'green', 'exception' => 'succeeded',
            'red' => 'failed',
            default => 'blocked',
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
            if (! is_array($env) || ! isset($env['phase_out'])) {
                continue;
            }
            $byPhase[$env['phase_out']] = $env;
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
                    'phase' => $phase,
                    'index' => $idx,
                    'status' => 'pending',
                    'gates_passed' => 0,
                    'gates_blocked' => 0,
                    'gate_coverage' => null,
                    'actor_kind' => null,
                    'operator_signature' => null,
                ];
                continue;
            }
            $gates = AiValueNormalizer::arrayOrEmpty($env['gates'] ?? null);
            $blocked = AiValueNormalizer::arrayOrEmpty($gates['blocked'] ?? null);
            $passed = AiValueNormalizer::arrayOrEmpty($gates['passed'] ?? null);
            $required = AiValueNormalizer::arrayOrEmpty($gates['required'] ?? null);
            $actor = AiValueNormalizer::arrayOrEmpty($env['actor'] ?? null);
            $skipped = ! empty($env['skip_reason']);
            $status = $skipped ? 'skipped' : (count($blocked) > 0 ? 'blocked' : ($env['ended_at'] ?? null ? 'complete' : 'in_progress'));
            $out[] = [
                'phase' => $phase,
                'index' => $idx,
                'status' => $status,
                'gates_passed' => count($passed),
                'gates_blocked' => count($blocked),
                'gate_coverage' => $this->gateCoverage->check($required, $passed),
                'actor_kind' => $actor['kind'] ?? null,
                'operator_signature' => $env['operator_signature'] ?? null,
            ];
        }

        return $out;
    }

    /** @param list<array<string,mixed>> $journey */
    private function currentPhase(array $journey): ?string
    {
        $last = null;
        foreach ($journey as $row) {
            if (in_array($row['status'] ?? null, ['complete', 'skipped'], true)) {
                $last = $row['phase'];
                continue;
            }
            if (in_array($row['status'] ?? null, ['in_progress', 'blocked'], true)) {
                return $row['phase'];
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
            foreach (AiValueNormalizer::arrayOrEmpty($env['blockers'] ?? null) as $blocker) {
                if (! is_array($blocker)) {
                    continue;
                }
                if (AiValueNormalizer::trimmedStringOrNull($blocker['id'] ?? null) === null) {
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
        if ($level < 4) {
            return false;
        }

        return in_array($currentPhase, AaeosPhaseHandoffService::PHASES_REQUIRING_SIGNATURE_AT_L4, true);
    }
}
