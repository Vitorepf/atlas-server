<?php

namespace App\Services\Ai\Evidence;

use App\Models\AiBlocker;
use App\Models\AiCertification;
use App\Models\AiEvidencePack;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class CertificationRuntimeService
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_PASSED = 'passed';

    public const STATUS_FAILED = 'failed';

    public const STATUS_BLOCKED = 'blocked';

    public const ALLOWED_STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_PASSED,
        self::STATUS_FAILED,
        self::STATUS_BLOCKED,
    ];

    public function __construct(
        private readonly AuditEventService $auditEvents,
        private readonly EvidencePackService $evidencePacks,
    ) {}

    /**
     * @param  array<string,mixed>  $args
     */
    public function certify(array $args): AiCertification
    {
        $targetType = (string) ($args['target_type'] ?? '');
        if (! in_array($targetType, EvidencePackService::ALLOWED_TARGETS, true)) {
            throw new \InvalidArgumentException("invalid target_type [{$targetType}]");
        }
        $targetId = (string) ($args['target_id'] ?? '');
        if ($targetId === '') {
            throw new \InvalidArgumentException('certification requires target_id.');
        }

        $required = $this->resolveRequiredRequirements($args);

        $providedRequirements = (array) ($args['provided_requirements'] ?? []);

        $evidencePack = $this->resolveEvidencePack($targetType, $targetId, $args);
        $blockers = $this->resolveOpenBlockers($targetType, $targetId);

        $checked = [];
        $missing = [];

        foreach ($required as $requirement) {
            $satisfied = in_array($requirement, $providedRequirements, true);
            $check = [
                'requirement' => $requirement,
                'status' => $satisfied ? 'passed' : 'failed',
                'detail' => $satisfied ? 'satisfied by provided_requirements' : 'not satisfied',
            ];
            $checked[] = $check;
            if (! $satisfied) {
                $missing[] = $check;
            }
        }

        $evidenceRefs = $this->collectEvidenceRefs($evidencePack, $args);

        $evidenceCheck = [
            'requirement' => 'evidence_refs_present',
            'status' => $evidenceRefs !== [] ? 'passed' : 'failed',
            'detail' => 'evidence_refs_count='.count($evidenceRefs),
        ];
        $checked[] = $evidenceCheck;
        if ($evidenceCheck['status'] !== 'passed') {
            $missing[] = $evidenceCheck;
        }

        $packEmptyCheck = [
            'requirement' => 'evidence_pack_not_empty',
            'status' => $evidencePack !== null && ! $this->evidencePacks->isEmpty($evidencePack) ? 'passed' : 'failed',
            'detail' => $evidencePack === null
                ? 'no evidence pack supplied'
                : ($this->evidencePacks->isEmpty($evidencePack) ? 'evidence pack has no refs' : 'pack has refs'),
        ];
        $checked[] = $packEmptyCheck;
        if ($packEmptyCheck['status'] !== 'passed') {
            $missing[] = $packEmptyCheck;
        }

        $hasOpenBlockers = $blockers->isNotEmpty();
        if ($hasOpenBlockers) {
            $status = self::STATUS_BLOCKED;
        } elseif ($missing === []) {
            $status = self::STATUS_PASSED;
        } else {
            $status = self::STATUS_FAILED;
        }

        $blockerRefs = $blockers->map(static fn (AiBlocker $b): array => [
            'blocker_id' => $b->id,
            'kind' => $b->blocker_type,
            'severity' => $b->severity,
        ])->all();

        $hashInput = [
            'target_type' => $targetType,
            'target_id' => $targetId,
            'mission_id' => $args['mission_id'] ?? null,
            'status' => $status,
            'checked_requirements' => $checked,
            'missing_requirements' => $missing,
            'evidence_refs' => $evidenceRefs,
            'blocker_refs' => $blockerRefs,
        ];

        $certification = AiCertification::query()->create([
            'uuid' => (string) Str::uuid(),
            'target_type' => $targetType,
            'target_id' => $targetId,
            'mission_id' => $args['mission_id'] ?? null,
            'status' => $status,
            'checked_requirements' => $checked,
            'missing_requirements' => $missing,
            'evidence_refs' => $evidenceRefs,
            'blocker_refs' => $blockerRefs !== [] ? $blockerRefs : null,
            'certification_hash' => EvidenceCanonicalHash::sha256($hashInput),
            'certified_at' => $status === self::STATUS_PASSED ? Carbon::now() : null,
        ]);

        $this->emitAuditEvent($certification, $status);

        return $certification;
    }

    public function canComplete(string $targetType, string $targetId): bool
    {
        $latest = AiCertification::query()
            ->where('target_type', $targetType)
            ->where('target_id', $targetId)
            ->latest('created_at')
            ->first();

        return $latest !== null && $latest->status === self::STATUS_PASSED;
    }

    /**
     * @param  array<string,mixed>  $args
     * @return array<int,string>
     */
    private function resolveRequiredRequirements(array $args): array
    {
        $supplied = $args['required_requirements'] ?? null;
        if (is_array($supplied) && $supplied !== []) {
            return array_values(array_map(static fn ($r): string => (string) $r, $supplied));
        }

        return [];
    }

    /**
     * @param  array<string,mixed>  $args
     */
    private function resolveEvidencePack(string $targetType, string $targetId, array $args): ?AiEvidencePack
    {
        $explicitId = $args['evidence_pack_id'] ?? null;
        if (is_string($explicitId) && $explicitId !== '') {
            $pack = AiEvidencePack::query()->where('id', $explicitId)->orWhere('uuid', $explicitId)->first();
            if ($pack !== null) {
                return $pack;
            }
        }

        return AiEvidencePack::query()
            ->where('target_type', $targetType)
            ->where('target_id', $targetId)
            ->latest('created_at')
            ->first();
    }

    /**
     * @return Collection<int,AiBlocker>
     */
    private function resolveOpenBlockers(string $targetType, string $targetId)
    {
        return AiBlocker::query()
            ->where('target_type', $targetType)
            ->where('target_id', $targetId)
            ->where('status', BlockerService::STATUS_OPEN)
            ->whereIn('severity', [BlockerService::SEVERITY_HIGH, BlockerService::SEVERITY_CRITICAL])
            ->get();
    }

    /**
     * @param  array<string,mixed>  $args
     * @return array<int,mixed>
     */
    private function collectEvidenceRefs(?AiEvidencePack $pack, array $args): array
    {
        $refs = [];
        if ($pack !== null) {
            $refs[] = ['kind' => 'evidence_pack', 'id' => $pack->id, 'hash' => $pack->evidence_hash];
            foreach (['artifact_refs', 'source_refs', 'command_refs', 'test_refs', 'receipt_refs'] as $field) {
                $value = (array) ($pack->{$field} ?? []);
                if ($value !== []) {
                    $refs[] = ['kind' => $field, 'count' => count($value)];
                }
            }
        }
        if (isset($args['additional_evidence_refs']) && is_array($args['additional_evidence_refs'])) {
            foreach ($args['additional_evidence_refs'] as $extra) {
                $refs[] = $extra;
            }
        }

        return $refs;
    }

    private function emitAuditEvent(AiCertification $certification, string $status): void
    {
        $eventType = match ($status) {
            self::STATUS_PASSED => AuditEventService::EVENT_CERTIFICATION_PASSED,
            self::STATUS_FAILED => AuditEventService::EVENT_CERTIFICATION_FAILED,
            self::STATUS_BLOCKED => AuditEventService::EVENT_CERTIFICATION_BLOCKED,
            default => AuditEventService::EVENT_CERTIFICATION_ATTEMPTED,
        };

        $this->auditEvents->record(
            $eventType,
            (string) $certification->target_type,
            (string) $certification->target_id,
            [
                'certification_id' => $certification->id,
                'status' => $status,
                'certification_hash' => $certification->certification_hash,
                'missing_count' => count((array) $certification->missing_requirements),
            ],
            missionId: $certification->mission_id,
        );
    }
}
