<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Orchestrates the full provider-independence proof pipeline:
 *
 *   1. Load a provider-agnostic benchmark set (safe cases, scoring dimensions, traps).
 *   2. Prove provider independence across mandatory phases (proof claims → independence verdict).
 *   3. Attribute outcomes across provider pools (routing lessons, causal classification).
 *   4. Plan a cross-pool patch dry-run (evidence contracts → sandbox readiness).
 *
 * All four sub-services are pure / deterministic / zero I/O, so the runner itself
 * is also pure.
 *
 * OUTPUT:
 *   { schema, independent, benchmark, proof, attribution, dry_run, reasons }
 *
 * - independent: true ONLY when all mandatory proof phases are independent AND
 *   benchmark cases all pass provider-safety checks
 * - reasons: strings explaining why independence fails (if it does)
 *
 * Nullable constructor injection for dependency mocking.
 */
final class AtlasExternalBrainProviderIndependenceProofRunner
{
    public const SCHEMA = 'atlas.external_brain.provider_independence_proof_runner.v1';

    /**
     * @param  AtlasExternalBrainProviderAgnosticBenchmarkSet|null  $benchmarkSet
     * @param  AtlasExternalBrainProviderIndependenceProof|null     $proof
     * @param  AtlasExternalBrainProviderPoolOutcomeAttributor|null $attributor
     * @param  AtlasExternalBrainProviderPoolPatchDryRunPlan|null   $dryRunPlan
     */
    public function __construct(
        private readonly ?AtlasExternalBrainProviderAgnosticBenchmarkSet $benchmarkSet = null,
        private readonly ?AtlasExternalBrainProviderIndependenceProof $proof = null,
        private readonly ?AtlasExternalBrainProviderPoolOutcomeAttributor $attributor = null,
        private readonly ?AtlasExternalBrainProviderPoolPatchDryRunPlan $dryRunPlan = null,
    ) {}

    /**
     * Run the full provider-independence proof pipeline.
     *
     * @param  array<string,mixed>  $input
     *        Optional keys:
     *          benchmark     => input for AtlasExternalBrainProviderAgnosticBenchmarkSet::load()
     *          proof_claims  => proof_claims for AtlasExternalBrainProviderIndependenceProof::prove()
     *          outcomes      => outcomes for AtlasExternalBrainProviderPoolOutcomeAttributor::attribute()
     *          evidence      => evidence for AtlasExternalBrainProviderPoolPatchDryRunPlan::plan()
     *
     * @return array<string,mixed>
     */
    public function run(array $input = []): array
    {
        $benchmarkInput  = is_array($input['benchmark'] ?? null) ? $input['benchmark'] : [];
        $proofClaims     = is_array($input['proof_claims'] ?? null) ? $input['proof_claims'] : [];
        $outcomes        = is_array($input['outcomes'] ?? null) ? $input['outcomes'] : [];
        $evidence        = is_array($input['evidence'] ?? null) ? $input['evidence'] : [];

        $instBenchmarkSet = $this->benchmarkSet ?? new AtlasExternalBrainProviderAgnosticBenchmarkSet;
        $instProof        = $this->proof        ?? new AtlasExternalBrainProviderIndependenceProof;
        $instAttributor   = $this->attributor   ?? new AtlasExternalBrainProviderPoolOutcomeAttributor;
        $instDryRunPlan   = $this->dryRunPlan   ?? new AtlasExternalBrainProviderPoolPatchDryRunPlan;

        // Step 1: Load benchmark set (provider-agnostic challenge cases).
        $benchmark = $instBenchmarkSet->load($benchmarkInput);

        // Step 2: Prove provider independence from proof claims.
        $proofResult  = $instProof->prove(['proof_claims' => $proofClaims]);

        // Step 3: Attribute outcomes across provider pools.
        $attribution  = $instAttributor->attribute(['outcomes' => $outcomes]);

        // Step 4: Plan cross-pool patch dry-run.
        $dryRun       = $instDryRunPlan->plan(['evidence' => $evidence]);

        // Derive overall independence verdict.
        $benckmarkSafe = ($benchmark['provider_safe_status']['is_safe'] ?? false) === true;
        $proofIndependent = ($proofResult['independent'] ?? false) === true;

        $independent = $benckmarkSafe && $proofIndependent;

        $reasons = [];
        if (! $benckmarkSafe) {
            $violations = $benchmark['provider_safe_status']['violations'] ?? [];
            $reasons[] = 'benchmark_provider_safety_violations:'.implode(',', $violations);
        }
        if (! $proofIndependent) {
            $blockers = $proofResult['steady_state_blockers'] ?? [];
            foreach ($proofResult['provider_required_phases'] ?? [] as $prp) {
                $phase = $prp['phase'] ?? 'unknown';
                $reasons[] = "provider_required_phase:{$phase}";
            }
            foreach ($proofResult['missing_proofs'] ?? [] as $mp) {
                $phase = $mp['phase'] ?? 'unknown';
                $missingList = implode(',', $mp['missing_coverage'] ?? []);
                $reasons[] = "missing_proof:{$phase}::{ {$missingList} }";
            }
        }

        return [
            'schema'      => self::SCHEMA,
            'independent' => $independent,
            'benchmark'   => $benchmark,
            'proof'       => $proofResult,
            'attribution' => $attribution,
            'dry_run'     => $dryRun,
            'reasons'     => $reasons,
        ];
    }
}
