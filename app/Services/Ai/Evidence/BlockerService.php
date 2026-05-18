<?php

namespace App\Services\Ai\Evidence;

use App\Models\AiBlocker;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class BlockerService
{
    public const STATUS_OPEN = 'open';

    public const STATUS_RESOLVED = 'resolved';

    public const STATUS_ACCEPTED = 'accepted';

    public const ALLOWED_STATUSES = [
        self::STATUS_OPEN,
        self::STATUS_RESOLVED,
        self::STATUS_ACCEPTED,
    ];

    public const SEVERITY_LOW = 'low';

    public const SEVERITY_MEDIUM = 'medium';

    public const SEVERITY_HIGH = 'high';

    public const SEVERITY_CRITICAL = 'critical';

    public const ALLOWED_SEVERITIES = [
        self::SEVERITY_LOW,
        self::SEVERITY_MEDIUM,
        self::SEVERITY_HIGH,
        self::SEVERITY_CRITICAL,
    ];

    public const KIND_MISSING_EVIDENCE = 'missing_evidence';

    public const KIND_MISSING_PERMISSION = 'missing_permission';

    public const KIND_SAFETY_GATE = 'safety_gate';

    public const KIND_EXTERNAL_DEPENDENCY = 'external_dependency';

    public const KIND_UNAUTHORIZED_ACTION = 'unauthorized_action';

    public const KIND_DATA_UNAVAILABLE = 'data_unavailable';

    public const KIND_LEGAL_OR_POLICY = 'legal_or_policy';

    public const KIND_COST_OR_BUDGET = 'cost_or_budget';

    public const KIND_INCONCLUSIVE_RESULT = 'inconclusive_result';

    public const KIND_MISSING_BENCHMARK = 'missing_benchmark';

    public const ALLOWED_KINDS = [
        self::KIND_MISSING_EVIDENCE,
        self::KIND_MISSING_PERMISSION,
        self::KIND_SAFETY_GATE,
        self::KIND_EXTERNAL_DEPENDENCY,
        self::KIND_UNAUTHORIZED_ACTION,
        self::KIND_DATA_UNAVAILABLE,
        self::KIND_LEGAL_OR_POLICY,
        self::KIND_COST_OR_BUDGET,
        self::KIND_INCONCLUSIVE_RESULT,
        self::KIND_MISSING_BENCHMARK,
    ];

    public function __construct(private readonly AuditEventService $auditEvents) {}

    /**
     * @param  array<string,mixed>  $args
     */
    public function open(array $args): AiBlocker
    {
        $targetType = (string) ($args['target_type'] ?? '');
        $targetId = (string) ($args['target_id'] ?? '');
        if ($targetType === '' || $targetId === '') {
            throw new \InvalidArgumentException('blocker requires target_type and target_id.');
        }

        $kind = (string) ($args['blocker_type'] ?? '');
        if (! in_array($kind, self::ALLOWED_KINDS, true)) {
            throw new \InvalidArgumentException("invalid blocker_type [{$kind}]");
        }
        $severity = (string) ($args['severity'] ?? self::SEVERITY_MEDIUM);
        if (! in_array($severity, self::ALLOWED_SEVERITIES, true)) {
            throw new \InvalidArgumentException("invalid blocker severity [{$severity}]");
        }
        $reason = (string) ($args['reason'] ?? '');
        if ($reason === '') {
            throw new \InvalidArgumentException('blocker reason cannot be empty.');
        }

        $blocker = AiBlocker::query()->create([
            'uuid' => (string) Str::uuid(),
            'target_type' => $targetType,
            'target_id' => $targetId,
            'blocker_type' => $kind,
            'severity' => $severity,
            'reason' => $reason,
            'evidence_refs' => $args['evidence_refs'] ?? null,
            'status' => self::STATUS_OPEN,
            'mission_id' => $args['mission_id'] ?? null,
        ]);

        $this->auditEvents->record(
            AuditEventService::EVENT_BLOCKER_OPENED,
            $targetType,
            $targetId,
            [
                'blocker_id' => $blocker->id,
                'blocker_type' => $kind,
                'severity' => $severity,
                'reason' => $reason,
            ],
            missionId: $blocker->mission_id,
        );

        return $blocker;
    }

    public function resolve(AiBlocker $blocker, string $status = self::STATUS_RESOLVED): AiBlocker
    {
        if (! in_array($status, [self::STATUS_RESOLVED, self::STATUS_ACCEPTED], true)) {
            throw new \InvalidArgumentException("invalid resolution status [{$status}]");
        }
        $blocker->status = $status;
        $blocker->resolved_at = Carbon::now();
        $blocker->save();

        $this->auditEvents->record(
            AuditEventService::EVENT_BLOCKER_RESOLVED,
            (string) $blocker->target_type,
            (string) $blocker->target_id,
            [
                'blocker_id' => $blocker->id,
                'status' => $status,
            ],
            missionId: $blocker->mission_id,
        );

        return $blocker;
    }
}
