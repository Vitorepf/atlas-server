<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainSmallModelEscalationAvoidanceGate;
use PHPUnit\Framework\TestCase;

final class AtlasExternalBrainSmallModelEscalationAvoidanceGateTest extends TestCase
{
    private AtlasExternalBrainSmallModelEscalationAvoidanceGate $gate;

    protected function setUp(): void
    {
        $this->gate = new AtlasExternalBrainSmallModelEscalationAvoidanceGate;
    }

    // ── AC: easy local tasks stay local ──

    public function test_easy_local_task_stays_local(): void
    {
        $result = $this->gate->evaluate([
            'originator_id' => 'orig-1',
            'round_id' => 'round-1',
            'task' => [
                'difficulty' => 'easy',
                'has_local_runtime' => true,
                'is_proxy_for_real_capability' => false,
            ],
        ]);

        $this->assertSame(AtlasExternalBrainSmallModelEscalationAvoidanceGate::VERDICT_LOCAL, $result['verdict']);
        $this->assertContains('easy_local_task_with_runtime', $result['reasons']);
    }

    // ── AC: proxy outputs are rejected ──

    public function test_proxy_task_is_rejected(): void
    {
        $result = $this->gate->evaluate([
            'originator_id' => 'orig-1',
            'round_id' => 'round-1',
            'task' => [
                'difficulty' => 'easy',
                'has_local_runtime' => false,
                'is_proxy_for_real_capability' => true,
            ],
        ]);

        $this->assertSame(AtlasExternalBrainSmallModelEscalationAvoidanceGate::VERDICT_REJECT_PROXY, $result['verdict']);
        $this->assertContains('rejected_proxy_for_real_capability', $result['reasons']);
    }

    // ── AC: hard-case escalation receives explicit evidence ──

    public function test_bounded_hard_case_with_evidence_escalates(): void
    {
        $result = $this->gate->evaluate([
            'originator_id' => 'orig-1',
            'round_id' => 'round-1',
            'task' => [
                'difficulty' => 'hard',
                'has_local_runtime' => false,
                'is_proxy_for_real_capability' => false,
                'bounded_hard_case' => true,
                'escalation_evidence' => ['missing_symbol_trace'],
            ],
        ]);

        $this->assertSame(AtlasExternalBrainSmallModelEscalationAvoidanceGate::VERDICT_ESCALATE, $result['verdict']);
        $this->assertContains('bounded_hard_case_with_evidence_allows_escalation', $result['reasons']);
        $this->assertSame(1, $result['escalation_evidence_count']);
    }

    public function test_unbounded_hard_case_is_rejected(): void
    {
        $result = $this->gate->evaluate([
            'originator_id' => 'orig-1',
            'round_id' => 'round-1',
            'task' => [
                'difficulty' => 'hard',
                'has_local_runtime' => false,
                'is_proxy_for_real_capability' => false,
                'bounded_hard_case' => false,
                'escalation_evidence' => ['missing_symbol_trace'],
            ],
        ]);

        $this->assertSame(AtlasExternalBrainSmallModelEscalationAvoidanceGate::VERDICT_REJECT_PROXY, $result['verdict']);
        $this->assertContains('unbounded_hard_case_requires_bounds', $result['reasons']);
    }

    // ── output structure ──

    public function test_output_has_required_keys(): void
    {
        $result = $this->gate->evaluate([
            'originator_id' => 'orig-1',
            'round_id' => 'round-1',
            'task' => [],
        ]);

        $this->assertSame(AtlasExternalBrainSmallModelEscalationAvoidanceGate::SCHEMA, $result['schema_version']);
        $this->assertArrayHasKey('verdict', $result);
        $this->assertArrayHasKey('reasons', $result);
        $this->assertArrayHasKey('difficulty', $result);
    }

    public function test_result_is_deterministic(): void
    {
        $input = [
            'originator_id' => 'orig-1',
            'round_id' => 'round-1',
            'task' => [
                'difficulty' => 'hard',
                'bounded_hard_case' => true,
                'escalation_evidence' => ['trace'],
            ],
        ];

        $a = $this->gate->evaluate($input);
        $b = $this->gate->evaluate($input);

        $this->assertSame(json_encode($a), json_encode($b));
    }
}
