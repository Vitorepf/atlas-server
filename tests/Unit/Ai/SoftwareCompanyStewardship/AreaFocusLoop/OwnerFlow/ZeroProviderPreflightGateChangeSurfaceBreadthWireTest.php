<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\AreaFocusLoop\OwnerFlow;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\OwnerFlow\ZeroProviderPreflightGate;
use Tests\TestCase;

/**
 * WIRE-OBSERVE pin: evaluate() now records the advisory
 * `change_surface_breadth` envelope (directory_count / breadth_band /
 * breadth_score) next to the scope_file_count/scope_layers it already reports.
 * The admitted/blockers verdict is byte-identical to before.
 */
final class ZeroProviderPreflightGateChangeSurfaceBreadthWireTest extends TestCase
{
    public function test_narrow_scope_is_admitted_and_measured_narrow(): void
    {
        $packet = (new ZeroProviderPreflightGate)->evaluate(
            ['app/Services/Ai/Foo.php', 'tests/Unit/Ai/FooTest.php'],
            ['./vendor/bin/phpunit tests/Unit/Ai/FooTest.php'],
            ['risk_level' => 'medium'],
        );

        $this->assertTrue($packet['admitted']);

        $breadth = $packet['change_surface_breadth'];
        $this->assertIsArray($breadth);
        $this->assertSame('atlas.software_company_stewardship.change_surface_breadth.v1', $breadth['schema_version']);
        $this->assertSame(2, $breadth['file_count']);
        $this->assertSame(1, $breadth['layer_count']);
        $this->assertSame(1, $breadth['directory_count']);
        $this->assertSame('narrow', $breadth['breadth_band']);
        $this->assertTrue($breadth['bounded']);
        $this->assertGreaterThanOrEqual(0.0, $breadth['breadth_score']);
        $this->assertLessThanOrEqual(1.0, $breadth['breadth_score']);
    }

    public function test_multi_layer_scope_still_blocks_and_is_measured_broad(): void
    {
        $packet = (new ZeroProviderPreflightGate)->evaluate(
            ['app/Services/Ai/Foo.php', 'app/Console/Commands/BarCommand.php'],
            ['./vendor/bin/phpunit tests/Unit/Ai/FooTest.php'],
            ['risk_level' => 'medium'],
        );

        // Existing verdict byte-identical: two architectural layers block.
        $this->assertFalse($packet['admitted']);
        $this->assertContains(ZeroProviderPreflightGate::REASON_SCOPE_MULTIPLE_LAYERS, $packet['blockers']);

        $breadth = $packet['change_surface_breadth'];
        $this->assertIsArray($breadth);
        $this->assertSame(2, $breadth['layer_count']);
        $this->assertSame('broad', $breadth['breadth_band']);
        $this->assertFalse($breadth['bounded']);
        $this->assertContains('multiple_layers', $breadth['reasons']);
    }
}
