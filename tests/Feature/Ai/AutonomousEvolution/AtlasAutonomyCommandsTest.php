<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\AutonomousEvolution;

use App\Services\Ai\AutonomousEvolution\AtlasLoopMasterSwitch;
use App\Services\Ai\NightShift\AtlasNightShiftAreaFocusContractRegistry;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AreaFocusOperatorDecisionService;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Obra #14 H3.2 — atlas:autonomy:promote / atlas:autonomy:status.
 * Operator-only CLI; no receipt = honest block; never invokes a provider.
 */
class AtlasAutonomyCommandsTest extends TestCase
{
    private const AREA = AtlasNightShiftAreaFocusContractRegistry::AREA_AGENTIC_ENGINEERING_OS;

    private string $switchEnvPath;

    private string $receiptPath;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('atlas_autonomy_tier_promotions');
        (require database_path('migrations/2026_07_06_100000_create_atlas_autonomy_tier_promotions_table.php'))->up();

        $this->switchEnvPath = tempnam(sys_get_temp_dir(), 'atlas-switch-');
        AtlasLoopMasterSwitch::$envPathOverride = $this->switchEnvPath;
        file_put_contents($this->switchEnvPath, AtlasLoopMasterSwitch::KEY."=true\n");

        $this->receiptPath = tempnam(sys_get_temp_dir(), 'atlas-receipt-');
        file_put_contents($this->receiptPath, json_encode([
            'schema_version' => AreaFocusOperatorDecisionService::RECEIPT_SCHEMA,
            'operator_signed' => true,
            'approved' => true,
            'requested_tier' => 1,
            'area_id' => self::AREA,
            'operator_actor' => 'vitor',
            'decision' => 'accept',
        ]));
    }

    protected function tearDown(): void
    {
        AtlasLoopMasterSwitch::$envPathOverride = null;
        @unlink($this->switchEnvPath);
        @unlink($this->receiptPath);
        Schema::dropIfExists('atlas_autonomy_tier_promotions');
        parent::tearDown();
    }

    public function test_promote_with_signed_receipt_promotes_and_status_reports_tier(): void
    {
        $this->artisan('atlas:autonomy:promote', [
            'area' => self::AREA,
            '--receipt' => $this->receiptPath,
            '--json' => true,
        ])->assertExitCode(0);

        $this->artisan('atlas:autonomy:status', ['--json' => true])
            ->expectsOutputToContain('"autonomy_tier_active": 1')
            ->expectsOutputToContain('"operator_signed": true')
            ->assertExitCode(0);
    }

    public function test_promote_without_receipt_blocks_honestly(): void
    {
        $this->artisan('atlas:autonomy:promote', [
            'area' => self::AREA,
            '--json' => true,
        ])->expectsOutputToContain('operator_receipt_signature_missing')
            ->assertExitCode(1);

        $this->artisan('atlas:autonomy:status', ['--json' => true])
            ->expectsOutputToContain('"autonomy_tier_active": 0')
            ->assertExitCode(0);
    }
}
