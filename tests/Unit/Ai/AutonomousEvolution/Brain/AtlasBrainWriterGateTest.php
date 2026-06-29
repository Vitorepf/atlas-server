<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution\Brain;

use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainWriterGate;
use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainWriterProviderResolver;
use PHPUnit\Framework\TestCase;

/**
 * FIX-2 writer-availability gate: composes the Cycle-4 provider resolver with a configured check. Freezes the
 * unavailable/available verdicts and that an explicit override is resolved before the configured check.
 */
final class AtlasBrainWriterGateTest extends TestCase
{
    public function test_writer_gate_unavailable_when_provider_reported_unconfigured(): void
    {
        $resolver = new AtlasBrainWriterProviderResolver('codex_cli');

        // isConfigured reports the resolved provider is NOT configured ⇒ the writer is unavailable.
        $unavailable = new AtlasBrainWriterGate($resolver, static fn (string $provider): bool => false);
        self::assertFalse($unavailable->available());

        // isConfigured reports configured ⇒ available. With no override, the brain_default ('codex_cli') is
        // the provider handed to the configured check.
        $seenDefault = [];
        $available = new AtlasBrainWriterGate($resolver, function (string $provider) use (&$seenDefault): bool {
            $seenDefault[] = $provider;

            return true;
        });
        self::assertTrue($available->available());
        self::assertSame(['codex_cli'], $seenDefault, 'the default resolves brain_default before the configured check');

        // An explicit override is honoured: 'hermes_cli' is resolved (not brain_default) before the check.
        $seenOverride = [];
        $availableOverride = new AtlasBrainWriterGate($resolver, function (string $provider) use (&$seenOverride): bool {
            $seenOverride[] = $provider;

            return true;
        });
        self::assertTrue($availableOverride->available('hermes_cli'));
        self::assertSame(['hermes_cli'], $seenOverride, 'an explicit override resolves before the configured check');
    }
}
