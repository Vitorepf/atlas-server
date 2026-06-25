<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Console\Commands\AtlasLoopQuaternityDashboardCommand;
use App\Console\Commands\AtlasLoopReceiptsDashboardCommand;
use App\Console\Commands\AtlasLoopStatusDashboardCommand;
use App\Console\Commands\AtlasLoopSubstrateOverviewCommand;
use App\Console\Commands\AtlasLoopTrinityDashboardCommand;
use App\Services\Ai\AutonomousEvolution\AtlasLoopMasterSwitch;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Proves the atlas:loop:overview composer: with master ON, the output equals the concatenation (with section
 * headers) of the 4 individual render() outputs given the SAME composite snapshot — composition is pure;
 * carries no composite/aggregate scalar score across panels; under master OFF only the master-off banner
 * appears and zero render() calls happen.
 */
final class AtlasLoopSubstrateOverviewCommandTest extends TestCase
{
    private string $envFile;

    protected function setUp(): void
    {
        parent::setUp();
        $this->envFile = sys_get_temp_dir().'/atlas_overview_env_'.bin2hex(random_bytes(6)).'.env';
        AtlasLoopMasterSwitch::$envPathOverride = $this->envFile;
        file_put_contents($this->envFile, "ATLAS_LOOP_MASTER_ENABLED=true\n");
    }

    protected function tearDown(): void
    {
        AtlasLoopMasterSwitch::$envPathOverride = null;
        @unlink($this->envFile);
        parent::tearDown();
    }

    private function frozenComposite(): array
    {
        return [
            'status' => ['queue_depth' => 7, 'in_flight' => 2, 'recent_completion_rate' => '5/10', 'last_10_facts' => []],
            'trinity' => [], // empty ⇒ NO_DATA_MARKER per Trinity dashboard contract
            'quaternity' => ['receipts_count' => 3],
            'receipts' => ['attempts' => [], 'impacts' => [], 'outcomes' => []],
        ];
    }

    public function test_artisan_command_is_registered(): void
    {
        $this->assertArrayHasKey('atlas:loop:overview', Artisan::all());
    }

    public function test_overview_equals_concat_with_headers_of_four_individual_render_outputs(): void
    {
        $composite = $this->frozenComposite();
        $this->app->instance(AtlasLoopSubstrateOverviewCommand::SNAPSHOT_SOURCE_KEY, static fn (): array => $composite);

        $exit = Artisan::call('atlas:loop:overview');
        $overviewOutput = trim(Artisan::output());

        $status = $this->app->make(AtlasLoopStatusDashboardCommand::class)->render($composite['status']);
        $trinity = $this->app->make(AtlasLoopTrinityDashboardCommand::class)->render($composite['trinity']);
        $quaternity = $this->app->make(AtlasLoopQuaternityDashboardCommand::class)->render($composite['quaternity']);
        $receipts = $this->app->make(AtlasLoopReceiptsDashboardCommand::class)->render($composite['receipts']);
        $expected = implode("\n", [
            '=== STATUS ===', $status,
            '=== TRINITY ===', $trinity,
            '=== QUATERNITY ===', $quaternity,
            '=== RECEIPTS ===', $receipts,
        ]);

        $this->assertSame(0, $exit);
        $this->assertSame($expected, $overviewOutput, 'overview composition is pure: equals concat-with-headers of individual render outputs');
    }

    public function test_master_off_emits_only_banner_and_invokes_no_render(): void
    {
        file_put_contents($this->envFile, "ATLAS_LOOP_MASTER_ENABLED=false\n");

        // Track via a snapshot-source spy: if the overview tries to compose, it WILL call the source. The
        // banner-only path bypasses loadComposite() so the source is never queried.
        $sourceCalls = 0;
        $this->app->instance(AtlasLoopSubstrateOverviewCommand::SNAPSHOT_SOURCE_KEY, function () use (&$sourceCalls): array {
            $sourceCalls++;

            return ['status' => [], 'trinity' => [], 'quaternity' => [], 'receipts' => []];
        });

        $exit = Artisan::call('atlas:loop:overview');
        $output = trim(Artisan::output());

        $this->assertSame(0, $exit);
        $this->assertSame(AtlasLoopSubstrateOverviewCommand::MASTER_OFF_BANNER, $output);
        $this->assertSame(0, $sourceCalls, 'master OFF must NEVER invoke the snapshot source');
    }

    public function test_overview_output_carries_no_composite_score_across_panels(): void
    {
        $composite = $this->frozenComposite();
        $this->app->instance(AtlasLoopSubstrateOverviewCommand::SNAPSHOT_SOURCE_KEY, static fn (): array => $composite);

        Artisan::call('atlas:loop:overview');
        $output = trim(Artisan::output());

        // The COMPOSER itself emits exactly 4 panel headers and nothing else aggregate. We assert no
        // composer-level scalar like "overall_score" / "composite_grade" / "score_total" leaks.
        $this->assertStringNotContainsString('overall_score', $output);
        $this->assertStringNotContainsString('composite_grade', $output);
        $this->assertStringNotContainsString('score_total', $output);
    }

    public function test_deterministic_for_same_snapshot(): void
    {
        $composite = $this->frozenComposite();
        $this->app->instance(AtlasLoopSubstrateOverviewCommand::SNAPSHOT_SOURCE_KEY, static fn (): array => $composite);

        Artisan::call('atlas:loop:overview');
        $a = trim(Artisan::output());
        Artisan::call('atlas:loop:overview');
        $b = trim(Artisan::output());
        $this->assertSame($a, $b, 'identical snapshot ⇒ byte-identical frame');
    }

    public function test_composer_renders_panels_in_fixed_order_status_trinity_quaternity_receipts(): void
    {
        $composite = $this->frozenComposite();
        $this->app->instance(AtlasLoopSubstrateOverviewCommand::SNAPSHOT_SOURCE_KEY, static fn (): array => $composite);

        Artisan::call('atlas:loop:overview');
        $output = trim(Artisan::output());

        $posStatus = strpos($output, '=== STATUS ===');
        $posTrinity = strpos($output, '=== TRINITY ===');
        $posQuat = strpos($output, '=== QUATERNITY ===');
        $posReceipts = strpos($output, '=== RECEIPTS ===');
        $this->assertNotFalse($posStatus);
        $this->assertNotFalse($posTrinity);
        $this->assertNotFalse($posQuat);
        $this->assertNotFalse($posReceipts);
        $this->assertLessThan($posTrinity, $posStatus);
        $this->assertLessThan($posQuat, $posTrinity);
        $this->assertLessThan($posReceipts, $posQuat);
    }
}
