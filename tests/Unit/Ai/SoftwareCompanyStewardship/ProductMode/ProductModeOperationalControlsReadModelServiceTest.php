<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\ProductMode;

use App\Services\Ai\SoftwareCompanyStewardship\ProductMode\ProductModeOperationalControlsReadModelService;
use Tests\TestCase;

final class ProductModeOperationalControlsReadModelServiceTest extends TestCase
{
    private function service(): ProductModeOperationalControlsReadModelService
    {
        return app(ProductModeOperationalControlsReadModelService::class);
    }

    public function test_projects_default_atlas_internal_controls_as_review_required_until_evidence_is_attached(): void
    {
        $payload = $this->service()->project('agentic_engineering_os', 'atlas_software_company');

        $this->assertSame(ProductModeOperationalControlsReadModelService::SCHEMA, $payload['schema_version']);
        $this->assertSame(ProductModeOperationalControlsReadModelService::STATUS_REVIEW, $payload['status']);
        $this->assertSame('AP-754', $payload['ap_contract']);
        $this->assertSame('atlas-server', $payload['repo_onboarding']['repository']);
        $this->assertTrue($payload['repo_onboarding']['is_authorized']);
        $this->assertSame(2, $payload['autonomy_tiers']['current_tier']);
        $this->assertSame(2, $payload['autonomy_tiers']['max_allowed_tier']);
        $this->assertSame('allowed', $payload['autonomy_tiers']['tier_status']);
        $this->assertFalse($payload['safety_controls']['kill_switch_active']);
        $this->assertSame('incomplete', $payload['evidence_inspector']['inspector_status']);
        $this->assertContains('focused_tests', $payload['evidence_inspector']['missing_refs']);
        $this->assertStringStartsWith('sha256:', $payload['controls_hash']);

        $this->assertFalse($payload['claim_policy']['writes_repo']);
        $this->assertFalse($payload['claim_policy']['authorizes_repository']);
        $this->assertFalse($payload['claim_policy']['changes_autonomy_tier']);
        $this->assertFalse($payload['claim_policy']['creates_branch']);
        $this->assertFalse($payload['claim_policy']['invokes_provider']);
    }

    public function test_complete_evidence_and_safe_defaults_make_controls_ready(): void
    {
        $payload = $this->service()->project('agentic_engineering_os', 'atlas_software_company', [
            'evidence_refs' => [
                'docs_health',
                'architecture_validate',
                'focused_tests',
                'owner_runtime_result',
            ],
        ]);

        $this->assertSame(ProductModeOperationalControlsReadModelService::STATUS_READY, $payload['status']);
        $this->assertSame([], $payload['blockers']);
        $this->assertSame('complete', $payload['evidence_inspector']['inspector_status']);
        $this->assertTrue($payload['evidence_inspector']['completion_claim_allowed']);
    }

    public function test_blocks_when_repository_is_not_authorized_or_tier_exceeds_policy(): void
    {
        $payload = $this->service()->project('agentic_engineering_os', 'atlas_software_company', [
            'repo' => 'blackink',
            'repo_authorization_status' => 'not_authorized',
            'authorized_repositories' => ['atlas-server'],
            'autonomy_tier' => 5,
            'max_allowed_autonomy_tier' => 2,
        ]);

        $this->assertSame(ProductModeOperationalControlsReadModelService::STATUS_BLOCKED, $payload['status']);
        $this->assertContains('repository_not_authorized_for_product_mode', $payload['blockers']);
        $this->assertContains('requested_autonomy_tier_exceeds_current_policy', $payload['blockers']);
        $this->assertSame('blocked', $payload['autonomy_tiers']['tier_status']);
    }

    public function test_blocks_on_kill_switch_budget_and_rate_limits(): void
    {
        $payload = $this->service()->project('agentic_engineering_os', 'atlas_software_company', [
            'kill_switch' => true,
            'rate_limited' => true,
            'cycle_budget' => 1,
            'used_cycles' => 2,
            'branch_wip_limit' => 1,
            'active_branch_count' => 2,
            'provider_call_limit' => 0,
            'provider_calls_used' => 1,
        ]);

        $this->assertSame(ProductModeOperationalControlsReadModelService::STATUS_BLOCKED, $payload['status']);
        $this->assertContains('product_mode_kill_switch_active', $payload['blockers']);
        $this->assertContains('product_mode_rate_limited', $payload['blockers']);
        $this->assertContains('cycle_budget_exceeded', $payload['blockers']);
        $this->assertContains('branch_wip_limit_exceeded', $payload['blockers']);
        $this->assertContains('provider_call_budget_exceeded', $payload['blockers']);
        $this->assertSame(0, $payload['autonomy_tiers']['max_allowed_tier']);
    }

    public function test_hash_is_stable_across_repeated_projection(): void
    {
        $first = $this->service()->project();
        $second = $this->service()->project();

        $this->assertSame($first['controls_hash'], $second['controls_hash']);
    }
}
