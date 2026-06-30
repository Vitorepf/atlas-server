<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\TaskFabric;

use App\Services\Ai\SelfConstruction\TaskFabric\AtlasTaskFabricEvidenceFloorSynthesizer;
use PHPUnit\Framework\TestCase;

final class AtlasTaskFabricEvidenceFloorSynthesizerTest extends TestCase
{
    private function synth(string $goal = 'Add capability X', array $risk = []): array
    {
        return (new AtlasTaskFabricEvidenceFloorSynthesizer)->synthesize($goal, $risk);
    }

    // ── AC1: base contract ────────────────────────────────────────────────────

    public function test_output_includes_schema_version(): void
    {
        $r = $this->synth();
        $this->assertSame(AtlasTaskFabricEvidenceFloorSynthesizer::SCHEMA, $r['schema_version']);
    }

    public function test_acceptance_criteria_includes_runnable_proof(): void
    {
        $r = $this->synth('Ship the Foo service');
        $joined = implode(' ', $r['acceptance_criteria']);
        $this->assertStringContainsString('artisan test', $joined);
        $this->assertStringContainsString('green', $joined);
    }

    public function test_acceptance_criteria_includes_capability_delta_claim(): void
    {
        $r = $this->synth('Ship the Foo service');
        $joined = implode(' ', $r['acceptance_criteria']);
        $this->assertStringContainsString('Ship the Foo service', $joined);
        $this->assertStringContainsString('verifiably observable', $joined);
    }

    public function test_acceptance_criteria_includes_falsifiable_gate_reference(): void
    {
        $r = $this->synth();
        $joined = implode(' ', $r['acceptance_criteria']);
        $this->assertStringContainsStringIgnoringCase('falsifiable', $joined);
    }

    public function test_required_evidence_includes_base_floor(): void
    {
        $r = $this->synth();
        $this->assertContains('tests_or_gates_result', $r['required_evidence']);
        $this->assertContains('implementation_notes', $r['required_evidence']);
    }

    public function test_output_carries_capability_goal_and_risk_level(): void
    {
        $r = $this->synth('Strengthen the gate', ['risk_level' => 'medium']);
        $this->assertSame('Strengthen the gate', $r['capability_goal']);
        $this->assertSame('medium', $r['risk_level']);
    }

    public function test_output_is_deterministic(): void
    {
        $svc = new AtlasTaskFabricEvidenceFloorSynthesizer;
        $a = $svc->synthesize('Implement Foo', ['risk_level' => 'high']);
        $b = $svc->synthesize('Implement Foo', ['risk_level' => 'high']);
        $this->assertSame(json_encode($a), json_encode($b));
    }

    // ── AC2: high-guard tasks ─────────────────────────────────────────────────

    public function test_high_risk_adds_anti_false_green_and_anti_stale_timestamp(): void
    {
        $r = $this->synth('Implement something', ['risk_level' => 'high']);
        $this->assertTrue($r['high_guard_active']);
        $this->assertContains('anti_false_green_receipt', $r['required_evidence']);
        $this->assertContains('anti_stale_timestamp_receipt', $r['required_evidence']);
    }

    public function test_queue_related_goal_adds_anti_false_green_guard(): void
    {
        $r = $this->synth('Fix queue jam in the task replenisher');
        $this->assertTrue($r['high_guard_active']);
        $this->assertContains('anti_false_green_receipt', $r['required_evidence']);
        $this->assertNotContains('anti_stale_timestamp_receipt', $r['required_evidence']);
    }

    public function test_verification_related_goal_adds_anti_false_green_guard(): void
    {
        $r = $this->synth('Improve the verification pipeline for certified tasks');
        $this->assertTrue($r['high_guard_active']);
        $this->assertContains('anti_false_green_receipt', $r['required_evidence']);
    }

    public function test_merge_related_goal_adds_anti_false_green_guard(): void
    {
        $r = $this->synth('Strengthen merge decision logic');
        $this->assertTrue($r['high_guard_active']);
        $this->assertContains('anti_false_green_receipt', $r['required_evidence']);
    }

    public function test_low_risk_neutral_goal_has_no_high_guard(): void
    {
        $r = $this->synth('Add a utility helper for string normalization', ['risk_level' => 'low']);
        $this->assertFalse($r['high_guard_active']);
        $this->assertNotContains('anti_false_green_receipt', $r['required_evidence']);
        $this->assertNotContains('anti_stale_timestamp_receipt', $r['required_evidence']);
    }

    public function test_high_risk_evidence_has_no_duplicates(): void
    {
        $r = $this->synth('Harden the queue gate verification merge', ['risk_level' => 'high']);
        $this->assertSame($r['required_evidence'], array_values(array_unique($r['required_evidence'])));
    }

    public function test_task_family_triggers_high_guard_on_gate_family(): void
    {
        $r = $this->synth('Add capability X', ['risk_level' => 'low', 'task_family' => 'gate']);
        $this->assertTrue($r['high_guard_active']);
        $this->assertContains('anti_false_green_receipt', $r['required_evidence']);
    }
}
