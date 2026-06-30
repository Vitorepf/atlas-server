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
 * Pure: no I/O, no network calls, no shell-outs.
 */
final class AtlasExternalBrainLocalSubscriptionClientInventory
{
    public const SCHEMA = 'atlas.external_brain.local_subscription_client_inventory.v1';

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
            'usable_subscription_client' => $classification === 'usable_subscription_client',
            'not_usable_reasons' => $notUsableReasons,
            'atlas_required' => false,
            'fallback_required' => true,
        ];
    }
}
