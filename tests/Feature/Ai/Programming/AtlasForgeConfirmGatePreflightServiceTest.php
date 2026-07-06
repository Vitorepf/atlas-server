<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Programming;

use App\Services\Ai\AutonomousEvolution\AtlasLoopMasterSwitch;
use App\Services\Ai\AutonomousEvolution\AtlasLoopTierPromotionChainService;
use App\Services\Ai\NightShift\AtlasNightShiftAreaFocusContractRegistry;
use App\Services\Ai\Programming\AtlasForgeConfirmGatePreflightService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AreaFocusOperatorDecisionService;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Obra #14 H3.2 · S50 — the preflight is a pure envelope: tier 0 ⇒ ALWAYS
 * block; tier 1 signed + six confirm-gates green ⇒ allow. No provider is
 * ever invoked (there is no provider dependency in the service at all).
 */
class AtlasForgeConfirmGatePreflightServiceTest extends TestCase
{
    private const AREA = AtlasNightShiftAreaFocusContractRegistry::AREA_AGENTIC_ENGINEERING_OS;

    private string $switchEnvPath;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('atlas_autonomy_tier_promotions');
        (require database_path('migrations/2026_07_06_100000_create_atlas_autonomy_tier_promotions_table.php'))->up();

        $this->switchEnvPath = tempnam(sys_get_temp_dir(), 'atlas-switch-');
        AtlasLoopMasterSwitch::$envPathOverride = $this->switchEnvPath;
        file_put_contents($this->switchEnvPath, AtlasLoopMasterSwitch::KEY."=true\n");
    }

    protected function tearDown(): void
    {
        AtlasLoopMasterSwitch::$envPathOverride = null;
        @unlink($this->switchEnvPath);
        Schema::dropIfExists('atlas_autonomy_tier_promotions');
        parent::tearDown();
    }

    public function test_tier_zero_always_blocks_even_with_all_gates_green(): void
    {
        $result = $this->service()->preflight(
            $this->greenGates(),
            ['authorized' => true],
            ['mode' => 'execute'],
            self::AREA,
        );

        $this->assertSame('block', $result['decision']);
        $this->assertFalse($result['execute_allowed']);
        $this->assertSame(0, $result['autonomy_tier_active']);
        $this->assertContains('autonomy_tier_zero', $result['blocking_reasons']);
    }

    public function test_tier_one_signed_plus_six_green_gates_allows_envelope_only(): void
    {
        app(AtlasLoopTierPromotionChainService::class)->promote([
            'schema_version' => AreaFocusOperatorDecisionService::RECEIPT_SCHEMA,
            'operator_signed' => true,
            'approved' => true,
            'requested_tier' => 1,
        ], self::AREA);

        $result = $this->service()->preflight(
            $this->greenGates(),
            ['authorized' => true],
            ['mode' => 'execute'],
            self::AREA,
        );

        $this->assertSame('allow', $result['decision']);
        $this->assertTrue($result['execute_allowed']);
        $this->assertSame(1, $result['autonomy_tier_active']);
        $this->assertSame([], $result['blocking_reasons']);
        $this->assertSame('execute', $result['mode_gate']['mode']);
    }

    public function test_failed_confirm_gate_blocks_even_with_tier_one(): void
    {
        app(AtlasLoopTierPromotionChainService::class)->promote([
            'schema_version' => AreaFocusOperatorDecisionService::RECEIPT_SCHEMA,
            'operator_signed' => true,
            'approved' => true,
            'requested_tier' => 1,
        ], self::AREA);

        $gates = $this->greenGates();
        $gates['kill_switch_clear'] = false;

        $result = $this->service()->preflight($gates, ['authorized' => true], ['mode' => 'execute'], self::AREA);

        $this->assertSame('block', $result['decision']);
        $this->assertContains('confirm_gate_failed:kill_switch_clear', $result['blocking_reasons']);
    }

    private function service(): AtlasForgeConfirmGatePreflightService
    {
        return app(AtlasForgeConfirmGatePreflightService::class);
    }

    /**
     * @return array<string,bool>
     */
    private function greenGates(): array
    {
        return [
            'slice_plan_locked' => true,
            'branch_worktree_ready' => true,
            'validation_commands_resolved' => true,
            'receipt_before_provider' => true,
            'budget_available' => true,
            'kill_switch_clear' => true,
        ];
    }
}
