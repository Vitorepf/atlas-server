<?php

namespace App\Services\Ai\Programming\Forge\Intelligence;

use App\Models\AiForgeIntake;
use App\Models\AiForgeWorkPacket;
use App\Services\Ai\Mission\MissionCanonicalHash;

final class ForgeWorkPacketCapabilityOrchestrator
{
    public const SCHEMA_VERSION = 'atlas.forge.work_packet_capabilities.v1';

    public function __construct(
        private readonly ForgeWorkPacketIntelligenceRuntimeService $workPacketIntelligence,
        private readonly ForgeObraContextGateService $contextGate,
        private readonly ForgeTestImpactRuntimeService $testImpact,
        private readonly ForgeSpecialistWorkcellRouterService $workcellRouter,
        private readonly ForgeSeniorObraReviewService $seniorReview,
        private readonly ForgeObraScopeGuardService $scopeGuard,
        private readonly ForgeProviderProjectionService $providerProjection,
        private readonly ForgeObraSimulationService $simulation,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function build(AiForgeWorkPacket $packet, ?AiForgeIntake $intake = null): array
    {
        $intake ??= $packet->intake;
        $intelligence = $this->workPacketIntelligence->analyze($packet, $intake);
        $contextGate = $this->contextGate->evaluate($packet, $intake, $intelligence);
        $testImpact = $this->testImpact->select($packet, $intake, $intelligence);
        $workcell = $this->workcellRouter->route($packet);
        $scopeGuard = $this->scopeGuard->guard($packet, $intake, $intelligence);
        $seniorReview = $this->seniorReview->review($packet, $intake, $contextGate, $testImpact, $workcell);
        $providerProjection = $this->providerProjection->build($packet, $intake, $contextGate, $scopeGuard, $testImpact);
        $simulation = $this->simulation->simulate($packet, $intake, $contextGate, $scopeGuard, $testImpact);

        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'packet_id' => (string) $packet->packet_id,
            'blocks' => [
                'FWPIR' => $intelligence,
                'FOCG' => $contextGate,
                'FTIR' => $testImpact,
                'FSWR' => $workcell,
                'FSORB' => $seniorReview,
                'FOSG' => $scopeGuard,
                'FPPR' => $providerProjection,
                'FOSR' => $simulation,
            ],
            'status' => $this->status([$contextGate, $scopeGuard, $seniorReview]),
            'claim_policy' => [
                'provider_calls_made' => false,
                'rivals_run' => false,
                'read_only_analysis' => true,
                'real_execution_performed' => false,
            ],
        ];
        $payload['capability_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * @param  list<array<string,mixed>>  $gates
     */
    private function status(array $gates): string
    {
        foreach ($gates as $gate) {
            if (($gate['status'] ?? null) === 'blocked') {
                return 'blocked';
            }
        }

        return 'ready';
    }
}
