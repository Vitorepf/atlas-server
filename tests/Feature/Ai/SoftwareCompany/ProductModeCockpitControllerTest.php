<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\SoftwareCompany;

use Tests\TestCase;

/**
 * Feature tests for the Stewardship Product Mode cockpit endpoint (AP-739/AP-742).
 */
final class ProductModeCockpitControllerTest extends TestCase
{
    private const PATH = '/ai/software-company-stewardship/product-mode-cockpit/atlas_software_company';

    /** @var array<string,string> */
    private array $headers = ['X-Atlas-Token' => 'test-token-with-enough-length-123'];

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('atlas.token', 'test-token-with-enough-length-123');
    }

    public function test_requires_atlas_token(): void
    {
        $this->getJson(self::PATH)->assertStatus(401);
    }

    public function test_returns_product_mode_cockpit_aggregate(): void
    {
        $this->getJson(self::PATH, $this->headers)
            ->assertStatus(200)
            ->assertJsonPath('schema_version', 'atlas.software_company.product_mode_cockpit.v1')
            ->assertJsonPath('status', 'ready')
            ->assertJsonPath('ap_contract', 'AP-739')
            ->assertJsonPath('portfolio_id', 'atlas_software_company')
            ->assertJsonPath('read_only', true)
            ->assertJsonPath('stack.name', 'Atlas Software Company Stewardship Stack')
            ->assertJsonPath('stack.not_a_new_os', true)
            ->assertJsonPath('claim_policy.dev_invoked', false)
            ->assertJsonPath('claim_policy.forge_invoked', false)
            ->assertJsonPath('claim_policy.domain_runtime_created', false)
            ->assertJsonPath('claim_policy.product_mode_controls_execute_actions', false)
            ->assertJsonPath('stewardship_outcome_history.schema_version', 'atlas.software_company.stewardship_outcome_bridge.v1')
            ->assertJsonPath('domain_runtime_creation_handoff.schema_version', 'atlas.software_company.self_expanding.domain_runtime_creation_handoff.v1')
            ->assertJsonPath('product_mode_operational_controls.schema_version', 'atlas.software_company.product_mode_operational_controls.v1')
            ->assertJsonPath('product_mode_operational_controls.claim_policy.writes_repo', false)
            ->assertJsonStructure([
                'area_focus',
                'executive_decision_inbox' => ['items', 'decision_summary'],
                'new_area_proposal_gate' => ['gate_items', 'decision_summary'],
                'self_expanding_company' => ['operator_inbox', 'promotion_boundary'],
                'stewardship_outcome_history' => ['evidence_items', 'morning_inbox_items', 'claim_policy'],
                'domain_runtime_creation_handoff' => ['handoff_packets', 'next_actions', 'claim_policy'],
                'product_mode_operational_controls' => ['repo_onboarding', 'autonomy_tiers', 'budget_policy', 'safety_controls', 'branch_review_center', 'evidence_inspector', 'risk_policy', 'claim_policy'],
                'review_queue',
                'operator_controls',
                'surface_hash',
            ]);
    }

    public function test_etag_supports_conditional_get(): void
    {
        $response = $this->getJson(self::PATH, $this->headers)->assertStatus(200);
        $etag = $response->headers->get('ETag');
        $this->assertNotEmpty($etag);

        $this->getJson(self::PATH, array_merge($this->headers, ['If-None-Match' => $etag]))
            ->assertStatus(304);
    }

    public function test_unknown_area_is_blocked(): void
    {
        $this->getJson(self::PATH.'?area=unknown_area', $this->headers)
            ->assertStatus(409)
            ->assertJsonPath('status', 'blocked')
            ->assertJsonPath('reason', 'area_focus_product_mode_blocked');
    }
}
