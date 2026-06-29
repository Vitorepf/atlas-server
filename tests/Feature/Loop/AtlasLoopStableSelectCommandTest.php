<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Proves the criterion-stability selector is live at the operator surface: the candidate passing the most
 * criteria is selected; a tie breaks to the smaller change; an empty set selects nothing.
 */
final class AtlasLoopStableSelectCommandTest extends TestCase
{
    private function select(array $candidates): array
    {
        $exit = Artisan::call('atlas:loop:stable-select', [
            '--candidates' => (string) json_encode($candidates),
            '--json' => true,
        ]);

        return ['exit' => $exit, 'd' => json_decode(trim(Artisan::output()), true)];
    }

    public function test_most_criteria_passed_is_selected(): void
    {
        ['exit' => $exit, 'd' => $d] = $this->select([
            ['id' => 'a', 'criteria_passed' => 3, 'change_size' => 10],
            ['id' => 'b', 'criteria_passed' => 5, 'change_size' => 50],
            ['id' => 'c', 'criteria_passed' => 4, 'change_size' => 5],
        ]);

        $this->assertSame(0, $exit);
        $this->assertSame('atlas.loop.criterion_stability_select.v1', $d['schema']);
        $this->assertSame('b', $d['selected_id'], (string) json_encode($d)); // 5 criteria beats 4 and 3
    }

    public function test_tie_breaks_to_smaller_change(): void
    {
        ['d' => $d] = $this->select([
            ['id' => 'a', 'criteria_passed' => 5, 'change_size' => 20],
            ['id' => 'b', 'criteria_passed' => 5, 'change_size' => 5], // same criteria, smaller change
        ]);

        $this->assertSame('b', $d['selected_id'], (string) json_encode($d));
    }

    public function test_empty_set_selects_nothing(): void
    {
        ['exit' => $exit, 'd' => $d] = $this->select([]);

        $this->assertSame(0, $exit);
        $this->assertNull($d['selected']);
        $this->assertNull($d['selected_id']);
    }

    public function test_missing_candidates_is_usage_error(): void
    {
        $exit = Artisan::call('atlas:loop:stable-select', ['--json' => true]);

        $this->assertNotSame(0, $exit);
        $this->assertStringContainsString('usage_error', Artisan::output());
    }
}
