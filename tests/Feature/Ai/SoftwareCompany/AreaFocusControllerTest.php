<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\SoftwareCompany;

use Tests\TestCase;

/**
 * Feature tests for the Area Focus Product Mode read surface (AP-721).
 *
 * Read-only HTTP read model. The GET must never mutate, execute, branch, merge
 * or deploy. Auth is the canonical `atlas.token` middleware.
 */
final class AreaFocusControllerTest extends TestCase
{
    private const PATH = '/ai/software-company-stewardship/area-focus/agentic_engineering_os';

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

    public function test_valid_area_returns_canonical_desktop_schema(): void
    {
        $response = $this->getJson(self::PATH, $this->headers);

        $response->assertStatus(200)
            ->assertJsonPath('schema_version', 'atlas.night_shift.area_focus_product_mode_surface.v1')
            ->assertJsonPath('area_id', 'agentic_engineering_os')
            ->assertJsonPath('read_only', true);

        // All nine Desktop-ready sections must be present.
        $response->assertJsonStructure([
            'schema_version',
            'status',
            'area_summary' => ['area_id', 'area_name', 'autonomy_tier', 'owner_docs'],
            'health' => ['overall', 'findings_total', 'high_risk_count', 'wip_pressure'],
            'findings' => ['total', 'by_risk', 'by_route', 'items'],
            'inbox_items',
            'work_orders',
            'budgets' => ['wip_limit', 'wip_used', 'execution_executed'],
            'evidence_packs' => ['required', 'validations_required', 'packs'],
            'kill_switch_state' => ['required', 'engaged', 'controlled_by'],
            'next_actions',
            'surface_hash',
        ]);
    }

    public function test_kill_switch_state_present_and_not_engaged(): void
    {
        $this->getJson(self::PATH, $this->headers)
            ->assertStatus(200)
            ->assertJsonPath('kill_switch_state.required', true)
            ->assertJsonPath('kill_switch_state.engaged', false)
            ->assertJsonPath('kill_switch_state.execution_enabled', false);
    }

    public function test_get_performs_no_mutation(): void
    {
        $response = $this->getJson(self::PATH, $this->headers)->assertStatus(200);

        $response->assertJsonPath('read_only', true)
            ->assertJsonPath('budgets.execution_executed', false)
            ->assertJsonPath('budgets.budget_consumed', false)
            ->assertJsonPath('claim_policy.dispatches_to_dev_or_forge', false)
            ->assertJsonPath('claim_policy.opens_branch', false);

        foreach ($response->json('work_orders') as $order) {
            $this->assertFalse($order['execution_executed']);
            $this->assertSame('planned_pending_operator', $order['status']);
        }

        // Idempotent: a second identical GET yields the same surface hash → no state changed.
        $first = $response->json('surface_hash');
        $second = $this->getJson(self::PATH, $this->headers)->json('surface_hash');
        $this->assertSame($first, $second);
    }

    public function test_unknown_area_returns_stable_error(): void
    {
        $this->getJson('/ai/software-company-stewardship/area-focus/not_a_real_area', $this->headers)
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'unknown_area')
            ->assertJsonPath('error.supported_areas', ['agentic_engineering_os']);
    }

    public function test_etag_supports_conditional_get(): void
    {
        $response = $this->getJson(self::PATH, $this->headers)->assertStatus(200);
        $etag = $response->headers->get('ETag');
        $this->assertNotEmpty($etag);

        $this->getJson(self::PATH, array_merge($this->headers, ['If-None-Match' => $etag]))
            ->assertStatus(304);
    }
}
