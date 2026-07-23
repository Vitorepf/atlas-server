<?php

namespace App\Services\Ai\Hermes;

use App\Services\Ai\Policy\AtlasAiPolicyService;
use App\Services\Ai\Hermes\Support\HermesStringListNormalizer;
use App\Services\CapturePrivacyService;

/**
 * Pure, side-effect-free auto-routing gate for the governed Hermes executive
 * runtime.
 *
 * Hermes is an executor / transport / candidate-source only; ATLS (Atlas
 * Decide) is the sovereign authority. This router NEVER selects a provider and
 * NEVER calls one — it is an advisor that tells Atlas Decide whether Hermes is
 * even a legitimate auto-routing candidate for a given request, and seals that
 * judgement into an `atlas.hermes.runtime_router_receipt.v1` so the Evidence
 * Ledger can prove the routing decision without trusting Hermes to narrate it.
 *
 * Default-safe: when `providers.hermes_cli.allow_auto` is false, Hermes is not
 * a candidate. Sensitive/secret captures and unsafe memory/gateway policies
 * block external runtime routing. Only `auto_routing_allowed_now` may ever be
 * true, and only on a successful, policy-enabled, unblocked, Atlas-selected
 * receipt.
 */
class HermesRuntimeRouter
{
    use HermesAdapterReceipt;

    public function __construct(
        private readonly AtlasAiPolicyService $policies,
        private readonly CapturePrivacyService $privacy,
    ) {}

    /**
     * @param  array<string,mixed>  $options
     * @param  array<string,mixed>  $policy
     */
    public function isAutoRoutingCandidate(array $options, array $policy): bool
    {
        return $this->policies->providerAllowsAuto($policy, 'hermes_cli') === true
            && $this->compatibleTask($options)
            && $this->blockReason($options, $policy) === null;
    }

    /**
     * @param  array<string,mixed>  $options
     */
    public function compatibleTask(array $options): bool
    {
        if ($this->isCodingSignal($options)) {
            return config('atlas.ai.hermes_runtime_router.allow_coding_when_advantageous', false) === true
                && data_get($options, 'payload.hermes.advantageous') === true;
        }

        $signal = $this->routingSignal($options);
        if ($signal === null) {
            return false;
        }

        return in_array($signal, $this->compatibleTasks(), true);
    }

    /**
     * @param  array<string,mixed>  $options
     * @param  array<string,mixed>  $policy
     */
    public function blockReason(array $options, array $policy): ?string
    {
        if (! $this->policies->providerAllowsAuto($policy, 'hermes_cli')) {
            return 'auto_disabled_by_policy';
        }

        if ($this->privacyBlocks($options)) {
            return 'sensitive_or_secret_privacy_blocks_external_runtime';
        }

        if ($this->unsafeMemoryOrGateway($options)) {
            return 'unsafe_memory_or_gateway_policy';
        }

        if (! $this->compatibleTask($options)) {
            return 'task_not_hermes_compatible';
        }

        return null;
    }

    /**
     * @param  array<string,mixed>  $options
     * @param  array<string,mixed>  $policy
     * @return array<string,mixed>
     */
    public function buildReceipt(array $options, array $policy, bool $selectedHermes, ?string $fallbackReason): array
    {
        $policyAllowAuto = $this->policies->providerAllowsAuto($policy, 'hermes_cli');
        $compatibleTask = $this->compatibleTask($options);
        $blockReason = $this->blockReason($options, $policy);
        $blocked = $blockReason !== null;
        $autoRoutingAllowedNow = $selectedHermes && $policyAllowAuto && ! $blocked;
        $candidateProvider = 'hermes_cli';
        $selectedProvider = $selectedHermes ? 'hermes_cli' : $this->fallbackProvider($options);
        $fallbackProvider = $selectedHermes ? null : $selectedProvider;

        $receipt = [
            'schema_version' => 'atlas.hermes.runtime_router_receipt.v1',
            'router' => 'hermes_runtime_router',
            'runtime_role' => 'executive_runtime',
            'authority' => 'atlas_decide',
            'policy_allow_auto' => $policyAllowAuto,
            'compatible_task' => $compatibleTask,
            'compatible_domains' => $this->compatibleDomains(),
            'compatible_tasks' => $this->compatibleTasks(),
            'blocked' => $blocked,
            'block_reason' => $blockReason,
            'auto_routing_allowed_now' => $autoRoutingAllowedNow,
            'candidate_provider' => $candidateProvider,
            'selected_provider' => $selectedProvider,
            'fallback_provider' => $fallbackProvider,
            'fallback_reason' => $selectedHermes ? null : $fallbackReason,
            'reason' => $this->reason($autoRoutingAllowedNow, $blocked, $blockReason, $policyAllowAuto, $compatibleTask, $selectedHermes),
            'status' => $this->status($autoRoutingAllowedNow, $blocked, $policyAllowAuto, $selectedHermes),
        ];

        return $this->withReceiptHash($receipt);
    }

    /**
     * @param  array<string,mixed>  $options
     */
    private function privacyBlocks(array $options): bool
    {
        $sensitivity = data_get($options, 'payload.privacy.sensitivity');

        return in_array($sensitivity, ['sensitive', 'secret'], true)
            || ! $this->privacy->externalAiAllowedForMetadata($this->privacyMetadata($options));
    }

    /**
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>|null
     */
    private function privacyMetadata(array $options): ?array
    {
        $metadata = data_get($options, 'payload.privacy');

        return is_array($metadata) ? $metadata : null;
    }

    /**
     * @param  array<string,mixed>  $options
     */
    private function unsafeMemoryOrGateway(array $options): bool
    {
        if (data_get($options, 'payload.hermes.gateway_allowed') === true) {
            return true;
        }

        $memoryPolicy = data_get($options, 'payload.hermes.memory_policy');

        return ! in_array($memoryPolicy, ['off', 'operational_only', 'atlas_adapter', null], true);
    }

    /**
     * @param  array<string,mixed>  $options
     */
    private function isCodingSignal(array $options): bool
    {
        $signal = $this->routingSignal($options);

        return in_array($signal, ['coding', 'programming', 'dev', 'code', 'debug'], true);
    }

    /**
     * @param  array<string,mixed>  $options
     */
    private function routingSignal(array $options): ?string
    {
        $signal = data_get($options, 'payload.hermes.routing_task')
            ?: data_get($options, 'payload.routing_task')
            ?: data_get($options, 'payload.task_type')
            ?: data_get($options, 'payload.atlas_workflow_mode');

        if (! is_string($signal)) {
            return null;
        }

        $signal = strtolower(trim($signal));

        return $signal === '' ? null : $signal;
    }

    /**
     * @param  array<string,mixed>  $options
     */
    private function fallbackProvider(array $options): string
    {
        $fallback = data_get($options, 'payload.hermes.fallback_provider')
            ?: config('atlas.ai.hermes_runtime_router.fallback_provider', 'claude_cli');

        return is_string($fallback) && trim($fallback) !== '' ? trim($fallback) : 'claude_cli';
    }

    /**
     * @return array<int,string>
     */
    private function compatibleTasks(): array
    {
        $tasks = config('atlas.ai.hermes_runtime_router.compatible_tasks', ['ops', 'gateway', 'long_running', 'tool_heavy', 'research']);

        return HermesStringListNormalizer::lowerArrayOrDefault($tasks, ['ops', 'gateway', 'long_running', 'tool_heavy', 'research']);
    }

    /**
     * @return array<int,string>
     */
    private function compatibleDomains(): array
    {
        $domains = config('atlas.ai.hermes_runtime_router.compatible_domains', []);

        return HermesStringListNormalizer::lowerArrayOrDefault($domains, []);
    }

    private function reason(
        bool $autoRoutingAllowedNow,
        bool $blocked,
        ?string $blockReason,
        bool $policyAllowAuto,
        bool $compatibleTask,
        bool $selectedHermes,
    ): string {
        if ($autoRoutingAllowedNow) {
            return 'Atlas Decide auto-routed Hermes as executive_runtime: compatible ops/tool-heavy task and policy enabled allow_auto.';
        }

        if (! $policyAllowAuto) {
            return 'Hermes is not an auto-routing candidate: providers.hermes_cli.allow_auto is disabled, so Atlas Decide keeps the default-safe provider.';
        }

        if ($blocked) {
            return match ($blockReason) {
                'sensitive_or_secret_privacy_blocks_external_runtime' => 'Atlas Decide blocked Hermes auto-routing: sensitive or secret capture privacy forbids external executive runtime.',
                'unsafe_memory_or_gateway_policy' => 'Atlas Decide blocked Hermes auto-routing: unsafe Hermes memory or gateway delivery policy requires an Atlas gate first.',
                'task_not_hermes_compatible' => 'Atlas Decide kept the default provider: the routing signal is not a Hermes-compatible executive runtime task.',
                default => 'Atlas Decide blocked Hermes auto-routing under governance policy.',
            };
        }

        if (! $compatibleTask) {
            return 'Atlas Decide kept the default provider: the routing signal is not a Hermes-compatible executive runtime task.';
        }

        return $selectedHermes
            ? 'Atlas Decide selected Hermes as executive_runtime under policy.'
            : 'Hermes was a candidate but Atlas Decide selected another provider for this request.';
    }

    private function status(
        bool $autoRoutingAllowedNow,
        bool $blocked,
        bool $policyAllowAuto,
        bool $selectedHermes,
    ): string {
        if ($autoRoutingAllowedNow) {
            return 'auto_routed_to_hermes';
        }

        if (! $policyAllowAuto) {
            return 'auto_routing_disabled_by_policy';
        }

        if ($blocked) {
            return 'auto_routing_blocked';
        }

        return 'not_a_candidate';
    }
}
