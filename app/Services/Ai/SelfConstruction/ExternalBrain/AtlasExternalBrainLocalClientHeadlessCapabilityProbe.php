<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Classifies whether an installed subscription client can run as a reliable
 * headless muscle for Hermes or Atlas without using paid model APIs. This
 * probe only judges supplied facts — it never launches the client, never
 * calls the network, and never reads credentials.
 *
 * Check order (every failing condition is reported, not just the first):
 *   !binary_exists                     -> binary_missing
 *   !login_state_known                 -> missing_login_state
 *   !print_mode_supported OR
 *   !noninteractive_prompt_supported   -> ui_only_client
 *   !patch_output_supported            -> patch_output_unsupported
 *   !workspace_scoping_supported       -> workspace_scoping_unsupported
 *   paid_api_required=true             -> paid_api_required
 *
 * ready_for_headless_trial=true only when paid_api_required=false and every
 * required headless fact is true (no blockers present).
 *
 * INPUT:
 *   binary_exists?:                  bool (default false)
 *   login_state_known?:              bool (default false)
 *   print_mode_supported?:           bool (default false)
 *   noninteractive_prompt_supported?: bool (default false)
 *   patch_output_supported?:         bool (default false)
 *   workspace_scoping_supported?:    bool (default false)
 *   paid_api_required?:              bool (default false)
 *
 * OUTPUT:
 *   { schema, status, facts, blockers, blocker_count,
 *     ready_for_headless_trial, provider_call_allowed=false,
 *     token_spend_allowed=false, adapter_execution_allowed=false }
 *
 * Pure: no I/O, no network calls, no credential reads, no side effects.
 */
final class AtlasExternalBrainLocalClientHeadlessCapabilityProbe
{
    public const SCHEMA = 'atlas.external_brain.local_client_headless_capability_probe.v1';

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function probe(array $input): array
    {
        $facts = [
            'binary_exists' => (bool) ($input['binary_exists'] ?? false),
            'login_state_known' => (bool) ($input['login_state_known'] ?? false),
            'print_mode_supported' => (bool) ($input['print_mode_supported'] ?? false),
            'noninteractive_prompt_supported' => (bool) ($input['noninteractive_prompt_supported'] ?? false),
            'patch_output_supported' => (bool) ($input['patch_output_supported'] ?? false),
            'workspace_scoping_supported' => (bool) ($input['workspace_scoping_supported'] ?? false),
            'paid_api_required' => (bool) ($input['paid_api_required'] ?? false),
        ];

        $blockers = [];
        if (! $facts['binary_exists']) {
            $blockers[] = 'binary_missing';
        }
        if (! $facts['login_state_known']) {
            $blockers[] = 'missing_login_state';
        }
        if (! $facts['print_mode_supported'] || ! $facts['noninteractive_prompt_supported']) {
            $blockers[] = 'ui_only_client';
        }
        if (! $facts['patch_output_supported']) {
            $blockers[] = 'patch_output_unsupported';
        }
        if (! $facts['workspace_scoping_supported']) {
            $blockers[] = 'workspace_scoping_unsupported';
        }
        if ($facts['paid_api_required']) {
            $blockers[] = 'paid_api_required';
        }

        $ready = $blockers === [];

        return [
            'schema' => self::SCHEMA,
            'status' => $ready ? 'ready_for_headless_trial' : 'blocked',
            'facts' => $facts,
            'blockers' => $blockers,
            'blocker_count' => count($blockers),
            'ready_for_headless_trial' => $ready,
            'provider_call_allowed' => false,
            'token_spend_allowed' => false,
            'adapter_execution_allowed' => false,
        ];
    }
}
