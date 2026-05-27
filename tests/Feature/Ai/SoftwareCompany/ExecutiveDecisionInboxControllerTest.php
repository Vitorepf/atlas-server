<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\SoftwareCompany;

use Tests\TestCase;

/**
 * Feature tests for the Autonomous Executive Decision Inbox surface (AP-736).
 *
 * Read-only HTTP surface. The GET must never record a decision, execute work,
 * create a branch, merge, deploy or touch secrets.
 */
final class ExecutiveDecisionInboxControllerTest extends TestCase
{
    private const PATH = '/ai/software-company-stewardship/executive-decision-inbox/atlas_software_company';

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

    public function test_valid_portfolio_returns_canonical_decision_inbox_surface(): void
    {
        $response = $this->getJson(self::PATH, $this->headers);

        $response->assertStatus(200)
            ->assertJsonPath('schema_version', 'atlas.autonomous_executive.decision_inbox_surface.v1')
            ->assertJsonPath('portfolio_id', 'atlas_software_company')
            ->assertJsonPath('read_only', true)
            ->assertJsonPath('claim_policy.surface_only', true)
            ->assertJsonPath('operator_controls.irreversible_action_allowed', false);

        $response->assertJsonStructure([
            'schema_version',
            'status',
            'portfolio_id',
            'source_ap_contracts',
            'decision_summary' => ['total', 'pending_operator_review'],
            'items',
            'operator_controls' => ['decision_options', 'stable_anchor_rule', 'decision_command'],
            'surface_hash',
        ]);
    }

    public function test_get_performs_no_mutation(): void
    {
        $response = $this->getJson(self::PATH, $this->headers)->assertStatus(200);

        $response->assertJsonPath('claim_policy.writes_local_state', false)
            ->assertJsonPath('claim_policy.provider_invoked', false)
            ->assertJsonPath('claim_policy.dev_invoked', false)
            ->assertJsonPath('claim_policy.forge_invoked', false)
            ->assertJsonPath('claim_policy.opens_branch', false)
            ->assertJsonPath('claim_policy.merges', false)
            ->assertJsonPath('claim_policy.deploys', false);

        $first = $response->json('surface_hash');
        $second = $this->getJson(self::PATH, $this->headers)->json('surface_hash');
        $this->assertSame($first, $second);
    }

    public function test_etag_supports_conditional_get(): void
    {
        $response = $this->getJson(self::PATH, $this->headers)->assertStatus(200);
        $etag = $response->headers->get('ETag');
        $this->assertNotEmpty($etag);

        $this->getJson(self::PATH, array_merge($this->headers, ['If-None-Match' => $etag]))
            ->assertStatus(304);
    }

    public function test_unknown_pack_returns_blocked_surface(): void
    {
        $this->getJson(self::PATH.'?pack_id=aer_missing', $this->headers)
            ->assertStatus(409)
            ->assertJsonPath('status', 'blocked')
            ->assertJsonPath('reason', 'executive_recommendation_pack_not_ready');
    }
}
