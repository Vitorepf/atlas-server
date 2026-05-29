<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\LoopPostCycleAuditorService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\ProviderSpentWithoutMergeContract;
use Tests\TestCase;

final class ProviderSpentWithoutMergeContractTest extends TestCase
{
    public function test_dedicated_psr4_contract_file_exists(): void
    {
        $path = app_path('Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/ProviderSpentWithoutMergeContract.php');

        $this->assertFileExists($path);
        $this->assertTrue(class_exists(ProviderSpentWithoutMergeContract::class));
    }

    public function test_default_shape_declares_provider_spent_without_merge_waste_signal(): void
    {
        $shape = ProviderSpentWithoutMergeContract::defaults()->toArray();

        $this->assertSame(ProviderSpentWithoutMergeContract::SCHEMA, $shape['schema_version']);
        $this->assertSame('provider_spent_without_merge_waste_signal', $shape['contract_id']);
        $this->assertSame('provider_spent_without_merge', $shape['signal_id']);
        $this->assertSame(
            'docs/engineering-knowledge-base/atlas-agentic-engineering-os-implementation-reality.md',
            $shape['implementation_reality_canonical'],
        );
        $this->assertSame(
            LoopPostCycleAuditorService::STATUS_VALID_BLOCK,
            $shape['post_cycle_status_valid_block'],
        );
        $this->assertSame('agentic_engineering_os', $shape['area_id']);
        $this->assertSame('dev_forge', $shape['focus']);
        $this->assertSame([
            'run_id' => '',
            'cycle_index' => 0,
            'provider_invoked' => false,
            'merge_performed' => false,
        ], $shape['inputs']);
        $this->assertSame(
            ProviderSpentWithoutMergeContract::CLASSIFICATION_NO_SPEND_BLOCK,
            $shape['outputs']['blocked_cycle_spend_classification'],
        );
        $this->assertFalse($shape['outputs']['counts_as_provider_waste']);
        $this->assertTrue($shape['outputs']['counts_as_honest_no_spend_block']);
        $this->assertFalse($shape['outputs']['surfaces_wasted_provider_spend']);
    }

    public function test_from_array_classifies_honest_no_spend_block_before_provider(): void
    {
        $shape = ProviderSpentWithoutMergeContract::fromArray([
            'run_id' => 'run-001',
            'cycle_index' => 2,
            'provider_invoked' => false,
            'merge_performed' => false,
        ])->toArray();

        $this->assertSame('run-001', $shape['inputs']['run_id']);
        $this->assertSame(2, $shape['inputs']['cycle_index']);
        $this->assertSame(
            ProviderSpentWithoutMergeContract::CLASSIFICATION_NO_SPEND_BLOCK,
            $shape['outputs']['blocked_cycle_spend_classification'],
        );
        $this->assertFalse($shape['outputs']['counts_as_provider_waste']);
        $this->assertTrue($shape['outputs']['counts_as_honest_no_spend_block']);
        $this->assertFalse($shape['outputs']['surfaces_wasted_provider_spend']);
    }

    public function test_from_array_classifies_provider_wasted_when_provider_ran_without_merge(): void
    {
        $shape = ProviderSpentWithoutMergeContract::fromArray([
            'run_id' => 'run-002',
            'cycle_index' => 3,
            'provider_invoked' => true,
            'merge_performed' => false,
        ])->toArray();

        $this->assertSame(
            ProviderSpentWithoutMergeContract::CLASSIFICATION_PROVIDER_WASTED,
            $shape['outputs']['blocked_cycle_spend_classification'],
        );
        $this->assertTrue($shape['outputs']['counts_as_provider_waste']);
        $this->assertFalse($shape['outputs']['counts_as_honest_no_spend_block']);
        $this->assertTrue($shape['outputs']['surfaces_wasted_provider_spend']);
    }

    public function test_from_array_treats_merged_cycle_as_no_spend_block_not_waste(): void
    {
        $shape = ProviderSpentWithoutMergeContract::fromArray([
            'provider_invoked' => true,
            'merge_performed' => true,
        ])->toArray();

        $this->assertSame(
            ProviderSpentWithoutMergeContract::CLASSIFICATION_NO_SPEND_BLOCK,
            $shape['outputs']['blocked_cycle_spend_classification'],
        );
        $this->assertFalse($shape['outputs']['counts_as_provider_waste']);
    }
}
