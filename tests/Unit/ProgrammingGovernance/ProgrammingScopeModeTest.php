<?php

namespace Tests\Unit\ProgrammingGovernance;

use App\Services\Ai\Programming\Governance\ProgrammingScopeMode;
use Tests\TestCase;

class ProgrammingScopeModeTest extends TestCase
{
    public function test_compact_requires_evidence_scope_guard_and_completion(): void
    {
        $gates = ProgrammingScopeMode::Compact->requiredGates();

        $this->assertContains('evidence-required', $gates);
        $this->assertContains('scope-guard', $gates);
        $this->assertContains('completion', $gates);
        $this->assertNotContains('spec-before-code', $gates);
    }

    public function test_structural_requires_all_eight_canonical_gates(): void
    {
        $gates = ProgrammingScopeMode::Structural->requiredGates();

        $this->assertSame([
            'feature-placement',
            'code-intelligence-context',
            'spec-before-code',
            'evidence-required',
            'scope-guard',
            'docs-health',
            'cartography-update',
            'completion',
        ], $gates);
    }

    public function test_scope_guard_is_blocking_in_both_modes(): void
    {
        $this->assertTrue(ProgrammingScopeMode::Compact->isBlocking('scope-guard'));
        $this->assertTrue(ProgrammingScopeMode::Structural->isBlocking('scope-guard'));
    }

    public function test_cartography_update_is_advisory_even_in_structural(): void
    {
        $this->assertFalse(ProgrammingScopeMode::Structural->isBlocking('cartography-update'));
        $this->assertTrue(ProgrammingScopeMode::Structural->isBlocking('spec-before-code'));
        $this->assertTrue(ProgrammingScopeMode::Structural->isBlocking('evidence-required'));
    }

    public function test_compact_blocking_gates_only_evidence_and_completion(): void
    {
        $this->assertTrue(ProgrammingScopeMode::Compact->isBlocking('evidence-required'));
        $this->assertTrue(ProgrammingScopeMode::Compact->isBlocking('completion'));
        $this->assertFalse(ProgrammingScopeMode::Compact->isBlocking('feature-placement'));
        $this->assertFalse(ProgrammingScopeMode::Compact->isBlocking('spec-before-code'));
    }
}
