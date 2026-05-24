<?php

namespace App\Services\Ai\Programming\Forge\Intelligence;

use App\Models\AiForgeIntake;
use App\Models\AiForgeWorkPacket;
use App\Services\Ai\Mission\MissionCanonicalHash;
use App\Services\Ai\Product\AtlasExecutionDoctrineGateService;
use App\Services\Ai\Product\AtlasExecutionDoctrineRuntimeService;

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
        private readonly AtlasExecutionDoctrineRuntimeService $aedpds = new AtlasExecutionDoctrineRuntimeService,
        private readonly AtlasExecutionDoctrineGateService $aedpdsGate = new AtlasExecutionDoctrineGateService,
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
        $aedpds = $this->aedpdsProjection($packet, $intake, $seniorReview);

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
                'AEDPDS' => $aedpds,
            ],
            'status' => $this->status([$contextGate, $scopeGuard, $seniorReview, $aedpds]),
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
     * @return array<string,mixed>
     */
    private function aedpdsProjection(AiForgeWorkPacket $packet, ?AiForgeIntake $intake): array
    {
        $context = array_values(array_unique(array_filter([
            ...array_values((array) ($packet->expected_files ?? [])),
            ...array_values((array) ($intake?->context_refs ?? [])),
        ], static fn ($item): bool => is_string($item) && $item !== '')));
        $tests = array_values((array) ($packet->suggested_tests ?? []));
        $evidence = array_values((array) ($packet->required_evidence ?? []));
        $review = in_array((string) $packet->risk_band, ['high', 'critical'], true)
            ? array_values(array_filter([
                'forge_senior_obra_review_runtime',
            ]))
            : ['risk_review_not_required_for_current_band'];

        $doctrine = $this->aedpds->select([
            'task' => (string) $packet->objective,
            'surface' => 'atlas_forge',
            'workspace' => (string) ($intake?->workspace_slug ?? $packet->scope ?? ''),
            'task_type' => 'feature',
            'risk_level' => (string) ($packet->risk_band ?? 'medium'),
            'code_changes_requested' => true,
            'forge_involved' => true,
            'complex_product' => true,
            'files' => array_values((array) ($packet->expected_files ?? [])),
            'missing_context' => $context === [],
            'senior_review_present' => $review !== [],
        ]);
        $gate = $this->aedpdsGate->evaluate([
            'doctrine' => $doctrine,
            'acceptance_criteria' => array_values((array) ($packet->acceptance_criteria ?? [])),
            'context_refs' => $context,
            'tests' => $tests !== [] ? $tests : ['forge_packet_verification_plan'],
            'contracts' => array_values((array) ($packet->dependencies ?? [])),
            'docs' => array_values((array) ($intake?->context_refs ?? [])),
            'review' => $review,
            'evidence' => $evidence !== [] ? $evidence : ['forge_packet_evidence_required'],
            'ux_expectations' => ['forge_packet_acceptance_projection'],
        ]);

        return [
            'schema_version' => 'atlas.forge.work_packet_aedpds_projection.v1',
            'status' => (string) $gate['status'],
            'selected_drivers' => array_values((array) ($doctrine['selected_primary_drivers'] ?? [])),
            'secondary_drivers' => array_values((array) ($doctrine['selected_secondary_drivers'] ?? [])),
            'required_gates' => array_values((array) ($doctrine['required_gates'] ?? [])),
            'required_context' => array_values((array) ($doctrine['required_context'] ?? [])),
            'required_tests' => array_values((array) ($doctrine['required_tests'] ?? [])),
            'required_evidence' => array_values((array) ($doctrine['required_evidence'] ?? [])),
            'recommended_escalation' => (string) ($doctrine['recommended_escalation'] ?? 'forge_work_packet'),
            'gate_blockers' => array_values((array) ($gate['blockers'] ?? [])),
            'gate_warnings' => array_values((array) ($gate['warnings'] ?? [])),
            'doctrine_hash' => (string) ($doctrine['certification_hash'] ?? ''),
            'gate_hash' => (string) ($gate['hash'] ?? ''),
        ];
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
