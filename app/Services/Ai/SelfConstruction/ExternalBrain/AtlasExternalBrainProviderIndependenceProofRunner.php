<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Proves no single provider is load-bearing by:
 *   1. Loading a provider-agnostic benchmark set
 *   2. Proving independence (no single provider is required for steady state)
 *   3. Attributing pool outcomes to individual providers
 *   4. Dry-running a patch across pools to prove it survives losing any one
 *
 * Pure: no I/O, no side effects.
 */
final class AtlasExternalBrainProviderIndependenceProofRunner
{
    public const SCHEMA = 'atlas.external_brain.provider_independence_proof_runner.v1';

    public function __construct(
        private readonly AtlasExternalBrainProviderAgnosticBenchmarkSet $benchmarkSet = new AtlasExternalBrainProviderAgnosticBenchmarkSet,
        private readonly AtlasExternalBrainProviderIndependenceProof $independenceProof = new AtlasExternalBrainProviderIndependenceProof,
        private readonly AtlasExternalBrainProviderPoolOutcomeAttributor $attributor = new AtlasExternalBrainProviderPoolOutcomeAttributor,
        private readonly AtlasExternalBrainProviderPoolPatchDryRunPlan $dryRunPlanner = new AtlasExternalBrainProviderPoolPatchDryRunPlan,
    ) {}

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function run(array $input): array
    {
        $providers = (array) ($input['providers'] ?? []);
        $outcomes = (array) ($input['outcomes'] ?? []);
        $patchHash = (string) ($input['patch_hash'] ?? '');

        $benchmarks = $this->benchmarkSet->load(['providers' => $providers]);
        $independence = $this->independenceProof->prove(['providers' => $providers]);
        $attribution = $this->attributor->attribute(['outcomes' => $outcomes]);
        $dryRun = $this->dryRunPlanner->plan(['providers' => $providers, 'patch_hash' => $patchHash]);

        $independent = $independence['independent'] && $dryRun['all_pools_survive'];

        return [
            'schema_version' => self::SCHEMA,
            'independent' => $independent,
            'load_bearing_providers' => $independence['load_bearing_providers'],
            'benchmarks' => $benchmarks,
            'independence_proof' => $independence,
            'outcome_attribution' => $attribution,
            'patch_dry_run' => $dryRun,
            'summary' => $independent
                ? 'no_single_provider_is_load_bearing'
                : 'load_bearing_providers:'.implode(',', $independence['load_bearing_providers']),
        ];
    }
}
