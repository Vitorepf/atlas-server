<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainImplementationSurfaceDiscoverer;
use Tests\TestCase;

final class AtlasExternalBrainImplementationSurfaceDiscovererTest extends TestCase
{
    private function discoverer(): AtlasExternalBrainImplementationSurfaceDiscoverer
    {
        return new AtlasExternalBrainImplementationSurfaceDiscoverer;
    }

    // ── AC: saturated surfaces are skipped ──

    public function test_saturated_surface_is_skipped(): void
    {
        $result = $this->discoverer()->discover([
            'surfaces' => [
                ['name' => 'complete', 'has_service_file' => true, 'has_test_file' => true],
            ],
        ]);

        $this->assertSame([], $result['proposed']);
        $this->assertCount(1, $result['skipped']);
        $this->assertSame('saturated', $result['skipped'][0]['reason']);
    }

    // ── AC: missing service-plus-test pairs are proposed ──

    public function test_missing_service_file_is_proposed(): void
    {
        $result = $this->discoverer()->discover([
            'surfaces' => [
                ['name' => 'incomplete', 'has_service_file' => false, 'has_test_file' => true, 'service_path' => 'app/Foo.php', 'test_path' => 'tests/FooTest.php'],
            ],
        ]);

        $this->assertCount(1, $result['proposed']);
        $this->assertSame('incomplete', $result['proposed'][0]['surface']);
        $this->assertContains('service_file', $result['proposed'][0]['missing']);
    }

    public function test_missing_test_file_is_proposed(): void
    {
        $result = $this->discoverer()->discover([
            'surfaces' => [
                ['name' => 'no-test', 'has_service_file' => true, 'has_test_file' => false, 'service_path' => 'app/Bar.php', 'test_path' => 'tests/BarTest.php'],
            ],
        ]);

        $this->assertCount(1, $result['proposed']);
        $this->assertContains('test_file', $result['proposed'][0]['missing']);
    }

    // ── AC: forbidden targets are excluded ──

    public function test_forbidden_target_is_excluded(): void
    {
        $result = $this->discoverer()->discover([
            'surfaces' => [
                ['name' => 'forbidden', 'has_service_file' => false, 'has_test_file' => false],
            ],
            'forbidden_targets' => ['forbidden'],
        ]);

        $this->assertSame([], $result['proposed']);
        $this->assertCount(1, $result['excluded']);
        $this->assertSame('forbidden_target', $result['excluded'][0]['reason']);
    }

    public function test_already_queued_target_is_excluded(): void
    {
        $result = $this->discoverer()->discover([
            'surfaces' => [
                ['name' => 'queued', 'has_service_file' => false, 'has_test_file' => false],
            ],
            'queued_targets' => ['queued'],
        ]);

        $this->assertSame([], $result['proposed']);
        $this->assertCount(1, $result['excluded']);
        $this->assertSame('already_queued', $result['excluded'][0]['reason']);
    }

    // ── output structure ──

    public function test_output_has_required_keys(): void
    {
        $result = $this->discoverer()->discover([]);

        $this->assertSame(AtlasExternalBrainImplementationSurfaceDiscoverer::SCHEMA, $result['schema_version']);
        $this->assertArrayHasKey('proposed', $result);
        $this->assertArrayHasKey('skipped', $result);
        $this->assertArrayHasKey('excluded', $result);
    }

    public function test_result_is_deterministic(): void
    {
        $input = [
            'surfaces' => [
                ['name' => 'b', 'has_service_file' => false, 'has_test_file' => false],
                ['name' => 'a', 'has_service_file' => false, 'has_test_file' => false],
            ],
        ];

        $a = $this->discoverer()->discover($input);
        $b = $this->discoverer()->discover($input);

        $this->assertSame(json_encode($a), json_encode($b));
    }
}
