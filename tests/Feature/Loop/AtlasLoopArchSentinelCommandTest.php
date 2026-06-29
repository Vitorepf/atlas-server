<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Proves the arch sentinel is live at the operator surface and emits deterministic facts: with both proposals
 * and the forbidden floor supplied, well-rationalised proposals on safe targets are healthy, while a proposal
 * whose directory touches a forbidden target's directory trips proximity_creep. A missing --proposals is a
 * usage error.
 */
final class AtlasLoopArchSentinelCommandTest extends TestCase
{
    private const LONG_RATIONALE = 'A thoroughly explained, evidence-grounded rationale that comfortably clears the eighty character median floor.';

    public function test_requires_proposals(): void
    {
        $exit = Artisan::call('atlas:loop:arch-sentinel', ['--json' => true]);
        $decoded = json_decode(trim(Artisan::output()), true);

        $this->assertNotSame(0, $exit);
        $this->assertSame('usage_error', $decoded['status']);
    }

    public function test_safe_proposals_against_floor_are_healthy(): void
    {
        $decoded = $this->audit(
            [
                ['kind' => 'extract_class', 'target_path' => 'app/Http/Demo/Alpha.php', 'rationale' => self::LONG_RATIONALE],
                ['kind' => 'edge_fix', 'target_path' => 'app/Http/Demo/Beta.php', 'rationale' => self::LONG_RATIONALE],
            ],
            ['app/Services/Locked/Gate.php'],
        );

        $this->assertSame('atlas.loop.v4_self_architecture_sentinel.v1', $decoded['schema']);
        $this->assertTrue($decoded['healthy']);
        $this->assertSame([], $decoded['alerts']);
    }

    public function test_proposal_in_forbidden_dir_trips_proximity_creep(): void
    {
        $decoded = $this->audit(
            [
                ['kind' => 'refactor', 'target_path' => 'app/Services/Locked/Other.php', 'rationale' => self::LONG_RATIONALE],
            ],
            ['app/Services/Locked/Gate.php'], // same directory as the proposal ⇒ creep
        );

        $this->assertFalse($decoded['healthy']);
        $this->assertContains('proximity_creep', array_column($decoded['alerts'], 'kind'));
    }

    /**
     * @param  list<array<string,mixed>>  $proposals
     * @param  list<string>  $forbidden
     * @return array<string,mixed>
     */
    private function audit(array $proposals, array $forbidden): array
    {
        $exit = Artisan::call('atlas:loop:arch-sentinel', [
            '--proposals' => json_encode($proposals),
            '--forbidden' => json_encode($forbidden),
            '--json' => true,
        ]);
        $this->assertSame(0, $exit);

        return json_decode(trim(Artisan::output()), true);
    }
}
