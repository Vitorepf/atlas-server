<?php

declare(strict_types=1);

namespace App\Services\AtlasCode;

/**
 * Atlas Code Provider Governance read-model.
 *
 * Canon: docs/engineering-knowledge-base/atlas-claude-code-subscription-governance-v1.md
 *
 * Schema: atlas.code.provider_governance.v1
 *
 * Read-only. Exposes the subscription-only contract to UI and other services
 * so that no caller can silently bypass the prohibition list. Every callsite
 * that wants to start a provider invocation MUST consult this service first;
 * if `external_provider_call_allowed=false` (subscription-only mode) the call
 * MUST be aborted and a blocker recorded.
 */
final class AtlasCodeProviderGovernanceService
{
    public const SCHEMA_VERSION = 'atlas.code.provider_governance.v1';

    /**
     * @return array<string, mixed>
     */
    public function snapshot(): array
    {
        $subscriptionOnly = (bool) config('atlas_code_provider_governance.subscription_only', true);
        $prohibitions = (array) config('atlas_code_provider_governance.prohibitions', []);
        $modes = $this->stringList((array) config('atlas_code_provider_governance.allowed_invocation_modes', []));
        $providers = (array) config('atlas_code_provider_governance.providers', []);
        $bootstrap = (array) config('atlas_code_provider_governance.bootstrap_role_assignments', []);

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'subscription_only' => $subscriptionOnly,
            'mode_label' => (string) config('atlas_code_provider_governance.mode_label', 'subscription_only'),
            // The single boolean callers must consult before invoking any
            // external provider. When false (subscription-only), only
            // `interactive_observed` and `manual_import` are permitted, and
            // those modes do not invoke the provider — they prepare the
            // packet/prompt/terminal for the operator.
            'external_provider_call_allowed' => false,
            'headless_provider_call_allowed' => false,
            'api_fallback_allowed' => false,
            'agent_sdk_allowed' => false,
            'operator_presence_required' => true,
            'prohibitions' => [
                'claude_dash_p' => (bool) ($prohibitions['claude_dash_p'] ?? true),
                'claude_agent_sdk' => (bool) ($prohibitions['claude_agent_sdk'] ?? true),
                'anthropic_api_key_fallback' => (bool) ($prohibitions['anthropic_api_key_fallback'] ?? true),
                'headless_worker' => (bool) ($prohibitions['headless_worker'] ?? true),
                'github_actions_claude' => (bool) ($prohibitions['github_actions_claude'] ?? true),
                'silent_fallback' => (bool) ($prohibitions['silent_fallback'] ?? true),
                'multiuser_via_personal_subscription' => (bool) ($prohibitions['multiuser_via_personal_subscription'] ?? true),
            ],
            'allowed_invocation_modes' => $modes,
            'providers' => array_map(fn (array $p): array => $this->shapeProvider($p), array_values(array_filter($providers, 'is_array'))),
            'bootstrap_role_assignments' => array_map(static fn ($v): string => (string) $v, $bootstrap),
            'api_key_detected' => $this->detectApiKey(),
            'safety_notes' => [
                'no_headless_in_subscription_only',
                'no_silent_fallback',
                'no_completion_from_provider_text',
                'human_acceptance_required',
                'workspace_path_required_for_execution',
            ],
        ];
    }

    public function allowedProviderIds(): array
    {
        return array_values(array_filter(array_map(
            static fn (array $p): ?string => isset($p['id']) ? (string) $p['id'] : null,
            (array) config('atlas_code_provider_governance.providers', [])
        )));
    }

    public function findProvider(string $providerId): ?array
    {
        $needle = trim($providerId);
        if ($needle === '') {
            return null;
        }
        foreach ((array) config('atlas_code_provider_governance.providers', []) as $p) {
            if (! is_array($p)) {
                continue;
            }
            if ((string) ($p['id'] ?? '') === $needle) {
                return $this->shapeProvider($p);
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $p
     * @return array<string, mixed>
     */
    private function shapeProvider(array $p): array
    {
        return [
            'id' => (string) ($p['id'] ?? ''),
            'name' => (string) ($p['name'] ?? ($p['id'] ?? '')),
            'family' => (string) ($p['family'] ?? 'unknown'),
            'invocation_mode' => (string) ($p['invocation_mode'] ?? 'interactive_observed'),
            'binary_hint' => isset($p['binary_hint']) && $p['binary_hint'] !== '' ? (string) $p['binary_hint'] : null,
            'subscription_status' => (string) ($p['subscription_status'] ?? 'allowed'),
            'bootstrap_role' => isset($p['bootstrap_role']) ? (string) $p['bootstrap_role'] : null,
            'notes' => (string) ($p['notes'] ?? ''),
        ];
    }

    private function detectApiKey(): bool
    {
        // We do NOT read the value, only signal that *something* is in the env.
        // Detection lets the UI warn the operator that subscription-only is at
        // risk; the operator can clear the variable before opening Claude.
        foreach (['ANTHROPIC_API_KEY', 'CLAUDE_API_KEY', 'OPENAI_API_KEY'] as $key) {
            if (env($key) !== null && env($key) !== '') {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<int, string>
     */
    private function stringList(array $raw): array
    {
        $out = [];
        foreach ($raw as $item) {
            if (is_string($item) && trim($item) !== '') {
                $out[] = trim($item);
            }
        }
        return array_values($out);
    }
}
