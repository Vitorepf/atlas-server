<?php

namespace App\Services\Ai\Evidence;

use App\Models\AiOperatorDecision;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class OperatorDecisionService
{
    public const DECISION_APPROVED = 'approved';

    public const DECISION_REJECTED = 'rejected';

    public const DECISION_DEFERRED = 'deferred';

    public const DECISION_BLOCKED = 'blocked';

    public const ALLOWED_DECISIONS = [
        self::DECISION_APPROVED,
        self::DECISION_REJECTED,
        self::DECISION_DEFERRED,
        self::DECISION_BLOCKED,
    ];

    public function __construct(private readonly AuditEventService $auditEvents) {}

    /**
     * @param  array<string,mixed>  $args
     */
    public function record(array $args): AiOperatorDecision
    {
        $decisionType = (string) ($args['decision_type'] ?? '');
        if ($decisionType === '') {
            throw new \InvalidArgumentException('decision_type cannot be empty.');
        }
        $decision = (string) ($args['decision'] ?? '');
        if (! in_array($decision, self::ALLOWED_DECISIONS, true)) {
            throw new \InvalidArgumentException("invalid decision [{$decision}]");
        }
        $targetType = (string) ($args['target_type'] ?? '');
        $targetId = (string) ($args['target_id'] ?? '');
        if ($targetType === '' || $targetId === '') {
            throw new \InvalidArgumentException('operator decision requires target_type and target_id.');
        }

        $hashInput = [
            'decision_type' => $decisionType,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'decision' => $decision,
            'reason' => $args['reason'] ?? null,
            'decided_by' => $args['decided_by'] ?? null,
            'payload' => $args['payload'] ?? [],
        ];

        $record = AiOperatorDecision::query()->create([
            'uuid' => (string) Str::uuid(),
            'decision_type' => $decisionType,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'decision' => $decision,
            'reason' => $args['reason'] ?? null,
            'payload' => $args['payload'] ?? null,
            'decided_by' => $args['decided_by'] ?? null,
            'decided_at' => Carbon::now(),
            'receipt_hash' => EvidenceCanonicalHash::sha256($hashInput),
            'mission_id' => $args['mission_id'] ?? null,
        ]);

        $this->auditEvents->record(
            AuditEventService::EVENT_OPERATOR_DECISION,
            $targetType,
            $targetId,
            [
                'operator_decision_id' => $record->id,
                'decision_type' => $decisionType,
                'decision' => $decision,
                'decided_by' => $record->decided_by,
                'receipt_hash' => $record->receipt_hash,
            ],
            actorType: 'operator',
            missionId: $record->mission_id,
        );

        return $record;
    }
}
