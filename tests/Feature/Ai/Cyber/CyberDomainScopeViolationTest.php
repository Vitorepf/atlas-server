<?php

namespace Tests\Feature\Ai\Cyber;

use App\Models\AiCyberEngagement;
use App\Models\AiCyberEvidenceChainEntry;
use App\Models\AiDefensiveSecurityReview;
use App\Services\Ai\Cyber\CyberDomainException;
use App\Services\Ai\Cyber\CyberRuntimeService;
use Tests\Concerns\CreatesCyberRuntimeTables;
use Tests\Concerns\CreatesEvidenceRuntimeTables;
use Tests\TestCase;

class CyberDomainScopeViolationTest extends TestCase
{
    use CreatesCyberRuntimeTables;
    use CreatesEvidenceRuntimeTables;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createEvidenceRuntimeTables();
        $this->createCyberRuntimeTables();
    }

    protected function tearDown(): void
    {
        $this->dropCyberRuntimeTables();
        $this->dropEvidenceRuntimeTables();
        parent::tearDown();
    }

    public function test_out_of_scope_appsec_target_ref_throws_exception(): void
    {
        $this->expectException(CyberDomainException::class);
        $this->expectExceptionMessage('out of scope');

        app(CyberRuntimeService::class)->driveDefensiveReview([
            'engagement' => [
                'title' => 'Test engagement',
                'requester' => 'test-user',
                'targets' => ['api.example.com'],
                'authorization_present' => true,
                'authorization' => ['doc' => 'auth-doc.pdf'],
                'engagement_kind' => 'defensive_review',
            ],
            'scope_rules' => [
                'in_scope_targets' => ['api.example.com'],
                'out_of_scope_targets' => ['api.example.com/admin'],
                'allowed_techniques' => ['reconnaissance'],
                'forbidden_techniques' => ['exploitation'],
                'escalation_contacts' => ['security@example.com'],
            ],
            'appsec_review' => [
                'target_ref' => 'api.example.com/admin',
                'title' => 'Test AppSec',
                'target_kind' => 'api',
                'owasp_categories' => ['A01'],
                'recommendations' => ['fix auth'],
            ],
            'defensive_review' => [
                'title' => 'Test review',
                'review_kind' => 'network_review',
                'scope' => ['api.example.com'],
                'controls_inspected' => ['access_control'],
                'recommendations' => ['fix auth'],
            ],
        ]);
    }

    public function test_out_of_scope_defensive_review_target_throws_exception(): void
    {
        $this->expectException(CyberDomainException::class);
        $this->expectExceptionMessage('out of scope');

        app(CyberRuntimeService::class)->driveDefensiveReview([
            'engagement' => [
                'title' => 'Test engagement',
                'requester' => 'test-user',
                'targets' => ['api.example.com'],
                'authorization_present' => true,
                'authorization' => ['doc' => 'auth-doc.pdf'],
                'engagement_kind' => 'defensive_review',
            ],
            'scope_rules' => [
                'in_scope_targets' => ['api.example.com'],
                'out_of_scope_targets' => ['internal-admin.example.com'],
                'allowed_techniques' => ['reconnaissance'],
                'forbidden_techniques' => ['exploitation'],
                'escalation_contacts' => ['security@example.com'],
            ],
            'appsec_review' => [
                'target_ref' => 'api.example.com',
                'title' => 'Test AppSec',
                'target_kind' => 'api',
                'owasp_categories' => ['A01'],
                'recommendations' => ['fix auth'],
            ],
            'defensive_review' => [
                'title' => 'Test review',
                'review_kind' => 'network_review',
                'scope' => ['api.example.com', 'internal-admin.example.com'],
                'controls_inspected' => ['access_control'],
                'recommendations' => ['fix auth'],
            ],
        ]);
    }

    public function test_in_scope_target_certifies_green(): void
    {
        $result = app(CyberRuntimeService::class)->driveDefensiveReview([
            'engagement' => [
                'title' => 'Test engagement',
                'requester' => 'test-user',
                'targets' => ['api.example.com'],
                'authorization_present' => true,
                'authorization' => ['doc' => 'auth-doc.pdf'],
                'engagement_kind' => 'defensive_review',
            ],
            'scope_rules' => [
                'in_scope_targets' => ['api.example.com'],
                'out_of_scope_targets' => ['internal-admin.example.com'],
                'allowed_techniques' => ['reconnaissance'],
                'forbidden_techniques' => ['exploitation'],
                'escalation_contacts' => ['security@example.com'],
            ],
            'appsec_review' => [
                'target_ref' => 'api.example.com',
                'title' => 'Test AppSec',
                'target_kind' => 'api',
                'owasp_categories' => ['A01'],
                'recommendations' => ['fix auth'],
            ],
            'defensive_review' => [
                'title' => 'Test review',
                'review_kind' => 'network_review',
                'scope' => ['api.example.com'],
                'controls_inspected' => ['access_control'],
                'recommendations' => ['fix auth'],
            ],
        ]);

        $this->assertArrayHasKey('engagement_id', $result);
        $this->assertGreaterThan(0, AiCyberEngagement::query()->count());
        $this->assertGreaterThan(0, AiDefensiveSecurityReview::query()->count());
        $this->assertGreaterThan(0, AiCyberEvidenceChainEntry::query()->count());
    }
}
