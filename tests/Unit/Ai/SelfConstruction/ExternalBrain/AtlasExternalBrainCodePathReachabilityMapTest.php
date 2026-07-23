<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainCodePathReachabilityMap;
use Tests\TestCase;

final class AtlasExternalBrainCodePathReachabilityMapTest extends TestCase
{
    private function map(): AtlasExternalBrainCodePathReachabilityMap
    {
        return new AtlasExternalBrainCodePathReachabilityMap;
    }

    public function test_schema_present(): void
    {
        $r = $this->map()->map(['target' => ['symbol' => 'Foo']]);
        $this->assertSame(AtlasExternalBrainCodePathReachabilityMap::SCHEMA, $r['schema']);
    }

    // ── unreferenced target is deletion-eligible ──────────────────────────────

    public function test_no_references_is_unreachable_and_deletion_eligible(): void
    {
        $r = $this->map()->map(['target' => ['symbol' => 'DeadService']]);

        $this->assertFalse($r['reachable']);
        $this->assertSame([], $r['reachable_via']);
        $this->assertFalse($r['unknown_reachability']);
        $this->assertTrue($r['deletion_eligible']);
    }

    // ── AC: reachable when any route, command, job, consumer or test references it ──

    public function test_route_reference_marks_reachable_and_blocks_deletion(): void
    {
        $r = $this->map()->map(['target' => ['symbol' => 'RouteHandler', 'route_reference_found' => true]]);

        $this->assertTrue($r['reachable']);
        $this->assertContains('route', $r['reachable_via']);
        $this->assertFalse($r['deletion_eligible']);
    }

    public function test_command_reference_marks_reachable(): void
    {
        $r = $this->map()->map(['target' => ['symbol' => 'CmdHandler', 'command_reference_found' => true]]);

        $this->assertTrue($r['reachable']);
        $this->assertContains('command', $r['reachable_via']);
        $this->assertFalse($r['deletion_eligible']);
    }

    public function test_job_reference_marks_reachable(): void
    {
        $r = $this->map()->map(['target' => ['symbol' => 'ScheduledJob', 'job_reference_found' => true]]);

        $this->assertTrue($r['reachable']);
        $this->assertContains('job', $r['reachable_via']);
        $this->assertFalse($r['deletion_eligible']);
    }

    public function test_consumer_reference_marks_reachable(): void
    {
        $r = $this->map()->map(['target' => ['symbol' => 'QueueConsumer', 'consumer_reference_found' => true]]);

        $this->assertTrue($r['reachable']);
        $this->assertContains('consumer', $r['reachable_via']);
        $this->assertFalse($r['deletion_eligible']);
    }

    public function test_test_reference_marks_reachable(): void
    {
        $r = $this->map()->map(['target' => ['symbol' => 'TestedHelper', 'test_reference_found' => true]]);

        $this->assertTrue($r['reachable']);
        $this->assertContains('test', $r['reachable_via']);
        $this->assertFalse($r['deletion_eligible']);
    }

    public function test_multiple_entry_points_all_listed_in_reachable_via(): void
    {
        $r = $this->map()->map(['target' => [
            'symbol' => 'MultiRef',
            'route_reference_found' => true,
            'test_reference_found' => true,
        ]]);

        $this->assertTrue($r['reachable']);
        $this->assertContains('route', $r['reachable_via']);
        $this->assertContains('test', $r['reachable_via']);
        $this->assertCount(2, $r['reachable_via']);
    }

    // ── AC: unknown dynamic references block deletion regardless of static facts ──

    public function test_dynamic_reference_suspected_produces_unknown_reachability_and_blocks_deletion(): void
    {
        $r = $this->map()->map(['target' => [
            'symbol' => 'ReflectedTarget',
            'dynamic_reference_suspected' => true,
        ]]);

        $this->assertTrue($r['unknown_reachability']);
        $this->assertFalse($r['deletion_eligible']);
    }

    public function test_dynamic_reference_suspected_blocks_deletion_even_with_no_static_references(): void
    {
        // Zero static hits does NOT make it safe once dynamic reference is suspected.
        $r = $this->map()->map(['target' => [
            'symbol' => 'MaybeReflected',
            'dynamic_reference_suspected' => true,
            'route_reference_found' => false,
            'command_reference_found' => false,
            'job_reference_found' => false,
            'consumer_reference_found' => false,
            'test_reference_found' => false,
        ]]);

        $this->assertFalse($r['reachable']);
        $this->assertTrue($r['unknown_reachability']);
        $this->assertFalse($r['deletion_eligible']);
    }

    public function test_dynamic_reference_suspected_alongside_static_hit_still_reports_reachable(): void
    {
        $r = $this->map()->map(['target' => [
            'symbol' => 'HybridTarget',
            'dynamic_reference_suspected' => true,
            'route_reference_found' => true,
        ]]);

        $this->assertTrue($r['reachable']);
        $this->assertTrue($r['unknown_reachability']);
        $this->assertFalse($r['deletion_eligible']);
    }

    // ── determinism ───────────────────────────────────────────────────────────

    public function test_map_is_deterministic(): void
    {
        $facts = ['target' => ['symbol' => 'Stable', 'test_reference_found' => true]];
        $this->assertSame(
            json_encode($this->map()->map($facts)),
            json_encode($this->map()->map($facts)),
        );
    }

    public function test_missing_target_defaults_to_unreachable_deletion_eligible(): void
    {
        $r = $this->map()->map([]);

        $this->assertFalse($r['reachable']);
        $this->assertFalse($r['unknown_reachability']);
        $this->assertTrue($r['deletion_eligible']);
    }
}
