<?php

namespace App\Services\Ai;

class AtlasAiPolicyService
{
    private const PROVIDERS = ['claude_cli', 'codex_cli', 'gemini_cli'];

    public function __construct(
        private readonly AtlasAiRuntimeSettings $settings,
        private readonly AiRuntimeBudgetService $budgets,
        private readonly AtlasDomainProfileRegistry $domainProfiles,
        private readonly AtlasEffectivePolicyComposer $effectivePolicies,
    ) {}

    /**
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    public function effectiveProfile(array $options = []): array
    {
        $payload = is_array($options['payload'] ?? null) ? $options['payload'] : [];
        $surface = $this->surface($options, $payload);
        $mode = $this->mode($options, $payload);
        $task = $this->task($payload, $mode);
        $profileId = $this->profileId($surface, $mode, $task, $payload);
        $runtime = $this->settings->effective();
        $budget = $this->budgets->payload();
        $domainProfile = $this->domainProfiles->resolve($profileId, [
            'payload' => $payload,
            'surface' => $surface,
            'mode' => $mode,
            'task' => $task,
        ]);
        $forge = $profileId === 'programming.forge' || data_get($payload, 'dev_execution_plan.programming_profile') === 'forge';
        $background = in_array($surface, ['background', 'scheduler'], true);
        $programming = str_starts_with($profileId, 'programming.');
        $complete = $forge || (bool) data_get($payload, 'dev_execution_plan.operator_options.complete', data_get($payload, 'dev_execution_plan.complete', false));
        $executionPolicy = $this->authoritativeExecutionPolicy(
            legacy: $this->executionPolicy($profileId, $task, $forge, $background, $payload),
            domainProfile: $domainProfile,
            forge: $forge,
            programming: $programming,
            complete: $complete,
        );

        $policy = [
            'schema_version' => 1,
            'profile_id' => $profileId,
            'policy_version' => 'atlas-ai-policy-v1',
            'domain' => (string) data_get($domainProfile, 'domain_id'),
            'flow' => (string) data_get($domainProfile, 'flow_id'),
            'domain_profile' => data_get($domainProfile, 'domain_profile', []),
            'flow_profile' => data_get($domainProfile, 'flow_profile', []),
            'domain_profile_registry' => [
                'schema_version' => (int) data_get($domainProfile, 'schema_version', 1),
                'source' => (string) data_get($domainProfile, 'source', 'static_fallback'),
            ],
            'surface' => $surface,
            'mode' => $mode,
            'task' => $task,
            'default_provider' => (string) ($runtime['default_provider'] ?? 'claude_cli'),
            'default_model_policy' => $forge ? 'best_quality' : 'balanced',
            'enabled_providers' => self::PROVIDERS,
            'disabled_providers' => [],
            'allowed_models' => $this->allowedModels((array) ($runtime['providers'] ?? [])),
            'allow_auto' => ! $background || (bool) data_get($payload, 'allow_background_auto', false),
            'allow_manual' => true,
            'allow_background' => $background ? (bool) data_get($payload, 'allow_background_auto', false) : true,
            'allow_council' => $forge || (bool) ($runtime['council_allow_auto'] ?? false),
            'allow_multistage_graph' => $forge || in_array($task, ['research', 'analysis', 'memory', 'visual'], true),
            'autonomy_level' => $forge ? 'high' : ($background ? 'low' : 'medium'),
            'required_gates' => $this->requiredGates($profileId, $task, $forge, $background),
            'profile_context' => [
                'surface' => $surface,
                'mode' => $mode,
                'task' => $task,
                'domain' => (string) data_get($domainProfile, 'domain_id'),
                'flow' => (string) data_get($domainProfile, 'flow_id'),
                'background' => $background,
                'programming' => $programming,
                'forge' => $forge,
            ],
            'execution_policy' => $executionPolicy,
            'execution_authority' => (string) ($executionPolicy['source'] ?? 'legacy_execution_policy'),
            'fallback_order' => $this->fallbackOrder($profileId, (string) ($runtime['default_provider'] ?? 'claude_cli')),
            'budget_policy' => [
                'enabled' => (bool) ($budget['enabled'] ?? false),
                'mode' => (string) ($budget['mode'] ?? 'block'),
                'window_hours' => (int) ($budget['window_hours'] ?? 24),
            ],
            'providers' => $this->providers((array) ($runtime['providers'] ?? []), $budget),
            'risk_limits' => [
                'destructive_write_requires_approval' => true,
                'background_high_risk_escalates' => $background,
            ],
        ];

        return $this->effectivePolicies->compose($policy, $domainProfile, $runtime, $payload, $options)['policy'];
    }

    /**
     * @param  array<string,mixed>  $policy
     */
    public function providerAllowsAuto(array $policy, string $provider): bool
    {
        return in_array($provider, self::PROVIDERS, true)
            && (bool) data_get($policy, "providers.{$provider}.allow_auto", true)
            && (bool) data_get($policy, 'allow_auto', true)
            && $this->budgetAllows($policy, $provider);
    }

    /**
     * @param  array<string,mixed>  $policy
     */
    public function providerAllowsManual(array $policy, string $provider): bool
    {
        return in_array($provider, self::PROVIDERS, true)
            && (bool) data_get($policy, "providers.{$provider}.allow_manual", true)
            && (bool) data_get($policy, 'allow_manual', true);
    }

    /**
     * @param  array<string,mixed>  $policy
     */
    public function fallbackProvider(array $policy, ?string $blockedProvider = null, bool $programmingLike = false): string
    {
        foreach ((array) ($policy['fallback_order'] ?? []) as $provider) {
            if (! is_string($provider) || $provider === $blockedProvider) {
                continue;
            }

            if ($programmingLike && $provider === 'gemini_cli') {
                continue;
            }

            if ($this->providerAllowsAuto($policy, $provider)) {
                return $provider;
            }
        }

        return 'claude_cli';
    }

    /**
     * @param  array<string,mixed>  $policy
     */
    public function budgetAllows(array $policy, string $provider): bool
    {
        if (! (bool) data_get($policy, 'budget_policy.enabled', false)
            || data_get($policy, 'budget_policy.mode') !== 'block'
        ) {
            return true;
        }

        return data_get($policy, "providers.{$provider}.budget_status") !== 'blocked';
    }

    /**
     * @param  array<string,mixed>  $providers
     * @return array<string,array<int,string>>
     */
    private function allowedModels(array $providers): array
    {
        $models = [];
        foreach (self::PROVIDERS as $provider) {
            $model = data_get($providers, "{$provider}.model");
            $models[$provider] = is_string($model) && trim($model) !== ''
                ? [trim($model)]
                : [];
        }

        return $models;
    }

    /**
     * @param  array<string,mixed>  $providers
     * @param  array<string,mixed>  $budget
     * @return array<string,array<string,mixed>>
     */
    private function providers(array $providers, array $budget): array
    {
        $rows = [];
        foreach (self::PROVIDERS as $provider) {
            $rows[$provider] = [
                'model' => data_get($providers, "{$provider}.model"),
                'model_label' => data_get($providers, "{$provider}.model_label"),
                'model_tier' => data_get($providers, "{$provider}.model_tier"),
                'allow_auto' => (bool) data_get($providers, "{$provider}.allow_auto", true),
                'allow_manual' => (bool) data_get($providers, "{$provider}.allow_manual", true),
                'budget_status' => $this->providerBudgetStatus($budget, $provider),
            ];
        }

        return $rows;
    }

    /**
     * @param  array<string,mixed>  $budget
     */
    private function providerBudgetStatus(array $budget, string $provider): string
    {
        $row = collect((array) ($budget['providers'] ?? []))
            ->first(fn (mixed $item): bool => is_array($item) && ($item['provider'] ?? null) === $provider);

        return is_array($row) ? (string) ($row['status'] ?? 'ok') : 'ok';
    }

    /**
     * @return array<int,string>
     */
    private function fallbackOrder(string $profileId, string $defaultProvider): array
    {
        $defaultProvider = in_array($defaultProvider, self::PROVIDERS, true) ? $defaultProvider : 'claude_cli';

        if ($profileId === 'programming.forge') {
            return array_values(array_unique(['codex_cli', 'claude_cli', $defaultProvider, 'gemini_cli']));
        }

        if (str_starts_with($profileId, 'programming.')) {
            return array_values(array_unique(['codex_cli', $defaultProvider, 'claude_cli', 'gemini_cli']));
        }

        return array_values(array_unique([$defaultProvider, 'claude_cli', 'codex_cli', 'gemini_cli']));
    }

    /**
     * @return array<int,string>
     */
    private function requiredGates(string $profileId, string $task, bool $forge, bool $background): array
    {
        $gates = ['response_sanity_check'];

        if (str_starts_with($profileId, 'programming.')) {
            $gates[] = 'tests_or_static_review';
            $gates[] = 'diff_scope_review';
        }

        if ($forge) {
            $gates[] = 'open_brain_required';
            $gates[] = 'tool_runtime_gate';
            $gates[] = 'quality_scan';
        }

        if (in_array($task, ['security', 'qa', 'database', 'visual'], true)) {
            $gates[] = $task.'_gate';
        }

        if ($background) {
            $gates[] = 'background_safety_gate';
        }

        return array_values(array_unique($gates));
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function executionPolicy(string $profileId, string $task, bool $forge, bool $background, array $payload): array
    {
        $programming = str_starts_with($profileId, 'programming.');
        $highRiskTask = in_array($task, ['security', 'database', 'qa', 'visual'], true);
        $complete = $forge || (bool) data_get($payload, 'dev_execution_plan.operator_options.complete', data_get($payload, 'dev_execution_plan.complete', false));
        $maxIterations = $forge
            ? 5
            : ($complete ? max(2, (int) data_get($payload, 'dev_execution_plan.operator_options.max_iterations', 3)) : 1);

        return [
            'executor_preference' => match (true) {
                $forge || $highRiskTask => 'engineering_harness',
                $programming && $complete => 'dev_repair_executor',
                $programming => 'simple_provider_execution',
                default => 'standard_ai_response',
            },
            'max_iterations' => min(10, max(1, $maxIterations)),
            'auto_test' => $forge || $complete || $highRiskTask,
            'quality_required' => $forge || $complete || $highRiskTask,
            'open_brain' => $forge ? 'required' : ($programming ? 'auto' : 'optional'),
            'background_execution' => [
                'allowed' => ! $background || (bool) data_get($payload, 'allow_background_auto', false),
                'requires_explicit_allow' => $background,
                'max_autonomy' => $background ? 'low' : ($forge ? 'high' : 'medium'),
            ],
            'harness_required' => $forge || $highRiskTask,
            'manual_approval_required_for_destructive' => true,
        ];
    }

    /**
     * @param  array<string,mixed>  $legacy
     * @param  array<string,mixed>  $domainProfile
     * @return array<string,mixed>
     */
    private function authoritativeExecutionPolicy(
        array $legacy,
        array $domainProfile,
        bool $forge,
        bool $programming,
        bool $complete,
    ): array {
        $declared = array_replace_recursive(
            (array) data_get($domainProfile, 'domain_profile.execution_policy', []),
            (array) data_get($domainProfile, 'flow_profile.execution_policy', []),
        );
        $databaseBacked = data_get($domainProfile, 'source') === 'database';
        $policy = $databaseBacked && $declared !== []
            ? array_replace_recursive($legacy, $declared, ['source' => 'domain_flow_profile'])
            : array_replace_recursive($legacy, ['source' => 'legacy_execution_policy']);

        if ($forge) {
            $policy['executor_preference'] = 'engineering_harness';
            $policy['harness_required'] = true;
            $policy['quality_required'] = true;
            $policy['max_iterations'] = max(5, (int) ($policy['max_iterations'] ?? 5));
            $policy['source'] = $databaseBacked && $declared !== []
                ? 'domain_flow_profile_with_forge_guard'
                : $policy['source'];
        } elseif ($programming && $complete && in_array((string) ($policy['executor_preference'] ?? ''), ['', 'simple_provider_execution', 'standard_ai_response'], true)) {
            $policy['executor_preference'] = 'dev_repair_executor';
            $policy['quality_required'] = true;
            $policy['max_iterations'] = max(2, (int) ($policy['max_iterations'] ?? 2));
            $policy['source'] = $databaseBacked && $declared !== []
                ? 'domain_flow_profile_with_complete_guard'
                : $policy['source'];
        }

        $policy['max_iterations'] = min(10, max(1, (int) ($policy['max_iterations'] ?? 1)));

        return $policy;
    }

    /**
     * @param  array<string,mixed>  $options
     * @param  array<string,mixed>  $payload
     */
    private function surface(array $options, array $payload): string
    {
        $surface = data_get($payload, 'app_surface') ?: ($options['source_type'] ?? 'cli');
        $surface = strtolower(trim((string) $surface));

        return match ($surface) {
            'atlas_cli', 'manual' => 'cli',
            'scheduled', 'capture' => 'background',
            default => $surface !== '' ? $surface : 'cli',
        };
    }

    /**
     * @param  array<string,mixed>  $options
     * @param  array<string,mixed>  $payload
     */
    private function mode(array $options, array $payload): string
    {
        $mode = data_get($payload, 'atlas_workflow_mode') ?: ($options['mode'] ?? 'general');
        $mode = strtolower(trim((string) $mode));

        return match ($mode) {
            'dev', 'debug', 'execute', 'quality_repair' => 'programming',
            'research', 'analysis' => 'operational',
            default => $mode !== '' ? $mode : 'general',
        };
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function task(array $payload, string $mode): string
    {
        $profile = data_get($payload, 'dev_execution_plan.programming_profile');
        if ($profile === 'forge') {
            return 'forge';
        }

        $task = data_get($payload, 'routing_task')
            ?: data_get($payload, 'task_type')
            ?: data_get($payload, 'task_request.task_type')
            ?: $mode;
        $task = strtolower(trim((string) $task));

        return match ($task) {
            'dev', 'debug', 'code', 'coding', 'programming' => 'dev',
            'database', 'db', 'postgres' => 'database',
            default => $task !== '' ? $task : $mode,
        };
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function profileId(string $surface, string $mode, string $task, array $payload): string
    {
        $explicitProfile = strtolower(trim((string) data_get($payload, 'atlas_profile_id', '')));
        if ($explicitProfile !== '' && str_contains($explicitProfile, '.')) {
            return $explicitProfile;
        }

        if ($task === 'forge' || data_get($payload, 'programming_profile') === 'forge') {
            return 'programming.forge';
        }

        if ($mode === 'programming') {
            return 'programming.'.$task;
        }

        if ($surface === 'background') {
            return 'background.safe';
        }

        return $mode.'.'.$task;
    }
}
