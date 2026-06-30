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
 *
 * OUTPUT:
 *   { schema, status, facts, next_verification_command,
 *     provider_call_allowed=false, token_spend_allowed=false }
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

        return [
            'schema' => self::SCHEMA,
            'status' => $status,
            'facts' => $facts,
            'next_verification_command' => self::NEXT_VERIFICATION_COMMANDS[$status],
            'provider_call_allowed' => false,
            'token_spend_allowed' => false,
        ];
    }
}
