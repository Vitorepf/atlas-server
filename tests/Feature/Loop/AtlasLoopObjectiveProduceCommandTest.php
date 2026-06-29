<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Proves the objective producer is live at the operator surface and emits deterministic facts: from candidate
 * packets it selects the highest-leverage one clearing the ambition floor; when none clears (unverifiable /
 * thin) it selects nothing. A missing --input is a usage error.
 */
final class AtlasLoopObjectiveProduceCommandTest extends TestCase
{
    /** @var list<string> */
    private array $files = [];

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'atlas.loop.producer_leverage_floor' => 0.6,
            'atlas.loop.producer_min_unblock' => 0.25,
        ]);
    }

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
        $exit = Artisan::call('atlas:loop:objective-produce', ['--json' => true]);
        $decoded = json_decode(trim(Artisan::output()), true);

        $this->assertNotSame(0, $exit);
        $this->assertSame('usage_error', $decoded['status']);
    }

    public function test_selects_highest_leverage_floor_passer(): void
    {
        // caller_count 20 ⇒ breadth 1.0; cyclomatic 30 ⇒ compounding 1.0; verifiable ⇒ leverage 1.2 > 0.6.
        $decoded = $this->invoke([
            ['path' => 'app/Weak.php', 'caller_count' => 0, 'cyclomatic' => 0, 'verifiable' => false],
            ['path' => 'app/Hub.php', 'shape' => 'refactor', 'caller_count' => 20, 'cyclomatic' => 30, 'verifiable' => true],
        ]);

        $this->assertSame('atlas.loop.objective_produce.v1', $decoded['schema']);
        $this->assertTrue($decoded['has_objective']);
        $this->assertSame('app/Hub.php', $decoded['selected']['path']);
        $this->assertGreaterThanOrEqual(0.6, $decoded['selected']['_score']['leverage']);
    }

    public function test_selects_nothing_when_no_candidate_clears_floor(): void
    {
        $decoded = $this->invoke([
            ['path' => 'app/Thin.php', 'caller_count' => 0, 'cyclomatic' => 0, 'verifiable' => false],
        ]);

        $this->assertFalse($decoded['has_objective']);
        $this->assertNull($decoded['selected']);
    }

    /**
     * @param  list<array<string,mixed>>  $packets
     * @return array<string,mixed>
     */
    private function invoke(array $packets): array
    {
        $path = tempnam(sys_get_temp_dir(), 'objective_').'.json';
        $this->files[] = $path;
        file_put_contents($path, json_encode($packets));

        $exit = Artisan::call('atlas:loop:objective-produce', ['--input' => $path, '--json' => true]);
        $this->assertSame(0, $exit);

        return json_decode(trim(Artisan::output()), true);
    }
}
