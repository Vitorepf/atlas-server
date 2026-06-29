<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Proves the V4 capability-delta attribution is live at the operator surface: a moved dimension is attributed
 * to the proposals that claimed it; a moved-but-unclaimed dimension is surfaced as a confound; mismatched
 * snapshots and zero movement are refused.
 */
final class AtlasLoopV4CapabilityDeltaCommandTest extends TestCase
{
    private string $input = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->input = sys_get_temp_dir().'/atlas-v4-delta-'.bin2hex(random_bytes(5)).'.json';
    }

    protected function tearDown(): void
    {
        @unlink($this->input);
        parent::tearDown();
    }

    private function attribute(array $pre, array $post, array $landed): array
    {
        file_put_contents($this->input, (string) json_encode(['pre' => $pre, 'post' => $post, 'landed_proposals' => $landed]));
        $exit = Artisan::call('atlas:loop:v4-capability-delta', ['--input' => $this->input, '--json' => true]);

        return ['exit' => $exit, 'd' => json_decode(trim(Artisan::output()), true)];
    }

    public function test_moved_dimension_is_attributed_to_claimant(): void
    {
        ['exit' => $exit, 'd' => $d] = $this->attribute(
            ['speed' => 1.0, 'quality' => 2.0],
            ['speed' => 2.0, 'quality' => 2.0],
            [['proposal_id' => 'p1', 'target_dimension' => 'speed']],
        );

        $this->assertSame(0, $exit);
        $this->assertSame('atlas.loop.v4_capability_delta.v1', $d['schema']);
        $this->assertTrue($d['attributed'], (string) json_encode($d));
        $this->assertNull($d['refuse_reason']);
        $this->assertEqualsWithDelta(1.0, $d['deltas']['speed']['delta'], 1e-9);
        $this->assertSame(['p1'], $d['deltas']['speed']['attributed_to']);
        $this->assertSame([], $d['unattributed_dimensions']);
    }

    public function test_moved_unclaimed_dimension_is_a_confound(): void
    {
        ['d' => $d] = $this->attribute(['speed' => 1.0], ['speed' => 2.0], []);

        $this->assertFalse($d['attributed']); // nothing attributed
        $this->assertSame(['speed'], $d['unattributed_dimensions']);
        $this->assertNull($d['refuse_reason']);
    }

    public function test_schema_drift_is_refused(): void
    {
        ['d' => $d] = $this->attribute(['a' => 1.0], ['b' => 1.0], []);

        $this->assertSame('snapshot_schema_drift', $d['refuse_reason']);
        $this->assertFalse($d['attributed']);
    }

    public function test_zero_movement_is_refused(): void
    {
        ['d' => $d] = $this->attribute(['a' => 1.0], ['a' => 1.0], []);

        $this->assertSame('zero_movement', $d['refuse_reason']);
    }

    public function test_missing_input_is_usage_error(): void
    {
        $exit = Artisan::call('atlas:loop:v4-capability-delta', ['--json' => true]);

        $this->assertNotSame(0, $exit);
        $this->assertStringContainsString('usage_error', Artisan::output());
    }
}
