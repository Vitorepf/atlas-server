<?php

namespace App\Services\Ai\Programming\Kernel;

use App\Models\AiDomainHandoff;
use App\Models\AiMission;
use App\Models\AiWorkOrder;
use App\Services\Ai\DomainRuntime\DomainHandoffService;
use App\Services\Ai\DomainRuntime\DomainManifestRegistryService;
use App\Services\Ai\DomainRuntime\DomainRuntimeException;
use App\Services\Ai\DualCore\DualCoreRouteDecisionCanon;
use App\Services\Ai\DualCore\DualCoreRouteDecisionService;
use App\Services\Ai\Mission\MissionLifecycleService;
use App\Services\Ai\Programming\AtlasDev\Schemas\EscalationPacket;
use App\Services\Ai\Support\DatabaseTableAvailability;
use Illuminate\Support\Str;
use Throwable;

/**
 * Kernel-side adapter that emits a canonical Domain Handoff (Meta 2) AND, when
 * dual-core contracts are available, the canonical
 * `atlas.dev_to_forge.escalation_packet.v1` + `atlas.dual_core.route_decision.v1`.
 *
 * Phase 1 of the consolidation plan (audit ref:
 * `docs/engineering-knowledge-base/atlas-dev-forge-escalation-consolidation-plan.md`):
 * the legacy `AiDomainHandoff` row is preserved bit-for-bit; the canonical
 * packet is ATTACHED to the mission event payload (under
 * `programming.adapter.forge_handoff.escalation_packet_v1`) and a
 * `route_decision.v1` row is recorded when the runtime is available. The
 * legacy `promote()` signature still returns `AiDomainHandoff` so existing
 * callers (smoke, readiness, tests) keep working.
 */
class AtlasForgeHandoffAdapter
{
    public function __construct(
        private readonly DomainManifestRegistryService $manifests,
        private readonly DomainHandoffService $handoffs,
        private readonly MissionLifecycleService $lifecycle,
        private readonly ProgrammingDomainManifestSeeder $seeder,
        private readonly DualCoreRouteDecisionService $routeDecisions,
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
        return $this->promoteWithPacket($mission, $workOrder, $reason, $extra)['handoff'];
    }

    /**
     * Same as {@see self::promote()} but returns the full canonical bundle
     * (legacy handoff + canonical escalation packet + route decision metadata).
     * Callers that want to consume the canonical contracts directly use this
     * method; legacy callers stay on {@see self::promote()}.
     *
     * @param  array<string,mixed>  $extra
     * @return array{handoff: AiDomainHandoff, escalation_packet_v1: array<string,mixed>|null, route_decision_v1: array<string,mixed>}
     */
    public function promoteWithPacket(AiMission $mission, AiWorkOrder $workOrder, string $reason, array $extra = []): array
    {
        $this->seeder->seed();

        $programming = $this->manifests->findByDomainId(ProgrammingDomainKernelCanon::DOMAIN_ID)
            ?? throw ProgrammingAdapterException::manifestMissing();

        // Acknowledged but unused (Meta 2 handoff_rules audit only). Kept for
        // future cross-domain validation hook.
        $allowedHandoffRules = (array) ($programming->handoff_rules ?? []);
        unset($allowedHandoffRules);

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

        $packet = $this->buildEscalationPacket($mission, $workOrder, $reason, $contextPack);
        $routeDecisionMeta = $this->recordRouteDecision($mission, $workOrder, $reason);

        $this->lifecycle->recordEvent(
            $mission,
            'programming.adapter.forge_handoff',
            'programming_adapter',
            [
                'handoff_id' => $handoff->id,
                'handoff_receipt_hash' => $handoff->receipt_hash,
                'why_escalated' => $reason,
                'work_order_id' => $workOrder->id,
                'escalation_packet_v1' => $packet,
                'route_decision_v1' => $routeDecisionMeta,
            ],
            null,
            $mission->status,
            $handoff->receipt_hash,
        );

        return [
            'handoff' => $handoff,
            'escalation_packet_v1' => $packet,
            'route_decision_v1' => $routeDecisionMeta,
        ];
    }

    public function shouldPromote(AiMission $mission): ?string
    {
        if ($mission->mission_type === 'obra') {
            return 'scope_too_large';
        }

        $prompt = trim((string) $mission->raw_prompt);
        if ($prompt === '') {
            $prompt = trim((string) $mission->title);
        }

        return ProgrammingDomainKernelCanon::shouldEscalateToForge($prompt, $mission->mission_type);
    }

    /**
     * Build the canonical `atlas.dev_to_forge.escalation_packet.v1` from the
     * mission/work_order/contextPack the legacy handoff already produced. Best
     * effort: any validation failure is captured so we never break the legacy
     * handoff path.
     *
     * @param  array<string,mixed>  $contextPack
     * @return array<string,mixed>|null
     */
    private function buildEscalationPacket(
        AiMission $mission,
        AiWorkOrder $workOrder,
        string $reason,
        array $contextPack,
    ): ?array {
        try {
            $intent = trim((string) $mission->raw_prompt);
            if ($intent === '') {
                $intent = trim((string) $mission->title);
            }
            if ($intent === '') {
                $intent = '(intent missing on mission '.$mission->id.')';
            }

            $triggers = array_values(array_filter([
                $this->mapReasonToTrigger($reason),
                $mission->mission_type === 'obra' ? EscalationPacket::TRIGGER_SCOPE_TOO_LARGE : null,
            ]));
            if ($triggers === []) {
                $triggers = [EscalationPacket::TRIGGER_OPERATOR_REQUESTED];
            }

            $evidenceIds = (array) ($contextPack['evidence_refs'] ?? []);
            $evidenceSlots = EscalationPacket::emptyEvidenceRefs();
            if ($evidenceIds !== []) {
                // We don't know which slot the first ref maps to; use `plan`
                // as the generic anchor and carry the remaining as failure
                // capsules so they survive serialization.
                $evidenceSlots['plan'] = 'mission_evidence:'.((string) $evidenceIds[0]);
                $remaining = array_slice($evidenceIds, 1);
                if ($remaining !== []) {
                    $evidenceSlots['failure_capsules'] = array_map(
                        static fn ($id): string => 'mission_evidence:'.((string) $id),
                        $remaining,
                    );
                }
            }

            $packet = EscalationPacket::issue(
                packetId: (string) Str::uuid(),
                originalUserIntent: $intent,
                normalizedIntent: (string) ($mission->normalized_intent ?? $intent),
                promotionReason: $reason,
                promotionTriggers: $triggers,
                scopeAssessment: 'mission_type='.((string) $mission->mission_type).'; risk='.((string) $mission->risk_level),
                riskAssessment: 'mission.risk_level='.((string) $mission->risk_level).'; autonomy='.((string) $mission->autonomy_level),
                ambiguityAssessment: 'kernel adapter: no ambiguity score available at this layer.',
                currentDevFindings: [],
                completedDevActions: [],
                incompleteDevActions: [],
                recommendedForgeMode: $this->recommendedForgeModeForReason($reason),
                suggestedWorkPackets: [[
                    'id' => 'wp_'.(string) $workOrder->id,
                    'title' => (string) $workOrder->title,
                    'capability' => (string) ($contextPack['task_contract']['capability'] ?? 'programming.forge'),
                    'why' => $reason,
                ]],
                definitionOfDone: array_values((array) ((array) $mission->definition_of_done)['criteria'] ?? []),
                requiredEvidence: ['workspace_audit', 'verification_receipt'],
                evidenceRefs: $evidenceSlots,
                contextRefs: [],
                contextPackHash: null,
                constraints: [],
                nonGoals: [],
                createdAt: now()->toJSON(),
            );

            return $packet->toCanonicalArray();
        } catch (Throwable $e) {
            return [
                'error' => 'escalation_packet_v1_build_failed: '.$e->getMessage(),
            ];
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function recordRouteDecision(AiMission $mission, AiWorkOrder $workOrder, string $reason): array
    {
        if (! DatabaseTableAvailability::has('ai_dual_core_route_decisions')) {
            return [
                'recorded' => false,
                'detail' => 'ai_dual_core_route_decisions table not available',
            ];
        }

        try {
            $intentSummary = trim((string) $mission->raw_prompt);
            if ($intentSummary === '') {
                $intentSummary = trim((string) $mission->title);
            }
            if ($intentSummary === '') {
                $intentSummary = 'programming.forge handoff for mission '.$mission->id;
            }

            $record = $this->routeDecisions->record(
                DualCoreRouteDecisionCanon::ROUTE_DEV_TO_FORGE,
                'kernel adapter forge_escalation: '.$reason,
                $intentSummary,
                [
                    'mission_id' => $mission->id,
                    'work_order_id' => $workOrder->id,
                    'risk_level' => $this->mapMissionRiskToCanon((string) $mission->risk_level),
                    'ambiguity_level' => DualCoreRouteDecisionCanon::AMBIGUITY_MEDIUM,
                    'expected_duration' => $mission->mission_type === 'obra'
                        ? DualCoreRouteDecisionCanon::DURATION_WEEKS
                        : DualCoreRouteDecisionCanon::DURATION_DAYS,
                    'modules_touched_estimate' => 1,
                    'sdd_required' => true,
                    'evidence_required' => ['workspace_audit', 'verification_receipt'],
                    'operator_visible' => true,
                    'routing_signals' => [
                        'source' => 'atlas_forge_handoff_adapter',
                        'mission_type' => (string) $mission->mission_type,
                        'reason' => $reason,
                    ],
                    'actor_type' => 'programming_adapter',
                ],
            );

            return [
                'recorded' => true,
                'uuid' => (string) $record->uuid,
                'route' => (string) $record->route,
                'decision_hash' => (string) $record->decision_hash,
            ];
        } catch (Throwable $e) {
            return [
                'recorded' => false,
                'detail' => 'route_decision_record_failed: '.$e->getMessage(),
            ];
        }
    }

    private function mapReasonToTrigger(string $reason): ?string
    {
        $lower = strtolower($reason);
        if (str_contains($lower, 'scope') || str_contains($lower, 'obra')) {
            return EscalationPacket::TRIGGER_SCOPE_TOO_LARGE;
        }
        if (str_contains($lower, 'sdd')) {
            return EscalationPacket::TRIGGER_SDD_REQUIRED;
        }
        if (str_contains($lower, 'multiagent') || str_contains($lower, 'multi-agent')) {
            return EscalationPacket::TRIGGER_MULTIAGENT_REQUIRED;
        }
        if (str_contains($lower, 'evidence')) {
            return EscalationPacket::TRIGGER_EVIDENCE_INSUFFICIENT;
        }
        if (str_contains($lower, 'high_risk') || str_contains($lower, 'risk_high') || str_contains($lower, 'r4') || str_contains($lower, 'critical')) {
            return EscalationPacket::TRIGGER_HIGH_RISK;
        }
        if (str_contains($lower, 'time_budget') || str_contains($lower, 'duration')) {
            return EscalationPacket::TRIGGER_TIME_BUDGET_EXCEEDED;
        }
        if (str_contains($lower, 'operator')) {
            return EscalationPacket::TRIGGER_OPERATOR_REQUESTED;
        }

        return null;
    }

    private function recommendedForgeModeForReason(string $reason): string
    {
        $lower = strtolower($reason);
        if (str_contains($lower, 'architecture')) {
            return EscalationPacket::RECOMMENDED_FORGE_MODE_ARCHITECTURE_REVIEW;
        }
        if (str_contains($lower, 'long_run') || str_contains($lower, 'time_budget')) {
            return EscalationPacket::RECOMMENDED_FORGE_MODE_LONG_RUN;
        }
        if (str_contains($lower, 'sdd')) {
            return EscalationPacket::RECOMMENDED_FORGE_MODE_SDD_INTAKE;
        }

        return EscalationPacket::RECOMMENDED_FORGE_MODE_OBRA_INTAKE;
    }

    private function mapMissionRiskToCanon(string $missionRisk): string
    {
        return match (strtolower($missionRisk)) {
            'critical' => DualCoreRouteDecisionCanon::RISK_CRITICAL,
            'high' => DualCoreRouteDecisionCanon::RISK_HIGH,
            'low' => DualCoreRouteDecisionCanon::RISK_LOW,
            default => DualCoreRouteDecisionCanon::RISK_MEDIUM,
        };
    }
}
