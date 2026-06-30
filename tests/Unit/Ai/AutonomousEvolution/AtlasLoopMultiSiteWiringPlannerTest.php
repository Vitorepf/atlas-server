<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution;

use App\Services\Ai\AutonomousEvolution\AtlasLoopMultiSiteWiringPlanner;
use Tests\TestCase;

/**
 * Proves the Multi-Site Wiring Planner: flag-gated (OFF ⇒ empty), one record per (primitive, consumer) pair,
 * status='wired' when the consumer file mentions the primitive class (grep-of-record on the LIVE tree) else
 * 'intended', and read-only/deterministic (no DB/provider — runs offline).
 */
final class AtlasLoopMultiSiteWiringPlannerTest extends TestCase
{
    /** A stub registry source whose LeverageSelector lights one WIRED consumer + one INTENDED consumer. */
    private function stubManifest(): \Closure
    {
        return static fn (): array => [[
            'primitive_id' => 'atlas_loop_leverage_selector',
            'file_path' => 'app/Services/Ai/AutonomousEvolution/AtlasLoopLeverageSelector.php',
            'config_flag' => 'atlas.loop.leverage_first_enabled',
            'status' => 'intended',
            'intended_consumer_paths' => [
                'app/Services/Ai/AutonomousEvolution/AtlasLoopCrossTypeLeverageSelector.php', // references LeverageSelector ⇒ wired
                'app/Services/Ai/AutonomousEvolution/Discovery/AtlasLoopDocGapSupplyLane.php', // does not ⇒ intended
            ],
        ]];
    }

    private function planner(?\Closure $resolver = null): AtlasLoopMultiSiteWiringPlanner
    {
        return new AtlasLoopMultiSiteWiringPlanner(null, $resolver, base_path());
    }

    public function test_flag_off_returns_empty(): void
    {
        config(['atlas.loop.multi_site_wiring_planner_enabled' => false]);

        $this->assertSame([], $this->planner($this->stubManifest())->plan(), 'flag OFF ⇒ byte-identical empty no-op');
    }

    public function test_one_record_per_primitive_consumer_pair_with_wired_status_from_real_tree(): void
    {
        config(['atlas.loop.multi_site_wiring_planner_enabled' => true]);

        $plan = $this->planner($this->stubManifest())->plan();

        $this->assertCount(2, $plan, 'one wiring-intent record per (primitive, consumer) pair');

        $byConsumer = [];
        foreach ($plan as $rec) {
            $this->assertSame('atlas_loop_leverage_selector', $rec['primitive_id']);
            $this->assertArrayHasKey('seam_anchor', $rec);
            $byConsumer[$rec['consumer_path']] = $rec['status'];
        }

        // Acceptance #3: LeverageSelector → CrossTypeLeverageSelector is WIRED (proven against the real tree).
        $this->assertSame('wired', $byConsumer['app/Services/Ai/AutonomousEvolution/AtlasLoopCrossTypeLeverageSelector.php']);
        // A consumer that does not reference the primitive is INTENDED.
        $this->assertSame('intended', $byConsumer['app/Services/Ai/AutonomousEvolution/Discovery/AtlasLoopDocGapSupplyLane.php']);
    }

    public function test_output_is_json_encodable_and_deterministic(): void
    {
        config(['atlas.loop.multi_site_wiring_planner_enabled' => true]);

        $run1 = $this->planner($this->stubManifest())->plan();
        $run2 = $this->planner($this->stubManifest())->plan();

        $json = json_encode($run1);
        $this->assertIsString($json);
        $this->assertNotFalse($json, 'plan output is JSON-encodable for the Intent Ledger');
        $this->assertSame($json, json_encode($run2), 'deterministic (read-only, no DB/provider)');
    }

    public function test_longer_identifier_embedding_basename_is_not_wired(): void
    {
        config(['atlas.loop.multi_site_wiring_planner_enabled' => true]);

        $root = sys_get_temp_dir().'/atlas-wiring-'.bin2hex(random_bytes(4));
        @mkdir($root.'/app', 0o775, true);
        // Consumer mentions only the compound name, NOT the exact class as a standalone token.
        file_put_contents($root.'/app/Consumer.php', "<?php\n// uses AtlasLoopLeverageSelectorFactory\n");

        $manifest = static fn (): array => [[
            'primitive_id' => 'sel',
            'file_path' => 'app/AtlasLoopLeverageSelector.php',
            'status' => 'intended',
            'intended_consumer_paths' => ['app/Consumer.php'],
        ]];

        try {
            $plan = (new AtlasLoopMultiSiteWiringPlanner(null, $manifest, $root))->plan();
            $this->assertSame('intended', $plan[0]['status'],
                'a longer identifier embedding the class basename must not be reported as wired');
        } finally {
            @unlink($root.'/app/Consumer.php');
            @rmdir($root.'/app');
            @rmdir($root);
        }
    }

    public function test_default_registry_path_is_gated_by_the_registry_flag(): void
    {
        // No resolver ⇒ uses the REAL registry. With the planner ON but the registry OFF, the registry yields
        // [] ⇒ no records (proves the real-registry path is wired + still flag-safe, no DB/provider touched).
        config([
            'atlas.loop.multi_site_wiring_planner_enabled' => true,
            'atlas.loop.cross_leverage_registry_enabled' => false,
        ]);

        $this->assertSame([], $this->planner()->plan());
    }
}
