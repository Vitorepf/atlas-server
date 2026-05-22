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
        if ($failureCapsule !== null) {
            $signals[] = 'failure_class:'.(string) ($failureCapsule['failure_class'] ?? 'unknown');
        }
        if (($cycle->gate_result['all_passed'] ?? null) === true) {
            $signals[] = 'gate_result:all_passed';
        }

        return $signals;
    }
}
