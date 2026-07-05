<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\AgentRuntimeRegistryCapabilityCatalog;
use Tests\TestCase;

/**
 * Unit tests for the hardened AgentRuntimeRegistryCapabilityCatalog methods:
 * normalizeCapabilityRecords(), validateCapabilityRecords(), and the
 * match() extension for missing_proof_ability / unsafe_runtime_state.
 */
final class AgentRuntimeRegistryCapabilityCatalogTest extends TestCase
{
    private function catalog(): AgentRuntimeRegistryCapabilityCatalog
    {
        return new AgentRuntimeRegistryCapabilityCatalog;
    }

    // ── normalizeCapabilityRecords ───────────────────────────────────────────

    public function test_normalize_capability_records_returns_deterministic_records(): void
    {
        $result = $this->catalog()->normalizeCapabilityRecords([
            ['id' => 'Code_Edit', 'class' => 'Service', 'required_gates' => ['test_runner'], 'evidence_abilities' => ['phpunit']],
            ['id' => 'docs_writer', 'class' => 'Docs', 'required_gates' => ['lint'], 'evidence_abilities' => ['markdown']],
        ]);

        $this->assertSame(AgentRuntimeRegistryCapabilityCatalog::SCHEMA_VERSION, $result['schema_version']);
        $this->assertCount(2, $result['records']);

        $first = $result['records'][0];
        $this->assertSame('code_edit', $first['id']);
        $this->assertSame('service', $first['class']);
        $this->assertSame(['test_runner'], $first['required_gates']);
        $this->assertSame(['phpunit'], $first['evidence_abilities']);
        $this->assertArrayHasKey('safety_flags', $first);
    }

    public function test_normalize_capability_records_deduplicates_by_id(): void
    {
        $result = $this->catalog()->normalizeCapabilityRecords([
            ['id' => 'code_edit', 'class' => 'a', 'required_gates' => ['g1'], 'evidence_abilities' => ['e1']],
            ['id' => 'code_edit', 'class' => 'b', 'required_gates' => ['g2'], 'evidence_abilities' => ['e2']],
        ]);

        $this->assertCount(1, $result['records']);
        $this->assertSame('a', $result['records'][0]['class']);
    }

    public function test_normalize_capability_records_sorts_by_id(): void
    {
        $result = $this->catalog()->normalizeCapabilityRecords([
            ['id' => 'zebra', 'class' => 'z', 'required_gates' => ['g'], 'evidence_abilities' => ['e']],
            ['id' => 'alpha', 'class' => 'a', 'required_gates' => ['g'], 'evidence_abilities' => ['e']],
        ]);

        $this->assertSame('alpha', $result['records'][0]['id']);
        $this->assertSame('zebra', $result['records'][1]['id']);
    }

    public function test_normalize_capability_records_includes_safety_flags_all_false(): void
    {
        $result = $this->catalog()->normalizeCapabilityRecords([
            ['id' => 'cap', 'class' => 'c', 'required_gates' => ['g'], 'evidence_abilities' => ['e']],
        ]);

        foreach ($result['records'][0]['safety_flags'] as $flag => $value) {
            $this->assertFalse($value, "safety flag {$flag} must be false");
        }
    }

    public function test_normalize_capability_records_skips_empty_ids(): void
    {
        $result = $this->catalog()->normalizeCapabilityRecords([
            ['id' => '', 'class' => 'c', 'required_gates' => ['g'], 'evidence_abilities' => ['e']],
            ['id' => 'valid', 'class' => 'c', 'required_gates' => ['g'], 'evidence_abilities' => ['e']],
        ]);

        $this->assertCount(1, $result['records']);
        $this->assertSame('valid', $result['records'][0]['id']);
    }

    // ── validateCapabilityRecords ────────────────────────────────────────────

    public function test_validate_capability_records_accepts_valid_input(): void
    {
        $result = $this->catalog()->validateCapabilityRecords([
            ['id' => 'cap_a', 'class' => 'c', 'required_gates' => ['g1'], 'evidence_abilities' => ['e1']],
            ['id' => 'cap_b', 'class' => 'c', 'required_gates' => ['g2'], 'evidence_abilities' => ['e2']],
        ]);

        $this->assertTrue($result['is_valid']);
        $this->assertSame([], $result['violations']);
    }

    public function test_validate_capability_records_rejects_missing_required_gates(): void
    {
        $result = $this->catalog()->validateCapabilityRecords([
            ['id' => 'cap_a', 'class' => 'c', 'required_gates' => [], 'evidence_abilities' => ['e1']],
        ]);

        $this->assertFalse($result['is_valid']);
        $this->assertContains('missing_required_gates:cap_a', $result['violations']);
    }

    public function test_validate_capability_records_rejects_missing_evidence_ability(): void
    {
        $result = $this->catalog()->validateCapabilityRecords([
            ['id' => 'cap_a', 'class' => 'c', 'required_gates' => ['g1'], 'evidence_abilities' => []],
        ]);

        $this->assertFalse($result['is_valid']);
        $this->assertContains('missing_evidence_ability:cap_a', $result['violations']);
    }

    public function test_validate_capability_records_rejects_unsafe_runtime_flags(): void
    {
        $result = $this->catalog()->validateCapabilityRecords([
            [
                'id' => 'cap_a',
                'class' => 'c',
                'required_gates' => ['g1'],
                'evidence_abilities' => ['e1'],
                'safety_flags' => ['runtime_execution_allowed' => true],
            ],
        ]);

        $this->assertFalse($result['is_valid']);
        $foundUnsafe = false;
        foreach ($result['violations'] as $v) {
            if (str_starts_with($v, 'unsafe_runtime_flag:cap_a:')) {
                $foundUnsafe = true;
            }
        }
        $this->assertTrue($foundUnsafe, 'expected unsafe_runtime_flag violation');
    }

    // ── match() with capabilityFacts ─────────────────────────────────────────

    public function test_match_distinguishes_missing_proof_ability(): void
    {
        $result = $this->catalog()->match(
            ['code_edit', 'docs_writer'],
            ['code_edit', 'docs_writer'],
            [
                'code_edit' => ['has_proof' => false, 'runtime_safe' => true],
                'docs_writer' => ['has_proof' => true, 'runtime_safe' => true],
            ],
        );

        $this->assertSame('missing_proof_ability', $result['match_status']);
        $this->assertContains('code_edit', $result['missing_proof_abilities']);
        $this->assertSame([], $result['unsafe_runtime_states']);
    }

    public function test_match_distinguishes_unsafe_runtime_state(): void
    {
        $result = $this->catalog()->match(
            ['code_edit'],
            ['code_edit'],
            [
                'code_edit' => ['has_proof' => true, 'runtime_safe' => false],
            ],
        );

        $this->assertSame('unsafe_runtime_state', $result['match_status']);
        $this->assertContains('code_edit', $result['unsafe_runtime_states']);
        $this->assertSame([], $result['missing_proof_abilities']);
    }

    public function test_match_full_match_with_all_proofs_and_safe_runtime_is_matched(): void
    {
        $result = $this->catalog()->match(
            ['code_edit', 'docs_writer'],
            ['code_edit', 'docs_writer'],
            [
                'code_edit' => ['has_proof' => true, 'runtime_safe' => true],
                'docs_writer' => ['has_proof' => true, 'runtime_safe' => true],
            ],
        );

        $this->assertSame('matched', $result['match_status']);
        $this->assertSame([], $result['missing_proof_abilities']);
        $this->assertSame([], $result['unsafe_runtime_states']);
    }

    public function test_match_partial_takes_precedence_over_missing_proof(): void
    {
        $result = $this->catalog()->match(
            ['code_edit', 'docs_writer'],
            ['code_edit'],
            [
                'code_edit' => ['has_proof' => false, 'runtime_safe' => true],
            ],
        );

        $this->assertSame('partial', $result['match_status']);
    }

    public function test_match_missing_takes_precedence_over_partial(): void
    {
        $result = $this->catalog()->match(
            ['code_edit'],
            ['docs_writer'],
        );

        $this->assertSame('missing', $result['match_status']);
    }

    public function test_match_without_capability_facts_works_as_before(): void
    {
        $result = $this->catalog()->match(['code_edit'], ['code_edit']);

        $this->assertSame('matched', $result['match_status']);
        $this->assertSame([], $result['missing_proof_abilities']);
        $this->assertSame([], $result['unsafe_runtime_states']);
    }

    // ── evaluateObservedCapability edge cases ────────────────────────────────

    public function test_evaluate_observed_capability_distinguishes_unverified_from_failure_prone(): void
    {
        $svc = $this->catalog();

        $unverified = $svc->evaluateObservedCapability([
            'capability' => 'code_edit',
            'task_family' => 'test',
            'supported_tools' => ['php'],
            'self_declared' => true,
            'recent_outcomes' => [['outcome' => 'success']],
        ]);

        $this->assertSame(AgentRuntimeRegistryCapabilityCatalog::CAPABILITY_STATUS_UNVERIFIED, $unverified['capability_status']);

        $failureProne = $svc->evaluateObservedCapability([
            'capability' => 'code_edit',
            'task_family' => 'test',
            'supported_tools' => ['php'],
            'self_declared' => true,
            'recent_outcomes' => [['outcome' => 'give_back'], ['outcome' => 'give_back'], ['outcome' => 'give_back']],
        ]);

        $this->assertSame(AgentRuntimeRegistryCapabilityCatalog::CAPABILITY_STATUS_FAILURE_PRONE, $failureProne['capability_status']);
    }
}
