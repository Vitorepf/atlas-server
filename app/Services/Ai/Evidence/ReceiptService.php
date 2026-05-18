<?php

namespace App\Services\Ai\Evidence;

use App\Models\AiReceipt;
use Illuminate\Support\Str;

class ReceiptService
{
    public const TYPE_COMMAND = 'command';

    public const TYPE_TOOL_CALL = 'tool_call';

    public const TYPE_PROVIDER_CALL = 'provider_call';

    public const TYPE_DOMAIN_STEP = 'domain_step';

    public const TYPE_HANDOFF = 'handoff';

    public const TYPE_GATE_RUN = 'gate_run';

    public const TYPE_REPAIR = 'repair';

    public const TYPE_POLICY_DECISION = 'policy_decision';

    public const TYPE_TEST = 'test';

    public const ALLOWED_TYPES = [
        self::TYPE_COMMAND,
        self::TYPE_TOOL_CALL,
        self::TYPE_PROVIDER_CALL,
        self::TYPE_DOMAIN_STEP,
        self::TYPE_HANDOFF,
        self::TYPE_GATE_RUN,
        self::TYPE_REPAIR,
        self::TYPE_POLICY_DECISION,
        self::TYPE_TEST,
    ];

    public const STATUS_OK = 'ok';

    public const STATUS_FAILED = 'failed';

    public const STATUS_PARTIAL = 'partial';

    public const STATUS_BLOCKED = 'blocked';

    public const ALLOWED_STATUSES = [
        self::STATUS_OK,
        self::STATUS_FAILED,
        self::STATUS_PARTIAL,
        self::STATUS_BLOCKED,
    ];

    public function __construct(private readonly AuditEventService $auditEvents) {}

    /**
     * @param  array<string,mixed>  $args
     */
    public function emit(array $args): AiReceipt
    {
        $type = (string) ($args['receipt_type'] ?? '');
        if (! in_array($type, self::ALLOWED_TYPES, true)) {
            throw new \InvalidArgumentException("invalid receipt_type [{$type}]");
        }

        $status = (string) ($args['status'] ?? self::STATUS_OK);
        if (! in_array($status, self::ALLOWED_STATUSES, true)) {
            throw new \InvalidArgumentException("invalid receipt status [{$status}]");
        }

        $action = (string) ($args['action'] ?? '');
        if ($action === '') {
            throw new \InvalidArgumentException('receipt action cannot be empty.');
        }

        $hashInput = [
            'receipt_type' => $type,
            'target_type' => $args['target_type'] ?? null,
            'target_id' => $args['target_id'] ?? null,
            'mission_id' => $args['mission_id'] ?? null,
            'work_order_id' => $args['work_order_id'] ?? null,
            'actor_type' => $args['actor_type'] ?? 'system',
            'action' => $action,
            'input_hash' => $args['input_hash'] ?? null,
            'output_hash' => $args['output_hash'] ?? null,
            'evidence_refs' => $args['evidence_refs'] ?? [],
            'status' => $status,
        ];

        $receipt = AiReceipt::query()->create([
            'uuid' => (string) Str::uuid(),
            'receipt_type' => $type,
            'target_type' => $args['target_type'] ?? null,
            'target_id' => $args['target_id'] ?? null,
            'mission_id' => $args['mission_id'] ?? null,
            'work_order_id' => $args['work_order_id'] ?? null,
            'actor_type' => (string) ($args['actor_type'] ?? 'system'),
            'action' => $action,
            'input_hash' => $args['input_hash'] ?? null,
            'output_hash' => $args['output_hash'] ?? null,
            'evidence_refs' => $args['evidence_refs'] ?? null,
            'status' => $status,
            'receipt_hash' => EvidenceCanonicalHash::sha256($hashInput),
        ]);

        $this->auditEvents->record(
            AuditEventService::EVENT_RECEIPT_EMITTED,
            $receipt->target_type,
            $receipt->target_id,
            [
                'receipt_id' => $receipt->id,
                'receipt_type' => $receipt->receipt_type,
                'status' => $receipt->status,
                'receipt_hash' => $receipt->receipt_hash,
            ],
            actorType: (string) $receipt->actor_type,
            missionId: $receipt->mission_id,
        );

        return $receipt;
    }
}
