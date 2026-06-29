<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Proves the V4 self-architecture sentinel is live at the operator surface and emits deterministic facts:
 * diverse, well-rationalised proposals on safe targets are healthy; a thin-rationale proposal trips the
 * rationale-decay alert. A missing --input is a usage error.
 */
final class AtlasLoopV4SelfArchitectureSentinelCommandTest extends TestCase
{
    /** @var list<string> */
    private array $files = [];

    private const LONG_RATIONALE = 'A thoroughly explained, evidence-grounded rationale that comfortably clears the eighty character median floor.';

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
        $exit = Artisan::call('atlas:loop:v4-self-architecture-sentinel', ['--json' => true]);
        $decoded = json_decode(trim(Artisan::output()), true);

        $this->assertNotSame(0, $exit);
        $this->assertSame('usage_error', $decoded['status']);
    }

    public function test_well_rationalised_safe_proposals_are_healthy(): void
    {
        $decoded = $this->invoke([
            ['kind' => 'extract_class', 'target_path' => 'app/Http/Demo/Alpha.php', 'rationale' => self::LONG_RATIONALE],
            ['kind' => 'edge_fix', 'target_path' => 'app/Http/Demo/Beta.php', 'rationale' => self::LONG_RATIONALE],
        ]);

        $this->assertSame('atlas.loop.v4_self_architecture_sentinel.v1', $decoded['schema']);
        $this->assertTrue($decoded['healthy']);
        $this->assertSame([], $decoded['alerts']);
    }

    public function test_thin_rationale_trips_rationale_decay(): void
    {
        $decoded = $this->invoke([
            ['kind' => 'edge_fix', 'target_path' => 'app/Http/Demo/Gamma.php', 'rationale' => 'too short'],
        ]);

        $this->assertFalse($decoded['healthy']);
        $this->assertContains('rationale_decay', array_column($decoded['alerts'], 'kind'));
    }

    /**
     * @param  list<array<string,mixed>>  $proposals
     * @return array<string,mixed>
     */
    private function invoke(array $proposals): array
    {
        $path = tempnam(sys_get_temp_dir(), 'sentinel_').'.json';
        $this->files[] = $path;
        file_put_contents($path, json_encode($proposals));

        $exit = Artisan::call('atlas:loop:v4-self-architecture-sentinel', ['--input' => $path, '--json' => true]);
        $this->assertSame(0, $exit);

        return json_decode(trim(Artisan::output()), true);
    }
}
