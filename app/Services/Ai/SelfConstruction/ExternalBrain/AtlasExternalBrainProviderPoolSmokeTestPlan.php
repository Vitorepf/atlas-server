<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Generates a safe, non-executing smoke-test plan for proving an optional
 * provider pool (e.g. Cursor Composer) before Atlas routes real muscle or
 * brain work through it. This class never calls a provider, never spends
 * tokens, never executes an adapter — it only returns the ordered plan and
 * judges supplied evidence fields.
 *
 * Ordered plan steps (every step is non_executing_plan_only=true):
 *   1. preflight
 *   2. harmless_prompt
 *   3. patch_generation_dry_run
 *   4. no_secret_policy_check
 *   5. cost_boundary_check
 *   6. timeout_boundary
 *   7. fallback_replay
 *
 * REQUIRED EVIDENCE (all must be true for status=ready_for_optional_routing):
 *   sdk_proof, entitlement_proof, output_contract_proof, cost_proof, fallback_proof
 *
 * INPUT:
 *   evidence: {
 *     sdk_proof?:            bool (default false)
 *     entitlement_proof?:    bool (default false)
 *     output_contract_proof?: bool (default false)
 *     cost_proof?:           bool (default false)
 *     fallback_proof?:       bool (default false)
 *   }
 *
 * OUTPUT:
 *   { schema, status, steps, required_evidence, evidence, missing_evidence,
 *     provider_call_allowed=false, token_spend_allowed=false, adapter_execution_allowed=false }
 *
 * Pure: no I/O, no network calls, no side effects.
 */
final class AtlasExternalBrainProviderPoolSmokeTestPlan
{
    public const SCHEMA = 'atlas.external_brain.provider_pool_smoke_test_plan.v1';

    public const REQUIRED_EVIDENCE = [
        'sdk_proof',
        'entitlement_proof',
        'output_contract_proof',
        'cost_proof',
        'fallback_proof',
    ];

    private const STEPS = [
        ['order' => 1, 'id' => 'preflight', 'label' => 'Confirm SDK presence and entitlement facts without calling the provider.'],
        ['order' => 2, 'id' => 'harmless_prompt', 'label' => 'Plan a single harmless prompt to observe a real response shape.'],
        ['order' => 3, 'id' => 'patch_generation_dry_run', 'label' => 'Plan a patch-generation dry run that never writes to the live repo.'],
        ['order' => 4, 'id' => 'no_secret_policy_check', 'label' => 'Verify no secret material would be sent to the provider.'],
        ['order' => 5, 'id' => 'cost_boundary_check', 'label' => 'Verify the cost/quota boundary is known before any real call.'],
        ['order' => 6, 'id' => 'timeout_boundary', 'label' => 'Verify a bounded timeout is enforced before any real call.'],
        ['order' => 7, 'id' => 'fallback_replay', 'label' => 'Verify the native fallback path can replay the same task if the pool fails.'],
    ];

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function plan(array $input): array
    {
        $evidenceInput = is_array($input['evidence'] ?? null) ? $input['evidence'] : [];

        $evidence = [];
        $missing = [];
        foreach (self::REQUIRED_EVIDENCE as $field) {
            $value = (bool) ($evidenceInput[$field] ?? false);
            $evidence[$field] = $value;
            if (! $value) {
                $missing[] = $field;
            }
        }

        $steps = array_map(
            static fn (array $step): array => $step + ['non_executing_plan_only' => true],
            self::STEPS,
        );

        return [
            'schema' => self::SCHEMA,
            'status' => $missing === [] ? 'ready_for_optional_routing' : 'evidence_incomplete',
            'steps' => $steps,
            'required_evidence' => self::REQUIRED_EVIDENCE,
            'evidence' => $evidence,
            'missing_evidence' => $missing,
            'provider_call_allowed' => false,
            'token_spend_allowed' => false,
            'adapter_execution_allowed' => false,
        ];
    }
}
