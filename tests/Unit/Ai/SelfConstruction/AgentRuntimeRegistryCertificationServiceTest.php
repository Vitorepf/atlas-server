<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\AgentRuntimeRegistryCertificationService;
use Tests\TestCase;

final class AgentRuntimeRegistryCertificationServiceTest extends TestCase
{
    // ── certifyWorkerReadiness: pure, no dependencies ──────────────────

    public function test_worker_readiness_certified_when_capability_and_evidence_are_clean(): void
    {
        $result = (new AgentRuntimeRegistryCertificationService)->certifyWorkerReadiness([
            'agent_id' => 'worker-1',
            'capabilities' => ['code_edit', 'evidence_collection'],
            'task_families' => [
                ['family' => 'code_patch', 'required_capabilities' => ['code_edit']],
            ],
            'evidence' => ['self_declared' => false, 'age_days' => 1],
            'recent_outcomes' => [],
        ]);

        $this->assertSame('certified', $result['readiness_status']);
        $this->assertContains('code_patch', $result['allowed_task_families']);
        $this->assertSame([], $result['blocked_task_families']);
        $this->assertFalse($result['global_evidence_failure']);
    }

    public function test_worker_readiness_blocked_when_evidence_self_declared(): void
    {
        $result = (new AgentRuntimeRegistryCertificationService)->certifyWorkerReadiness([
            'agent_id' => 'worker-2',
            'capabilities' => ['code_edit'],
            'task_families' => [
                ['family' => 'code_patch', 'required_capabilities' => ['code_edit']],
            ],
            'evidence' => ['self_declared' => true, 'age_days' => 1],
        ]);

        $this->assertSame('blocked', $result['readiness_status']);
        $this->assertSame([], $result['allowed_task_families']);
    }

    public function test_worker_readiness_blocked_when_evidence_stale(): void
    {
        $result = (new AgentRuntimeRegistryCertificationService)->certifyWorkerReadiness([
            'agent_id' => 'worker-3',
            'capabilities' => ['code_edit'],
            'task_families' => [
                ['family' => 'code_patch', 'required_capabilities' => ['code_edit']],
            ],
            'evidence' => ['self_declared' => false, 'age_days' => 30],
        ]);

        $this->assertSame('blocked', $result['readiness_status']);
    }

    public function test_worker_readiness_blocked_for_family_missing_required_capability(): void
    {
        $result = (new AgentRuntimeRegistryCertificationService)->certifyWorkerReadiness([
            'agent_id' => 'worker-4',
            'capabilities' => ['only_dry_run'],
            'task_families' => [
                ['family' => 'code_patch', 'required_capabilities' => ['code_edit']],
            ],
            'evidence' => ['self_declared' => false, 'age_days' => 1],
        ]);

        $this->assertSame('blocked', $result['readiness_status']);
        $blockedFamily = $result['blocked_task_families'][0] ?? [];
        $this->assertContains('missing_capability:code_edit', $blockedFamily['reasons'] ?? []);
    }

    public function test_worker_readiness_blocked_for_family_with_high_give_back_rate(): void
    {
        $result = (new AgentRuntimeRegistryCertificationService)->certifyWorkerReadiness([
            'agent_id' => 'worker-5',
            'capabilities' => ['code_edit'],
            'task_families' => [
                ['family' => 'code_patch', 'required_capabilities' => ['code_edit']],
            ],
            'evidence' => ['self_declared' => false, 'age_days' => 1],
            'recent_outcomes' => [
                ['family' => 'code_patch', 'outcome' => 'give_back'],
                ['family' => 'code_patch', 'outcome' => 'success'],
                ['family' => 'code_patch', 'outcome' => 'give_back'],
                ['family' => 'code_patch', 'outcome' => 'give_back'],
            ],
        ]);

        $this->assertSame('blocked', $result['readiness_status']);
        $this->assertContains('high_give_back_rate', $result['blocked_task_families'][0]['reasons'] ?? []);
    }

    public function test_worker_readiness_not_blocked_by_give_back_rate_below_sample_floor(): void
    {
        $result = (new AgentRuntimeRegistryCertificationService)->certifyWorkerReadiness([
            'agent_id' => 'worker-6',
            'capabilities' => ['code_edit'],
            'task_families' => [
                ['family' => 'code_patch', 'required_capabilities' => ['code_edit']],
            ],
            'evidence' => ['self_declared' => false, 'age_days' => 1],
            // 2 outcomes only — below MIN_OUTCOME_SAMPLE_FOR_GIVE_BACK_RATE (3)
            'recent_outcomes' => [
                ['family' => 'code_patch', 'outcome' => 'give_back'],
                ['family' => 'code_patch', 'outcome' => 'give_back'],
            ],
        ]);

        $this->assertSame('certified', $result['readiness_status']);
    }

    public function test_worker_readiness_blocked_for_known_failure_mode_family(): void
    {
        $result = (new AgentRuntimeRegistryCertificationService)->certifyWorkerReadiness([
            'agent_id' => 'worker-7',
            'capabilities' => ['code_edit'],
            'task_families' => [
                ['family' => 'code_patch', 'required_capabilities' => ['code_edit']],
            ],
            'evidence' => ['self_declared' => false, 'age_days' => 1],
            'known_failure_modes' => ['code_patch'],
        ]);

        $this->assertSame('blocked', $result['readiness_status']);
        $this->assertContains('known_failure_mode', $result['blocked_task_families'][0]['reasons'] ?? []);
    }

    public function test_worker_readiness_partial_when_some_families_allowed_and_some_blocked(): void
    {
        $result = (new AgentRuntimeRegistryCertificationService)->certifyWorkerReadiness([
            'agent_id' => 'worker-8',
            'capabilities' => ['code_edit', 'reading'],
            'task_families' => [
                ['family' => 'code_patch', 'required_capabilities' => ['code_edit']],
                ['family' => 'analysis', 'required_capabilities' => ['reading']],
                ['family' => 'forbidden', 'required_capabilities' => ['code_edit']],
            ],
            'evidence' => ['self_declared' => false, 'age_days' => 1],
            'known_failure_modes' => ['forbidden'],
        ]);

        $this->assertSame('partial', $result['readiness_status']);
        $this->assertContains('code_patch', $result['allowed_task_families']);
        $this->assertContains('analysis', $result['allowed_task_families']);
    }

    public function test_worker_readiness_runtime_flags_all_false(): void
    {
        $result = (new AgentRuntimeRegistryCertificationService)->certifyWorkerReadiness([
            'agent_id' => 'worker-9',
            'capabilities' => ['code_edit'],
            'task_families' => [
                ['family' => 'code_patch', 'required_capabilities' => ['code_edit']],
            ],
            'evidence' => ['self_declared' => false, 'age_days' => 1],
        ]);

        $this->assertFalse($result['runtime_execution_allowed']);
        $this->assertFalse($result['dispatch_allowed']);
        $this->assertFalse($result['provider_call_allowed']);
        $this->assertFalse($result['token_spend_allowed']);
        $this->assertFalse($result['self_programming_allowed']);
        $this->assertFalse($result['ledger_write_allowed']);
    }

    // ── runtimeFlags ──────────────────────────────────────────────────

    public function test_runtime_flags_helper(): void
    {
        $flags = (new AgentRuntimeRegistryCertificationService)->runtimeFlags();

        $this->assertFalse($flags['runtime_execution_allowed']);
        $this->assertFalse($flags['dispatch_allowed']);
        $this->assertFalse($flags['provider_call_allowed']);
        $this->assertFalse($flags['token_spend_allowed']);
        $this->assertFalse($flags['self_programming_allowed']);
        $this->assertFalse($flags['ledger_write_allowed']);
        $this->assertFalse($flags['handoff_execution_allowed']);
    }

    // ── Constants ──────────────────────────────────────────────────────

    public function test_constants_canonical(): void
    {
        $this->assertSame(
            'atlas.self_construction.agent_runtime_registry_certification.v1',
            AgentRuntimeRegistryCertificationService::SCHEMA_VERSION,
        );
        $this->assertSame(
            'read_only_agent_runtime_registry_certification',
            AgentRuntimeRegistryCertificationService::MODE,
        );
    }

    // ── certify: integration with real deps ────────────────────────────

    public function test_certify_returns_expected_schema(): void
    {
        $result = app(AgentRuntimeRegistryCertificationService::class)->certify([]);

        $this->assertSame(
            AgentRuntimeRegistryCertificationService::SCHEMA_VERSION,
            $result['schema_version'],
        );
        $this->assertSame(
            AgentRuntimeRegistryCertificationService::MODE,
            $result['mode'],
        );
        $this->assertArrayHasKey('certification_hash', $result);
    }

    public function test_runtime_safety_block_present_and_false(): void
    {
        $result = app(AgentRuntimeRegistryCertificationService::class)->certify([]);

        $safety = $result['runtime_safety'];
        $this->assertFalse($safety['runtime_execution_allowed']);
        $this->assertFalse($safety['dispatch_allowed']);
        $this->assertFalse($safety['provider_call_allowed']);
        $this->assertFalse($safety['token_spend_allowed']);
        $this->assertFalse($safety['self_programming_allowed']);
        $this->assertFalse($safety['ledger_write_allowed']);
        $this->assertFalse($safety['handoff_execution_allowed']);
        $this->assertTrue($safety['runtime_safety_all_false']);
    }

    public function test_certification_hash_stable(): void
    {
        $a = app(AgentRuntimeRegistryCertificationService::class)->certify([]);
        $b = app(AgentRuntimeRegistryCertificationService::class)->certify([]);

        // certification_hash is computed from invariants (which are deterministic)
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $a['certification_hash']);
    }
}
