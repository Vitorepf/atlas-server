<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\TwentyFourHStewardshipRecoveryUntilConsecutiveMergedCyclesAreNormalContract;
use Tests\TestCase;

final class _24hStewardshipRecoveryUntilConsecutiveMergedCyclesAreNormalContractTest extends TestCase
{
    private const CONTRACT_PATH = __DIR__.'/../../../../../app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/24hStewardshipRecoveryUntilConsecutiveMergedCyclesAreNormalContract.php';

    protected function setUp(): void
    {
        parent::setUp();

        if (! class_exists(TwentyFourHStewardshipRecoveryUntilConsecutiveMergedCyclesAreNormalContract::class, false)) {
            require_once self::CONTRACT_PATH;
        }
    }

    public function test_dedicated_psr4_contract_file_exists(): void
    {
        $this->assertFileExists(self::CONTRACT_PATH);
        $this->assertTrue(
            class_exists(TwentyFourHStewardshipRecoveryUntilConsecutiveMergedCyclesAreNormalContract::class),
        );
    }

    public function test_default_shape_matches_ap790_stewardship_recovery_contract(): void
    {
        $contract = TwentyFourHStewardshipRecoveryUntilConsecutiveMergedCyclesAreNormalContract::defaults();
        $shape = $contract->toArray();

        $this->assertSame(
            TwentyFourHStewardshipRecoveryUntilConsecutiveMergedCyclesAreNormalContract::SCHEMA,
            $shape['schema_version'],
        );
        $this->assertSame('agentic_engineering_os', $shape['area_id']);
        $this->assertSame('dev_forge', $shape['focus']);
        $this->assertSame([
            'last_cycle_index' => 0,
            'merges_total' => 0,
            'blocked_in_row' => 0,
            'consecutive_merged_cycles' => 0,
            'target_consecutive_merged_cycles' => TwentyFourHStewardshipRecoveryUntilConsecutiveMergedCyclesAreNormalContract::DEFAULT_TARGET_CONSECUTIVE_MERGES,
        ], $shape['inputs']);
        $this->assertSame(
            TwentyFourHStewardshipRecoveryUntilConsecutiveMergedCyclesAreNormalContract::MERGE_ELIGIBILITY,
            $shape['merge_eligibility'],
        );
        $this->assertSame([
            'recovery_normal' => false,
            'consecutive_merged_cycles' => 0,
            'blocked_in_row' => 0,
        ], $shape['outputs']);
    }

    public function test_from_array_marks_recovery_normal_when_target_met(): void
    {
        $fromArray = TwentyFourHStewardshipRecoveryUntilConsecutiveMergedCyclesAreNormalContract::fromArray([
            'area_id' => 'atlas_core',
            'focus' => 'self_construction',
            'last_cycle_index' => 3,
            'merges_total' => 2,
            'blocked_in_row' => 0,
            'consecutive_merged_cycles' => 2,
        ])->toArray();

        $this->assertSame('atlas_core', $fromArray['area_id']);
        $this->assertTrue($fromArray['outputs']['recovery_normal']);
    }
}
