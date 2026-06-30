<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainImplementationProofDemand;
use Tests\TestCase;

final class AtlasExternalBrainImplementationProofDemandTest extends TestCase
{
    private function svc(): AtlasExternalBrainImplementationProofDemand
    {
        return new AtlasExternalBrainImplementationProofDemand;
    }

    private function derive(
        string $risk = 'low',
        string $targetClass = 'logic',
        string $valueMechanism = 'logic',
        bool $isPropertyGated = false,
    ): array {
        return $this->svc()->derive([
            'task' => [
                'risk_level' => $risk,
                'target_class' => $targetClass,
                'value_mechanism' => $valueMechanism,
                'is_property_gated' => $isPropertyGated,
            ],
        ]);
    }

    // ── base proof by target_class ────────────────────────────────────────────

    public function test_logic_target_class_requires_unit_test(): void
    {
        $r = $this->derive(targetClass: 'logic');
        $this->assertContains('unit_test', $r['required_proofs']);
    }

    public function test_command_target_class_requires_command_smoke(): void
    {
        $r = $this->derive(targetClass: 'command');
        $this->assertContains('command_smoke', $r['required_proofs']);
    }

    public function test_queue_target_class_requires_queue_health(): void
    {
        $r = $this->derive(targetClass: 'queue');
        $this->assertContains('queue_health', $r['required_proofs']);
    }

    public function test_doc_target_class_requires_doc_proposal(): void
    {
        $r = $this->derive(targetClass: 'doc');
        $this->assertContains('doc_proposal', $r['required_proofs']);
    }

    public function test_feature_target_class_requires_feature_test(): void
    {
        $r = $this->derive(targetClass: 'feature');
        $this->assertContains('feature_test', $r['required_proofs']);
    }

    // ── risk escalation ───────────────────────────────────────────────────────

    public function test_medium_risk_adds_collision_sweep(): void
    {
        $r = $this->derive(risk: 'medium');
        $this->assertContains('collision_sweep', $r['required_proofs']);
    }

    public function test_high_risk_adds_collision_sweep_and_runtime_receipt(): void
    {
        $r = $this->derive(risk: 'high');
        $this->assertContains('collision_sweep', $r['required_proofs']);
        $this->assertContains('runtime_receipt', $r['required_proofs']);
    }

    public function test_low_risk_does_not_add_collision_sweep(): void
    {
        $r = $this->derive(risk: 'low');
        $this->assertNotContains('collision_sweep', $r['required_proofs']);
    }

    // ── implementation_notes_sufficient ──────────────────────────────────────

    public function test_high_risk_implementation_notes_not_sufficient(): void
    {
        $r = $this->derive(risk: 'high');
        $this->assertFalse($r['implementation_notes_sufficient']);
    }

    public function test_property_gated_flag_makes_notes_insufficient(): void
    {
        $r = $this->derive(risk: 'low', isPropertyGated: true);
        $this->assertFalse($r['implementation_notes_sufficient']);
    }

    public function test_property_gated_value_mechanism_makes_notes_insufficient(): void
    {
        $r = $this->derive(risk: 'low', valueMechanism: 'property_gated');
        $this->assertFalse($r['implementation_notes_sufficient']);
    }

    public function test_property_gated_adds_runtime_receipt(): void
    {
        $r = $this->derive(risk: 'low', isPropertyGated: true);
        $this->assertContains('runtime_receipt', $r['required_proofs']);
    }

    public function test_low_risk_non_gated_notes_sufficient(): void
    {
        $r = $this->derive(risk: 'low');
        $this->assertTrue($r['implementation_notes_sufficient']);
    }

    public function test_medium_risk_non_gated_notes_sufficient(): void
    {
        $r = $this->derive(risk: 'medium');
        $this->assertTrue($r['implementation_notes_sufficient']);
    }

    // ── deduplication ─────────────────────────────────────────────────────────

    public function test_high_risk_runtime_target_does_not_duplicate_runtime_receipt(): void
    {
        $r = $this->derive(risk: 'high', targetClass: 'runtime');

        $count = count(array_filter($r['required_proofs'],
            static fn (string $p): bool => $p === 'runtime_receipt'));

        $this->assertSame(1, $count);
    }

    // ── rationale ─────────────────────────────────────────────────────────────

    public function test_high_risk_rationale_mentions_runnable_gate(): void
    {
        $r = $this->derive(risk: 'high');
        $this->assertStringContainsString('runnable gate mandatory', $r['minimum_proof_rationale']);
    }

    public function test_property_gated_rationale_mentions_runtime_receipt(): void
    {
        $r = $this->derive(isPropertyGated: true);
        $this->assertStringContainsString('runtime receipt mandatory', $r['minimum_proof_rationale']);
    }

    // ── schema ────────────────────────────────────────────────────────────────

    public function test_schema_version_present(): void
    {
        $r = $this->svc()->derive([]);
        $this->assertSame(AtlasExternalBrainImplementationProofDemand::SCHEMA, $r['schema_version']);
    }
}
