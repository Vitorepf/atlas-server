<?php

namespace App\Services\Ai\Evidence;

use App\Models\AiAuditEvent;
use Illuminate\Support\Facades\Schema;
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
     * Tool Runtime degradation events. Emitted when a Tool Runtime bridge
     * (Policy or Evidence) falls back or fails — in BOTH strict and lenient
     * modes — so silent degradation is structurally impossible.
     *
     * Canon: docs/engineering-knowledge-base/atlas-architecture-critical-judgment-report.md
     */
    public const EVENT_TOOL_POLICY_BRIDGE_DEGRADED = 'tool_policy_bridge_degraded';

    public const EVENT_TOOL_RECEIPT_BRIDGE_DEGRADED = 'tool_receipt_bridge_degraded';

    /**
     * @param  array<string,mixed>  $payload
     * @param  array<string,mixed>  $timeline  Optional TEOS-I1 fields: scope_type, scope_id, correlation_id, causation_id.
     */
    public function record(
        string $eventType,
        ?string $targetType,
        ?string $targetId,
        array $payload,
        string $actorType = 'system',
        ?string $missionId = null,
        array $timeline = [],
    ): AiAuditEvent {
        $hashInput = [
            'event_type' => $eventType,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'actor_type' => $actorType,
            'payload' => $payload,
            'mission_id' => $missionId,
        ];

        $scopeType = $this->stringOrNull($timeline['scope_type'] ?? null);
        $scopeId = $this->stringOrNull($timeline['scope_id'] ?? null);
        $correlationId = $this->stringOrNull($timeline['correlation_id'] ?? null);
        $causationId = $this->stringOrNull($timeline['causation_id'] ?? null);

        // Only fold timeline fields into the hash when at least one is provided.
        // Back-compat: legacy callers continue to produce the original event_hash.
        $hasTimelineSignal = $scopeType !== null
            || $scopeId !== null
            || $correlationId !== null
            || $causationId !== null;
        if ($hasTimelineSignal) {
            $hashInput['timeline'] = array_filter([
                'scope_type' => $scopeType,
                'scope_id' => $scopeId,
                'correlation_id' => $correlationId,
                'causation_id' => $causationId,
            ], static fn (mixed $value): bool => $value !== null);
        }

        $row = [
            'uuid' => (string) Str::uuid(),
            'event_type' => $eventType,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'actor_type' => $actorType,
            'payload' => $payload,
            'event_hash' => EvidenceCanonicalHash::sha256($hashInput),
            'mission_id' => $missionId,
        ];

        if (Schema::hasColumn('ai_audit_events', 'scope_type')) {
            $row['scope_type'] = $scopeType;
        }
        if (Schema::hasColumn('ai_audit_events', 'scope_id')) {
            $row['scope_id'] = $scopeId;
        }
        if (Schema::hasColumn('ai_audit_events', 'correlation_id')) {
            $row['correlation_id'] = $correlationId;
        }
        if (Schema::hasColumn('ai_audit_events', 'causation_id')) {
            $row['causation_id'] = $causationId;
        }

        return AiAuditEvent::query()->create($row);
    }

    private function stringOrNull(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
