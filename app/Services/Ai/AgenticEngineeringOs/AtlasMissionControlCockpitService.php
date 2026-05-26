<?php

declare(strict_types=1);

namespace App\Services\Ai\AgenticEngineeringOs;

use InvalidArgumentException;

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
    ) {}

    /**
     * Build the cockpit snapshot for a given intent.
     *
     * @param  list<array<string,mixed>>  $phaseEnvelopes  Emitted phase envelopes for this intent, in order.
     * @param  array<string,bool|string|null>  $gateSignals  Universal-gate signals (gate_id => true|false|null|'exception').
     * @param  array<string,string>  $exceptionReceipts  gate_id => receipt_id for exceptions.
     * @return array<string,mixed>
     */
    public function snapshot(
        string $intentId,
        array $phaseEnvelopes,
        array $gateSignals = [],
        array $exceptionReceipts = [],
        string $autonomyLevel = 'L1',
    ): array {
        if ($intentId === '') {
            throw new InvalidArgumentException('intent_id required');
        }

        $journey = $this->buildJourney($phaseEnvelopes);
        $gateReport = $this->gates->evaluate($intentId, $gateSignals, $exceptionReceipts);
        $departmentsCount = $this->departments->catalogue()['department_count'];
        $currentPhase = $this->currentPhase($journey);
        $blockers = $this->collectBlockers($phaseEnvelopes);
        $signatureRequired = $this->signatureRequired($currentPhase, $autonomyLevel);

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
            'operator_signature_required' => $signatureRequired,
            'provider_safe' => true,
            'generated_at' => gmdate('c'),
        ];
        $payload['snapshot_hash'] = 'sha256:'.hash('sha256', json_encode([
            $intentId,
            array_column($journey, 'phase'),
            array_column($journey, 'status'),
            $gateReport['report_hash'] ?? null,
        ]) ?: '');

        return $payload;
    }

    /**
     * @param  list<array<string,mixed>>  $envelopes
     * @return list<array{phase:string,index:int,status:string,gates_passed:int,gates_blocked:int,actor_kind:?string,operator_signature:?string}>
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
                    'actor_kind' => null,
                    'operator_signature' => null,
                ];
                continue;
            }
            $gates = is_array($env['gates'] ?? null) ? $env['gates'] : [];
            $blocked = is_array($gates['blocked'] ?? null) ? $gates['blocked'] : [];
            $passed = is_array($gates['passed'] ?? null) ? $gates['passed'] : [];
            $skipped = ! empty($env['skip_reason']);
            $status = $skipped ? 'skipped' : (count($blocked) > 0 ? 'blocked' : ($env['ended_at'] ?? null ? 'complete' : 'in_progress'));
            $out[] = [
                'phase' => $phase,
                'index' => $idx,
                'status' => $status,
                'gates_passed' => count($passed),
                'gates_blocked' => count($blocked),
                'actor_kind' => is_array($env['actor'] ?? null) ? ($env['actor']['kind'] ?? null) : null,
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
            foreach (($env['blockers'] ?? []) as $blocker) {
                if (is_array($blocker) && isset($blocker['id'])) {
                    $out[] = $blocker;
                }
            }
        }

        return $out;
    }

    private function signatureRequired(?string $currentPhase, string $autonomyLevel): bool
    {
        if ($currentPhase === null) {
            return false;
        }
        $level = (int) ltrim($autonomyLevel, 'Ll');
        if ($level < 4) {
            return false;
        }

        return in_array($currentPhase, AaeosPhaseHandoffService::PHASES_REQUIRING_SIGNATURE_AT_L4, true);
    }
}
