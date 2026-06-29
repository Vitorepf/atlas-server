<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Proves the criterion-stability selector is live at the operator surface and emits deterministic facts:
 * the candidate passing the most criteria wins, ties are broken by the smaller change (less risk); empty
 * input selects nothing. A missing --input is a usage error.
 */
final class AtlasLoopCriterionStabilitySelectCommandTest extends TestCase
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
        $exit = Artisan::call('atlas:loop:criterion-stability-select', ['--json' => true]);
        $decoded = json_decode(trim(Artisan::output()), true);

        $this->assertNotSame(0, $exit);
        $this->assertSame('usage_error', $decoded['status']);
    }

    public function test_selects_most_passed_breaking_ties_by_smaller_change(): void
    {
        $decoded = $this->invoke([
            ['id' => 'a', 'criteria_passed' => 3, 'change_size' => 10],
            ['id' => 'b', 'criteria_passed' => 5, 'change_size' => 200],
            ['id' => 'c', 'criteria_passed' => 5, 'change_size' => 50], // ties b on passed, smaller change ⇒ wins
        ]);

        $this->assertSame('atlas.loop.criterion_stability_select.v1', $decoded['schema']);
        $this->assertTrue($decoded['has_selection']);
        $this->assertSame('c', $decoded['selected']['id']);
    }

    public function test_no_valid_candidate_selects_nothing(): void
    {
        $decoded = $this->invoke([
            ['criteria_passed' => 5], // no id ⇒ skipped
        ]);

        $this->assertFalse($decoded['has_selection']);
        $this->assertNull($decoded['selected']);
    }

    /**
     * @param  list<array<string,mixed>>  $candidates
     * @return array<string,mixed>
     */
    private function invoke(array $candidates): array
    {
        $path = tempnam(sys_get_temp_dir(), 'criterion_').'.json';
        $this->files[] = $path;
        file_put_contents($path, json_encode($candidates));

        $exit = Artisan::call('atlas:loop:criterion-stability-select', ['--input' => $path, '--json' => true]);
        $this->assertSame(0, $exit);

        return json_decode(trim(Artisan::output()), true);
    }
}
