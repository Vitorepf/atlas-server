<?php

namespace Tests\Feature\Ai\Evidence;

use App\Models\AiArtifact;
use App\Models\AiAuditEvent;
use App\Models\AiCertification;
use App\Models\AiEvidencePack;
use App\Models\AiReceipt;
use App\Models\AiSourceRef;
use App\Models\AiTestResult;
use App\Services\Ai\Evidence\CertificationRuntimeService;
use Tests\Concerns\CreatesEvidenceRuntimeTables;
use Tests\TestCase;

class EvidenceRuntimeSmokeTest extends TestCase
{
    use CreatesEvidenceRuntimeTables;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createEvidenceRuntimeTables();
    }

    protected function tearDown(): void
    {
        $this->dropEvidenceRuntimeTables();
        parent::tearDown();
    }

    public function test_smoke_action_runs_end_to_end_evidence_flow_with_passed_certification(): void
    {
        $exit = $this->artisan('atlas:ai:evidence', [
            '--action' => 'smoke',
            '--json' => true,
        ])->run();

        $this->assertSame(0, $exit, 'smoke action did not exit 0');

        $this->assertGreaterThan(0, AiArtifact::query()->count());
        $this->assertGreaterThan(0, AiSourceRef::query()->count());
        $this->assertGreaterThan(0, AiTestResult::query()->count());
        $this->assertGreaterThan(0, AiReceipt::query()->count());
        $this->assertGreaterThan(0, AiEvidencePack::query()->count());

        $certification = AiCertification::query()->latest('created_at')->first();
        $this->assertNotNull($certification, 'smoke should produce a certification');
        $this->assertSame(CertificationRuntimeService::STATUS_PASSED, $certification->status);
        $this->assertNotNull($certification->certified_at);
        $this->assertNotEmpty($certification->evidence_refs);

        $audited = AiAuditEvent::query()->pluck('event_type')->unique()->all();
        foreach (['evidence_attached', 'receipt_emitted', 'certification_passed'] as $expected) {
            $this->assertContains($expected, $audited, "expected audit event type [{$expected}]");
        }
    }
}
