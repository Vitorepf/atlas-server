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
 * client_descriptor (new, optional, default []): an arbitrary provider/client config payload
 * (e.g. from an accidental copy-paste) scanned for secret-like FIELD NAMES (token/api_key/
 * password/secret/credential, case-insensitive) — never for value content. A match adds
 * 'raw_secret_in_payload' to blockers (the same blocker credential_value_present triggers — same
 * underlying risk) and records the field name (never its value) in redacted_diagnostics as
 * "{field}=[REDACTED]". A descriptor containing only capability/invocation-boundary metadata
 * (headless_supported, model_hints, local_invocation_supported, etc.) triggers no new blocker.
 *
 * billing_required (new, optional, default false): true means the provider assumes a paid
 * steady-state dependency — adds 'disallowed_steady_state_dependency' to blockers.
 *
 * Pure: no I/O, no network calls, no logging, no secret reads.
 */
final class AtlasExternalBrainProviderPoolCredentialSafetyGate
{
    public const SCHEMA = 'atlas.external_brain.provider_pool_credential_safety_gate.v1';

    private const SECRET_LIKE_KEY_PATTERN = '/token|api[_-]?key|password|secret|credential/i';

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

        // AC2: scan an optional client_descriptor payload for secret-like FIELD NAMES — never
        // for value content, and the raw value is never placed in the diagnostic.
        $clientDescriptor = is_array($input['client_descriptor'] ?? null) ? $input['client_descriptor'] : [];
        $redactedDiagnostics = [];
        foreach (array_keys($clientDescriptor) as $key) {
            if (preg_match(self::SECRET_LIKE_KEY_PATTERN, (string) $key) === 1) {
                $redactedDiagnostics[] = "{$key}=[REDACTED]";
            }
        }
        if ($redactedDiagnostics !== [] && ! in_array('raw_secret_in_payload', $blockers, true)) {
            $blockers[] = 'raw_secret_in_payload';
        }

        // AC3: a billing-required provider assumption is a disallowed steady-state dependency.
        $billingRequired = (bool) ($input['billing_required'] ?? false);
        if ($billingRequired) {
            $blockers[] = 'disallowed_steady_state_dependency';
        }

        $fallbackAvailable = $facts['local_client_logged_in'] && $facts['environment_scope'];
        $safeToProbe = ! $facts['credential_value_present'] && $facts['redaction_status'] && $fallbackAvailable
            && $redactedDiagnostics === [] && ! $billingRequired;

        return [
            'schema' => self::SCHEMA,
            'facts' => $facts,
            'blockers' => $blockers,
            'blocker_count' => count($blockers),
            'safe_to_probe' => $safeToProbe,
            'fallback_available' => $fallbackAvailable,
            'provider_call_allowed' => false,
            'redacted_diagnostics' => $redactedDiagnostics,
        ];
    }
}
