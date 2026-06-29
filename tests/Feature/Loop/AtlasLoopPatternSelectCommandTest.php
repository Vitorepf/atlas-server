<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Proves the loop pattern selector is live at the operator surface and emits deterministic facts: a real
 * objective (known kind, non-trivial impact) selects a seed pattern with a ranking; a cosmetic objective is
 * refused with no pattern. A missing --input is a usage error.
 */
final class AtlasLoopPatternSelectCommandTest extends TestCase
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
        $exit = Artisan::call('atlas:loop:pattern-select', ['--json' => true]);
        $decoded = json_decode(trim(Artisan::output()), true);

        $this->assertNotSame(0, $exit);
        $this->assertSame('usage_error', $decoded['status']);
    }

    public function test_real_objective_selects_a_pattern(): void
    {
        $decoded = $this->invoke([
            'objective_kind' => 'bug',
            'expected_impact' => 0.6,
            'evidence' => 0.5,
            'risk' => 0.2,
            'cost' => 0.2,
        ]);

        $this->assertSame('atlas.loop.pattern_select.v1', $decoded['schema']);
        $this->assertFalse($decoded['rejected']);
        $this->assertNotNull($decoded['pattern_id']);
        $this->assertNotEmpty($decoded['ranking']);
    }

    public function test_cosmetic_objective_is_refused(): void
    {
        $decoded = $this->invoke([
            'objective_kind' => 'refactor',
            'expected_impact' => 0.0,
            'cosmetic' => true,
        ]);

        $this->assertTrue($decoded['rejected']);
        $this->assertNull($decoded['pattern_id']);
    }

    /**
     * @param  array<string,mixed>  $objective
     * @return array<string,mixed>
     */
    private function invoke(array $objective): array
    {
        $path = tempnam(sys_get_temp_dir(), 'pattern_').'.json';
        $this->files[] = $path;
        file_put_contents($path, json_encode($objective));

        $exit = Artisan::call('atlas:loop:pattern-select', ['--input' => $path, '--json' => true]);
        $this->assertSame(0, $exit);

        return json_decode(trim(Artisan::output()), true);
    }
}
