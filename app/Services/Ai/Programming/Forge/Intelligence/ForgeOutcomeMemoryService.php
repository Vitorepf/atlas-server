<?php

namespace App\Services\Ai\Programming\Forge\Intelligence;

use App\Models\AiForgeOutcomeMemory;
use App\Models\AiForgeWorkPacketExecutionCycle;
use App\Services\Ai\EngineeringKernel\OutcomeProofGate;
use App\Services\Ai\Mission\MissionCanonicalHash;
use App\Services\Ai\Support\AiStringListNormalizer;
use Illuminate\Support\Str;

final class ForgeOutcomeMemoryService
{
    public const SCHEMA_VERSION = 'atlas.forge.outcome_memory.v1';

    public function __construct(
        private readonly OutcomeProofGate $proofGate = new OutcomeProofGate,
    ) {}

    /**
     * @param  array<string,mixed>|null  $failureCapsule
     * @return array<string,mixed>
     */
    public function summarize(AiForgeWorkPacketExecutionCycle $cycle, ?array $failureCapsule = null): array
    {
        $aedpds = (array) data_get($cycle->execution_plan, 'forge_native_capabilities.blocks.AEDPDS', []);
        $aedpdsDrivers = array_values((array) ($aedpds['selected_drivers'] ?? []));
        $aedpdsEffectiveness = $this->aedpdsEffectiveness($cycle, $aedpdsDrivers, $aedpds);

        // Proof gate (cross-surface, Goal 2) — a claimed success whose supplied execution
        // evidence is a lie (0 tests / lint-as-suite / fixed-smoke) must NOT promote into
        // learning. Same OutcomeProofGate/FalseClaimInvariant as Dev. Absent execution block
        // ⇒ unproven (not fake-green): default-safe, no destructive flip of legacy successes.
        $proof = $this->proofGate->assess((string) ($cycle->outcome_status ?? 'unknown'), $this->executionEvidence($cycle));

        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'cycle_uuid' => $cycle->uuid,
            'packet_id' => $cycle->work_packet_canonical_id,
            'outcome_status' => $cycle->outcome_status,
            'execution_mode' => $cycle->execution_mode,
            'proven_real' => $proof['proven_real'],
            'fake_green' => $proof['fake_green'],
            'proof_reason' => $proof['reason'],
            'evidence_kinds' => AiStringListNormalizer::uniqueTruthyMappedScalarStrings(
                (array) ($cycle->evidence_refs ?? []),
                static fn (mixed $ref): mixed => is_array($ref) ? ($ref['kind'] ?? '') : '',
            ),
            'aedpds_drivers' => $aedpdsDrivers,
            'aedpds_gate_status' => (string) ($aedpds['status'] ?? 'unknown'),
            'aedpds_doctrine_hash' => (string) ($aedpds['doctrine_hash'] ?? ''),
            'aedpds_gate_hash' => (string) ($aedpds['gate_hash'] ?? ''),
            'aedpds_effectiveness' => $aedpdsEffectiveness,
            'learning_candidates' => $this->learningCandidates($cycle, $failureCapsule, $proof['fake_green']),
            'should_promote_to_aemor' => $cycle->outcome_status !== null && ! $proof['fake_green'],
            'human_review_required' => $cycle->outcome_status !== 'success' || $proof['fake_green'],
        ];
        $payload['outcome_memory_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * Execution-evidence block the proof gate inspects. The executor emits it into
     * gate_result.execution when it runs a real suite; absence means "unproven", not
     * "fake-green" (no positive lie).
     *
     * @return array<string,mixed>
     */
    private function executionEvidence(AiForgeWorkPacketExecutionCycle $cycle): array
    {
        $execution = data_get($cycle->gate_result, 'execution', []);

        return is_array($execution) ? $execution : [];
    }

    /**
     * Real fake-green counter for Forge: outcomes whose claimed success was refused as
     * fake-green (persisted marker). Measured, never fabricated.
     */
    public static function fakeGreenSuppressedCount(): int
    {
        return AiForgeOutcomeMemory::query()
            ->whereJsonContains('learning_candidates', OutcomeProofGate::FAKE_GREEN_MARKER)
            ->count();
    }

    /**
     * @param  array<string,mixed>|null  $failureCapsule
     */
    public function persist(AiForgeWorkPacketExecutionCycle $cycle, ?array $failureCapsule = null): AiForgeOutcomeMemory
    {
        $summary = $this->summarize($cycle, $failureCapsule);

        return AiForgeOutcomeMemory::query()->updateOrCreate(
            [
                'execution_cycle_id' => $cycle->id,
                'work_packet_canonical_id' => $cycle->work_packet_canonical_id,
            ],
            [
                'schema_version' => self::SCHEMA_VERSION,
                'uuid' => (string) Str::uuid(),
                'intake_id' => $cycle->intake_id,
                'work_packet_id' => $cycle->work_packet_id,
                'cycle_uuid' => $cycle->uuid,
                'outcome_status' => (string) $summary['outcome_status'],
                'execution_mode' => (string) $summary['execution_mode'],
                'evidence_kinds' => array_values((array) $summary['evidence_kinds']),
                'aedpds_drivers' => array_values((array) $summary['aedpds_drivers']),
                'aedpds_gate_status' => (string) $summary['aedpds_gate_status'],
                'aedpds_doctrine_hash' => (string) $summary['aedpds_doctrine_hash'],
                'aedpds_gate_hash' => (string) $summary['aedpds_gate_hash'],
                'aedpds_effectiveness' => (array) $summary['aedpds_effectiveness'],
                'learning_candidates' => array_values((array) $summary['learning_candidates']),
                'failure_capsule' => $failureCapsule,
                'should_promote_to_aemor' => (bool) $summary['should_promote_to_aemor'],
                'human_review_required' => (bool) $summary['human_review_required'],
                'outcome_memory_hash' => (string) $summary['outcome_memory_hash'],
            ],
        );
    }

    /**
     * @param  array<string,mixed>|null  $failureCapsule
     * @return list<string>
     */
    private function learningCandidates(AiForgeWorkPacketExecutionCycle $cycle, ?array $failureCapsule, bool $fakeGreen): array
    {
        $signals = ['forge_packet_cycle:'.$cycle->outcome_status];
        foreach ((array) data_get($cycle->execution_plan, 'forge_native_capabilities.blocks.AEDPDS.selected_drivers', []) as $driver) {
            if (is_string($driver) && $driver !== '') {
                $signals[] = 'aedpds_driver:'.$driver.':'.($cycle->outcome_status === 'success' ? 'effective' : 'needs_review');
            }
        }
        if ($failureCapsule !== null) {
            $signals[] = 'failure_class:'.(string) ($failureCapsule['failure_class'] ?? 'unknown');
        }
        if (($cycle->gate_result['all_passed'] ?? null) === true) {
            $signals[] = 'gate_result:all_passed';
        }
        if ($fakeGreen) {
            $signals[] = OutcomeProofGate::FAKE_GREEN_MARKER;
        }

        return $signals;
    }

    /**
     * @param  list<string>  $drivers
     * @param  array<string,mixed>  $aedpds
     * @return array<string,mixed>
     */
    private function aedpdsEffectiveness(AiForgeWorkPacketExecutionCycle $cycle, array $drivers, array $aedpds): array
    {
        $outcome = (string) ($cycle->outcome_status ?? 'unknown');
        $gateStatus = (string) ($aedpds['status'] ?? 'unknown');
        $effective = $outcome === 'success' && ! in_array($gateStatus, ['blocked', 'unknown'], true);

        return [
            'schema_version' => 'atlas.forge.aedpds_driver_effectiveness.v1',
            'outcome_status' => $outcome,
            'gate_status' => $gateStatus,
            'effective' => $effective,
            'effective_drivers' => $effective ? $drivers : [],
            'drivers_requiring_review' => $effective ? [] : $drivers,
            'next_time_policy' => $effective ? 'reuse_driver_mix_with_same_gates' : 'review_driver_selection_context_and_evidence',
        ];
    }
}
