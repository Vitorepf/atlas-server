<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Proves the benchmark harness summarizer is live at the operator surface: it sorts the samples and emits the
 * deterministic summary shape (n, interpolated median/IQR, population stddev, min, max).
 */
final class AtlasLoopBenchmarkSummarizeCommandTest extends TestCase
{
    private string $input = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->input = sys_get_temp_dir().'/atlas-bench-'.bin2hex(random_bytes(5)).'.json';
    }

    protected function tearDown(): void
    {
        @unlink($this->input);
        parent::tearDown();
    }

    public function test_summarizes_unsorted_samples_deterministically(): void
    {
        // intentionally unsorted — summarize() sorts numerically first
        file_put_contents($this->input, (string) json_encode([30, 10, 40, 20]));

        $exit = Artisan::call('atlas:loop:benchmark-summarize', ['--input' => $this->input, '--json' => true]);
        $d = json_decode(trim(Artisan::output()), true);

        $this->assertSame(0, $exit);
        $this->assertSame('atlas.loop.benchmark_summary.v1', $d['schema']);
        $this->assertSame(4, $d['n']);
        $this->assertEqualsWithDelta(25.0, $d['median_ns'], 1e-9);   // interp median of [10,20,30,40]
        $this->assertEqualsWithDelta(15.0, $d['iqr_ns'], 1e-9);      // q3 32.5 - q1 17.5
        $this->assertEqualsWithDelta(sqrt(125.0), $d['stddev_ns'], 1e-9); // population stddev
        $this->assertEqualsWithDelta(10.0, $d['min_ns'], 1e-9);
        $this->assertEqualsWithDelta(40.0, $d['max_ns'], 1e-9);
    }

    public function test_single_sample_has_zero_spread(): void
    {
        file_put_contents($this->input, (string) json_encode(['samples' => [42]]));

        $exit = Artisan::call('atlas:loop:benchmark-summarize', ['--input' => $this->input, '--json' => true]);
        $d = json_decode(trim(Artisan::output()), true);

        $this->assertSame(0, $exit);
        $this->assertSame(1, $d['n']);
        $this->assertEqualsWithDelta(42.0, $d['median_ns'], 1e-9);
        $this->assertEqualsWithDelta(0.0, $d['iqr_ns'], 1e-9);
        $this->assertEqualsWithDelta(0.0, $d['stddev_ns'], 1e-9);
    }

    public function test_empty_samples_is_usage_error(): void
    {
        file_put_contents($this->input, (string) json_encode([]));

        $exit = Artisan::call('atlas:loop:benchmark-summarize', ['--input' => $this->input, '--json' => true]);

        $this->assertNotSame(0, $exit);
        $this->assertStringContainsString('usage_error', Artisan::output());
    }

    public function test_missing_input_is_usage_error(): void
    {
        $exit = Artisan::call('atlas:loop:benchmark-summarize', ['--json' => true]);

        $this->assertNotSame(0, $exit);
        $this->assertStringContainsString('usage_error', Artisan::output());
    }
}
