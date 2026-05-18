<?php

namespace App\Services\Ai\Evidence;

use App\Models\AiAuditEvent;
use Illuminate\Support\Str;

class AuditEventService
{
    public const EVENT_EVIDENCE_ATTACHED = 'evidence_attached';

    public const EVENT_RECEIPT_EMITTED = 'receipt_emitted';

    public const EVENT_CLAIM_MADE = 'claim_made';

    public const EVENT_CLAIM_REVISED = 'claim_revised';

    public const EVENT_ARTIFACT_REGISTERED = 'artifact_registered';

    public const EVENT_SOURCE_REGISTERED = 'source_registered';

    public const EVENT_GATE_RUN = 'gate_run';

    public const EVENT_TEST_RESULT = 'test_result';

    public const EVENT_CERTIFICATION_ATTEMPTED = 'certification_attempted';

    public const EVENT_CERTIFICATION_PASSED = 'certification_passed';

    public const EVENT_CERTIFICATION_FAILED = 'certification_failed';

    public const EVENT_CERTIFICATION_BLOCKED = 'certification_blocked';

    public const EVENT_BLOCKER_OPENED = 'blocker_opened';

    public const EVENT_BLOCKER_RESOLVED = 'blocker_resolved';

    public const EVENT_OPERATOR_DECISION = 'operator_decision';

    /**
     * @param  array<string,mixed>  $payload
     */
    public function record(
        string $eventType,
        ?string $targetType,
        ?string $targetId,
        array $payload,
        string $actorType = 'system',
        ?string $missionId = null,
    ): AiAuditEvent {
        $hashInput = [
            'event_type' => $eventType,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'actor_type' => $actorType,
            'payload' => $payload,
            'mission_id' => $missionId,
        ];

        return AiAuditEvent::query()->create([
            'uuid' => (string) Str::uuid(),
            'event_type' => $eventType,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'actor_type' => $actorType,
            'payload' => $payload,
            'event_hash' => EvidenceCanonicalHash::sha256($hashInput),
            'mission_id' => $missionId,
        ]);
    }
}
