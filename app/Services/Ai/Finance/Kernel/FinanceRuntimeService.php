<?php

namespace App\Services\Ai\Finance\Kernel;

use App\Models\AiDomainRuntimeRecord;
use App\Models\AiMission;
use App\Services\Ai\DomainRuntime\DomainManifestRegistryService;
use App\Services\Ai\DomainRuntime\DomainRuntimeRecordService;
use App\Services\Ai\Mission\MissionLifecycleService;

class FinanceRuntimeService
{
    public function __construct(
        private readonly DomainManifestRegistryService $registry,
        private readonly DomainRuntimeRecordService $records,
        private readonly MissionLifecycleService $lifecycle,
        private readonly FinanceDomainManifestSeeder $seeder,
        private readonly FinanceComplianceService $compliance,
    ) {}

    /**
     * Open a DomainRuntimeRecord for a finance mission and run the kill-switch
     * pre-check. Throws on live-trade intent before anything else.
     *
     * @param  array<string,mixed>  $context
     */
    public function open(AiMission $mission, string $capability, array $context = []): AiDomainRuntimeRecord
    {
        $this->compliance->assertNotLiveTrade($capability, $mission->raw_prompt);
        $this->seeder->seed();
        $manifest = $this->registry->findByDomainId(FinanceDomainCanon::DOMAIN_ID)
            ?? throw FinanceDomainException::manifestMissing();

        $record = $this->records->open($manifest, [
            'mission_id' => $mission->id,
            'work_order_id' => $context['work_order_id'] ?? null,
            'selected_capabilities' => [$capability],
            'execution_plan' => [
                'capability' => $capability,
                'mode' => 'review_only',
                'live_trading_allowed' => false,
            ],
            'evidence_refs' => $mission->evidenceRefs()->pluck('id')->all(),
            'blockers' => null,
        ]);

        $this->lifecycle->recordEvent(
            $mission,
            'finance.runtime.opened',
            'finance_runtime',
            [
                'capability' => $capability,
                'manifest_hash' => $manifest->manifest_hash,
                'runtime_record_id' => $record->id,
            ],
            null,
            $mission->status,
            $record->receipt_hash,
        );

        return $record;
    }

    public function complete(AiDomainRuntimeRecord $record): AiDomainRuntimeRecord
    {
        return $this->records->transition($record, DomainRuntimeRecordService::STATUS_COMPLETED);
    }
}
