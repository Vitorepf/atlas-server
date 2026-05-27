<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\SoftwareCompany;

use App\Services\Ai\SoftwareCompanyStewardship\SelfExpanding\SelfExpandingDomainRuntimeCreationHandoffService;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

final class SelfExpandingDomainRuntimeCreationHandoffCommandTest extends TestCase
{
    public function test_cli_exposes_ap741_domain_runtime_creation_handoff_projection(): void
    {
        $exitCode = Artisan::call('atlas:software-company-stewardship', [
            'action' => 'domain-runtime-creation-handoff',
            '--json' => true,
        ]);

        $this->assertSame(0, $exitCode);

        $output = json_decode(trim(Artisan::output()), true);

        $this->assertIsArray($output);
        $this->assertSame(SelfExpandingDomainRuntimeCreationHandoffService::REPORT_SCHEMA, $output['schema_version']);
        $this->assertSame('AP-741', $output['ap_contract']);
        $this->assertSame('Atlas Software Company Stewardship Stack', $output['stack']);
        $this->assertSame('Atlas Domain Runtime Creation Gate', $output['target_owner']);
        $this->assertFalse($output['record_handoff_requested']);
        $this->assertFalse($output['claim_policy']['creates_domain_runtime']);
        $this->assertFalse($output['claim_policy']['registers_domain_manifest']);
    }
}
