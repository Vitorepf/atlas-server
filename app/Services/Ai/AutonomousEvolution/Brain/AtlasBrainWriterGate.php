<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Brain;

use App\Services\Ai\Programming\AtlasForgeProviderInvocationDriverRouter;
use Closure;

/**
 * FIX-2 building block (docs/atlas-brain-harness-build-spec.md): answers ONE question — is the brain writer
 * available? — by composing the Cycle-4 {@see AtlasBrainWriterProviderResolver} (provider = --provider ??
 * brain_default) with a provider-configured check. The Cycle-8 origination pipeline injects available() as its
 * `(): bool` preflight, and the conductor uses it to emit brain_provider_unavailable instead of recording a dead
 * writer as a no_proposal refusal.
 *
 * Pure given the injected collaborators: no config()/file/network reads. The provider is RESOLVED (not
 * re-derived) through the Cycle-4 resolver, and the configured check is supplied (the live router or a
 * callable(string):bool for tests).
 */
final class AtlasBrainWriterGate
{
    /** @var Closure(string):bool */
    private readonly Closure $isConfigured;

    /**
     * @param  AtlasForgeProviderInvocationDriverRouter|Closure  $isConfigured  the live router, or a
     *                                                                          callable(string):bool for tests
     */
    public function __construct(
        private readonly AtlasBrainWriterProviderResolver $resolver,
        AtlasForgeProviderInvocationDriverRouter|Closure $isConfigured,
    ) {
        $this->isConfigured = $isConfigured instanceof AtlasForgeProviderInvocationDriverRouter
            ? static fn (string $provider): bool => $isConfigured->isConfigured($provider)
            : $isConfigured;
    }

    /**
     * Is the brain writer available? Resolves the provider (an explicit $override wins over brain_default) and
     * returns true only when that provider is reported configured.
     */
    public function available(?string $override = null): bool
    {
        $provider = (string) $this->resolver->resolve($override)['provider'];

        return (bool) ($this->isConfigured)($provider);
    }
}
