<?php

namespace App\Services\Ai\Mission;

/**
 * Mission Mode detection signal.
 *
 * Produced by {@see MissionDetectionService::detect()}. Tells the
 * orchestrator whether the inbound prompt deserves Mission Mode activation
 * (persistence, multi-step commitment, Obra scope) or stays as a one-shot
 * trivial/task that does not need an AiMission record.
 *
 * Schema: atlas.ai.mission_signal.v1
 */
final class MissionSignal
{
    public const SCHEMA_VERSION = 'atlas.ai.mission_signal.v1';

    /**
     * @param  array<int,string>  $persistenceKeywords
     * @param  array<int,string>  $obraKeywords
     */
    public function __construct(
        public readonly bool $shouldActivateMissionMode,
        public readonly string $suggestedMissionType,
        public readonly array $persistenceKeywords,
        public readonly array $obraKeywords,
        public readonly string $factoryType,
        public readonly float $confidence,
        public readonly string $reason,
        public readonly string $normalizedIntent,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'should_activate_mission_mode' => $this->shouldActivateMissionMode,
            'suggested_mission_type' => $this->suggestedMissionType,
            'persistence_keywords' => $this->persistenceKeywords,
            'obra_keywords' => $this->obraKeywords,
            'factory_type' => $this->factoryType,
            'confidence' => $this->confidence,
            'reason' => $this->reason,
            'normalized_intent' => $this->normalizedIntent,
        ];
    }
}
