<?php

namespace App\Services\Ai\Evidence;

use App\Models\AiGateRun;
use Illuminate\Support\Str;

class GateRunService
{
    public const STATUS_PASSED = 'passed';

    public const STATUS_FAILED = 'failed';

    public const STATUS_BLOCKED = 'blocked';

    public const STATUS_SKIPPED = 'skipped';

    public const ALLOWED_STATUSES = [
        self::STATUS_PASSED,
        self::STATUS_FAILED,
        self::STATUS_BLOCKED,
        self::STATUS_SKIPPED,
    ];

    public function __construct(private readonly AuditEventService $auditEvents) {}

    /**
     * @param  array<string,mixed>  $args
     */
    public function record(array $args): AiGateRun
    {
        $gateType = (string) ($args['gate_type'] ?? '');
        if ($gateType === '') {
            throw new \InvalidArgumentException('gate_type cannot be empty.');
        }
        $targetType = (string) ($args['target_type'] ?? '');
        $targetId = (string) ($args['target_id'] ?? '');
        if ($targetType === '' || $targetId === '') {
            throw new \InvalidArgumentException('gate_run requires target_type and target_id.');
        }

        $status = (string) ($args['status'] ?? '');
        if (! in_array($status, self::ALLOWED_STATUSES, true)) {
            throw new \InvalidArgumentException("invalid gate status [{$status}]");
        }

        /** @var array<int,array<string,mixed>> $checked */
        $checked = (array) ($args['checked_requirements'] ?? []);
        /** @var array<int,array<string,mixed>> $missing */
        $missing = (array) ($args['missing_requirements'] ?? []);

        $hashInput = [
            'gate_type' => $gateType,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'status' => $status,
            'checked' => $checked,
            'missing' => $missing,
        ];

        $gateRun = AiGateRun::query()->create([
            'uuid' => (string) Str::uuid(),
            'gate_type' => $gateType,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'status' => $status,
            'checked_requirements' => $checked,
            'missing_requirements' => $missing,
            'evidence_refs' => $args['evidence_refs'] ?? null,
            'gate_hash' => EvidenceCanonicalHash::sha256($hashInput),
            'mission_id' => $args['mission_id'] ?? null,
        ]);

        $this->auditEvents->record(
            AuditEventService::EVENT_GATE_RUN,
            $targetType,
            $targetId,
            [
                'gate_run_id' => $gateRun->id,
                'gate_type' => $gateType,
                'status' => $status,
                'missing_count' => count($missing),
                'gate_hash' => $gateRun->gate_hash,
            ],
            missionId: $gateRun->mission_id,
        );

        return $gateRun;
    }
}
