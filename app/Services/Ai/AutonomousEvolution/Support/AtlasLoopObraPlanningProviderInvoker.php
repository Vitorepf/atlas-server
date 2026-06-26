<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Support;

use App\Models\AiJob;
use App\Services\Ai\AiProvider;
use App\Services\Ai\AiProviderManager;

/**
 * ITEM8 — the cohesive planning-provider invocation the obra execution adapter uses to shell out to the
 * spec/DAG provider seams.
 *
 * Two methods:
 *  - planningProviderKey(): the configured provider key (atlas.ai.default_provider, fallback hermes_cli).
 *  - obraPlanningProviderRaw($mode, $prompt): resolve the provider via the manager, run a read-only
 *    EPHEMERAL AiJob, return the raw output text (the parse path runs downstream). Empty string on any
 *    failure (provider not found, run failure) — the planning seams downstream treat '' as a parse-fail
 *    and abstain / replan, so the byte-identical contract is preserved.
 *
 * Receives the AiProviderManager as a constructor dependency so the adapter can keep its nullable-default
 * `providers` seam (test doubles inject, production falls back to app(AiProviderManager::class)) without
 * forcing the adapter's constructor signature to move. planningProviderKey() consults config() directly
 * — same call as the god-class — so the loop default (hermes_cli) and operator override paths are unchanged.
 */
class AtlasLoopObraPlanningProviderInvoker
{
    public function __construct(
        private readonly ?AiProviderManager $providers = null,
    ) {}

    /** The provider the planner runs through — the loop default (hermes_cli -> MiniMax). */
    public function planningProviderKey(): string
    {
        $configured = config('atlas.ai.default_provider', 'hermes_cli');

        return is_string($configured) && trim($configured) !== '' ? trim($configured) : 'hermes_cli';
    }

    /**
     * ACDE Leap 1 — the ACTUAL provider invocation (the ONLY place a provider runs in the planner path).
     * Mirrors {@see AtlasLiveCodeDeliveryService}: resolve the configured
     * provider via {@see AiProviderManager}->get(), run an EPHEMERAL read-only job, return the raw output
     * text (the generate*ViaProvider methods parse it). Overridable in a test double to return a RECORDED
     * JSON transcript so the parse path runs for real with NO live call / NO spend. Read-only: the job
     * never edits anything; it only returns planning TEXT.
     */
    public function obraPlanningProviderRaw(string $mode, string $prompt): string
    {
        $manager = $this->providers ?? app(AiProviderManager::class);
        $providerKey = $this->planningProviderKey();
        $provider = $manager->get($providerKey);
        if (! $provider instanceof AiProvider) {
            return '';
        }

        $job = new AiJob;
        $job->kind = 'obra_planning';
        $job->provider = $providerKey;
        $job->prompt = $prompt;
        $job->input_text = $prompt;
        $job->metadata = ['permission_mode' => 'read', 'obra_planning_mode' => $mode];
        $job->timeout_seconds = max(60, min(3600, (int) config('atlas.ai.timeout_seconds', 600)));

        $result = $provider->run($job, $prompt);
        if (! (bool) ($result->ok ?? false)) {
            return '';
        }

        return (string) ($result->output ?? '');
    }
}
