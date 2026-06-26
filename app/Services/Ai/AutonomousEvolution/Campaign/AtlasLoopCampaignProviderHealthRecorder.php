<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Campaign;

use App\Models\AtlasLoopCampaign;
use App\Models\AtlasLoopTask;
use App\Services\Ai\AutonomousEvolution\AtlasLoopProviderCircuitBreaker;
use App\Services\Ai\AutonomousEvolution\AtlasLoopProviderEffortPolicy;
use App\Services\Ai\AutonomousEvolution\AtlasLoopProviderHealthProbe;
use App\Services\Ai\AutonomousEvolution\AtlasLoopProviderSwapPolicy;
use Closure;
use Throwable;

/**
 * PROVIDER HEALTH / EFFORT / SWAP concern, extracted from the god-class
 * {@see AtlasLoopCampaignSupervisor}.
 *
 * Owns recordProviderHealth (provider-health probe), applyProviderEffort
 * (reasoning-effort policy) and applyProviderSwap (reversible provider swap).
 * All three read the supervisor's CURRENT policy instances via closures so
 * test overrides (setProviderEffortPolicyForTesting / setProviderSwapPolicyForTesting)
 * still apply.
 */
final class AtlasLoopCampaignProviderHealthRecorder
{
    /**
     * @param  Closure(): ?AtlasLoopProviderEffortPolicy  $effortPolicyResolver
     * @param  Closure(): ?AtlasLoopProviderSwapPolicy  $swapPolicyResolver
     * @param  Closure(string, array<string,mixed>): void  $ledgerAppender
     */
    public function __construct(
        private readonly Closure $effortPolicyResolver,
        private readonly Closure $swapPolicyResolver,
        private readonly Closure $ledgerAppender,
    ) {}

    public function recordProviderHealth(string $campaignId, ?AtlasLoopTask $task, array $result, string $grindId): void
    {
        if (! (bool) config('atlas.loop.provider_health_probe_enabled', false)) {
            return;
        }
        try {
            $payload = is_array($task?->payload) ? $task->payload : [];
            $providerKey = trim((string) ($result['provider'] ?? $payload['provider'] ?? ''));
            $model = isset($result['model']) ? (string) $result['model'] : (isset($payload['model']) ? (string) $payload['model'] : null);
            (new AtlasLoopProviderHealthProbe)->record($providerKey === '' ? 'unknown' : $providerKey, $model, [
                'ok' => AtlasLoopProviderCircuitBreaker::outcomeIsProviderHealthy($result),
                'latency_ms' => max(0, (int) ($result['elapsed_seconds'] ?? 0)) * 1000,
                'cost_cents' => array_key_exists('cost_cents', $result) && is_numeric($result['cost_cents']) ? (int) $result['cost_cents'] : null,
                'grind_id' => $grindId,
            ]);
        } catch (Throwable) {
            // fail-safe: the provider-health probe never breaks a grind
        }
    }

    public function applyProviderEffort(AtlasLoopTask $task): void
    {
        try {
            $policy = ($this->effortPolicyResolver)() ?? new AtlasLoopProviderEffortPolicy;
            $payload = is_array($task->payload) ? $task->payload : [];
            $enabled = (bool) config('atlas.loop.provider_effort_policy_enabled', false);
            $decision = $enabled
                ? $policy->resolve([
                    'objective_kind' => (string) ($payload['objective_kind'] ?? ''),
                    'target_kind' => (string) ($payload['target_kind'] ?? ''),
                    'attempt_index' => (int) ($task->attempts ?? 0),
                    'prior_failures' => (int) ($payload['prior_failures'] ?? 0),
                    'routed_provider_tier' => (string) ($payload['provider_tier'] ?? $payload['routed_provider_tier'] ?? 'unknown'),
                ])
                : [
                    'schema' => AtlasLoopProviderEffortPolicy::SCHEMA,
                    'effort' => $policy->defaultEffort(),
                    'reason' => 'configured_default',
                    'routed_provider_tier' => (string) ($payload['provider_tier'] ?? $payload['routed_provider_tier'] ?? 'unknown'),
                ];

            $payload['reasoning_effort'] = $decision['effort'];
            $payload['provider_effort_policy'] = $decision + ['enabled' => $enabled];
            $task->payload = $payload;
        } catch (Throwable) {
            // fail-safe: effort policy must never block the grind.
        }
    }

    public function applyProviderSwap(AtlasLoopCampaign $campaign, AtlasLoopTask $task): void
    {
        if (! (bool) config('atlas.loop.provider_swap_policy_enabled', false)) {
            return;
        }
        try {
            $primary = trim((string) ($campaign->provider ?? ''));
            if ($primary === '') {
                $primary = (string) config('atlas.loop.default_provider', (string) config('atlas.ai.default_provider', ''));
            }
            $chain = array_values(array_map('strval', (array) config('atlas.loop.provider_fallback_chain', [])));

            $policy = ($this->swapPolicyResolver)() ?? new AtlasLoopProviderSwapPolicy;
            $decision = $policy->decide((string) $campaign->id, $primary, $chain);
            $effective = $policy->activeProvider((string) $campaign->id, $primary);

            if ($effective !== '') {
                $payload = is_array($task->payload) ? $task->payload : [];
                $payload['provider'] = $effective;
                $task->payload = $payload;
            }

            ($this->ledgerAppender)((string) $campaign->id, [
                'event' => 'provider_swap',
                'action' => $decision['action'],
                'from_provider' => $decision['from_provider'],
                'to_provider' => $decision['to_provider'],
                'effective_provider' => $effective,
                'reason' => $decision['reason'],
                'consecutive_rounds' => $decision['consecutive_rounds'],
            ]);
        } catch (Throwable) {
            // fail-safe: provider-swap selection never breaks a grind
        }
    }
}
