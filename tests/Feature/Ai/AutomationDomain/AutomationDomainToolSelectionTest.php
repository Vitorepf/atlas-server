<?php

namespace Tests\Feature\Ai\AutomationDomain;

use App\Services\Ai\AutomationDomain\AutomationDomainCanon;
use App\Services\Ai\AutomationDomain\ToolSelectionWorkflowService;
use Tests\Concerns\CreatesAutomationDomainTables;
use Tests\TestCase;

class AutomationDomainToolSelectionTest extends TestCase
{
    use CreatesAutomationDomainTables;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createAutomationDomainTables();
    }

    protected function tearDown(): void
    {
        $this->dropAutomationDomainTables();
        parent::tearDown();
    }

    public function test_decides_api_call_when_official_api_available(): void
    {
        $decision = app(ToolSelectionWorkflowService::class)->decide([
            'need' => 'Fetch GitHub repo metadata',
            'capability_id' => 'api.readonly',
            'official_api_available' => true,
            'risk_level' => 'low',
        ]);

        $this->assertContains($decision->decision_kind, [
            AutomationDomainCanon::DECISION_API_CALL,
            AutomationDomainCanon::DECISION_USE_EXISTING,
        ]);
        $this->assertGreaterThan(0.0, $decision->score);
        $this->assertNotEmpty($decision->decision_hash);
        $this->assertNotEmpty($decision->alternatives);
    }

    public function test_security_sensitive_need_downweights_clone_repo_and_buy(): void
    {
        $decision = app(ToolSelectionWorkflowService::class)->decide([
            'need' => 'Sign release artifacts with private key',
            'security_sensitive' => true,
            'open_source_candidate' => [
                'url' => 'https://github.com/example/sign-tool',
                'license' => 'MIT',
            ],
            'saas_candidate' => true,
        ]);

        $this->assertNotSame(AutomationDomainCanon::DECISION_CLONE_REPO, $decision->decision_kind);
        $this->assertNotSame(AutomationDomainCanon::DECISION_BUY_OR_SUBSCRIBE, $decision->decision_kind);
    }

    public function test_critical_risk_promotes_manual_fallback(): void
    {
        $decision = app(ToolSelectionWorkflowService::class)->decide([
            'need' => 'Apply production database schema migration with downtime',
            'risk_level' => 'critical',
        ]);

        $this->assertSame(AutomationDomainCanon::DECISION_MANUAL_FALLBACK, $decision->decision_kind);
    }

    public function test_rejects_incompatible_license_with_do_not_use_in_alternatives(): void
    {
        $decision = app(ToolSelectionWorkflowService::class)->decide([
            'need' => 'Embed proprietary GPL tool',
            'open_source_candidate' => [
                'url' => 'https://github.com/example/gpl-tool',
                'license' => 'GPL-3.0',
            ],
        ]);

        $hasDoNotUse = collect($decision->alternatives)
            ->where('kind', AutomationDomainCanon::DECISION_DO_NOT_USE)
            ->isNotEmpty();
        $this->assertTrue($hasDoNotUse, 'expected GPL repo to appear as do_not_use alternative');
    }

    public function test_decision_hash_stable_for_same_inputs(): void
    {
        $args = [
            'need' => 'Deterministic stable need',
            'capability_id' => 'api.readonly',
            'official_api_available' => true,
            'risk_level' => 'low',
        ];

        $a = app(ToolSelectionWorkflowService::class)->decide($args);
        $b = app(ToolSelectionWorkflowService::class)->decide($args);

        $this->assertSame($a->decision_hash, $b->decision_hash);
    }
}
