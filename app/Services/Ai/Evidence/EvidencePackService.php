<?php

namespace App\Services\Ai\Evidence;

use App\Models\AiEvidencePack;
use Illuminate\Support\Str;

class EvidencePackService
{
    public const TARGET_MISSION = 'mission';

    public const TARGET_WORK_ORDER = 'work_order';

    public const TARGET_DOMAIN_DELIVERY = 'domain_delivery';

    public const TARGET_TOOL_RUN = 'tool_run';

    public const TARGET_HANDOFF = 'handoff';

    public const TARGET_CLAIM = 'claim';

    public const ALLOWED_TARGETS = [
        self::TARGET_MISSION,
        self::TARGET_WORK_ORDER,
        self::TARGET_DOMAIN_DELIVERY,
        self::TARGET_TOOL_RUN,
        self::TARGET_HANDOFF,
        self::TARGET_CLAIM,
    ];

    public const STATUS_OPEN = 'open';

    public const STATUS_SEALED = 'sealed';

    public function __construct(private readonly AuditEventService $auditEvents) {}

    /**
     * @param  array<string,mixed>  $args
     */
    public function build(array $args): AiEvidencePack
    {
        $targetType = (string) ($args['target_type'] ?? '');
        if (! in_array($targetType, self::ALLOWED_TARGETS, true)) {
            throw new \InvalidArgumentException("invalid target_type [{$targetType}]");
        }
        $targetId = (string) ($args['target_id'] ?? '');
        if ($targetId === '') {
            throw new \InvalidArgumentException('evidence pack requires target_id.');
        }

        /** @var array<int,mixed> $artifactRefs */
        $artifactRefs = (array) ($args['artifact_refs'] ?? []);
        /** @var array<int,mixed> $sourceRefs */
        $sourceRefs = (array) ($args['source_refs'] ?? []);
        /** @var array<int,mixed> $commandRefs */
        $commandRefs = (array) ($args['command_refs'] ?? []);
        /** @var array<int,mixed> $testRefs */
        $testRefs = (array) ($args['test_refs'] ?? []);
        /** @var array<int,mixed> $receiptRefs */
        $receiptRefs = (array) ($args['receipt_refs'] ?? []);
        /** @var array<int,mixed> $blockerRefs */
        $blockerRefs = (array) ($args['blocker_refs'] ?? []);

        $hashInput = [
            'target_type' => $targetType,
            'target_id' => $targetId,
            'mission_id' => $args['mission_id'] ?? null,
            'work_order_id' => $args['work_order_id'] ?? null,
            'domain_id' => $args['domain_id'] ?? null,
            'artifact_refs' => $artifactRefs,
            'source_refs' => $sourceRefs,
            'command_refs' => $commandRefs,
            'test_refs' => $testRefs,
            'receipt_refs' => $receiptRefs,
            'blocker_refs' => $blockerRefs,
        ];

        $pack = AiEvidencePack::query()->create([
            'uuid' => (string) Str::uuid(),
            'target_type' => $targetType,
            'target_id' => $targetId,
            'mission_id' => $args['mission_id'] ?? null,
            'work_order_id' => $args['work_order_id'] ?? null,
            'domain_id' => $args['domain_id'] ?? null,
            'artifact_refs' => $artifactRefs !== [] ? $artifactRefs : null,
            'source_refs' => $sourceRefs !== [] ? $sourceRefs : null,
            'command_refs' => $commandRefs !== [] ? $commandRefs : null,
            'test_refs' => $testRefs !== [] ? $testRefs : null,
            'receipt_refs' => $receiptRefs !== [] ? $receiptRefs : null,
            'blocker_refs' => $blockerRefs !== [] ? $blockerRefs : null,
            'evidence_hash' => EvidenceCanonicalHash::sha256($hashInput),
            'status' => (string) ($args['status'] ?? self::STATUS_OPEN),
        ]);

        $this->auditEvents->record(
            AuditEventService::EVENT_EVIDENCE_ATTACHED,
            $targetType,
            $targetId,
            [
                'evidence_pack_id' => $pack->id,
                'evidence_hash' => $pack->evidence_hash,
                'artifact_count' => count($artifactRefs),
                'source_count' => count($sourceRefs),
                'test_count' => count($testRefs),
                'receipt_count' => count($receiptRefs),
                'blocker_count' => count($blockerRefs),
            ],
            missionId: $pack->mission_id,
        );

        return $pack;
    }

    public function isEmpty(AiEvidencePack $pack): bool
    {
        foreach (['artifact_refs', 'source_refs', 'command_refs', 'test_refs', 'receipt_refs'] as $field) {
            $value = $pack->{$field};
            if (is_array($value) && $value !== []) {
                return false;
            }
        }

        return true;
    }
}
