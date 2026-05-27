<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AreaFocusOperatorDecisionService;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * AP-724 · Area Focus Operator Decision Receipt contract tests.
 *
 * Receipts are pure deterministic builds (no persistence, no provider, no
 * execution); these tests pin the schema, the allowed decisions, the blocking
 * rules and the no-execution guarantees.
 */
class AreaFocusOperatorDecisionServiceTest extends TestCase
{
    private function service(): AreaFocusOperatorDecisionService
    {
        return app(AreaFocusOperatorDecisionService::class);
    }

    /**
     * @param  array<string,mixed>  $over
     * @return array<string,mixed>
     */
    private function input(array $over = []): array
    {
        return array_merge([
            'inbox_item_id' => 'afib_1234567890abcdef',
            'finding_hash' => 'sha256:'.hash('sha256', 'finding-1'),
            'operator_actor' => 'vitor',
            'decision' => 'accept',
            'rationale' => 'looks good',
            'risk' => 'medium',
            'area_id' => 'agentic_engineering_os',
        ], $over);
    }

    public function test_emits_receipt_schema_and_full_envelope(): void
    {
        $r = $this->service()->decide($this->input());

        $this->assertSame(AreaFocusOperatorDecisionService::RECEIPT_SCHEMA, $r['schema_version']);
        $this->assertSame('AP-724', $r['ap_contract']);
        foreach (['decision_id', 'finding_hash', 'operator_actor', 'decision', 'rationale', 'next_allowed_action', 'decision_hash', 'decided_at', 'executed'] as $k) {
            $this->assertArrayHasKey($k, $r, "missing receipt key {$k}");
        }
        $this->assertStringStartsWith('afod_', $r['decision_id']);
        $this->assertStringStartsWith('sha256:', $r['decision_hash']);
    }

    public function test_decision_hash_and_id_are_deterministic(): void
    {
        $first = $this->service()->decide($this->input());
        $second = $this->service()->decide($this->input());

        $this->assertSame($first['decision_hash'], $second['decision_hash']);
        $this->assertSame($first['decision_id'], $second['decision_id']);
    }

    public function test_all_four_decisions_build_with_distinct_next_actions(): void
    {
        $actions = [];
        foreach (['accept', 'reject', 'defer', 'request_changes'] as $decision) {
            $r = $this->service()->decide($this->input(['decision' => $decision, 'rationale' => 'ok']));
            $this->assertSame($decision, $r['decision']);
            $actions[$decision] = $r['next_allowed_action'];
        }
        // Each decision unlocks a distinct stage.
        $this->assertCount(4, array_unique($actions));
    }

    public function test_accept_requires_owner_execution_but_never_executes(): void
    {
        $r = $this->service()->decide($this->input(['decision' => 'accept']));

        $this->assertTrue($r['requires_owner_execution']);
        $this->assertFalse($r['executed']);
        $this->assertFalse($r['autoapproval_allowed']);
        $this->assertFalse($r['autoimplementation_allowed']);
        $this->assertFalse($r['atlas_auto_decided']);
        $this->assertFalse($r['branch_created']);
        $this->assertFalse($r['mutates_target_repo']);
    }

    public function test_reject_does_not_require_owner_execution(): void
    {
        $r = $this->service()->decide($this->input(['decision' => 'reject']));

        $this->assertFalse($r['requires_owner_execution']);
        $this->assertFalse($r['executed']);
        $this->assertSame('close_item_no_action', $r['next_allowed_action']);
    }

    public function test_empty_actor_is_blocked(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/operator_actor_required/');
        $this->service()->decide($this->input(['operator_actor' => '   ']));
    }

    public function test_invalid_decision_is_blocked(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/invalid_decision/');
        $this->service()->decide($this->input(['decision' => 'approve']));
    }

    public function test_item_without_hash_is_blocked(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/item_without_hash/');
        $this->service()->decide($this->input(['finding_hash' => '']));
    }

    public function test_high_risk_accept_requires_rationale(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/rationale_required_for_high_risk_accept/');
        $this->service()->decide($this->input(['decision' => 'accept', 'risk' => 'high', 'rationale' => '']));
    }

    public function test_high_risk_accept_with_rationale_succeeds(): void
    {
        $r = $this->service()->decide($this->input(['decision' => 'accept', 'risk' => 'critical', 'rationale' => 'reviewed blast radius, contained']));

        $this->assertSame('accept', $r['decision']);
        $this->assertSame('critical', $r['risk_level']);
        $this->assertFalse($r['executed']);
    }

    public function test_high_risk_reject_does_not_require_rationale(): void
    {
        // The rationale gate only applies to accept.
        $r = $this->service()->decide($this->input(['decision' => 'reject', 'risk' => 'high', 'rationale' => '']));

        $this->assertSame('reject', $r['decision']);
        $this->assertFalse($r['executed']);
    }

    public function test_optional_anchors_passthrough(): void
    {
        $r = $this->service()->decide($this->input([
            'decision' => 'defer',
            'work_order_id' => 'awo_abc',
            'evidence_pack_hash' => 'sha256:'.hash('sha256', 'pack'),
        ]));

        $this->assertSame('awo_abc', $r['work_order_id']);
        $this->assertSame('sha256:'.hash('sha256', 'pack'), $r['evidence_pack_hash']);
        $this->assertSame('re_review_next_area_focus_cycle', $r['next_allowed_action']);
    }
}
