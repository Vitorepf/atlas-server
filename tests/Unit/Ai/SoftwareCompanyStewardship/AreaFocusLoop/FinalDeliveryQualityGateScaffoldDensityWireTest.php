<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\FinalDeliveryQualityGateService;
use Tests\TestCase;

/**
 * WIRE-OBSERVE pin: assess() now records an advisory `scaffold_density`
 * envelope graded from the ADDED lines (files vs baseline). The final/blocker
 * verdict is untouched: marker-free commentary walls are only measured, and a
 * marker-carrying delivery still blocks exactly as before.
 */
final class FinalDeliveryQualityGateScaffoldDensityWireTest extends TestCase
{
    private const BASELINE = "<?php\n\nfinal class Widget\n{\n    public function go(): int\n    {\n        return 1;\n    }\n}\n";

    public function test_marker_free_commentary_wall_is_measured_without_blocking(): void
    {
        $updated = "<?php\n\nfinal class Widget\n{\n"
            ."    // explains the flow in prose\n"
            ."    // more prose about the flow\n"
            ."    // yet more prose\n"
            ."    public function go(): int\n    {\n"
            ."        \$x = 2;\n"
            ."        return \$x;\n"
            ."    }\n}\n";

        $result = (new FinalDeliveryQualityGateService)->assess(
            ['app/Domain/Widget.php' => $updated],
            ['app/Domain/Widget.php' => self::BASELINE],
        );

        $this->assertTrue($result['final'], 'no self-declared marker => verdict unchanged');
        $this->assertNull($result['blocker']);

        $density = $result['scaffold_density'];
        $this->assertIsArray($density);
        $this->assertSame('atlas.software_company_stewardship.scaffold_density.v1', $density['schema_version']);
        // Added lines vs baseline multiset: 3 commentary + 2 behavioural => 3/5.
        $this->assertSame(3, $density['commentary_line_count']);
        $this->assertSame(2, $density['real_logic_line_count']);
        $this->assertSame(0.6, $density['scaffold_density']);
        $this->assertSame('thin', $density['band']);
        $this->assertFalse($density['filler_only']);
    }

    public function test_marker_delivery_still_blocks_and_density_records_the_marker(): void
    {
        $updated = str_replace(
            "    public function go(): int\n",
            "    // TODO finish the wiring\n    public function go(): int\n",
            self::BASELINE,
        );

        $result = (new FinalDeliveryQualityGateService)->assess(
            ['app/Domain/Widget.php' => $updated],
            ['app/Domain/Widget.php' => self::BASELINE],
        );

        // Existing hard law byte-identical: new TODO marker blocks the merge.
        $this->assertFalse($result['final']);
        $this->assertSame(FinalDeliveryQualityGateService::BLOCKER, $result['blocker']);

        $density = $result['scaffold_density'];
        $this->assertIsArray($density);
        $this->assertSame(1, $density['marker_line_count']);
        $this->assertSame('hollow', $density['band'], 'only added line is a marker => filler_only hollow');
        $this->assertTrue($density['filler_only']);
    }
}
