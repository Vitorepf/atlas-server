<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\ProductMode\Support;

use App\Services\Ai\SoftwareCompanyStewardship\ProductMode\Support\ProductModeOperationalControlsProjectionSupport as Support;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Pure Support peel for Product Mode operational controls projection — no I/O, no service, no DB.
 */
final class ProductModeOperationalControlsProjectionSupportTest extends TestCase
{
    private const SUPPORT_PATH = 'app/Services/Ai/SoftwareCompanyStewardship/ProductMode/Support/ProductModeOperationalControlsProjectionSupport.php';

    public function test_support_peel_path_and_static_surface(): void
    {
        // tests/Unit/Ai/SoftwareCompanyStewardship/ProductMode/Support → repo root = 6 levels
        $abs = dirname(__DIR__, 6).'/'.self::SUPPORT_PATH;
        $this->assertFileExists($abs, 'Support peel must live at '.self::SUPPORT_PATH);

        $ref = new ReflectionClass(Support::class);
        foreach ([
            'project',
            'controlReceiptPolicy',
            'repoOnboarding',
            'safetyControls',
            'autonomyTiers',
            'budgetPolicy',
            'branchReviewCenter',
            'branchFromReviewPacket',
            'evidenceInspector',
            'riskPolicy',
            'reviewRequired',
            'nextActions',
            'claimPolicy',
            'operatorControls',
            'hashIdentity',
            'withControlsHash',
        ] as $method) {
            $this->assertTrue($ref->hasMethod($method), $method);
            $m = $ref->getMethod($method);
            $this->assertTrue($m->isPublic());
            $this->assertTrue($m->isStatic());
        }
    }

    public function test_claim_policy_is_read_only_projection_only(): void
    {
        $policy = Support::claimPolicy();

        $this->assertTrue($policy['read_only']);
        $this->assertFalse($policy['writes_repo']);
        $this->assertFalse($policy['authorizes_repository']);
        $this->assertFalse($policy['changes_autonomy_tier']);
        $this->assertFalse($policy['creates_branch']);
        $this->assertFalse($policy['invokes_provider']);
        $this->assertFalse($policy['invokes_dev']);
        $this->assertFalse($policy['invokes_forge']);
        $this->assertTrue($policy['applies_control_receipts_as_projection_only']);
    }

    public function test_project_defaults_to_review_until_evidence_complete(): void
    {
        $payload = Support::project();

        $this->assertSame(Support::SCHEMA, $payload['schema_version']);
        $this->assertSame(Support::STATUS_REVIEW, $payload['status']);
        $this->assertSame('atlas-server', $payload['repo_onboarding']['repository']);
        $this->assertTrue($payload['repo_onboarding']['is_authorized']);
        $this->assertSame('incomplete', $payload['evidence_inspector']['inspector_status']);
        $this->assertStringStartsWith('sha256:', $payload['controls_hash']);
        $this->assertArrayNotHasKey('generated_at', $payload);
    }

    public function test_project_ready_when_evidence_complete(): void
    {
        $payload = Support::project('agentic_engineering_os', 'atlas_software_company', [
            'evidence_refs' => [
                'docs_health',
                'architecture_validate',
                'focused_tests',
                'owner_sandbox_runtime_run',
                'owner_runtime_result',
            ],
        ]);

        $this->assertSame(Support::STATUS_READY, $payload['status']);
        $this->assertSame([], $payload['blockers']);
        $this->assertTrue($payload['evidence_inspector']['completion_claim_allowed']);
        $this->assertContains(
            'Keep irreversible actions behind operator approval and owner runtime gates.',
            $payload['next_actions'],
        );
    }

    public function test_blocks_unauthorized_repo_and_tier_over_policy(): void
    {
        $payload = Support::project('aeos', 'portfolio', [
            'repo' => 'blackink',
            'repo_authorization_status' => 'not_authorized',
            'authorized_repositories' => ['atlas-server'],
            'autonomy_tier' => 5,
            'max_allowed_autonomy_tier' => 2,
        ]);

        $this->assertSame(Support::STATUS_BLOCKED, $payload['status']);
        $this->assertContains('repository_not_authorized_for_product_mode', $payload['blockers']);
        $this->assertContains('requested_autonomy_tier_exceeds_current_policy', $payload['blockers']);
        $this->assertSame('blocked', $payload['autonomy_tiers']['tier_status']);
    }

    public function test_safety_and_budget_blockers_and_kill_switch_caps_tier(): void
    {
        $payload = Support::project('aeos', 'portfolio', [
            'kill_switch' => true,
            'rate_limited' => true,
            'cycle_budget' => 1,
            'used_cycles' => 2,
            'branch_wip_limit' => 1,
            'active_branch_count' => 2,
            'provider_call_limit' => 0,
            'provider_calls_used' => 1,
        ]);

        $this->assertSame(Support::STATUS_BLOCKED, $payload['status']);
        $this->assertContains('product_mode_kill_switch_active', $payload['blockers']);
        $this->assertContains('product_mode_rate_limited', $payload['blockers']);
        $this->assertContains('cycle_budget_exceeded', $payload['blockers']);
        $this->assertContains('branch_wip_limit_exceeded', $payload['blockers']);
        $this->assertContains('provider_call_budget_exceeded', $payload['blockers']);
        $this->assertSame(0, $payload['autonomy_tiers']['max_allowed_tier']);
    }

    public function test_branch_review_center_maps_ap780_packets(): void
    {
        $center = Support::branchReviewCenter([
            'branch_review_packets' => [
                [
                    'schema_version' => 'atlas.software_company_stewardship.branch_review_packet.v1',
                    'ap_contract' => 'AP-780',
                    'status' => 'auto_merge_candidate',
                    'branch_identity' => [
                        'branch_ref' => 'atlas/area-focus/docs-safe',
                        'base_ref' => 'main',
                        'branch_commit' => 'branch123',
                        'base_commit' => 'base123',
                    ],
                    'gitkraken_review_surface' => [
                        'visible_branch_ref' => 'atlas/area-focus/docs-safe',
                        'visible_base_ref' => 'main',
                        'changed_files' => ['docs/README.md'],
                        'reviewable_commits' => [['short_hash' => 'abc1234', 'subject' => 'Docs']],
                    ],
                    'cycle_traceability' => ['finding_id' => 'finding_001'],
                    'decision_options' => [['decision' => 'execute_policy_gated_auto_merge']],
                    'packet_hash' => 'sha256:packet',
                    'blockers' => [],
                ],
                [
                    'status' => 'ready_for_operator_review',
                    'branch_identity' => ['branch_ref' => 'atlas/area-focus/code-review'],
                    'gitkraken_review_surface' => [],
                    'blockers' => [],
                ],
            ],
        ]);

        $this->assertSame(2, $center['branch_count']);
        $this->assertSame(2, $center['review_packet_count']);
        $this->assertSame(1, $center['auto_merge_candidate_count']);
        $this->assertSame(1, $center['ready_for_operator_review_count']);
        $this->assertSame('atlas/area-focus/docs-safe', $center['branches'][0]['branch_ref']);
        $this->assertFalse($center['merge_allowed_from_product_mode']);
    }

    public function test_blocked_packet_sets_branch_review_blocker(): void
    {
        $center = Support::branchReviewCenter([
            'branch_review_packets' => [
                [
                    'status' => 'blocked',
                    'branch_identity' => ['branch_ref' => 'atlas/area-focus/conflict'],
                    'blockers' => ['merge_conflict_detected'],
                ],
            ],
        ]);

        $this->assertSame(['branch_review_packet_blocked'], $center['blockers']);
        $this->assertSame(1, $center['blocked_branch_count']);
    }

    public function test_control_receipt_policy_merges_inner_policy_status(): void
    {
        $empty = Support::controlReceiptPolicy([]);
        $this->assertSame('not_attached', $empty['status']);
        $this->assertSame([], $empty['policy']);

        $applied = Support::controlReceiptPolicy([
            'control_policy' => [
                'applied_decision_ids' => ['d1', 'd2'],
                'policy' => ['kill_switch' => true],
                'policy_hash' => 'sha256:custom',
            ],
        ]);
        $this->assertSame('applied', $applied['status']);
        $this->assertSame(2, $applied['applied_receipt_count']);
        $this->assertTrue($applied['policy']['kill_switch']);
        $this->assertSame('sha256:custom', $applied['policy_hash']);
    }

    public function test_hash_identity_strips_volatile_fields_and_is_stable(): void
    {
        $first = Support::project();
        $second = Support::project();

        $this->assertSame($first['controls_hash'], $second['controls_hash']);

        $identity = Support::hashIdentity($first + ['generated_at' => '2020-01-01T00:00:00+00:00']);
        $this->assertArrayNotHasKey('generated_at', $identity);
        $this->assertArrayNotHasKey('controls_hash', $identity);
    }

    public function test_review_required_from_pending_or_incomplete_evidence(): void
    {
        $this->assertTrue(Support::reviewRequired(['pending_review_count' => 1]));
        $this->assertTrue(Support::reviewRequired(['inspector_status' => 'incomplete']));
        $this->assertFalse(Support::reviewRequired(
            ['pending_review_count' => 0],
            ['inspector_status' => 'complete'],
        ));
    }
}
