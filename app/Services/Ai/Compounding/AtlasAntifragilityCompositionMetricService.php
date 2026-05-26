<?php

declare(strict_types=1);

namespace App\Services\Ai\Compounding;

use App\Services\Ai\AtlasDecide\AtlasDecideGatewayConsultationService;
use App\Services\Ai\Cognition\AtlasCognitionScoreCardService;
use App\Services\Ai\Governance\AtlasAutonomyAdmissionService;
use App\Services\Ai\Governance\AtlasConstitutionalKernelService;
use App\Services\Ai\Reconciliation\AtlasAutonomousReconciliationRuntimeService;
use App\Services\Ai\Teos\AtlasTeosI4CounterfactualTreeService;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

/**
 * Atlas Antifragility Composition Metric — Patamar 3 closure.
 *
 * Honest runtime measurement of the composed wrapper multiplier described
 * in the Atlas canon: resultado = N × M, where
 *   N = provider capability the operator brings in (assumed=1.0 baseline)
 *   M = Atlas wrapper multiplier composed of measurable, append-only signals.
 *
 * This service does NOT estimate N (we never claim about external provider
 * capability). It returns a structural M factor based ONLY on local-first
 * observable behavior — append-only counters, Kernel hash, scorecard ratio.
 *
 * The metric is provider-safe: no claims about benchmark/rivals/superiority.
 * It exists so the operator can see whether the wrapper is actually
 * compounding over time vs flat.
 *
 * Schema: atlas.antifragility.composition_metric.v1
 */
final class AtlasAntifragilityCompositionMetricService
{
    public const SCHEMA_VERSION = 'atlas.antifragility.composition_metric.v1';

    public function __construct(
        private readonly AtlasCognitionScoreCardService $scoreCard,
        private readonly AtlasConstitutionalKernelService $kernel,
        private readonly AtlasAutonomyAdmissionService $admission,
        private readonly AtlasAutonomousReconciliationRuntimeService $reconciliation,
        private readonly AtlasTeosI4CounterfactualTreeService $teosI4,
        private readonly AtlasDecideGatewayConsultationService $gatewayConsult,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function measure(): array
    {
        $scorecard = $this->scoreCard->build();
        $subsystemCount = (int) ($scorecard['subsystem_count'] ?? 0);
        $score = (array) ($scorecard['score'] ?? []);
        $overall = (int) ($score['overall_out_of_10'] ?? 0);

        $admissionCount = count($this->admission->listTickets());
        $reconciliationTicks = count($this->reconciliation->listTicks());
        $teosTrees = count($this->teosI4->listTrees());
        $kernelViolations = count($this->kernel->listViolations());
        $gatewayConsultations = count($this->gatewayConsult->listConsultations());

        // Structural multiplier M components (each contributes a factor):
        //   m_scorecard      : overall/10 — measures structural readiness
        //   m_observability  : log10(1 + admissions + ticks + trees) clamped — measures runtime activity volume
        //   m_governance     : kernel intactness (1.0 baseline, decremented by violation_share)
        //   m_subsystem_density : subsystem_count / canonical_baseline (43 today)
        $mScorecard = max(0.0, min(1.0, $overall / 10.0));

        $activitySum = $admissionCount + $reconciliationTicks + $teosTrees + $gatewayConsultations;
        $mObservability = log10(1 + $activitySum) / 3.0; // log10(1000)=3 ⇒ caps at activity=999
        $mObservability = max(0.0, min(1.0, $mObservability));

        // Governance: any violation reduces governance multiplier proportionally to ticket density.
        $totalGovEvents = $admissionCount + max(1, $kernelViolations);
        $mGovernance = max(0.0, 1.0 - ($kernelViolations / $totalGovEvents));

        $canonicalBaseline = 43;
        $mDensity = $canonicalBaseline > 0 ? min(1.0, $subsystemCount / $canonicalBaseline) : 0.0;

        // Composed M = geometric mean of components (each ∈ [0,1]).
        $components = [$mScorecard, $mObservability, $mGovernance, $mDensity];
        $product = 1.0;
        foreach ($components as $c) {
            $product *= max(0.0001, $c); // floor to keep geometric mean well-defined
        }
        $m = pow($product, 1.0 / count($components));

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'generated_at' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DateTimeInterface::ATOM),
            'inputs' => [
                'subsystem_count' => $subsystemCount,
                'overall_out_of_10' => $overall,
                'admission_count' => $admissionCount,
                'reconciliation_ticks' => $reconciliationTicks,
                'teos_i4_trees' => $teosTrees,
                'gateway_consultations' => $gatewayConsultations,
                'kernel_violations' => $kernelViolations,
            ],
            'components' => [
                'm_scorecard' => round($mScorecard, 4),
                'm_observability' => round($mObservability, 4),
                'm_governance' => round($mGovernance, 4),
                'm_density' => round($mDensity, 4),
            ],
            'wrapper_multiplier_m' => round($m, 4),
            'note' => 'M is the wrapper multiplier only. N (provider capability) is NOT measured here per claim_policy.',
            'claim_policy' => [
                'benchmark_claim_allowed' => false,
                'rivals_claim_allowed' => false,
                'superiority_claim_allowed' => false,
                'provider_capability_estimated' => false,
            ],
        ];
    }
}
