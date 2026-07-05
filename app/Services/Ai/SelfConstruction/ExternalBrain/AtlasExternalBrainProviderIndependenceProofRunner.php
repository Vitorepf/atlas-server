<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Composes and runs the full provider-independence proof pipeline:
 *
 *   1. Load provider-agnostic benchmarks
 *   2. Prove provider independence across mandatory phases
 *   3. Attribute pool outcomes
 *   4. Plan cross-pool patch dry-run
 *
 * Pure / deterministic / no I/O. Never calls a provider, never dispatches,
 * never spends tokens.
 */
final class AtlasExternalBrainProviderIndependenceProofRunner
{
    public const SCHEMA = 'atlas.external_brain.provider_independence_proof_runner.v1';

    private AtlasExternalBrainProviderAgnosticBenchmarkSet $benchmarkSet;
    private AtlasExternalBrainProviderIndependenceProof $independenceProof;
    private AtlasExternalBrainProviderPoolOutcomeAttributor $outcomeAttributor;
    private AtlasExternalBrainProviderPoolPatchDryRunPlan $dryRunPlan;

    public function __construct(
        ?AtlasExternalBrainProviderAgnosticBenchmarkSet $benchmarkSet = null,
        ?AtlasExternalBrainProviderIndependenceProof $independenceProof = null,
        ?AtlasExternalBrainProviderPoolOutcomeAttributor $outcomeAttributor = null,
        ?AtlasExternalBrainProviderPoolPatchDryRunPlan $dryRunPlan = null,
    ) {
        $this->benchmarkSet = $benchmarkSet ?? new AtlasExternalBrainProviderAgnosticBenchmarkSet;
        $this->independenceProof = $independenceProof ?? new AtlasExternalBrainProviderIndependenceProof;
        $this->outcomeAttributor = $outcomeAttributor ?? new AtlasExternalBrainProviderPoolOutcomeAttributor;
        $this->dryRunPlan = $dryRunPlan ?? new AtlasExternalBrainProviderPoolPatchDryRunPlan;
    }

    /**
     * Run the full provider-independence proof pipeline.
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function run(array $input = []): array
    {
        $benchmarkResult = $this->benchmarkSet->load($input);
        $proofResult = $this->independenceProof->prove(
            array_merge($input, ['_benchmark_result' => $benchmarkResult]),
        );
        $attributionResult = $this->outcomeAttributor->attribute(
            array_merge($input, ['_proof_result' => $proofResult]),
        );
        $planResult = $this->dryRunPlan->plan(
            array_merge($input, ['_attribution_result' => $attributionResult]),
        );

        $independent = (bool) ($proofResult['independent'] ?? false);
        $reasons = [];
        if (! $independent) {
            $blockers = (array) ($proofResult['steady_state_blockers'] ?? []);
            foreach ($blockers as $phase) {
                $reasons[] = "provider_dependent_phase:{$phase}";
            }
            $missingProofs = (array) ($proofResult['missing_proofs'] ?? []);
            foreach ($missingProofs as $mp) {
                $phase = (string) ($mp['phase'] ?? 'unknown');
                $reasons[] = "missing_proof:{$phase}";
            }
            if ($reasons === []) {
                $reasons[] = 'provider_dependency_detected';
            }
        }

        return [
            'schema'       => self::SCHEMA,
            'independent'  => $independent,
            'benchmark'    => $benchmarkResult,
            'proof'        => $proofResult,
            'attribution'  => $attributionResult,
            'dry_run'      => $planResult,
            'reasons'      => $reasons,
        ];
    }
}
