<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Assesses whether an optional local subscription client (e.g. Cursor) can
 * be probed without ever leaking, reading, logging, or requiring a raw API
 * key or paid API credential. Only boolean facts are accepted — never a raw
 * secret value.
 *
 * INPUT (booleans only, never raw secret values):
 *   local_client_logged_in:           bool (default false) — local client session exists without Atlas holding a secret
 *   subscription_entitlement_observed: bool (default false) — entitlement is via subscription, not a paid API key
 *   credential_value_present:         bool (default false) — true means a raw secret value was supplied (always unsafe)
 *   redaction_status:                 bool (default false) — true means any credential reference is redacted/clean
 *   environment_scope:                bool (default false) — true means credential scope is process-local, not user-home/global
 *   operator_attested_entitlement:    bool (default false) — operator has confirmed/attested entitlement + rotation plan
 *
 * BLOCKERS (derived deterministically from the facts above):
 *   raw_secret_in_payload              <- credential_value_present
 *   paid_api_key_required              <- !subscription_entitlement_observed
 *   unredacted_secret_reference        <- !redaction_status
 *   user_home_global_secret_required   <- !environment_scope
 *   missing_rotation_plan              <- !operator_attested_entitlement
 *   provider_required_for_steady_state <- !local_client_logged_in
 *
 * safe_to_probe=true only when: no raw secret present, redaction is clean,
 * and the local client fallback is confirmed available (logged in locally,
 * process-local scope). The gate itself never calls a provider:
 * provider_call_allowed is always false.
 *
 * Pure: no I/O, no network calls, no logging, no secret reads.
 */
final class AtlasExternalBrainProviderPoolCredentialSafetyGate
{
    public const SCHEMA = 'atlas.external_brain.provider_pool_credential_safety_gate.v1';

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function assess(array $input): array
    {
        $facts = [
            'local_client_logged_in' => (bool) ($input['local_client_logged_in'] ?? false),
            'subscription_entitlement_observed' => (bool) ($input['subscription_entitlement_observed'] ?? false),
            'credential_value_present' => (bool) ($input['credential_value_present'] ?? false),
            'redaction_status' => (bool) ($input['redaction_status'] ?? false),
            'environment_scope' => (bool) ($input['environment_scope'] ?? false),
            'operator_attested_entitlement' => (bool) ($input['operator_attested_entitlement'] ?? false),
        ];

        $blockers = [];
        if ($facts['credential_value_present']) {
            $blockers[] = 'raw_secret_in_payload';
        }
        if (! $facts['subscription_entitlement_observed']) {
            $blockers[] = 'paid_api_key_required';
        }
        if (! $facts['redaction_status']) {
            $blockers[] = 'unredacted_secret_reference';
        }
        if (! $facts['environment_scope']) {
            $blockers[] = 'user_home_global_secret_required';
        }
        if (! $facts['operator_attested_entitlement']) {
            $blockers[] = 'missing_rotation_plan';
        }
        if (! $facts['local_client_logged_in']) {
            $blockers[] = 'provider_required_for_steady_state';
        }

        $fallbackAvailable = $facts['local_client_logged_in'] && $facts['environment_scope'];
        $safeToProbe = ! $facts['credential_value_present'] && $facts['redaction_status'] && $fallbackAvailable;

        return [
            'schema' => self::SCHEMA,
            'facts' => $facts,
            'blockers' => $blockers,
            'blocker_count' => count($blockers),
            'safe_to_probe' => $safeToProbe,
            'fallback_available' => $fallbackAvailable,
            'provider_call_allowed' => false,
        ];
    }
}
