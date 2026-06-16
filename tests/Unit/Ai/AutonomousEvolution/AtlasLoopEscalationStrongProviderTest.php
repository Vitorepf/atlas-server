<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution;

use App\Services\Ai\AutonomousEvolution\AtlasLoopTaskGrinder;
use PHPUnit\Framework\TestCase;

/**
 * ACDE lever #5 — the strong-engine escalation rung policy. Pure, container-free: the strongest tier
 * pins a genuinely stronger engine, but only when it is non-empty, non-Claude (Anthropic 3rd-party block +
 * operator no-burn rule), and actually DIFFERENT from the weak engine the prior rounds used. Every refusal
 * falls back to '' => the same-engine $deep tier => byte-identical.
 */
final class AtlasLoopEscalationStrongProviderTest extends TestCase
{
    public function test_resolves_a_genuinely_stronger_engine(): void
    {
        $this->assertSame('codex_cli', AtlasLoopTaskGrinder::resolveStrongProvider('codex_cli', 'hermes_cli'));
        $this->assertSame('codex_cli', AtlasLoopTaskGrinder::resolveStrongProvider('  codex_cli  ', 'hermes_cli'));
    }

    public function test_empty_strong_provider_falls_back_byte_identical(): void
    {
        $this->assertSame('', AtlasLoopTaskGrinder::resolveStrongProvider('', 'hermes_cli'));
        $this->assertSame('', AtlasLoopTaskGrinder::resolveStrongProvider('   ', 'hermes_cli'));
    }

    public function test_never_routes_to_claude_anthropic(): void
    {
        $this->assertSame('', AtlasLoopTaskGrinder::resolveStrongProvider('claude-opus-4-8', 'hermes_cli'));
        $this->assertSame('', AtlasLoopTaskGrinder::resolveStrongProvider('anthropic_cli', 'hermes_cli'));
        $this->assertSame('', AtlasLoopTaskGrinder::resolveStrongProvider('Claude_CLI', 'hermes_cli'));
    }

    public function test_anti_theatre_strong_equal_to_weak_is_refused(): void
    {
        $this->assertSame('', AtlasLoopTaskGrinder::resolveStrongProvider('hermes_cli', 'hermes_cli'));
        $this->assertSame('', AtlasLoopTaskGrinder::resolveStrongProvider('hermes_cli', '  hermes_cli  '));
    }
}
