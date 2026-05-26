<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Governance;

use App\Services\Ai\Governance\AtlasTrustBudgetService;
use Tests\TestCase;

class AtlasTrustBudgetServiceTest extends TestCase
{
    private string $log;

    private AtlasTrustBudgetService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->log = sys_get_temp_dir().'/atlas_trust_budget_'.uniqid('', true).'.jsonl';
        $this->svc = new AtlasTrustBudgetService;
        $this->svc->setLogPathForTesting($this->log);
    }

    protected function tearDown(): void
    {
        @unlink($this->log);
        parent::tearDown();
    }

    private function baseConsume(array $o = []): array
    {
        return array_merge([
            'tier' => AtlasTrustBudgetService::TIER_MEDIUM,
            'operator_class' => AtlasTrustBudgetService::CLASS_AUTONOMOUS_AGENT,
            'action_kind' => 'ascb_propose',
            'actor' => 'reconciliation_runtime',
            'reason' => 'test consume',
        ], $o);
    }

    public function test_canonical_budget_has_4_tiers_x_3_classes(): void
    {
        $canon = $this->svc->canonicalBudget();
        $this->assertCount(4, $canon);
        foreach (AtlasTrustBudgetService::VALID_TIERS as $tier) {
            $this->assertArrayHasKey($tier, $canon);
            $this->assertCount(3, $canon[$tier]);
            foreach (AtlasTrustBudgetService::VALID_CLASSES as $class) {
                $this->assertArrayHasKey($class, $canon[$tier]);
            }
        }
    }

    public function test_critical_autonomous_cap_is_zero(): void
    {
        $this->assertSame(
            0,
            $this->svc->budgetFor(
                AtlasTrustBudgetService::TIER_CRITICAL,
                AtlasTrustBudgetService::CLASS_AUTONOMOUS_AGENT
            )
        );
    }

    public function test_critical_external_cap_is_zero(): void
    {
        $this->assertSame(
            0,
            $this->svc->budgetFor(
                AtlasTrustBudgetService::TIER_CRITICAL,
                AtlasTrustBudgetService::CLASS_EXTERNAL
            )
        );
    }

    public function test_check_allow_when_remaining(): void
    {
        $v = $this->svc->check(
            AtlasTrustBudgetService::TIER_MEDIUM,
            AtlasTrustBudgetService::CLASS_OPERATOR
        );
        $this->assertSame(AtlasTrustBudgetService::VERDICT_ALLOW, $v['verdict']);
    }

    public function test_check_deny_unknown_tier(): void
    {
        $v = $this->svc->check('mythical_tier', AtlasTrustBudgetService::CLASS_OPERATOR);
        $this->assertSame(AtlasTrustBudgetService::VERDICT_DENY_UNKNOWN_TIER, $v['verdict']);
    }

    public function test_check_deny_unknown_class(): void
    {
        $v = $this->svc->check(AtlasTrustBudgetService::TIER_LOW, 'mythical_class');
        $this->assertSame(AtlasTrustBudgetService::VERDICT_DENY_UNKNOWN_CLASS, $v['verdict']);
    }

    public function test_check_deny_when_critical_autonomous(): void
    {
        $v = $this->svc->check(
            AtlasTrustBudgetService::TIER_CRITICAL,
            AtlasTrustBudgetService::CLASS_AUTONOMOUS_AGENT
        );
        $this->assertSame(AtlasTrustBudgetService::VERDICT_DENY_BUDGET_EXCEEDED, $v['verdict']);
    }

    public function test_consume_records_receipt_with_hash(): void
    {
        $r = $this->svc->consume($this->baseConsume());
        $this->assertSame(AtlasTrustBudgetService::CONSUME_SCHEMA, $r['schema_version']);
        $this->assertStringStartsWith('tba_', $r['action_id']);
        $this->assertStringStartsWith('sha256:', $r['receipt_hash']);
        $this->assertSame(AtlasTrustBudgetService::VERDICT_ALLOW, $r['verdict']);
    }

    public function test_consume_requires_tier_class_kind_actor_reason(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->svc->consume(['tier' => '', 'operator_class' => '']);
    }

    public function test_consume_rejects_unknown_tier(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->svc->consume($this->baseConsume(['tier' => 'mythical']));
    }

    public function test_consume_rejects_unknown_class(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->svc->consume($this->baseConsume(['operator_class' => 'mythical']));
    }

    public function test_state_tracks_consumed_and_remaining(): void
    {
        $this->svc->consume($this->baseConsume());
        $this->svc->consume($this->baseConsume());
        $state = $this->svc->state(
            AtlasTrustBudgetService::TIER_MEDIUM,
            AtlasTrustBudgetService::CLASS_AUTONOMOUS_AGENT
        );
        $this->assertSame(2, $state['consumed_gross']);
        $this->assertSame(2, $state['consumed_net']);
        // medium × autonomous_agent canon = 50
        $this->assertSame(50, $state['cap']);
        $this->assertSame(48, $state['remaining']);
    }

    public function test_rollback_decrements_net_consumption(): void
    {
        $r = $this->svc->consume($this->baseConsume());
        $this->svc->rollback($r['action_id'], 'operator', 'undo for test');
        $state = $this->svc->state(
            AtlasTrustBudgetService::TIER_MEDIUM,
            AtlasTrustBudgetService::CLASS_AUTONOMOUS_AGENT
        );
        $this->assertSame(1, $state['consumed_gross']);
        $this->assertSame(1, $state['rolled_back']);
        $this->assertSame(0, $state['consumed_net']);
        $this->assertSame(50, $state['remaining']);
    }

    public function test_rollback_unknown_action_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->svc->rollback('tba_does_not_exist', 'operator', 'test');
    }

    public function test_rollback_requires_actor_and_reason(): void
    {
        $r = $this->svc->consume($this->baseConsume());
        $this->expectException(\InvalidArgumentException::class);
        $this->svc->rollback($r['action_id'], '', '');
    }

    public function test_consume_exceeded_budget_records_deny_verdict(): void
    {
        // Force cap=0 case via critical autonomous; consume should record deny.
        $r = $this->svc->consume($this->baseConsume([
            'tier' => AtlasTrustBudgetService::TIER_CRITICAL,
            'operator_class' => AtlasTrustBudgetService::CLASS_AUTONOMOUS_AGENT,
        ]));
        $this->assertSame(AtlasTrustBudgetService::VERDICT_DENY_BUDGET_EXCEEDED, $r['verdict']);
    }

    public function test_tier_constants_canon(): void
    {
        $this->assertSame('low_risk', AtlasTrustBudgetService::TIER_LOW);
        $this->assertSame('medium_risk', AtlasTrustBudgetService::TIER_MEDIUM);
        $this->assertSame('high_risk', AtlasTrustBudgetService::TIER_HIGH);
        $this->assertSame('critical', AtlasTrustBudgetService::TIER_CRITICAL);
    }

    public function test_class_constants_canon(): void
    {
        $this->assertSame('operator', AtlasTrustBudgetService::CLASS_OPERATOR);
        $this->assertSame('autonomous_agent', AtlasTrustBudgetService::CLASS_AUTONOMOUS_AGENT);
        $this->assertSame('external', AtlasTrustBudgetService::CLASS_EXTERNAL);
    }
}
