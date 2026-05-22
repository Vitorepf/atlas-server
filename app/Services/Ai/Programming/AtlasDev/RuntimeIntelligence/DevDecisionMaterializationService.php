<?php

namespace App\Services\Ai\Programming\AtlasDev\RuntimeIntelligence;

use App\Models\AtlasDevDecisionMaterialization;
use App\Models\AtlasDevTaskPacket;
use App\Services\Ai\Mission\MissionCanonicalHash;

class DevDecisionMaterializationService
{
    public const SCHEMA_VERSION = 'atlas.dev.decision_materialization.v1';

    /**
     * @param  array<string,mixed>  $payload
     */
    public function persist(AtlasDevTaskPacket $taskPacket, string $kind, array $payload): AtlasDevDecisionMaterialization
    {
        $canonical = [
            'schema_version' => self::SCHEMA_VERSION,
            'run_id' => $taskPacket->run_id,
            'task_id' => $taskPacket->task_id,
            'decision_kind' => $kind,
            'status' => (string) ($payload['status'] ?? 'ready'),
            'payload' => $payload,
        ];
        $canonical['decision_hash'] = MissionCanonicalHash::sha256($canonical);

        return AtlasDevDecisionMaterialization::query()->updateOrCreate(
            [
                'run_id' => $taskPacket->run_id,
                'task_id' => $taskPacket->task_id,
                'decision_kind' => $kind,
            ],
            [
                'schema_version' => self::SCHEMA_VERSION,
                'uuid' => $canonical['decision_hash'],
                'task_packet_id' => $taskPacket->id,
                'status' => $canonical['status'],
                'payload' => $payload,
                'decision_hash' => $canonical['decision_hash'],
            ],
        );
    }
}
