<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Simplification;

/**
 * Runs the orphan simplification-analysis cluster for one candidate and
 * assembles the facts bundle that AtlasSelfConstructionSimplificationReadinessGate::evaluate()
 * consumes. Each analyzer is pure — the runner never writes files, never
 * calls providers, never spawns processes.
 *
 * INPUT candidate sections:
 *   organs:       list<array{name, capability_label, inputs, outputs, proof_refs, consumers, …}>
 *                 — fed to CircuitClusterDetector::detect()
 *   consumers:    list<array{name, category, proof_refs, transitive, …}>
 *                 — fed to ConsumerImpactAnalyzer::analyze()
 *   before_after: array{before: array, after: array}
 *                 — fed to CohesionMetric::measure()
 *   targets:      list<array{name, test_proof, replay_proof, …}>
 *                 — fed to ProofCoverageIndex::build()
 *   rollback:     array{reversible: bool}
 *                 — surface as facts['rollback']
 *   parity:       array{replacement_allowed: bool}
 *                 — surface as facts['parity']
 *
 * OUTPUT bundle shape (ReadinessGate input):
 *   {
 *     cluster:         {merge_ready: bool, clusters: list},
 *     cohesion:        {consolidation_improves_circuit: bool, delta: float, before_score: float, after_score: float},
 *     consumer_impact: {breaking_changes: list<string>},
 *     proof_coverage:  float (0..1 — fraction of targets safe_for_consolidation),
 *     rollback:        {reversible: bool},
 *     parity:          {replacement_allowed: bool},
 *   }
 */
final class AtlasSelfConstructionSimplificationSignalRunner
{
    public const SCHEMA = 'atlas.self_construction.simplification_signal_runner.v1';

    public function __construct(
        private readonly AtlasSelfConstructionCircuitClusterDetector $clusterDetector = new AtlasSelfConstructionCircuitClusterDetector,
        private readonly AtlasSelfConstructionSimplificationConsumerImpactAnalyzer $consumerAnalyzer = new AtlasSelfConstructionSimplificationConsumerImpactAnalyzer,
        private readonly AtlasSelfConstructionCircuitCohesionMetric $cohesionMetric = new AtlasSelfConstructionCircuitCohesionMetric,
        private readonly AtlasSelfConstructionSimplificationProofCoverageIndex $proofIndex = new AtlasSelfConstructionSimplificationProofCoverageIndex,
    ) {}

    /**
     * @param  array<string,mixed>  $candidate
     * @return array<string,mixed>
     */
    public function run(array $candidate): array
    {
        $organs = (array) ($candidate['organs'] ?? []);
        $consumers = (array) ($candidate['consumers'] ?? []);
        $beforeAfter = (array) ($candidate['before_after'] ?? []);
        $targets = (array) ($candidate['targets'] ?? []);
        $rollback = (array) ($candidate['rollback'] ?? []);
        $parity = (array) ($candidate['parity'] ?? []);

        // 1. Cluster detection
        $clusterResult = $this->clusterDetector->detect($organs);
        $mergeReady = false;
        $clusterSections = [];
        foreach ((array) ($clusterResult['clusters'] ?? []) as $cluster) {
            if ((bool) ($cluster['merge_ready'] ?? false)) {
                $mergeReady = true;
            }
            $clusterSections[] = [
                'cluster_id' => (string) ($cluster['cluster_id'] ?? ''),
                'capability_label' => (string) ($cluster['capability_label'] ?? ''),
                'member_count' => count((array) ($cluster['members'] ?? [])),
                'merge_ready' => (bool) ($cluster['merge_ready'] ?? false),
                'duplicate_confidence' => (string) ($cluster['duplicate_confidence'] ?? 'low'),
                'cluster_type' => (string) ($cluster['cluster_type'] ?? ''),
            ];
        }

        // 2. Consumer impact
        $consumerResult = $this->consumerAnalyzer->analyze(['consumers' => $consumers]);
        $breakingChanges = array_values((array) ($consumerResult['unsafe_consumers'] ?? []));

        // 3. Cohesion metric (before/after)
        $cohesionResult = $this->cohesionMetric->measure($beforeAfter);

        // 4. Proof coverage
        $proofResult = $this->proofIndex->build($targets);
        $safeCount = 0;
        $totalTargets = count($targets);
        foreach ((array) ($proofResult['coverage_by_target'] ?? []) as $flags) {
            if ((bool) ($flags['safe_for_consolidation'] ?? false)) {
                $safeCount++;
            }
        }
        $proofCoverage = $totalTargets > 0 ? round($safeCount / $totalTargets, 4) : 0.0;

        // 5. Rollback and parity are surfaced from the candidate directly
        $rollbackReversible = (bool) ($rollback['reversible'] ?? false);
        $parityAllowed = (bool) ($parity['replacement_allowed'] ?? false);

        $facts = [
            'cluster' => [
                'merge_ready' => $mergeReady,
                'clusters' => $clusterSections,
            ],
            'parity' => [
                'replacement_allowed' => $parityAllowed,
            ],
            'shadow_plan' => [
                'promotion_allowed' => $mergeReady && $parityAllowed && ! $breakingChanges,
            ],
            'cohesion' => [
                'consolidation_improves_circuit' => (bool) ($cohesionResult['consolidation_improves_circuit'] ?? false),
                'delta' => (float) ($cohesionResult['delta'] ?? 0.0),
                'before_score' => (float) ($cohesionResult['before_score'] ?? 0.0),
                'after_score' => (float) ($cohesionResult['after_score'] ?? 0.0),
            ],
            'consumer_impact' => [
                'breaking_changes' => $breakingChanges,
            ],
            'boundary_confidence' => $proofCoverage, // proxy: cluster overlap quality
            'proof_coverage' => $proofCoverage,
            'rollback' => [
                'reversible' => $rollbackReversible,
            ],
        ];

        return [
            'schema' => self::SCHEMA,
            'facts' => $facts,
            'raw_cluster_count' => count((array) ($clusterResult['clusters'] ?? [])),
            'raw_consumer_blocker_count' => count((array) ($consumerResult['blockers'] ?? [])),
            'raw_cohesion_delta' => (float) ($cohesionResult['delta'] ?? 0.0),
            'raw_proof_safe_count' => $safeCount,
            'raw_proof_total_count' => $totalTargets,
        ];
    }
}
