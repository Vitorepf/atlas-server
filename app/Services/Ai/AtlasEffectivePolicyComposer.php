<?php

namespace App\Services\Ai;

use App\Services\Ai\Support\AiStringListNormalizer;

class AtlasEffectivePolicyComposer
{
    private const POLICY_FAMILIES = [
        'model_policy',
        'context_policy',
        'skill_policy',
        'tool_policy',
        'memory_policy',
        'gate_policy',
    ];

    private const PROVIDER_KEYS = ['hermes_cli', 'minimax_m27_cli', 'claude_cli', 'codex_cli', 'gemini_cli'];

    /**
     * @param  array<string,mixed>  $policy
     * @param  array<string,mixed>  $domainProfile
     * @param  array<string,mixed>  $runtime
     * @param  array<string,mixed>  $payload
     * @param  array<string,mixed>  $options
     * @return array{policy:array<string,mixed>,receipt:array<string,mixed>}
     */
    public function compose(
        array $policy,
        array $domainProfile,
        array $runtime,
        array $payload,
        array $options = [],
    ): array {
        $sessionOverride = $this->sessionOverride($payload, $options);
        $warnings = [];
        $policy = $this->applySessionRuntimeOverrides($policy, $sessionOverride, $warnings);

        $families = $this->policyFamilies($domainProfile, $sessionOverride);
        $contracts = $this->operationalContracts($policy, $families, $domainProfile);

        $receipt = [
            'schema_version' => 2,
            'policy_version' => 'atlas-ai-policy-v2',
            'profile_id' => (string) ($policy['profile_id'] ?? data_get($domainProfile, 'profile_id', 'general.answer')),
            'domain' => (string) ($policy['domain'] ?? data_get($domainProfile, 'domain_id', 'general')),
            'flow' => (string) ($policy['flow'] ?? data_get($domainProfile, 'flow_id', 'general.answer')),
            'source_order' => [
                'domain_profile',
                'flow_profile',
                'runtime_settings',
                'session_override',
                'legacy_execution_policy',
            ],
            'registry' => [
                'schema_version' => (int) data_get($domainProfile, 'schema_version', 1),
                'source' => (string) data_get($domainProfile, 'source', 'static_fallback'),
            ],
            'runtime_settings' => [
                'source' => (string) ($runtime['source'] ?? 'config'),
                'updated_at' => $runtime['updated_at'] ?? null,
                'default_provider' => (string) ($runtime['default_provider'] ?? 'hermes_cli'),
            ],
            'session_override' => [
                'present' => $sessionOverride !== [],
                'applied_keys' => $this->appliedSessionKeys($sessionOverride),
                'ignored_keys' => $this->ignoredSessionKeys($sessionOverride),
            ],
            'policies' => $families,
            'operational_contracts' => $contracts,
            'runtime_policy' => [
                'default_provider' => (string) ($policy['default_provider'] ?? 'hermes_cli'),
                'default_model_policy' => (string) ($policy['default_model_policy'] ?? 'balanced'),
                'enabled_providers' => array_values((array) ($policy['enabled_providers'] ?? self::PROVIDER_KEYS)),
                'disabled_providers' => array_values((array) ($policy['disabled_providers'] ?? [])),
                'allowed_models' => (array) ($policy['allowed_models'] ?? []),
                'providers' => (array) ($policy['providers'] ?? []),
                'fallback_order' => array_values((array) ($policy['fallback_order'] ?? [])),
                'allow_auto' => (bool) ($policy['allow_auto'] ?? true),
                'allow_manual' => (bool) ($policy['allow_manual'] ?? true),
                'allow_background' => (bool) ($policy['allow_background'] ?? false),
                'allow_council' => (bool) ($policy['allow_council'] ?? false),
                'allow_multistage_graph' => (bool) ($policy['allow_multistage_graph'] ?? false),
                'budget_policy' => (array) ($policy['budget_policy'] ?? []),
            ],
            'execution_policy' => (array) ($policy['execution_policy'] ?? []),
            'profile_declared_execution_policy' => $this->profileDeclaredExecutionPolicy($domainProfile),
            'execution_authority' => (string) ($policy['execution_authority'] ?? 'legacy_execution_policy'),
            'merge_warnings' => $warnings,
            'compatibility' => [
                'legacy_fields_preserved' => true,
                'operational_contracts_exposed' => true,
                'flow_execution_policy_authoritative' => str_starts_with((string) ($policy['execution_authority'] ?? ''), 'domain_flow_profile'),
            ],
        ];

        $policy['schema_version'] = 2;
        $policy['policy_version'] = 'atlas-ai-policy-v2';
        $policy['policy_contracts'] = $contracts;
        $policy['effective_policy'] = $receipt;
        $policy['policy_merge_receipt'] = [
            'schema_version' => 1,
            'source_order' => $receipt['source_order'],
            'session_override_present' => $receipt['session_override']['present'],
            'execution_authority' => $receipt['execution_authority'],
            'warnings' => $warnings,
        ];

        return ['policy' => $policy, 'receipt' => $receipt];
    }

    /**
     * @param  array<string,mixed>  $policy
     * @param  array<string,array<string,mixed>>  $families
     * @param  array<string,mixed>  $domainProfile
     * @return array<string,array<string,mixed>>
     */
    private function operationalContracts(array $policy, array $families, array $domainProfile): array
    {
        return [
            'model_graph' => $this->modelGraphContract($policy, $families['model_policy'] ?? [], $domainProfile),
            'context' => $this->contextContract($families['context_policy'] ?? []),
            'memory' => $this->memoryContract($families['memory_policy'] ?? []),
            'skills' => $this->skillContract($families['skill_policy'] ?? [], $domainProfile),
            'tools' => $this->toolContract($policy, $families['tool_policy'] ?? []),
            'gates' => $this->gateContract($policy, $families['gate_policy'] ?? []),
        ];
    }

    /**
     * @param  array<string,mixed>  $policy
     * @param  array<string,mixed>  $modelPolicy
     * @param  array<string,mixed>  $domainProfile
     * @return array<string,mixed>
     */
    private function modelGraphContract(array $policy, array $modelPolicy, array $domainProfile): array
    {
        $provider = $this->provider(data_get($policy, 'default_provider')) ?? 'hermes_cli';
        $providers = (array) ($policy['providers'] ?? []);
        $enabledProviders = array_values(array_filter(
            array_map(fn (mixed $item): ?string => $this->provider($item), (array) ($policy['enabled_providers'] ?? self::PROVIDER_KEYS)),
            fn (?string $item): bool => $item !== null,
        ));
        if ($enabledProviders !== [] && ! in_array($provider, $enabledProviders, true)) {
            $provider = $enabledProviders[0];
        }
        $fallback = array_values((array) ($policy['fallback_order'] ?? [$provider, 'hermes_cli', 'minimax_m27_cli', 'claude_cli', 'codex_cli', 'gemini_cli']));
        $preset = $this->text(data_get($modelPolicy, 'preset'), data_get($modelPolicy, 'default'), data_get($modelPolicy, 'mode'), data_get($policy, 'default_model_policy')) ?? 'balanced';
        $declaredGraph = $this->text(data_get($modelPolicy, 'graph'), data_get($modelPolicy, 'default_graph'));
        $multistage = (bool) ($policy['allow_multistage_graph'] ?? false)
            || in_array($preset, ['quality', 'best_quality', 'council', 'research'], true)
            || data_get($domainProfile, 'flow_profile.runtime') === 'EngineeringHarness';
        if (count($enabledProviders) === 1) {
            $multistage = false;
        }
        $graph = $declaredGraph ?: ($multistage ? 'scout_execute_review' : 'single_executor');
        if (count($enabledProviders) === 1 && $graph !== 'single_executor') {
            $graph = 'single_executor';
        }

        if (is_array($modelPolicy['nodes'] ?? null) && $modelPolicy['nodes'] !== []) {
            $nodes = array_values(array_filter(array_map(
                fn (mixed $node): ?array => $this->modelGraphNode($node, $providers, $fallback, $enabledProviders),
                $modelPolicy['nodes'],
            )));
        } elseif ($graph === 'scout_execute_review') {
            $nodes = [
                $this->modelGraphNode([
                    'id' => 'context_scout',
                    'role' => 'context_scout',
                    'provider' => 'gemini_cli',
                ], $providers, $fallback, $enabledProviders),
                $this->modelGraphNode([
                    'id' => 'executor',
                    'role' => 'executor',
                    'provider' => $provider,
                ], $providers, $fallback, $enabledProviders),
                $this->modelGraphNode([
                    'id' => 'reviewer',
                    'role' => 'reviewer',
                    'provider' => 'claude_cli',
                ], $providers, $fallback, $enabledProviders),
            ];
        } else {
            $nodes = [
                $this->modelGraphNode([
                    'id' => 'executor',
                    'role' => 'executor',
                    'provider' => $provider,
                ], $providers, $fallback, $enabledProviders),
            ];
        }

        return [
            'graph' => $graph,
            'preset' => $preset,
            'nodes' => $nodes,
            'fallback_order' => $fallback,
            'strict_model_match' => (bool) data_get($modelPolicy, 'strict_model_match', in_array($preset, ['fixed', 'locked'], true)),
            'manual_override_allowed' => true,
            'respect_global_provider_blocks' => true,
        ];
    }

    /**
     * @param  array<string,mixed>  $providers
     * @param  array<int,mixed>  $fallback
     * @return array<string,mixed>|null
     */
    private function modelGraphNode(mixed $node, array $providers, array $fallback, array $enabledProviders = []): ?array
    {
        if (! is_array($node)) {
            return null;
        }

        $provider = $this->provider(data_get($node, 'provider', data_get($node, 'preferred_provider')))
            ?? $this->provider($fallback[0] ?? null)
            ?? 'claude_cli';
        if ($enabledProviders !== [] && ! in_array($provider, $enabledProviders, true)) {
            $provider = $enabledProviders[0];
        }
        $nodeFallback = is_array($node['fallback_order'] ?? null)
            ? array_values(array_filter(array_map(fn (mixed $item): ?string => $this->provider($item), $node['fallback_order'])))
            : array_values(array_unique([$provider, ...array_filter(array_map(fn (mixed $item): ?string => $this->provider($item), $fallback))]));
        if ($enabledProviders !== []) {
            $nodeFallback = array_values(array_filter($nodeFallback, fn (string $item): bool => in_array($item, $enabledProviders, true)));
            if ($nodeFallback === []) {
                $nodeFallback = [$provider];
            }
        }

        return [
            'id' => $this->text(data_get($node, 'id')) ?? 'node',
            'role' => $this->text(data_get($node, 'role')) ?? 'executor',
            'provider' => $provider,
            'model' => $this->text(data_get($node, 'model'), data_get($providers, "{$provider}.model")),
            'model_tier' => $this->text(data_get($node, 'model_tier'), data_get($providers, "{$provider}.model_tier")),
            'fallback_order' => $nodeFallback,
        ];
    }

    /**
     * @param  array<string,mixed>  $policy
     * @return array<string,mixed>
     */
    private function contextContract(array $policy): array
    {
        $depth = $this->text(data_get($policy, 'depth'), data_get($policy, 'preset')) ?? 'focused';

        return [
            'depth' => $depth,
            'require_context_pack' => (bool) data_get($policy, 'require_context_pack', $depth === 'deep'),
            'include_memory' => (bool) data_get($policy, 'include_memory', $depth !== 'light'),
            'include_recent_traces' => (bool) data_get($policy, 'include_recent_traces', $depth !== 'light'),
            'source' => $policy !== [] ? 'profile_policy' : 'derived_default',
        ];
    }

    /**
     * @param  array<string,mixed>  $policy
     * @return array<string,mixed>
     */
    private function memoryContract(array $policy): array
    {
        $scope = $this->text(data_get($policy, 'scope'), data_get($policy, 'preset')) ?? 'project';

        return [
            'scope' => $scope,
            'recall' => AiStringListNormalizer::trimmedCastValueList(data_get($policy, 'recall', $scope === 'deep' ? ['thread', 'project', 'semantic', 'decisions'] : ['thread', 'project'])),
            'record_decisions' => (bool) data_get($policy, 'record_decisions', $scope !== 'thread'),
            'privacy_gate' => (string) data_get($policy, 'privacy_gate', 'provider_safe'),
            'source' => $policy !== [] ? 'profile_policy' : 'derived_default',
        ];
    }

    /**
     * @param  array<string,mixed>  $policy
     * @param  array<string,mixed>  $domainProfile
     * @return array<string,mixed>
     */
    private function skillContract(array $policy, array $domainProfile): array
    {
        $mode = $this->text(data_get($policy, 'mode'), data_get($policy, 'preset')) ?? 'domain';
        $domain = (string) data_get($domainProfile, 'domain_id', 'general');
        $flow = (string) data_get($domainProfile, 'flow_id', '');

        return [
            'mode' => $mode,
            'required_bundles' => AiStringListNormalizer::trimmedCastValueList(data_get($policy, 'required_bundles', $this->defaultSkillBundles($domain, $flow))),
            'allow_multi_skill' => (bool) data_get($policy, 'allow_multi_skill', $mode !== 'minimal'),
            'require_skill_trace' => (bool) data_get($policy, 'require_skill_trace', $mode !== 'minimal'),
            'source' => $policy !== [] ? 'profile_policy' : 'derived_default',
        ];
    }

    /**
     * @return array<int,string>
     */
    private function defaultSkillBundles(string $domain, string $flow): array
    {
        return match (true) {
            $flow === 'programming.forge' => ['engineering-blueprint', 'dev-quality-gate', 'code-reviewer'],
            $domain === 'programming' => ['dev-quality-gate'],
            $domain === 'research' => ['researcher-quick'],
            default => [],
        };
    }

    /**
     * @param  array<string,mixed>  $policy
     * @param  array<string,mixed>  $toolPolicy
     * @return array<string,mixed>
     */
    private function toolContract(array $policy, array $toolPolicy): array
    {
        $executor = (string) data_get($policy, 'execution_policy.executor_preference', 'standard_ai_response');
        $mode = $this->text(data_get($toolPolicy, 'mode'), data_get($toolPolicy, 'preset'))
            ?? match (true) {
                $executor === 'engineering_harness' => 'harness',
                $executor === 'dev_repair_executor' => 'workspace_write',
                str_starts_with($executor, 'simple_') => 'workspace_write',
                default => 'read_only',
            };
        $workspaceWrite = (bool) data_get($toolPolicy, 'workspace_write', in_array($mode, ['workspace_write', 'harness'], true));

        return [
            'mode' => $mode,
            'workspace_write' => $workspaceWrite,
            'destructive_requires_approval' => (bool) data_get($toolPolicy, 'destructive_requires_approval', data_get($policy, 'risk_limits.destructive_write_requires_approval', true)),
            'require_evidence_packet' => (bool) data_get($toolPolicy, 'require_evidence_packet', in_array($mode, ['workspace_write', 'harness'], true)),
            'source' => $toolPolicy !== [] ? 'profile_policy' : 'derived_default',
        ];
    }

    /**
     * @param  array<string,mixed>  $policy
     * @param  array<string,mixed>  $gatePolicy
     * @return array<string,mixed>
     */
    private function gateContract(array $policy, array $gatePolicy): array
    {
        $required = array_values(array_unique([
            ...AiStringListNormalizer::trimmedCastValueList($policy['required_gates'] ?? []),
            ...AiStringListNormalizer::trimmedCastValueList(data_get($gatePolicy, 'required_gates', [])),
        ]));
        $minimum = $this->text(data_get($gatePolicy, 'minimum_gate'), data_get($gatePolicy, 'preset'))
            ?? ((bool) data_get($policy, 'execution_policy.quality_required', false) ? 'strict' : 'standard');

        return [
            'minimum_gate' => $minimum,
            'required_gates' => $required,
            'evidence_required' => (bool) data_get($gatePolicy, 'evidence_required', $minimum !== 'standard' || (bool) data_get($policy, 'execution_policy.quality_required', false)),
            'blocking' => (bool) data_get($gatePolicy, 'blocking', $minimum !== 'advisory'),
            'source' => $gatePolicy !== [] ? 'profile_policy' : 'derived_default',
        ];
    }

    /**
     * @param  array<string,mixed>  $payload
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    private function sessionOverride(array $payload, array $options): array
    {
        foreach ([
            data_get($payload, 'ai_policy_override'),
            data_get($payload, 'session_policy_override'),
            data_get($payload, 'policy_override'),
            data_get($options, 'session_override'),
        ] as $candidate) {
            if (is_array($candidate)) {
                return $candidate;
            }
        }

        return [];
    }

    /**
     * @param  array<string,mixed>  $policy
     * @param  array<string,mixed>  $override
     * @param  array<int,string>  $warnings
     * @return array<string,mixed>
     */
    private function applySessionRuntimeOverrides(array $policy, array $override, array &$warnings): array
    {
        $enabled = array_values((array) ($policy['enabled_providers'] ?? self::PROVIDER_KEYS));
        $enabledOverrideApplied = false;

        if (is_array($override['enabled_providers'] ?? null)) {
            $enabled = array_values(array_filter(
                array_map(fn (mixed $provider): ?string => $this->provider($provider), $override['enabled_providers']),
                fn (?string $provider): bool => $provider !== null,
            ));
            $policy['enabled_providers'] = $enabled;
            $enabledOverrideApplied = true;
        }

        if (is_array($override['disabled_providers'] ?? null)) {
            $disabled = array_values(array_filter(
                array_map(fn (mixed $provider): ?string => $this->provider($provider), $override['disabled_providers']),
                fn (?string $provider): bool => $provider !== null,
            ));
            $policy['disabled_providers'] = $disabled;
            foreach ($disabled as $provider) {
                data_set($policy, "providers.{$provider}.allow_auto", false);
                data_set($policy, "providers.{$provider}.allow_manual", false);
            }
        }

        if (($provider = $this->provider(data_get($override, 'default_provider'))) !== null && in_array($provider, $enabled, true)) {
            $policy['default_provider'] = $provider;
        }

        foreach (['allow_auto', 'allow_manual', 'allow_background', 'allow_council', 'allow_multistage_graph'] as $key) {
            if (array_key_exists($key, $override)) {
                $policy[$key] = filter_var($override[$key], FILTER_VALIDATE_BOOLEAN);
            }
        }

        foreach (['default_model_policy', 'autonomy_level'] as $key) {
            if (is_string($override[$key] ?? null) && trim((string) $override[$key]) !== '') {
                $policy[$key] = trim((string) $override[$key]);
            }
        }

        if (is_array($override['fallback_order'] ?? null)) {
            $policy['fallback_order'] = array_values(array_filter(
                array_map(fn (mixed $provider): ?string => $this->provider($provider), $override['fallback_order']),
                fn (?string $provider): bool => $provider !== null && in_array($provider, $enabled, true),
            ));
        }

        if (is_array($override['providers'] ?? null)) {
            foreach ($override['providers'] as $provider => $providerOverride) {
                $provider = $this->provider($provider);
                if ($provider === null || ! in_array($provider, $enabled, true) || ! is_array($providerOverride)) {
                    continue;
                }

                foreach (['model', 'model_label', 'model_tier', 'model_identity'] as $key) {
                    if (array_key_exists($key, $providerOverride)) {
                        data_set($policy, "providers.{$provider}.{$key}", $this->nullableString($providerOverride[$key]));
                    }
                }

                foreach (['allow_auto', 'allow_manual'] as $key) {
                    if (array_key_exists($key, $providerOverride)) {
                        data_set($policy, "providers.{$provider}.{$key}", filter_var($providerOverride[$key], FILTER_VALIDATE_BOOLEAN));
                    }
                }
            }
        }

        if (is_array($override['allowed_models'] ?? null)) {
            foreach ($override['allowed_models'] as $provider => $models) {
                $provider = $this->provider($provider);
                if ($provider === null || ! in_array($provider, $enabled, true)) {
                    continue;
                }

                $policy['allowed_models'][$provider] = AiStringListNormalizer::trimmedCastValueList($models);
            }
        }

        foreach ($enabled as $provider) {
            $catalog = data_get($policy, "providers.{$provider}.models");
            if (is_array($catalog)) {
                $catalogModels = array_values(array_unique(array_filter(array_map(
                    fn (mixed $entry): ?string => is_array($entry) && is_string($entry['model'] ?? null) && trim($entry['model']) !== ''
                        ? trim($entry['model'])
                        : null,
                    $catalog,
                ))));
                if ($catalogModels !== []) {
                    $policy['allowed_models'][$provider] = $catalogModels;
                    continue;
                }
            }

            $model = data_get($policy, "providers.{$provider}.model");
            if (is_string($model) && trim($model) !== '') {
                $policy['allowed_models'][$provider] = [trim($model)];
            }
        }

        if ($enabledOverrideApplied) {
            $policy['allowed_models'] = array_intersect_key(
                (array) ($policy['allowed_models'] ?? []),
                array_flip($enabled),
            );
        }

        if (array_key_exists('execution_policy', $override)) {
            $warnings[] = 'session_execution_policy_ignored_until_effective_policy_v2_execution_authority';
        }

        return $policy;
    }

    /**
     * @param  array<string,mixed>  $domainProfile
     * @param  array<string,mixed>  $sessionOverride
     * @return array<string,array<string,mixed>>
     */
    private function policyFamilies(array $domainProfile, array $sessionOverride): array
    {
        $families = [];
        foreach (self::POLICY_FAMILIES as $key) {
            $families[$key] = array_replace_recursive(
                $this->array(data_get($domainProfile, "domain_profile.{$key}")),
                $this->array(data_get($domainProfile, "flow_profile.{$key}")),
                $this->array(data_get($sessionOverride, $key)),
            );
        }

        return $families;
    }

    /**
     * @param  array<string,mixed>  $domainProfile
     * @return array<string,mixed>
     */
    private function profileDeclaredExecutionPolicy(array $domainProfile): array
    {
        return array_replace_recursive(
            $this->array(data_get($domainProfile, 'domain_profile.execution_policy')),
            $this->array(data_get($domainProfile, 'flow_profile.execution_policy')),
        );
    }

    /**
     * @param  array<string,mixed>  $override
     * @return array<int,string>
     */
    private function appliedSessionKeys(array $override): array
    {
        $allowed = [
            'default_provider',
            'providers',
            'allowed_models',
            'fallback_order',
            'allow_auto',
            'allow_manual',
            'allow_background',
            'allow_council',
            'allow_multistage_graph',
            'default_model_policy',
            'autonomy_level',
            ...self::POLICY_FAMILIES,
        ];

        return array_values(array_intersect(array_keys($override), $allowed));
    }

    /**
     * @param  array<string,mixed>  $override
     * @return array<int,string>
     */
    private function ignoredSessionKeys(array $override): array
    {
        return array_values(array_diff(array_keys($override), $this->appliedSessionKeys($override)));
    }

    private function provider(mixed $provider): ?string
    {
        if (! is_string($provider)) {
            return null;
        }

        $provider = strtolower(trim($provider));

        return in_array($provider, self::PROVIDER_KEYS, true) ? $provider : null;
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }

    private function text(mixed ...$values): ?string
    {
        foreach ($values as $value) {
            if (! is_string($value) && ! is_numeric($value)) {
                continue;
            }

            $text = trim((string) $value);
            if ($text !== '') {
                return $text;
            }
        }

        return null;
    }

    /**
     * @return array<string,mixed>
     */
    private function array(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }
}
