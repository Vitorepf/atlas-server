<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainWriterGate;
use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainWriterProviderResolver;
use PHPUnit\Framework\TestCase;

final class AtlasBrainWriterGateTest extends TestCase
{
    private function gate(string $brainDefault, array $configured = []): AtlasBrainWriterGate
    {
        $resolver = new AtlasBrainWriterProviderResolver($brainDefault);
        $check = static fn (string $p): bool => in_array($p, $configured, true);

        return new AtlasBrainWriterGate($resolver, $check);
    }

    // ── AC2: explicit override wins over brain default ────────────────────────

    public function test_override_wins_over_brain_default(): void
    {
        // brain_default 'minimax' not configured; 'codex' is
        $gate = $this->gate('minimax', ['codex']);

        $this->assertTrue($gate->available('codex'));
    }

    public function test_brain_default_used_when_no_override(): void
    {
        $gate = $this->gate('minimax', ['minimax']);

        $this->assertTrue($gate->available());
    }

    public function test_whitespace_only_override_falls_back_to_brain_default(): void
    {
        $gate = $this->gate('minimax', ['minimax']);

        $this->assertTrue($gate->available('   '));
    }

    public function test_null_override_falls_back_to_brain_default(): void
    {
        $gate = $this->gate('minimax', ['minimax']);

        $this->assertTrue($gate->available(null));
    }

    // ── AC3: available reflects injected configured check ─────────────────────

    public function test_available_true_when_provider_configured(): void
    {
        $gate = $this->gate('codex', ['codex']);

        $this->assertTrue($gate->available());
    }

    public function test_available_false_when_provider_not_configured(): void
    {
        $gate = $this->gate('codex', []);

        $this->assertFalse($gate->available());
    }

    public function test_available_false_for_unconfigured_override(): void
    {
        $gate = $this->gate('minimax', ['minimax']);

        $this->assertFalse($gate->available('gpt5'));
    }

    // ── AC4: pure — collaborators are the only I/O surface ───────────────────

    public function test_check_receives_resolved_provider_name(): void
    {
        $received = null;
        $resolver = new AtlasBrainWriterProviderResolver('default-brain');
        $check = static function (string $p) use (&$received): bool {
            $received = $p;
            return true;
        };
        $gate = new AtlasBrainWriterGate($resolver, $check);

        $gate->available('my-override');

        $this->assertSame('my-override', $received);
    }

    public function test_brain_default_provider_forwarded_to_check(): void
    {
        $received = null;
        $resolver = new AtlasBrainWriterProviderResolver('brain-default-x');
        $check = static function (string $p) use (&$received): bool {
            $received = $p;
            return true;
        };
        $gate = new AtlasBrainWriterGate($resolver, $check);

        $gate->available(null);

        $this->assertSame('brain-default-x', $received);
    }
}
