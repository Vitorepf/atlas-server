<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\Autopoiesis;

use App\Services\Ai\SelfConstruction\Autopoiesis\AtlasSelfConstructionAutopoiesisGuardrailGate;
use Tests\TestCase;

final class AtlasSelfConstructionAutopoiesisGuardrailGateTest extends TestCase
{
    public function test_kernel_bypass_is_blocked(): void
    {
        $verdict = (new AtlasSelfConstructionAutopoiesisGuardrailGate)->evaluate(['bypasses_kernel' => true, 'read_only' => true]);
        $this->assertFalse($verdict['accepted']);
        $this->assertContains('experiment_bypasses_kernel', $verdict['blockers']);
    }

    public function test_verification_court_bypass_is_blocked(): void
    {
        $verdict = (new AtlasSelfConstructionAutopoiesisGuardrailGate)->evaluate(['bypasses_verification_court' => true, 'reversible' => true, 'rollback_plan' => ['mode' => 'x'], 'evidence_refs' => ['r1']]);
        $this->assertFalse($verdict['accepted']);
        $this->assertContains('experiment_bypasses_verification_court', $verdict['blockers']);
    }

    public function test_merge_governor_bypass_is_blocked(): void
    {
        $verdict = (new AtlasSelfConstructionAutopoiesisGuardrailGate)->evaluate(['bypasses_merge_governor' => true, 'read_only' => true]);
        $this->assertFalse($verdict['accepted']);
        $this->assertContains('experiment_bypasses_merge_governor', $verdict['blockers']);
    }

    public function test_allowed_files_bypass_is_blocked(): void
    {
        $verdict = (new AtlasSelfConstructionAutopoiesisGuardrailGate)->evaluate(['bypasses_allowed_files' => true, 'read_only' => true]);
        $this->assertContains('experiment_bypasses_allowed_files', $verdict['blockers']);
    }

    public function test_non_atlas_native_owner_is_blocked(): void
    {
        $verdict = (new AtlasSelfConstructionAutopoiesisGuardrailGate)->evaluate(['non_atlas_native_owner' => true, 'read_only' => true]);
        $this->assertContains('experiment_owner_not_atlas_native', $verdict['blockers']);
    }

    public function test_read_only_experiment_is_allowed(): void
    {
        $verdict = (new AtlasSelfConstructionAutopoiesisGuardrailGate)->evaluate(['read_only' => true]);
        $this->assertTrue($verdict['accepted']);
        $this->assertSame(AtlasSelfConstructionAutopoiesisGuardrailGate::CLASS_READ_ONLY, $verdict['allowed_class']);
    }

    public function test_reversible_experiment_with_rollback_and_evidence_is_allowed(): void
    {
        $verdict = (new AtlasSelfConstructionAutopoiesisGuardrailGate)->evaluate([
            'reversible' => true,
            'rollback_plan' => ['mode' => 'revert_commit'],
            'evidence_refs' => ['receipt:r1'],
        ]);
        $this->assertTrue($verdict['accepted']);
        $this->assertSame(AtlasSelfConstructionAutopoiesisGuardrailGate::CLASS_REVERSIBLE, $verdict['allowed_class']);
    }

    public function test_reversible_without_rollback_is_blocked(): void
    {
        $verdict = (new AtlasSelfConstructionAutopoiesisGuardrailGate)->evaluate([
            'reversible' => true,
            'evidence_refs' => ['receipt:r1'],
        ]);
        $this->assertFalse($verdict['accepted']);
        $this->assertContains('reversible_experiment_missing_rollback_plan', $verdict['blockers']);
    }

    public function test_reversible_without_evidence_is_blocked(): void
    {
        $verdict = (new AtlasSelfConstructionAutopoiesisGuardrailGate)->evaluate([
            'reversible' => true,
            'rollback_plan' => ['mode' => 'x'],
        ]);
        $this->assertFalse($verdict['accepted']);
        $this->assertContains('reversible_experiment_missing_evidence_refs', $verdict['blockers']);
    }

    public function test_neither_read_only_nor_reversible_is_blocked(): void
    {
        $verdict = (new AtlasSelfConstructionAutopoiesisGuardrailGate)->evaluate([]);
        $this->assertFalse($verdict['accepted']);
        $this->assertContains('experiment_neither_read_only_nor_reversible', $verdict['blockers']);
    }

    public function test_both_read_only_and_reversible_blocks_with_ambiguous_mode(): void
    {
        $verdict = (new AtlasSelfConstructionAutopoiesisGuardrailGate)->evaluate([
            'read_only' => true,
            'reversible' => true,
            'rollback_plan' => ['mode' => 'revert_commit'],
            'evidence_refs' => ['receipt:r1'],
        ]);
        $this->assertFalse($verdict['accepted']);
        $this->assertContains('ambiguous_mode', $verdict['blockers']);
        $this->assertSame(AtlasSelfConstructionAutopoiesisGuardrailGate::CLASS_REJECTED, $verdict['allowed_class']);
    }

    public function test_whitespace_only_evidence_refs_are_rejected(): void
    {
        $verdict = (new AtlasSelfConstructionAutopoiesisGuardrailGate)->evaluate([
            'reversible' => true,
            'rollback_plan' => ['mode' => 'revert_commit'],
            'evidence_refs' => ['  ', "\t", ''],
        ]);
        $this->assertFalse($verdict['accepted']);
        $this->assertContains('reversible_experiment_missing_evidence_refs', $verdict['blockers']);
    }

    public function test_duplicate_evidence_refs_are_normalized_and_accepted(): void
    {
        $verdict = (new AtlasSelfConstructionAutopoiesisGuardrailGate)->evaluate([
            'reversible' => true,
            'rollback_plan' => ['mode' => 'revert_commit'],
            'evidence_refs' => ['receipt:r1', 'receipt:r1', '  receipt:r2  '],
        ]);
        $this->assertTrue($verdict['accepted']);
        $this->assertSame(AtlasSelfConstructionAutopoiesisGuardrailGate::CLASS_REVERSIBLE, $verdict['allowed_class']);
    }
}
