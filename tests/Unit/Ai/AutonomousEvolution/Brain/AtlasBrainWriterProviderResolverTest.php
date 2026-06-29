<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution\Brain;

use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainWriterProviderResolver;
use PHPUnit\Framework\TestCase;

/**
 * FIX 2 writer-provider resolution: `--provider ?? brain_default`, never the wrong atlas.loop.default_provider
 * key. Freezes the precedence and the typed result shape.
 */
final class AtlasBrainWriterProviderResolverTest extends TestCase
{
    public function test_blank_override_falls_back_to_brain_default(): void
    {
        $resolver = new AtlasBrainWriterProviderResolver('codex_cli');

        // A null or whitespace-only override falls back to the injected brain default.
        foreach ([null, '  '] as $blank) {
            $result = $resolver->resolve($blank);
            self::assertSame('codex_cli', $result['provider']);
            self::assertSame('brain_default', $result['source']);
            self::assertSame('atlas.brain.writer_provider.v1', $result['schema_version']);
        }

        // An explicit override wins over the default.
        $override = $resolver->resolve('hermes_cli');
        self::assertSame('hermes_cli', $override['provider']);
        self::assertSame('override', $override['source']);
        self::assertSame('atlas.brain.writer_provider.v1', $override['schema_version']);
    }
}
