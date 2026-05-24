<?php

namespace App\Services\Ai\Programming\Forge\Intelligence;

use App\Models\AiForgeOutcomeMemory;
use App\Models\AiForgeWorkPacketExecutionCycle;
use App\Services\Ai\Mission\MissionCanonicalHash;
use Illuminate\Support\Str;

final class ForgeOutcomeMemoryService
{
    public const SCHEMA_VERSION = 'atlas.forge.outcome_memory.v1';

    /**
     * @param  array<string,mixed>|null  $failureCapsule
     * @return array<string,mixed>
     */
    public function summarize(AiForgeWorkPacketExecutionCycle $cycle, ?array $failureCapsule = null): array
    {
        $aedpds = (array) data_get($cycle->execution_plan, 'forge_native_capabilities.blocks.AEDPDS', []);
        $aedpdsDrivers = array_values((array) ($aedpds['selected_drivers'] ?? []));
        $aedpdsEffectiveness = $this->aedpdsEffectiveness($cycle, $aedpdsDrivers, $aedpds);
        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'cycle_uuid' => $cycle->uuid,
            'packet_id' => $cycle->work_packet_canonical_id,
            'outcome_status' => $cycle->outcome_status,
            'execution_mode' => $cycle->execution_mode,
            'evidence_kinds' => array_values(array_unique(array_filter(array_map(
                static fn ($ref): string => is_array($ref) ? (string) ($ref['kind'] ?? '') : '',
                (array) ($cycle->evidence_refs ?? []),
            )))),
            'aedpds_drivers' => $aedpdsDrivers,
            'aedpds_gate_status' => (string) ($aedpds['status'] ?? 'unknown'),
            'aedpds_doctrine_hash' => (string) ($aedpds['doctrine_hash'] ?? ''),
            'aedpds_gate_hash' => (string) ($aedpds['gate_hash'] ?? ''),
            'aedpds_effectiveness' => $aedpdsEffectiveness,
            'learning_candidates' => $this->learningCandidates($cycle, $failureCapsule),
            'should_promote_to_aemor' => $cycle->outcome_status !== null,
            'human_review_required' => $cycle->outcome_status !== 'success',
        ];
        $payload['outcome_memory_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
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
    private function learningCandidates(AiForgeWorkPacketExecutionCycle $cycle, ?array $failureCapsule): array
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
