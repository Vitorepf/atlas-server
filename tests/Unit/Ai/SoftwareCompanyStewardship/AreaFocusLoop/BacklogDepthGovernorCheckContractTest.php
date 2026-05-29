<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\BacklogDepthGovernorCheckContract;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\BacklogDepthGovernorService;
use Tests\TestCase;

final class BacklogDepthGovernorCheckContractTest extends TestCase
{
    public function test_dedicated_psr4_contract_file_exists(): void
    {
        $path = app_path('Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/BacklogDepthGovernorCheckContract.php');

        $this->assertFileExists($path);
        $this->assertTrue(class_exists(BacklogDepthGovernorCheckContract::class));
    }

    public function test_default_shape_declares_backlog_depth_governor_check(): void
    {
        $shape = BacklogDepthGovernorCheckContract::defaults()->toArray();

        $this->assertSame(BacklogDepthGovernorCheckContract::SCHEMA, $shape['schema_version']);
        $this->assertSame('backlog_depth_governor_check', $shape['check_id']);
        $this->assertSame(BacklogDepthGovernorService::REPORT_SCHEMA, $shape['governor_report_schema']);
        $this->assertSame(
            'docs/engineering-knowledge-base/atlas-software-company-stewardship-stack.md',
            $shape['stack_canonical'],
        );
        $this->assertSame(BacklogDepthGovernorService::DEFAULT_FLOOR, $shape['default_floor']);
        $this->assertSame('agentic_engineering_os', $shape['area_id']);
        $this->assertSame('dev_forge', $shape['focus']);
        $this->assertSame([
            'packets_count' => BacklogDepthGovernorService::DEFAULT_FLOOR,
            'floor' => BacklogDepthGovernorService::DEFAULT_FLOOR,
            'governor_status' => BacklogDepthGovernorService::STATUS_OK,
            'blocks_24h' => null,
        ], $shape['inputs']);
        $this->assertFalse($shape['outputs']['blocks_24h']);
        $this->assertFalse($shape['outputs']['blocks_supervisor_24h_cycle']);
        $this->assertNull($shape['outputs']['blocker_id']);
    }

    public function test_from_array_blocks_supervisor_24h_cycle_when_governor_blocks_24h(): void
    {
        $shape = BacklogDepthGovernorCheckContract::fromArray([
            'area_id' => 'agentic_engineering_os',
            'focus' => 'dev_forge',
            'packets_count' => 3,
            'floor' => 10,
            'governor_status' => BacklogDepthGovernorService::STATUS_BELOW_FLOOR,
            'blocks_24h' => true,
        ])->toArray();

        $this->assertSame(3, $shape['inputs']['packets_count']);
        $this->assertSame(10, $shape['inputs']['floor']);
        $this->assertSame(BacklogDepthGovernorService::STATUS_BELOW_FLOOR, $shape['inputs']['governor_status']);
        $this->assertTrue($shape['inputs']['blocks_24h']);
        $this->assertTrue($shape['outputs']['blocks_24h']);
        $this->assertTrue($shape['outputs']['blocks_supervisor_24h_cycle']);
        $this->assertSame('packet_depth_below_floor', $shape['outputs']['blocker_id']);
    }

    public function test_from_array_does_not_block_supervisor_when_depth_meets_floor(): void
    {
        $shape = BacklogDepthGovernorCheckContract::fromArray([
            'packets_count' => 12,
            'floor' => 10,
            'governor_status' => BacklogDepthGovernorService::STATUS_OK,
            'blocks_24h' => false,
        ])->toArray();

        $this->assertSame(12, $shape['inputs']['packets_count']);
        $this->assertFalse($shape['outputs']['blocks_24h']);
        $this->assertFalse($shape['outputs']['blocks_supervisor_24h_cycle']);
        $this->assertNull($shape['outputs']['blocker_id']);
    }

    public function test_from_array_derives_blocks_24h_from_packet_depth_when_not_explicit(): void
    {
        $shape = BacklogDepthGovernorCheckContract::fromArray([
            'packets_count' => 4,
            'floor' => 10,
            'governor_status' => BacklogDepthGovernorService::STATUS_BELOW_FLOOR,
        ])->toArray();

        $this->assertNull($shape['inputs']['blocks_24h']);
        $this->assertTrue($shape['outputs']['blocks_24h']);
        $this->assertTrue($shape['outputs']['blocks_supervisor_24h_cycle']);
        $this->assertSame('packet_depth_below_floor', $shape['outputs']['blocker_id']);
    }
}
