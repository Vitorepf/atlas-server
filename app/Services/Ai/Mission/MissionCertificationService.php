<?php

namespace App\Services\Ai\Mission;

use App\Models\AiMission;
use App\Models\AiMissionCertification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class MissionCertificationService
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_PASSED = 'passed';

    public const STATUS_FAILED = 'failed';

    public const STATUS_BLOCKED = 'blocked';

    public function __construct(private readonly MissionLifecycleService $lifecycle) {}

    public function certify(AiMission $mission): AiMissionCertification
    {
        $checks = $this->runChecks($mission);

        $missing = array_values(array_filter(
            $checks,
            static fn (array $check): bool => $check['status'] !== 'passed',
        ));

        $status = $missing === [] ? self::STATUS_PASSED : self::STATUS_FAILED;

        $evidenceRefs = $mission->evidenceRefs()
            ->orderBy('created_at')
            ->pluck('id')
            ->all();

        $hashInput = [
            'mission_id' => $mission->id,
            'mission_uuid' => $mission->uuid,
            'status' => $status,
            'checked_requirements' => $checks,
            'missing_requirements' => $missing,
            'evidence_refs' => $evidenceRefs,
        ];

        $certificationHash = MissionCanonicalHash::sha256($hashInput);

        $certification = AiMissionCertification::query()->create([
            'uuid' => (string) Str::uuid(),
            'mission_id' => $mission->id,
            'status' => $status,
            'checked_requirements' => $checks,
            'missing_requirements' => $missing,
            'evidence_refs' => $evidenceRefs,
            'certification_hash' => $certificationHash,
            'certified_at' => $status === self::STATUS_PASSED ? Carbon::now() : null,
        ]);

        $mission->certification_hash = $certificationHash;
        $mission->evidence_pack_hash = MissionCanonicalHash::sha256($evidenceRefs);
        $mission->save();

        $this->lifecycle->recordEvent(
            $mission,
            'certification.recorded',
            'system',
            [
                'certification_id' => $certification->id,
                'status' => $status,
                'certification_hash' => $certificationHash,
                'missing_count' => count($missing),
            ],
            receiptHash: $certificationHash,
        );

        return $certification;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function runChecks(AiMission $mission): array
    {
        $objectivesCount = $mission->objectives()->count();
        $workOrdersCount = $mission->workOrders()->count();
        $evidenceCount = $mission->evidenceRefs()->count();
        $dod = (array) $mission->definition_of_done;
        $dodCriteria = (array) ($dod['criteria'] ?? []);
        $workOrdersMissingHash = $mission->workOrders()
            ->whereNull('receipt_hash')
            ->count();

        return [
            [
                'requirement' => 'objectives_exist',
                'status' => $objectivesCount > 0 ? 'passed' : 'failed',
                'detail' => "objectives_count={$objectivesCount}",
            ],
            [
                'requirement' => 'work_orders_exist',
                'status' => $workOrdersCount > 0 ? 'passed' : 'failed',
                'detail' => "work_orders_count={$workOrdersCount}",
            ],
            [
                'requirement' => 'evidence_refs_exist',
                'status' => $evidenceCount > 0 ? 'passed' : 'failed',
                'detail' => "evidence_refs_count={$evidenceCount}",
            ],
            [
                'requirement' => 'dod_has_criteria',
                'status' => $dodCriteria !== [] ? 'passed' : 'failed',
                'detail' => 'criteria_count='.count($dodCriteria),
            ],
            [
                'requirement' => 'work_orders_have_receipt_hash',
                'status' => $workOrdersCount > 0 && $workOrdersMissingHash === 0 ? 'passed' : 'failed',
                'detail' => "work_orders_missing_hash={$workOrdersMissingHash}",
            ],
        ];
    }
}
