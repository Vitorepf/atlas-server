<?php

namespace Tests\Feature\Ai\Cyber;

use App\Services\Ai\Cyber\AppSecReviewService;
use App\Services\Ai\Cyber\CyberDomainException;
use App\Services\Ai\Cyber\GRCMappingService;
use App\Services\Ai\Cyber\RemediationPlanService;
use Tests\Concerns\CreatesCyberRuntimeTables;
use Tests\TestCase;

class CyberDomainAppSecTest extends TestCase
{
    use CreatesCyberRuntimeTables;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createCyberRuntimeTables();
    }

    protected function tearDown(): void
    {
        $this->dropCyberRuntimeTables();
        parent::tearDown();
    }

    public function test_appsec_review_persists_with_required_fields(): void
    {
        $review = app(AppSecReviewService::class)->review([
            'title' => 'AppSec review',
            'target_kind' => AppSecReviewService::TARGET_REPO,
            'target_ref' => 'atlas-server',
            'owasp_categories' => ['A01:2021'],
            'findings' => [['id' => 'f-1', 'title' => 'test']],
            'recommendations' => [['id' => 'r-1']],
            'risk_score' => 5.5,
        ]);
        $this->assertNotEmpty($review->review_hash);
        $this->assertSame(5.5, $review->risk_score);
    }

    public function test_appsec_review_rejects_invalid_risk_score(): void
    {
        $this->expectException(CyberDomainException::class);
        app(AppSecReviewService::class)->review([
            'title' => 'Invalid score',
            'target_kind' => AppSecReviewService::TARGET_REPO,
            'target_ref' => 'x',
            'owasp_categories' => ['A01:2021'],
            'findings' => [],
            'recommendations' => [['id' => 'r-1']],
            'risk_score' => 99.9,
        ]);
    }

    public function test_grc_mapping_accepts_supported_framework(): void
    {
        $mapping = app(GRCMappingService::class)->map([
            'framework' => GRCMappingService::FRAMEWORK_NIST_CSF,
            'control_id' => 'PR.AC-1',
            'control_title' => 'Identities are managed',
            'compliance_status' => GRCMappingService::STATUS_PARTIAL,
        ]);
        $this->assertSame(GRCMappingService::STATUS_PARTIAL, $mapping->compliance_status);
    }

    public function test_grc_mapping_rejects_unknown_framework(): void
    {
        $this->expectException(CyberDomainException::class);
        app(GRCMappingService::class)->map([
            'framework' => 'fake_framework',
            'control_id' => 'X',
            'control_title' => 'Y',
        ]);
    }

    public function test_remediation_plan_requires_findings_actions_owners_timeline(): void
    {
        $plan = app(RemediationPlanService::class)->propose([
            'title' => 'Rotate secret',
            'severity' => RemediationPlanService::SEVERITY_HIGH,
            'findings_refs' => [['id' => 'f-1']],
            'actions' => [['action' => 'rotate']],
            'owners' => [['name' => 'operator']],
            'timeline' => ['due_in_days' => 3],
        ]);
        $this->assertSame(RemediationPlanService::SEVERITY_HIGH, $plan->severity);
    }

    public function test_remediation_plan_rejects_missing_actions(): void
    {
        $this->expectException(CyberDomainException::class);
        app(RemediationPlanService::class)->propose([
            'title' => 'Missing actions',
            'findings_refs' => [['id' => 'f-1']],
            'actions' => [],
            'owners' => [['name' => 'x']],
            'timeline' => ['due_in_days' => 3],
        ]);
    }
}
