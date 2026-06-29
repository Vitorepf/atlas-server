<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Proves the extract-sequence planner is live at the operator surface: methods strictly above the tractable
 * threshold are sequenced worst-first (deterministic lexicographic tie-break), the step cap is honored, and a
 * tractable map yields an empty plan.
 */
final class AtlasLoopExtractSequencePlanCommandTest extends TestCase
{
    private string $input = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->input = sys_get_temp_dir().'/atlas-xseq-'.bin2hex(random_bytes(5)).'.json';
    }

    protected function tearDown(): void
    {
        @unlink($this->input);
        parent::tearDown();
    }

    private function plan(array $params): array
    {
        $exit = Artisan::call('atlas:loop:extract-sequence-plan', $params + ['--input' => $this->input, '--json' => true]);

        return ['exit' => $exit, 'd' => json_decode(trim(Artisan::output()), true)];
    }

    public function test_sequences_worst_first_above_threshold(): void
    {
        file_put_contents($this->input, (string) json_encode([
            'C::a' => 15, 'C::b' => 12, 'C::tractable' => 8, 'C::d' => 20,
        ]));

        ['exit' => $exit, 'd' => $d] = $this->plan(['--threshold' => 10, '--max-steps' => 10]);

        $this->assertSame(0, $exit);
        $this->assertSame('atlas.loop.extract_sequence_plan.v1', $d['schema']);
        $this->assertSame(3, $d['step_count'], (string) json_encode($d)); // tractable (8) excluded
        $this->assertSame(['C::d', 'C::a', 'C::b'], array_column($d['plan'], 'target_method'));
        $this->assertSame([20, 15, 12], array_column($d['plan'], 'cyclomatic'));
        $this->assertSame([0, 1, 2], array_column($d['plan'], 'step_index'));
    }

    public function test_tie_break_is_lexicographic_and_cap_honored(): void
    {
        file_put_contents($this->input, (string) json_encode([
            'C::x' => 15, 'C::a' => 15, 'C::m' => 15,
        ]));

        // equal scores ⇒ lexicographic order a, m, x; cap 2 ⇒ only the first two
        ['d' => $d] = $this->plan(['--threshold' => 10, '--max-steps' => 2]);

        $this->assertSame(2, $d['step_count']);
        $this->assertSame(['C::a', 'C::m'], array_column($d['plan'], 'target_method'));
    }

    public function test_all_tractable_yields_empty_plan(): void
    {
        file_put_contents($this->input, (string) json_encode(['C::a' => 3, 'C::b' => 5]));

        ['exit' => $exit, 'd' => $d] = $this->plan(['--threshold' => 10]);

        $this->assertSame(0, $exit);
        $this->assertSame(0, $d['step_count']);
        $this->assertSame([], $d['plan']);
    }

    public function test_missing_input_is_usage_error(): void
    {
        $exit = Artisan::call('atlas:loop:extract-sequence-plan', ['--json' => true]);

        $this->assertNotSame(0, $exit);
        $this->assertStringContainsString('usage_error', Artisan::output());
    }
}
