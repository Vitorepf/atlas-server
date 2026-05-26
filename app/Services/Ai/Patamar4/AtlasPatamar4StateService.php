<?php

declare(strict_types=1);

namespace App\Services\Ai\Patamar4;

use App\Services\Ai\AtlasDecide\AtlasDecideGatewayConsultationService;
use App\Services\Ai\AtlasDecide\AtlasSwarmConductorService;
use App\Services\Ai\Cognition\AtlasCognitiveFunctionAtlasService;
use App\Services\Ai\Compounding\AtlasAntifragilityCompositionMetricService;
use App\Services\Ai\CrossDomain\AtlasTemporaryDomainCompositionService;
use App\Services\Ai\Governance\AtlasAutonomyAdmissionService;
use App\Services\Ai\Governance\AtlasConstitutionalKernelService;
use App\Services\Ai\Reconciliation\AtlasAutonomousReconciliationRuntimeService;
use App\Services\Ai\Teos\AtlasTeosI4CounterfactualTreeService;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

/**
 * Atlas Patamar 4 State — read-only aggregator surfacing the live state of
 * the autonomous loop for UI / API consumers.
 *
 * Composer puro sobre os 7 services Patamar 4. NÃO duplica nada.
 *
 * Schema: atlas.patamar4.state.v1
 */
final class AtlasPatamar4StateService
{
    public const SCHEMA_VERSION = 'atlas.patamar4.state.v1';

    public function __construct(
        private readonly AtlasConstitutionalKernelService $kernel,
        private readonly AtlasAutonomyAdmissionService $admission,
        private readonly AtlasCognitiveFunctionAtlasService $cfa,
        private readonly AtlasAutonomousReconciliationRuntimeService $reconciliation,
        private readonly AtlasTeosI4CounterfactualTreeService $teosI4,
        private readonly AtlasSwarmConductorService $swarm,
        private readonly AtlasTemporaryDomainCompositionService $tdc,
        private readonly AtlasDecideGatewayConsultationService $gatewayConsult,
        private readonly AtlasAntifragilityCompositionMetricService $antifragility,
        private readonly ?\App\Services\Ai\AtlasDecide\AtlasDecideLiveOutcomeFeedbackService $liveFeedback = null,
        private readonly ?AtlasSchedulerHealthService $schedulerHealth = null,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function snapshot(int $tail = 5): array
    {
        $admissionTickets = $this->admission->listTickets();
        $reconciliationTicks = $this->reconciliation->listTicks();
        $teosTrees = $this->teosI4->listTrees();
        $swarmDispatches = $this->swarm->listDispatches();
        $activeCapsules = $this->tdc->listActiveCapsules();
        $allCapsules = $this->tdc->listAllCapsules();
        $kernelViolations = $this->kernel->listViolations();

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'generated_at' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DateTimeInterface::ATOM),
            'kernel' => [
                'kernel_hash' => $this->kernel->kernelHash(),
                'invariants' => $this->kernel->listInvariants(AtlasConstitutionalKernelService::CLASS_PETREO),
                'violation_count' => count($kernelViolations),
                'recent_violations' => array_slice($kernelViolations, -$tail),
            ],
            'cognitive_function_atlas' => [
                'subsystem_count' => $this->cfa->selfModel()['subsystem_count'] ?? 0,
                'group_count' => $this->cfa->selfModel()['group_count'] ?? 0,
                'gaps' => $this->cfa->gapsByGroup(),
            ],
            'autonomy_admission' => [
                'ticket_count' => count($admissionTickets),
                'recent_tickets' => array_slice($admissionTickets, -$tail),
            ],
            'reconciliation' => [
                'summary' => $this->reconciliation->summary(),
                'recent_ticks' => array_slice($reconciliationTicks, -$tail),
            ],
            'teos_i4' => [
                'tree_count' => count($teosTrees),
                'recent_trees' => array_slice($teosTrees, -$tail),
            ],
            'swarm' => [
                'dispatch_count' => count($swarmDispatches),
                'recent_dispatches' => array_slice($swarmDispatches, -$tail),
            ],
            'temporary_domain' => [
                'active_capsule_count' => count($activeCapsules),
                'total_capsule_count' => count($allCapsules),
                'recent_capsules' => array_slice($allCapsules, -$tail),
            ],
            'gateway_consultations' => [
                'count' => count($this->gatewayConsult->listConsultations()),
                'recent' => array_slice($this->gatewayConsult->listConsultations(), -$tail),
            ],
            'scheduler' => $this->schedulerStatus(),
            'live_outcome_feedback' => $this->liveOutcomeFeedbackSummary($tail),
            'antifragility' => $this->antifragility->measure(),
            'claim_policy' => [
                'benchmark_claim_allowed' => false,
                'rivals_claim_allowed' => false,
                'superiority_claim_allowed' => false,
                'external_rivals_certification_touched' => false,
                'cognitive_immune_law_enforced' => true,
                'provider_safe_only_enforced' => true,
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function schedulerStatus(): array
    {
        if ($this->schedulerHealth === null) {
            return [
                'wired' => false,
                'silent_alarm' => true,
                'reason' => 'service_not_wired',
            ];
        }
        try {
            return ['wired' => true] + $this->schedulerHealth->status();
        } catch (\Throwable $e) {
            return [
                'wired' => true,
                'silent_alarm' => true,
                'reason' => 'status_error',
                'error' => substr($e->getMessage(), 0, 120),
            ];
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function liveOutcomeFeedbackSummary(int $tail): array
    {
        if ($this->liveFeedback === null) {
            return [
                'wired' => false,
                'outcome_count' => 0,
                'recent_outcomes' => [],
            ];
        }
        try {
            $outcomes = $this->liveFeedback->listOutcomes();

            return [
                'wired' => true,
                'outcome_count' => count($outcomes),
                'recent_outcomes' => array_slice($outcomes, -$tail),
            ];
        } catch (\Throwable $e) {
            return [
                'wired' => true,
                'outcome_count' => 0,
                'recent_outcomes' => [],
                'error' => substr($e->getMessage(), 0, 120),
            ];
        }
    }
}
