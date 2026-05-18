<?php

namespace App\Services\Ai\Programming\Kernel;

use App\Models\AiDomainHandoff;
use App\Models\AiMission;
use App\Models\AiWorkOrder;
use App\Services\Ai\DomainRuntime\DomainHandoffService;
use App\Services\Ai\DomainRuntime\DomainManifestRegistryService;
use App\Services\Ai\DomainRuntime\DomainRuntimeException;
use App\Services\Ai\Mission\MissionLifecycleService;

class AtlasForgeHandoffAdapter
{
    public function __construct(
        private readonly DomainManifestRegistryService $manifests,
        private readonly DomainHandoffService $handoffs,
        private readonly MissionLifecycleService $lifecycle,
        private readonly ProgrammingDomainManifestSeeder $seeder,
    ) {}

    /**
     * Promote a heavy programming work_order to Atlas Forge via a Domain Runtime
     * handoff. Builds the canonical handoff packet (mission_id, work_order_id,
     * source/target domain, context_pack, evidence_refs, expected_output, receipt_hash)
     * and registers it through Meta 2 DomainHandoffService.
     *
     * NOTE: This adapter does NOT execute Forge directly. It emits the handoff record
     * that AtlasForgeProviderInvocationDriver / AtlasForgeNativeRivalsProtocolService
     * etc. consume in their own pipeline. Existing Forge services are not modified.
     *
     * @param  array<string,mixed>  $extra
     */
    public function promote(AiMission $mission, AiWorkOrder $workOrder, string $reason, array $extra = []): AiDomainHandoff
    {
        $this->seeder->seed();

        $programming = $this->manifests->findByDomainId(ProgrammingDomainKernelCanon::DOMAIN_ID)
            ?? throw ProgrammingAdapterException::manifestMissing();

        $allowedHandoffRules = (array) ($programming->handoff_rules ?? []);
        $sourceDomain = ProgrammingDomainKernelCanon::DOMAIN_ID;
        $targetDomain = $sourceDomain;

        $contextPack = [
            'workspace' => $extra['workspace'] ?? 'atlas-server',
            'task_contract' => [
                'work_order_id' => $workOrder->id,
                'mission_id' => $mission->id,
                'risk_level' => $mission->risk_level,
                'autonomy_level' => $mission->autonomy_level,
                'mission_type' => $mission->mission_type,
                'capability' => $extra['capability'] ?? 'programming.dev',
            ],
            'evidence_refs' => $mission->evidenceRefs()->pluck('id')->all(),
            'risk_register' => $extra['risk_register'] ?? [],
            'forge_target' => 'programming.forge',
            'why_escalated' => $reason,
        ];

        $expectedOutput = $extra['expected_output'] ?? [
            'kind' => 'forge_obra_delivery',
            'must_emit' => ['spec_pack', 'patch_set', 'certification', 'evidence_pack'],
        ];

        try {
            $handoff = $this->handoffs->emit($sourceDomain, $targetDomain, 'forge_escalation: '.$reason, [
                'context_pack' => $contextPack,
                'expected_output' => $expectedOutput,
                'evidence_refs' => $contextPack['evidence_refs'],
                'mission_id' => $mission->id,
                'work_order_id' => $workOrder->id,
            ]);
        } catch (DomainRuntimeException $e) {
            throw ProgrammingAdapterException::handoffFailed($e->getMessage());
        }

        $this->lifecycle->recordEvent(
            $mission,
            'programming.adapter.forge_handoff',
            'programming_adapter',
            [
                'handoff_id' => $handoff->id,
                'handoff_receipt_hash' => $handoff->receipt_hash,
                'why_escalated' => $reason,
                'work_order_id' => $workOrder->id,
            ],
            null,
            $mission->status,
            $handoff->receipt_hash,
        );

        return $handoff;
    }

    public function shouldPromote(AiMission $mission): ?string
    {
        if ($mission->mission_type === 'obra') {
            return 'scope_too_large';
        }

        return ProgrammingDomainKernelCanon::shouldEscalateToForge($mission->raw_prompt, $mission->mission_type);
    }
}
