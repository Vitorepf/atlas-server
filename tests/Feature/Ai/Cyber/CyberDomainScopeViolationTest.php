<?php

namespace Tests\Feature\Ai\Cyber;

use App\Models\AiCyberEngagement;
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

    private function validPayload(): array
    {
        return [
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
        ];
    }

    public function test_out_of_scope_appsec_target_ref_throws_exception(): void
    {
        $this->expectException(CyberDomainException::class);
        $this->expectExceptionMessage('out of scope');

        $payload = $this->validPayload();
        $payload['scope_rules']['out_of_scope_targets'] = ['api.example.com/admin'];
        $payload['appsec_review']['target_ref'] = 'api.example.com/admin';

        app(CyberRuntimeService::class)->driveDefensiveReview($payload);
    }

    public function test_out_of_scope_defensive_review_target_throws_exception(): void
    {
        $this->expectException(CyberDomainException::class);
        $this->expectExceptionMessage('out of scope');

        $payload = $this->validPayload();
        $payload['defensive_review']['scope'] = ['api.example.com', 'internal-admin.example.com'];

        app(CyberRuntimeService::class)->driveDefensiveReview($payload);
    }

    public function test_in_scope_target_passes_without_exception(): void
    {
        $payload = $this->validPayload();

        // Must not throw.
        $result = app(CyberRuntimeService::class)->driveDefensiveReview($payload);

        // Verify DB records were created.
        $this->assertGreaterThan(0, AiCyberEngagement::query()->count());
        $this->assertGreaterThan(0, AiDefensiveSecurityReview::query()->count());
    }
}
