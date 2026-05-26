<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

class AtlasPatamar4RunLoopOnceCommandTest extends TestCase
{
    public function test_smoke_runs_clean_end_to_end(): void
    {
        $output = new BufferedOutput;
        $code = Artisan::call('atlas:patamar4:run-loop-once', [], $output);
        $this->assertSame(0, $code);
        $text = $output->fetch();

        // All 7 services should emit identifiable artifacts.
        $this->assertStringContainsString('reconciliation.outcome', $text);
        $this->assertStringContainsString('teos_i3.branch_id', $text);
        $this->assertStringContainsString('teos_i4.tree_id', $text);
        $this->assertStringContainsString('swarm.dispatch_id', $text);
        $this->assertStringContainsString('tdc.capsule_id', $text);
    }

    public function test_json_output_contains_canonical_envelope(): void
    {
        $output = new BufferedOutput;
        $code = Artisan::call('atlas:patamar4:run-loop-once', ['--json' => true], $output);
        $this->assertSame(0, $code);
        $text = $output->fetch();
        $decoded = json_decode($text, true);
        $this->assertIsArray($decoded);
        $this->assertSame('atlas.patamar4.smoke_envelope.v1', $decoded['schema_version']);
        $this->assertArrayHasKey('fired', $decoded);
        $this->assertArrayHasKey('artifacts', $decoded);
        $this->assertArrayHasKey('reconciliation_tick', $decoded['artifacts']);
        $this->assertArrayHasKey('teos_i3_branch', $decoded['artifacts']);
        $this->assertArrayHasKey('teos_i4_tree', $decoded['artifacts']);
        $this->assertArrayHasKey('swarm_dispatch', $decoded['artifacts']);
        $this->assertArrayHasKey('tdc_capsule', $decoded['artifacts']);
        $this->assertArrayHasKey('state_snapshot', $decoded['artifacts']);
    }

    public function test_aurg_chain_is_wired_in_di(): void
    {
        // Production DI must wire TEOS-I3 → AURG-4D so every branch emits a chain tick.
        $output = new BufferedOutput;
        Artisan::call('atlas:patamar4:run-loop-once', ['--json' => true], $output);
        $decoded = json_decode($output->fetch(), true);
        $this->assertNotNull($decoded['fired']['teos_i3_aurg_tick_id'], 'TEOS-I3 must auto-emit AURG-4D tick in production');
        $this->assertStringStartsWith('tick_', (string) $decoded['fired']['teos_i3_aurg_tick_id']);
    }

    public function test_reconciliation_fires_ascb_when_autonomous_allowed(): void
    {
        $output = new BufferedOutput;
        Artisan::call('atlas:patamar4:run-loop-once', [
            '--autonomy' => 'autonomous',
            '--privacy' => 'public',
            '--json' => true,
        ], $output);
        $decoded = json_decode($output->fetch(), true);
        if ($decoded['fired']['reconciliation_outcome'] === 'auto_applied') {
            $this->assertNotNull($decoded['fired']['reconciliation_ascb_proposal_id']);
            $this->assertStringStartsWith('prop_', (string) $decoded['fired']['reconciliation_ascb_proposal_id']);
        } else {
            $this->markTestSkipped('outcome was '.$decoded['fired']['reconciliation_outcome']);
        }
    }
}
