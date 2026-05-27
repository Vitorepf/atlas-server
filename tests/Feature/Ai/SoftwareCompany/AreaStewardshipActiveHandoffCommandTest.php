<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\SoftwareCompany;

use App\Services\Ai\SoftwareCompanyStewardship\AreaStewardship\AreaStewardshipActiveHandoffService;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

final class AreaStewardshipActiveHandoffCommandTest extends TestCase
{
    public function test_cli_exposes_ap743_area_stewardship_active_handoff_projection(): void
    {
        $exitCode = Artisan::call('atlas:software-company-stewardship', [
            'action' => 'area-stewardship-active-handoff',
            '--json' => true,
        ]);

        $this->assertSame(0, $exitCode);

        $output = json_decode(trim(Artisan::output()), true);

        $this->assertIsArray($output);
        $this->assertSame(AreaStewardshipActiveHandoffService::REPORT_SCHEMA, $output['schema_version']);
        $this->assertSame('AP-743', $output['ap_contract']);
        $this->assertSame('Atlas Software Company Stewardship Stack', $output['stack']);
        $this->assertSame('Atlas Area Stewardship Layer', $output['target_owner']);
        $this->assertFalse($output['record_active_handoff_requested']);
        $this->assertFalse($output['claim_policy']['dev_invoked']);
        $this->assertFalse($output['claim_policy']['forge_invoked']);
        $this->assertFalse($output['claim_policy']['opens_branch']);
        $this->assertFalse($output['claim_policy']['mutates_target_repo']);
    }
}

