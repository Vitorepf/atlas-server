<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Proves the give-back reshape strategy is live at the operator surface and emits deterministic facts: when a
 * missing-symbol trace anchors inside the domain envelope, a reshaped scope is proposed; with no anchor it
 * returns a clear none (empty). A missing --evidence is a usage error.
 */
final class AtlasLoopReshapeProposeCommandTest extends TestCase
{
    public function test_requires_evidence(): void
    {
        $exit = Artisan::call('atlas:loop:reshape-propose', ['--json' => true]);
        $decoded = json_decode(trim(Artisan::output()), true);

        $this->assertNotSame(0, $exit);
        $this->assertSame('usage_error', $decoded['status']);
    }

    public function test_proposes_reshape_when_trace_anchors_in_envelope(): void
    {
        $decoded = $this->propose([
            'allowed_files' => ['app/Foo.php', 'app/Bar.php'],
            'scope_in' => ['app/Foo.php', 'app/Bar.php'],
            'missing_symbol_traces' => [['anchor_file' => 'app/Bar.php']],
        ]);

        $this->assertSame('atlas.loop.reshape_propose.v1', $decoded['schema']);
        $this->assertFalse($decoded['empty']);
        $this->assertSame('high', $decoded['confidence']);
        $this->assertContains('app/Bar.php', $decoded['allowed_files']);
    }

    public function test_returns_clear_none_when_no_anchor(): void
    {
        $decoded = $this->propose([
            'allowed_files' => ['app/Foo.php'],
            // no missing_symbol_traces ⇒ no anchor ⇒ empty proposal
        ]);

        $this->assertTrue($decoded['empty']);
    }

    /**
     * @param  array<string,mixed>  $evidence
     * @return array<string,mixed>
     */
    private function propose(array $evidence): array
    {
        $exit = Artisan::call('atlas:loop:reshape-propose', [
            '--evidence' => json_encode((object) $evidence),
            '--json' => true,
        ]);
        $this->assertSame(0, $exit);

        return json_decode(trim(Artisan::output()), true);
    }
}
