<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Provider-pool safety gating runner. Before a provider pool serves, assesses
 * credential leakage risk, records quota headroom, runs a smoke-test plan,
 * and normalizes cross-provider output — so the capability-proof map refuses
 * a credential-leaking or over-quota pool.
 *
 * Pure / deterministic: no I/O, no side effects.
 */
final class AtlasExternalBrainProviderPoolSafetyRunner
{
    public const SCHEMA = 'atlas.external_brain.provider_pool_safety_runner.v1';

    private AtlasExternalBrainProviderPoolCredentialSafetyGate $credentialGate;

    private AtlasExternalBrainProviderPoolQuotaBoundaryLedger $quotaLedger;

    private AtlasExternalBrainProviderPoolSmokeTestPlan $smokeTestPlan;

    private AtlasExternalBrainProviderPoolOutputNormalizer $outputNormalizer;

    public function __construct(
        ?AtlasExternalBrainProviderPoolCredentialSafetyGate $credentialGate = null,
        ?AtlasExternalBrainProviderPoolQuotaBoundaryLedger $quotaLedger = null,
        ?AtlasExternalBrainProviderPoolSmokeTestPlan $smokeTestPlan = null,
        ?AtlasExternalBrainProviderPoolOutputNormalizer $outputNormalizer = null,
    ) {
        $this->credentialGate = $credentialGate ?? new AtlasExternalBrainProviderPoolCredentialSafetyGate;
        $this->quotaLedger = $quotaLedger ?? new AtlasExternalBrainProviderPoolQuotaBoundaryLedger;
        $this->smokeTestPlan = $smokeTestPlan ?? new AtlasExternalBrainProviderPoolSmokeTestPlan;
        $this->outputNormalizer = $outputNormalizer ?? new AtlasExternalBrainProviderPoolOutputNormalizer;
    }

    /**
     * @param  array<string,mixed>  $input
     *   {
     *     pool_id?:               string
     *     credential_facts:       array  — passed to credentialGate->assess()
     *     quota_facts:            array  — passed to quotaLedger->record()
     *     smoke_evidence:         array  — passed to smokeTestPlan->plan()
     *     raw_outputs?:           array  — passed to outputNormalizer->normalize()
     *   }
     * @return array<string,mixed>
     */
    public function run(array $input): array
    {
        $poolId = (string) ($input['pool_id'] ?? '');

        $credentialFacts = is_array($input['credential_facts'] ?? null) ? $input['credential_facts'] : [];
        $credentialSafety = $this->credentialGate->assess($credentialFacts);

        $quotaFacts = is_array($input['quota_facts'] ?? null) ? $input['quota_facts'] : [];
        $quotaEntry = $this->quotaLedger->record($quotaFacts);

        $smokeInput = is_array($input['smoke_evidence'] ?? null) ? ['evidence' => $input['smoke_evidence']] : [];
        $smokePlan = $this->smokeTestPlan->plan($smokeInput);

        $rawOutputs = is_array($input['raw_outputs'] ?? null) ? ['outputs' => $input['raw_outputs']] : [];
        $normalized = $this->outputNormalizer->normalize($rawOutputs);

        // Collect reasons — credential blocking, quota exhaustion, incomplete smoke.
        $reasons = [];
        foreach ($credentialSafety['blockers'] ?? [] as $blocker) {
            $reasons[] = 'credential:'.$blocker;
        }

        if (($quotaEntry['observed_exhaustion'] ?? false)) {
            $reasons[] = 'quota:exhausted';
        }
        if (! ($quotaEntry['quota_reliable_for_24_7'] ?? false)) {
            foreach ($quotaEntry['unreliable_reasons'] ?? [] as $ur) {
                $reasons[] = 'quota:'.$ur;
            }
        }

        if (($smokePlan['status'] ?? '') !== 'ready_for_optional_routing') {
            foreach ($smokePlan['missing_evidence'] ?? [] as $missing) {
                $reasons[] = 'smoke:'.$missing;
            }
        }

        $credentialSafe = (bool) ($credentialSafety['safe_to_probe'] ?? false);
        $safe = $credentialSafe && $reasons === [];

        return [
            'schema' => self::SCHEMA,
            'pool_id' => $poolId,
            'safe' => $safe,
            'reasons' => $reasons,
            'credential_safety' => $credentialSafety,
            'quota_boundary' => $quotaEntry,
            'smoke_plan' => $smokePlan,
            'normalized_outputs' => $normalized,
        ];
    }
}
