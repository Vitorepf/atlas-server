<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Pure inventory of local subscription clients (Cursor, Codex, Claude,
 * Hermes, etc). Models each as an OPTIONAL installed capability — never as
 * required infrastructure — so Atlas autonomy never depends on a human's
 * subscription seat or a paid API key.
 *
 * usable_subscription_client requires ALL of:
 *   - paid_api_required === false  (pay-per-call has no Atlas-controlled ceiling)
 *   - local_invocation_supported === true
 *   - an authenticated local session (logged_in_session_observed) OR an
 *     observed subscription entitlement (subscription_plan_observed !== '')
 *
 * Every classified client is marked atlas_required=false and
 * fallback_required=true regardless of usability — Atlas's own autonomy
 * must never be load-bearing on an external local client, including Cursor.
 *
 * client_class (AC2 new): a 4-way availability taxonomy, orthogonal to the pre-existing
 * usable/not_usable verdict above —
 *   internal_runtime — is_internal_runtime=true (Atlas's own engine, e.g. Hermes/self-hosted)
 *   local            — locally invocable (installed + local_invocation_supported)
 *   subscription_ui  — has an entitlement/session but is not locally invocable (browser/app UI only)
 *   unavailable      — nothing installed and no entitlement observed
 *
 * Credential redaction (AC3 new): optional facts raw_credential/api_key/session_token/auth_token
 * are NEVER echoed back in any form — only a credential_present boolean reports whether one was
 * supplied.
 *
 * suitable_task_families (AC4 new): [] when not usable; a single conservative
 * manual_supervised_only family when usable but capability evidence (headless_supported +
 * model_hints) is thin; a broader family list only when both signals are present.
 *
 * Pure: no I/O, no network calls, no shell-outs.
 */
final class AtlasExternalBrainLocalSubscriptionClientInventory
{
    public const SCHEMA = 'atlas.external_brain.local_subscription_client_inventory.v1';

    public const CLASS_INTERNAL_RUNTIME = 'internal_runtime';

    public const CLASS_LOCAL = 'local';

    public const CLASS_SUBSCRIPTION_UI = 'subscription_ui';

    public const CLASS_UNAVAILABLE = 'unavailable';

    private const CREDENTIAL_FIELDS = ['raw_credential', 'api_key', 'session_token', 'auth_token'];

    /**
     * @param  array<string,mixed>  $facts
     * @return array<string,mixed>
     */
    public function classify(array $facts): array
    {
        $clientId = (string) ($facts['client_id'] ?? '');
        $appInstalled = (bool) ($facts['app_installed'] ?? false);
        $cliBinaryPresent = (bool) ($facts['cli_binary_present'] ?? false);
        $loggedInSessionObserved = (bool) ($facts['logged_in_session_observed'] ?? false);
        $subscriptionPlanObserved = trim((string) ($facts['subscription_plan_observed'] ?? ''));
        $paidApiRequired = (bool) ($facts['paid_api_required'] ?? false);
        $headlessSupported = (bool) ($facts['headless_supported'] ?? false);
        $modelHints = array_values((array) ($facts['model_hints'] ?? []));
        $localInvocationSupported = (bool) ($facts['local_invocation_supported'] ?? false);

        $hasEntitlement = $loggedInSessionObserved || $subscriptionPlanObserved !== '';

        $notUsableReasons = [];
        if ($paidApiRequired) {
            $notUsableReasons[] = 'paid_api_required';
        }
        if (! $localInvocationSupported) {
            $notUsableReasons[] = 'local_invocation_not_supported';
        }
        if (! $hasEntitlement) {
            $notUsableReasons[] = 'no_authenticated_session_or_subscription_entitlement_observed';
        }
        if (! $appInstalled && ! $cliBinaryPresent) {
            $notUsableReasons[] = 'client_not_installed';
        }

        $classification = $notUsableReasons === [] ? 'usable_subscription_client' : 'not_usable';
        $usable = $classification === 'usable_subscription_client';

        // AC2: 4-way availability taxonomy, independent of the usable/not_usable verdict above.
        $isInternalRuntime = (bool) ($facts['is_internal_runtime'] ?? false);
        $clientInstalled = $appInstalled || $cliBinaryPresent;
        $clientClass = match (true) {
            $isInternalRuntime => self::CLASS_INTERNAL_RUNTIME,
            $clientInstalled && $localInvocationSupported => self::CLASS_LOCAL,
            $hasEntitlement => self::CLASS_SUBSCRIPTION_UI,
            default => self::CLASS_UNAVAILABLE,
        };

        // AC3: credential-like facts are NEVER echoed back — only whether one was supplied.
        $credentialPresent = false;
        foreach (self::CREDENTIAL_FIELDS as $field) {
            if (trim((string) ($facts[$field] ?? '')) !== '') {
                $credentialPresent = true;
                break;
            }
        }

        // AC4: conservative task-family suggestion — empty when unusable, a single supervised
        // family when capability evidence is thin, broader only with real evidence.
        $hasCapabilityEvidence = $headlessSupported && $modelHints !== [];
        $suitableTaskFamilies = match (true) {
            ! $usable => [],
            $hasCapabilityEvidence => ['implementation', 'refactor', 'test_authoring'],
            default => ['manual_supervised_only'],
        };

        return [
            'schema_version' => self::SCHEMA,
            'client_id' => $clientId,
            'app_installed' => $appInstalled,
            'cli_binary_present' => $cliBinaryPresent,
            'logged_in_session_observed' => $loggedInSessionObserved,
            'subscription_plan_observed' => $subscriptionPlanObserved,
            'paid_api_required' => $paidApiRequired,
            'headless_supported' => $headlessSupported,
            'model_hints' => $modelHints,
            'local_invocation_supported' => $localInvocationSupported,
            'classification' => $classification,
            'usable_subscription_client' => $usable,
            'not_usable_reasons' => $notUsableReasons,
            'atlas_required' => false,
            'fallback_required' => true,
            'client_class' => $clientClass,
            'credential_present' => $credentialPresent,
            'suitable_task_families' => $suitableTaskFamilies,
        ];
    }
}
