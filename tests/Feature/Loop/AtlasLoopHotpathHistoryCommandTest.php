<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Proves the cortex hotpath history reporter is live at the operator surface and emits deterministic facts:
 * over a 2-cycle window, an FQCN touched in both cycles gets vector [1,1], one touched only in the first
 * gets [1,0]. A missing --input is a usage error.
 */
final class AtlasLoopHotpathHistoryCommandTest extends TestCase
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
        $exit = Artisan::call('atlas:loop:hotpath-history', ['--json' => true]);
        $decoded = json_decode(trim(Artisan::output()), true);

        $this->assertNotSame(0, $exit);
        $this->assertSame('usage_error', $decoded['status']);
    }

    public function test_builds_appearance_vectors_over_the_window(): void
    {
        $decoded = $this->invoke([
            ['cycle_id' => 'c1', 'touched_fqcns' => ['App\\A', 'App\\B']],
            ['cycle_id' => 'c2', 'touched_fqcns' => ['App\\A']],
        ]);

        $this->assertSame('atlas.loop.hotpath_history.v1', $decoded['schema']);
        $this->assertSame(2, $decoded['window_size']);
        $this->assertSame(2, $decoded['count']);

        $byFqcn = array_column($decoded['history'], null, 'fqcn');
        $this->assertSame([1, 1], $byFqcn['App\\A']['appearance_vector']);
        $this->assertSame([1, 0], $byFqcn['App\\B']['appearance_vector']);
        $this->assertSame(2, $byFqcn['App\\A']['window_size']);
    }

    /**
     * @param  list<array<string,mixed>>  $cycleFacts
     * @return array<string,mixed>
     */
    private function invoke(array $cycleFacts): array
    {
        $path = tempnam(sys_get_temp_dir(), 'hotpath_').'.json';
        $this->files[] = $path;
        file_put_contents($path, json_encode($cycleFacts));

        $exit = Artisan::call('atlas:loop:hotpath-history', ['--input' => $path, '--json' => true]);
        $this->assertSame(0, $exit);

        return json_decode(trim(Artisan::output()), true);
    }
}
