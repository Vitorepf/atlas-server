<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Read-only readiness classifier for using Cursor SDK / Cursor pools inside
 * Atlas. Never imports the Cursor SDK, never calls the network, never reads
 * secrets. It only classifies the local facts the caller supplies.
 *
 * Check order (first failing condition wins):
 *   1. sdk_package_detected=false            -> blocked_missing_sdk
 *   2. billing_boundary_known=false          -> blocked_billing_unknown
 *   3. headless_patch_apply_supported=false  -> blocked_ui_only
 *   4. api_key_configured=false OR
 *      cloud_agent_api_reachable=false OR
 *      model_pool_entitlement_observed=false -> blocked_missing_entitlement_proof
 *   5. all facts true                        -> ready_for_optional_adapter
 *
 * INPUT:
 *   sdk_package_detected:               bool (default false)
 *   api_key_configured:                 bool (default false)
 *   cloud_agent_api_reachable:          bool (default false)
 *   model_pool_entitlement_observed:    bool (default false)
 *   headless_patch_apply_supported:     bool (default false)
 *   billing_boundary_known:             bool (default false)
 *   proof_capture_observed:             bool (default false)
 *
 * OUTPUT:
 *   { schema, status, readiness_class, facts, next_verification_command,
 *     atlas_native_steady_state_autonomy, safe_usage_notes, fallback_recommendation,
 *     provider_call_allowed=false, token_spend_allowed=false }
 *
 * readiness_class re-expresses `status` in the coarse vocabulary demanded of every
 * pool-readiness probe (ready / interactive_only / unknown_cost / no_sdk_boundary /
 * unsafe_dependency) without changing the existing `status` values other callers assert on.
 *
 * atlas_native_steady_state_autonomy is only ever true when the pool is fully ready AND
 * headless invocation AND local proof capture are both observed — a missing SDK, unknown
 * billing boundary, UI-only invocation or unproven entitlement can never be laundered into
 * "steady-state autonomy".
 *
 * Pure: no I/O, no network calls, no secret reads, no side effects.
 */
final class AtlasExternalBrainCursorSdkPoolReadinessProbe
{
    public const SCHEMA = 'atlas.external_brain.cursor_sdk_pool_readiness_probe.v1';

    private const NEXT_VERIFICATION_COMMANDS = [
        'blocked_missing_sdk' => 'composer show | grep cursor-sdk (confirm the Cursor SDK package is actually installed before enabling the adapter)',
        'blocked_billing_unknown' => 'review the Cursor account billing/plan page manually and record billing_boundary_known=true once the spend boundary is confirmed',
        'blocked_ui_only' => 'check Cursor release notes for headless/CLI patch-apply support before treating this pool as automatable',
        'blocked_missing_entitlement_proof' => 'capture a local, non-network entitlement record (api key presence, reachability check, pool entitlement) before trusting this pool',
        'ready_for_optional_adapter' => 'proceed to AtlasExternalBrainProviderPoolCapabilityContract::describe() to register this pool as an optional accelerator',
    ];

    /** AC2: coarse readiness classification, keyed by the existing `status` value. */
    private const READINESS_CLASSES = [
        'blocked_missing_sdk' => 'no_sdk_boundary',
        'blocked_billing_unknown' => 'unknown_cost',
        'blocked_ui_only' => 'interactive_only',
        'blocked_missing_entitlement_proof' => 'unsafe_dependency',
        'ready_for_optional_adapter' => 'ready',
    ];

    /** AC4: safe, no-paid-access usage notes shown for every status. */
    private const SAFE_USAGE_NOTES = [
        'blocked_missing_sdk' => ['Treat Cursor as unavailable until the SDK package is confirmed installed locally.'],
        'blocked_billing_unknown' => ['Do not enable any Cursor call path until the billing/spend boundary is confirmed by manual review.'],
        'blocked_ui_only' => ['Cursor may still be used manually by a human operator; do not attempt headless automation.'],
        'blocked_missing_entitlement_proof' => ['Do not assume entitlement; capture local, non-network proof (key presence, reachability, pool entitlement) first.'],
        'ready_for_optional_adapter' => ['Use Cursor only as an optional accelerator alongside Atlas-native execution, never as the sole path.'],
    ];

    private const FALLBACK_RECOMMENDATIONS = [
        'blocked_missing_sdk' => 'fall back to Atlas-native execution; do not install or purchase anything to unblock this probe',
        'blocked_billing_unknown' => 'fall back to Atlas-native execution until the spend boundary is confirmed',
        'blocked_ui_only' => 'fall back to Atlas-native execution; keep Cursor as a manual, human-driven side channel',
        'blocked_missing_entitlement_proof' => 'fall back to Atlas-native execution until local entitlement proof is captured',
        'ready_for_optional_adapter' => 'Atlas-native execution remains the primary path; Cursor is an optional accelerator only',
    ];

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function probe(array $input): array
    {
        $facts = [
            'sdk_package_detected' => (bool) ($input['sdk_package_detected'] ?? false),
            'api_key_configured' => (bool) ($input['api_key_configured'] ?? false),
            'cloud_agent_api_reachable' => (bool) ($input['cloud_agent_api_reachable'] ?? false),
            'model_pool_entitlement_observed' => (bool) ($input['model_pool_entitlement_observed'] ?? false),
            'headless_patch_apply_supported' => (bool) ($input['headless_patch_apply_supported'] ?? false),
            'billing_boundary_known' => (bool) ($input['billing_boundary_known'] ?? false),
            'proof_capture_observed' => (bool) ($input['proof_capture_observed'] ?? false),
        ];

        $status = match (true) {
            ! $facts['sdk_package_detected'] => 'blocked_missing_sdk',
            ! $facts['billing_boundary_known'] => 'blocked_billing_unknown',
            ! $facts['headless_patch_apply_supported'] => 'blocked_ui_only',
            ! $facts['api_key_configured']
                || ! $facts['cloud_agent_api_reachable']
                || ! $facts['model_pool_entitlement_observed'] => 'blocked_missing_entitlement_proof',
            default => 'ready_for_optional_adapter',
        };

        $steadyStateAutonomy = $status === 'ready_for_optional_adapter'
            && $facts['headless_patch_apply_supported']
            && $facts['proof_capture_observed'];

        return [
            'schema' => self::SCHEMA,
            'status' => $status,
            'readiness_class' => self::READINESS_CLASSES[$status],
            'facts' => $facts,
            'next_verification_command' => self::NEXT_VERIFICATION_COMMANDS[$status],
            'atlas_native_steady_state_autonomy' => $steadyStateAutonomy,
            'safe_usage_notes' => self::SAFE_USAGE_NOTES[$status],
            'fallback_recommendation' => self::FALLBACK_RECOMMENDATIONS[$status],
            'provider_call_allowed' => false,
            'token_spend_allowed' => false,
            'optional_adapter_only' => true,
        ];
    }
}
