<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\SoftwareCompany;

use App\Services\Ai\SoftwareCompanyStewardship\StewardshipEvolution\StewardshipRuntimeResultBridgeService;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class StewardshipRuntimeResultBridgeCommandTest extends TestCase
{
    public function test_cli_closes_the_cycle_with_the_built_in_fixture(): void
    {
        $exitCode = Artisan::call('atlas:software-company-stewardship', [
            'action' => 'runtime-result-bridge',
            '--area' => 'agentic_engineering_os',
            '--fixture' => true,
            '--json' => true,
        ]);

        $this->assertSame(0, $exitCode);

        $output = json_decode(trim(Artisan::output()), true);

        $this->assertIsArray($output);
        $this->assertSame(StewardshipRuntimeResultBridgeService::REPORT_SCHEMA, $output['schema_version']);
        $this->assertSame('AP-765', $output['ap_contract']);
        $this->assertSame(StewardshipRuntimeResultBridgeService::STATUS_READY, $output['status']);
        $this->assertNotSame('', (string) $output['result_bridge_id']);
        $this->assertNotSame('', (string) $output['evidence_pack_id']);
        $this->assertNotSame('', (string) $output['product_mode_event_id']);
        $this->assertNotSame('', (string) $output['portfolio_signal_id']);
        $this->assertFalse($output['acceptance_options']['accept_executes']);
        $this->assertTrue($output['safety_summary']['no_auto_merge']);
        // List payload stays a bridge to detail, never the heavy arrays.
        $this->assertArrayNotHasKey('changed_files', $output['inbox_item']['payload']);
    }

    public function test_cli_blocks_without_result_file_or_fixture(): void
    {
        $exitCode = Artisan::call('atlas:software-company-stewardship', [
            'action' => 'runtime-result-bridge',
            '--area' => 'agentic_engineering_os',
            '--json' => true,
        ]);

        $this->assertSame(1, $exitCode);

        $output = json_decode(trim(Artisan::output()), true);
        $this->assertIsArray($output);
        $this->assertSame('execution_result_required', $output['reason'] ?? $output['error'] ?? '');
    }
}
