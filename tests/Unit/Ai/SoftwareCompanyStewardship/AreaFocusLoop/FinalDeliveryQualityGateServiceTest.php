<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\FinalDeliveryQualityGateService;
use Tests\TestCase;

/**
 * HARD LAW: a loop cycle may never merge scaffold / mock / non-final code as a
 * completed delivery. These pin the exact markers that block a merge.
 */
final class FinalDeliveryQualityGateServiceTest extends TestCase
{
    private function gate(): FinalDeliveryQualityGateService
    {
        return new FinalDeliveryQualityGateService;
    }

    public function test_step_n_of_m_scaffold_in_product_code_is_not_final(): void
    {
        // The exact pattern the 5 inert contracts shipped.
        $files = [
            'app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/SomeContract.php' =>
                "<?php\n/**\n * Step 1 of 3: shape only — no firewall service wiring in this class.\n */\nfinal class SomeContract {}",
        ];

        $result = $this->gate()->assess($files);

        $this->assertFalse($result['final']);
        $this->assertSame(FinalDeliveryQualityGateService::BLOCKER, $result['blocker']);
        $this->assertNotEmpty($result['violations']);
        $this->assertContains($result['violations'][0]['marker'], ['step_n_of_m', 'shape_only']);
    }

    public function test_todo_fixme_not_implemented_and_placeholder_block(): void
    {
        foreach ([
            "<?php\nclass A { public function x() { /* TODO: finish */ return 1; } }",
            "<?php\nclass A { public function x() { // FIXME later\n return 1; } }",
            "<?php\nclass A { public function x() { throw new \\RuntimeException('not implemented'); } }",
            "<?php\nclass A { public const X = 'placeholder'; }",
        ] as $i => $code) {
            $result = $this->gate()->assess(['app/Services/Ai/A'.$i.'.php' => $code]);
            $this->assertFalse($result['final'], "code variant {$i} must be blocked as non-final");
        }
    }

    public function test_mock_test_doubles_in_product_code_block(): void
    {
        $files = [
            'app/Services/Ai/Foo.php' => "<?php\nclass Foo { public function x() { return \\Mockery::mock(Bar::class); } }",
        ];
        $result = $this->gate()->assess($files);
        $this->assertFalse($result['final']);
        $this->assertSame('mock_in_product', $result['violations'][0]['marker']);
    }

    public function test_mocks_and_scaffold_markers_in_TEST_files_are_allowed(): void
    {
        // Tests legitimately mock and may describe scaffolding — never blocked.
        $files = [
            'tests/Unit/Ai/FooTest.php' =>
                "<?php\nclass FooTest { public function t() { \$m = \\Mockery::mock(Bar::class); /* TODO check */ } }",
            'tests/Feature/BarTest.php' => "<?php\n// Step 1 of 3 scaffold under test\nclass BarTest {}",
        ];
        $result = $this->gate()->assess($files);
        $this->assertTrue($result['final'], 'test files must be exempt from the final-delivery law');
        $this->assertNull($result['blocker']);
        $this->assertSame(0, $result['scanned_product_files']);
    }

    public function test_real_wired_final_product_code_passes(): void
    {
        $files = [
            'app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/RealService.php' =>
                "<?php\nfinal class RealService {\n    public function decide(array \$in): string {\n        return \$in['ok'] ? 'merge' : 'block';\n    }\n}",
        ];
        $result = $this->gate()->assess($files);
        $this->assertTrue($result['final']);
        $this->assertNull($result['blocker']);
        $this->assertSame([], $result['violations']);
        $this->assertSame(1, $result['scanned_product_files']);
    }

    public function test_preexisting_non_final_marker_in_touched_product_file_does_not_block_candidate(): void
    {
        $path = 'app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AlwaysOnLoopSupervisorService.php';
        $baseline = "<?php\nfinal class AlwaysOnLoopSupervisorService {\n    /** Supervisor 24h gating is a future step. */\n    public function status(): string { return 'blocked'; }\n}";
        $candidate = "<?php\nfinal class AlwaysOnLoopSupervisorService {\n    /** Supervisor 24h gating is a future step. */\n    public function status(): string { return 'healthy'; }\n}";

        $result = $this->gate()->assess([$path => $candidate], [$path => $baseline]);

        $this->assertTrue($result['final']);
        $this->assertNull($result['blocker']);
        $this->assertSame([], $result['violations']);
        $this->assertSame(1, $result['scanned_product_files']);
    }

    public function test_new_non_final_marker_in_touched_product_file_still_blocks_candidate(): void
    {
        $path = 'app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AlwaysOnLoopSupervisorService.php';
        $baseline = "<?php\nfinal class AlwaysOnLoopSupervisorService {\n    /** Supervisor 24h gating is a future step. */\n    public function status(): string { return 'blocked'; }\n}";
        $candidate = "<?php\nfinal class AlwaysOnLoopSupervisorService {\n    /** Supervisor 24h gating is a future step. */\n    /** Remaining rules are future work. */\n    public function status(): string { return 'healthy'; }\n}";

        $result = $this->gate()->assess([$path => $candidate], [$path => $baseline]);

        $this->assertFalse($result['final']);
        $this->assertSame(FinalDeliveryQualityGateService::BLOCKER, $result['blocker']);
        $this->assertSame('future_work', $result['violations'][0]['marker']);
    }

    public function test_non_php_files_are_ignored(): void
    {
        $files = ['docs/notes.md' => 'Step 1 of 3: shape only, TODO, placeholder, Mockery'];
        $result = $this->gate()->assess($files);
        $this->assertTrue($result['final']);
        $this->assertSame(0, $result['scanned_product_files']);
    }
}
