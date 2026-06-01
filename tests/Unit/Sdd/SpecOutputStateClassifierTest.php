<?php

declare(strict_types=1);

namespace Tests\Unit\Sdd;

use App\Services\Ai\Programming\Sdd\SpecOutputStateClassifier;
use Tests\TestCase;

final class SpecOutputStateClassifierTest extends TestCase
{
    private SpecOutputStateClassifier $classifier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->classifier = new SpecOutputStateClassifier();
    }

    public function test_security_policy_conflict_outranks_blocking_ambiguity_issue(): void
    {
        $result = $this->classifier->classify(
            [['field' => 'auth', 'severity' => 'high', 'reason' => 'blocking_ambiguity']],
            'blocking_ambiguity',
            [['domain' => 'security', 'reason' => 'missing_auth_rule']],
            'implementation',
        );

        $this->assertSame('atlas.sdd_output_state_classification.v1', $result['schema_version']);
        $this->assertSame('blocked_by_policy', $result['state']);
        $this->assertStringStartsWith('policy_conflict:', $result['dominant_reason']);
        $this->assertFalse($result['plan_permitted']);
        $this->assertSame(1, $result['precedence_rank']);
        $this->assertSame(1, $result['signals']['policy_conflict_count']);
        $this->assertTrue($result['signals']['core_unresolved']);
    }

    public function test_high_blocking_ambiguity_issue_without_policy_needs_clarification(): void
    {
        $result = $this->classifier->classify(
            [['field' => 'scope', 'severity' => 'high', 'reason' => 'blocking_ambiguity']],
            'low',
            [],
            'implementation',
        );

        $this->assertSame('needs_clarification', $result['state']);
        $this->assertFalse($result['plan_permitted']);
        $this->assertSame(2, $result['precedence_rank']);
        $this->assertSame(0, $result['signals']['policy_conflict_count']);
        $this->assertSame(1, $result['signals']['blocking_issue_count']);
    }

    public function test_high_missing_target_file_issue_needs_clarification(): void
    {
        $result = $this->classifier->classify(
            [['field' => 'target_file', 'severity' => 'high', 'reason' => 'missing_target']],
            'medium',
            [],
            'implementation',
        );

        $this->assertSame('needs_clarification', $result['state']);
        $this->assertFalse($result['plan_permitted']);
        $this->assertSame(2, $result['precedence_rank']);
        $this->assertSame(1, $result['signals']['blocking_issue_count']);
    }

    public function test_exploration_under_blocking_ambiguity_is_spike_only(): void
    {
        $result = $this->classifier->classify(
            [],
            'blocking_ambiguity',
            [],
            'exploration',
        );

        $this->assertSame('spike_only', $result['state']);
        $this->assertFalse($result['plan_permitted']);
        $this->assertSame(3, $result['precedence_rank']);
        $this->assertTrue($result['signals']['is_exploration_only']);
        $this->assertTrue($result['signals']['is_blocking_ambiguity']);
        $this->assertFalse($result['signals']['core_unresolved']);
    }

    public function test_clean_high_confidence_implementation_is_ready_for_plan(): void
    {
        $result = $this->classifier->classify(
            [],
            'high',
            [],
            'implementation',
        );

        $this->assertSame('ready_for_plan', $result['state']);
        $this->assertTrue($result['plan_permitted']);
        $this->assertSame(4, $result['precedence_rank']);
        $this->assertSame(0, $result['signals']['blocking_issue_count']);
        $this->assertFalse($result['signals']['core_unresolved']);
    }

    public function test_low_or_warn_severity_issue_alone_stays_ready_for_plan(): void
    {
        $lowResult = $this->classifier->classify(
            [['field' => 'scope', 'severity' => 'low', 'reason' => 'blocking_ambiguity']],
            'high',
            [],
            'implementation',
        );

        $this->assertSame('ready_for_plan', $lowResult['state']);
        $this->assertTrue($lowResult['plan_permitted']);
        $this->assertSame(4, $lowResult['precedence_rank']);
        $this->assertSame(0, $lowResult['signals']['blocking_issue_count']);

        $warnResult = $this->classifier->classify(
            [['field' => 'target_file', 'severity' => 'warn', 'reason' => 'missing_target']],
            'high',
            [],
            'implementation',
        );

        $this->assertSame('ready_for_plan', $warnResult['state']);
        $this->assertTrue($warnResult['plan_permitted']);
        $this->assertSame(0, $warnResult['signals']['blocking_issue_count']);
    }

    public function test_design_and_architecture_policy_domains_also_block(): void
    {
        $design = $this->classifier->classify(
            [],
            'high',
            [['domain' => 'design', 'reason' => 'token_violation']],
            'implementation',
        );

        $this->assertSame('blocked_by_policy', $design['state']);
        $this->assertSame(1, $design['precedence_rank']);

        $architecture = $this->classifier->classify(
            [],
            'high',
            [['domain' => 'architecture', 'reason' => 'layer_breach']],
            'implementation',
        );

        $this->assertSame('blocked_by_policy', $architecture['state']);
        $this->assertFalse($architecture['plan_permitted']);
    }

    public function test_unknown_policy_domain_does_not_block(): void
    {
        $result = $this->classifier->classify(
            [],
            'high',
            [['domain' => 'cosmetic', 'reason' => 'nitpick']],
            'implementation',
        );

        $this->assertSame('ready_for_plan', $result['state']);
        $this->assertTrue($result['plan_permitted']);
        $this->assertSame(0, $result['signals']['policy_conflict_count']);
    }

    public function test_exploration_without_blocking_ambiguity_is_not_spike_only(): void
    {
        // intentKind is exploration but confidence is high, so the spike gate
        // does not fire and the verdict falls through to ready_for_plan.
        $result = $this->classifier->classify(
            [],
            'high',
            [],
            'exploration',
        );

        $this->assertSame('ready_for_plan', $result['state']);
        $this->assertSame(4, $result['precedence_rank']);
        $this->assertTrue($result['signals']['is_exploration_only']);
        $this->assertFalse($result['signals']['is_blocking_ambiguity']);
    }

    public function test_blocking_ambiguity_confidence_without_exploration_intent_is_not_spike(): void
    {
        // Mirror of the spike case with intentKind=implementation: confidence is
        // blocking_ambiguity but there is no exploration intent and no blocking
        // issue, so it must resolve to ready_for_plan, not spike_only.
        $result = $this->classifier->classify(
            [],
            'blocking_ambiguity',
            [],
            'implementation',
        );

        $this->assertSame('ready_for_plan', $result['state']);
        $this->assertSame(4, $result['precedence_rank']);
        $this->assertTrue($result['signals']['is_blocking_ambiguity']);
        $this->assertFalse($result['signals']['is_exploration_only']);
    }

    public function test_plan_permitted_true_only_for_ready_for_plan_across_all_states(): void
    {
        $blocked = $this->classifier->classify([], 'high', [['domain' => 'security', 'reason' => 'x']], 'implementation');
        $clarify = $this->classifier->classify([['field' => 'a', 'severity' => 'high', 'reason' => 'blocking_ambiguity']], 'high', [], 'implementation');
        $spike = $this->classifier->classify([], 'blocking_ambiguity', [], 'exploration');
        $ready = $this->classifier->classify([], 'high', [], 'implementation');

        $this->assertFalse($blocked['plan_permitted']);
        $this->assertFalse($clarify['plan_permitted']);
        $this->assertFalse($spike['plan_permitted']);
        $this->assertTrue($ready['plan_permitted']);
    }

    public function test_classification_is_deterministic(): void
    {
        $issues = [['field' => 'target_file', 'severity' => 'high', 'reason' => 'missing_target']];

        $this->assertSame(
            $this->classifier->classify($issues, 'medium', [], 'implementation'),
            $this->classifier->classify($issues, 'medium', [], 'implementation'),
        );
    }
}
