<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Proves the pattern champion-challenger gate is live at the operator surface and emits deterministic facts:
 * a challenge clearing all four lanes promotes; a self-approved challenge (proposer == approver) does not.
 * A missing --input is a usage error.
 */
final class AtlasLoopPatternChampionGateCommandTest extends TestCase
{
    /** @var list<string> */
    private array $files = [];

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
        parent::tearDown();
    }

    public function test_requires_input(): void
    {
        $exit = Artisan::call('atlas:loop:pattern-champion-gate', ['--json' => true]);
        $decoded = json_decode(trim(Artisan::output()), true);

        $this->assertNotSame(0, $exit);
        $this->assertSame('usage_error', $decoded['status']);
    }

    public function test_valid_challenge_promotes(): void
    {
        $decoded = $this->invoke([
            'proposer' => 'agent-a',
            'approver' => 'agent-b',
            'eval_fresh' => true,
            'eval_battery_id' => 'battery-001',
            'champion_score' => 0.80,
            'challenger_score' => 0.95,
            'margin' => 0.05,
            'guardrail_regressed' => false,
        ]);

        $this->assertSame('atlas.loop.pattern_champion_gate.v1', $decoded['schema']);
        $this->assertTrue($decoded['promote']);
        foreach ($decoded['checks'] as $check) {
            $this->assertTrue($check);
        }
    }

    public function test_self_approved_challenge_is_rejected(): void
    {
        $decoded = $this->invoke([
            'proposer' => 'agent-a',
            'approver' => 'agent-a', // self-approval ⇒ blocked
            'eval_fresh' => true,
            'eval_battery_id' => 'battery-001',
            'champion_score' => 0.80,
            'challenger_score' => 0.95,
            'margin' => 0.05,
            'guardrail_regressed' => false,
        ]);

        $this->assertFalse($decoded['promote']);
        $this->assertFalse($decoded['checks']['self_approval_blocked']);
    }

    /**
     * @param  array<string,mixed>  $challenge
     * @return array<string,mixed>
     */
    private function invoke(array $challenge): array
    {
        $path = tempnam(sys_get_temp_dir(), 'champion_').'.json';
        $this->files[] = $path;
        file_put_contents($path, json_encode($challenge));

        $exit = Artisan::call('atlas:loop:pattern-champion-gate', ['--input' => $path, '--json' => true]);
        $this->assertSame(0, $exit);

        return json_decode(trim(Artisan::output()), true);
    }
}
