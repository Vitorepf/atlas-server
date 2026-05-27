<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\SoftwareCompany;

use App\Services\Ai\SoftwareCompanyStewardship\StewardshipEvolution\StewardshipOutcomeEvidenceBridgeService;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class StewardshipOutcomeEvidenceCommandTest extends TestCase
{
    public function test_cli_exposes_ap740_outcome_evidence_projection(): void
    {
        $exitCode = Artisan::call('atlas:software-company-stewardship', [
            'action' => 'outcome-evidence',
            '--json' => true,
        ]);

        $this->assertSame(0, $exitCode);

        $output = json_decode(trim(Artisan::output()), true);

        $this->assertIsArray($output);
        $this->assertSame(StewardshipOutcomeEvidenceBridgeService::REPORT_SCHEMA, $output['schema_version']);
        $this->assertSame('AP-740', $output['ap_contract']);
        $this->assertFalse($output['record_evidence_requested']);
        $this->assertFalse($output['emit_inbox_requested']);
        $this->assertSame('Atlas Software Company Stewardship Stack', $output['stack']);
    }
}
