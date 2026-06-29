<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Proves the leverage scorer is live at the operator surface and emits deterministic facts: a candidate is
 * scored into its leverage + component breakdown, the verifiable flag passes through, and breadth grows
 * monotonically with caller_count. A missing --input is a usage error.
 */
final class AtlasLoopLeverageScoreCommandTest extends TestCase
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
        $exit = Artisan::call('atlas:loop:leverage-score', ['--json' => true]);
        $decoded = json_decode(trim(Artisan::output()), true);

        $this->assertNotSame(0, $exit);
        $this->assertSame('usage_error', $decoded['status']);
    }

    public function test_scores_candidate_into_component_breakdown(): void
    {
        $decoded = $this->invoke([
            'path' => 'app/Foo.php',
            'shape' => 'refactor',
            'caller_count' => 40,
            'cyclomatic' => 30,
            'strategic_impact' => 0.9,
            'cost' => 0.5,
            'risk' => 0.5,
            'verifiable' => true,
        ]);

        $this->assertSame('atlas.loop.leverage_score.v1', $decoded['schema']);
        $this->assertIsNumeric($decoded['leverage']);
        $this->assertTrue($decoded['verifiable']);
        foreach (['strategic_impact', 'breadth', 'compounding', 'cost', 'risk'] as $component) {
            $this->assertArrayHasKey($component, $decoded['components']);
        }
        $this->assertGreaterThanOrEqual(0.0, $decoded['components']['breadth']);
        $this->assertLessThanOrEqual(1.0, $decoded['components']['breadth']);
    }

    public function test_breadth_grows_with_caller_count(): void
    {
        $low = $this->invoke(['path' => 'a', 'caller_count' => 1]);
        $high = $this->invoke(['path' => 'b', 'caller_count' => 500]);

        $this->assertGreaterThan($low['components']['breadth'], $high['components']['breadth']);
    }

    /**
     * @param  array<string,mixed>  $candidate
     * @return array<string,mixed>
     */
    private function invoke(array $candidate): array
    {
        $path = tempnam(sys_get_temp_dir(), 'leverage_').'.json';
        $this->files[] = $path;
        file_put_contents($path, json_encode($candidate));

        $exit = Artisan::call('atlas:loop:leverage-score', ['--input' => $path, '--json' => true]);
        $this->assertSame(0, $exit);

        return json_decode(trim(Artisan::output()), true);
    }
}
