<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Proves the feature-sequence planner is live at the operator surface: the human-frozen atoms are partitioned
 * into ordered steps of at most max-step-size, preserving author order; an empty atom set yields no steps.
 */
final class AtlasLoopFeatureSequencePlanCommandTest extends TestCase
{
    private string $input = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->input = sys_get_temp_dir().'/atlas-fseq-'.bin2hex(random_bytes(5)).'.json';
    }

    protected function tearDown(): void
    {
        @unlink($this->input);
        parent::tearDown();
    }

    private function plan(array $params): array
    {
        $exit = Artisan::call('atlas:loop:feature-sequence-plan', $params + ['--input' => $this->input, '--json' => true]);

        return ['exit' => $exit, 'd' => json_decode(trim(Artisan::output()), true)];
    }

    public function test_partitions_atoms_into_ordered_steps(): void
    {
        file_put_contents($this->input, (string) json_encode([
            ['id' => 'a1'], ['id' => 'a2'], ['id' => 'a3'], ['id' => 'a4'], ['id' => 'a5'],
        ]));

        ['exit' => $exit, 'd' => $d] = $this->plan(['--max-step-size' => 2]);

        $this->assertSame(0, $exit);
        $this->assertSame('atlas.loop.feature_sequence_plan.v1', $d['schema']);
        $this->assertSame(3, $d['step_count'], (string) json_encode($d)); // 2+2+1
        $this->assertSame([1, 2, 3], array_column($d['steps'], 'step'));
        $this->assertSame(['a1', 'a2'], array_column($d['steps'][0]['atoms'], 'id'));
        $this->assertSame(['a3', 'a4'], array_column($d['steps'][1]['atoms'], 'id'));
        $this->assertSame(['a5'], array_column($d['steps'][2]['atoms'], 'id'));
    }

    public function test_step_size_one_yields_one_atom_per_step(): void
    {
        file_put_contents($this->input, (string) json_encode([['id' => 'x'], ['id' => 'y'], ['id' => 'z']]));

        ['d' => $d] = $this->plan(['--max-step-size' => 1]);

        $this->assertSame(3, $d['step_count']);
        $this->assertSame(['x'], array_column($d['steps'][0]['atoms'], 'id'));
    }

    public function test_empty_atoms_yields_no_steps(): void
    {
        file_put_contents($this->input, (string) json_encode([]));

        ['exit' => $exit, 'd' => $d] = $this->plan([]);

        $this->assertSame(0, $exit);
        $this->assertSame(0, $d['step_count']);
        $this->assertSame([], $d['steps']);
    }

    public function test_missing_input_is_usage_error(): void
    {
        $exit = Artisan::call('atlas:loop:feature-sequence-plan', ['--json' => true]);

        $this->assertNotSame(0, $exit);
        $this->assertStringContainsString('usage_error', Artisan::output());
    }
}
