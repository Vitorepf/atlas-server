<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\ControlPlane\AgentControlPlaneCertificationBaselineService;
use Tests\TestCase;

final class AgentControlPlaneCertificationBaselineServiceTest extends TestCase
{
    private function build(): array
    {
        return app(AgentControlPlaneCertificationBaselineService::class)->build();
    }

    // ── Output structure ───────────────────────────────────────────────

    public function test_build_returns_expected_schema_version(): void
    {
        $result = $this->build();

        $this->assertSame(
            AgentControlPlaneCertificationBaselineService::SCHEMA_VERSION,
            $result['schema_version'],
        );
        $this->assertSame(
            AgentControlPlaneCertificationBaselineService::MODE,
            $result['mode'],
        );
        $this->assertArrayHasKey('baseline_hash', $result);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $result['baseline_hash']);
    }

    // ── Sections present ───────────────────────────────────────────────

    public function test_build_includes_expected_top_level_sections(): void
    {
        $result = $this->build();

        $expected = [
            'sections',
            'invariants',
            'non_execution_guarantees',
            'baseline_hash',
            'baseline_fingerprint',
        ];
        foreach ($expected as $key) {
            $this->assertArrayHasKey($key, $result, "build() must include: {$key}");
        }
    }

    public function test_build_includes_all_required_baseline_sections(): void
    {
        $result = $this->build();

        $requiredKeys = [
            'control_plane',
            'chain_integrity',
            'deterministic_replay',
            'snapshot_store',
            'replay_diff',
            'promotion_gate',
            'docs',
            'command_surface',
            'capability_surface',
            'readiness_surface',
            'invoker_surface',
            'test_surface',
            'runtime_safety',
        ];
        foreach ($requiredKeys as $key) {
            $this->assertArrayHasKey($key, $result['sections'], "sections must include: {$key}");
            $this->assertIsArray($result['sections'][$key]);
        }
    }

    public function test_build_returns_non_execution_guarantees(): void
    {
        $result = $this->build();

        $this->assertArrayHasKey('non_execution_guarantees', $result);
        $this->assertNotEmpty($result['non_execution_guarantees']);
        $this->assertContains(
            'baseline_does_not_start_codex',
            $result['non_execution_guarantees'],
        );
        $this->assertContains(
            'baseline_does_not_call_provider',
            $result['non_execution_guarantees'],
        );
    }

    // ── Hash format ────────────────────────────────────────────────────

    public function test_build_hash_is_valid_hex_format(): void
    {
        $result = $this->build();

        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $result['baseline_hash']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{12}$/', $result['baseline_fingerprint']);
        $this->assertSame(substr($result['baseline_hash'], 0, 12), $result['baseline_fingerprint']);
    }

    // ── Invariants ─────────────────────────────────────────────────────

    public function test_build_invariants_are_present(): void
    {
        $result = $this->build();

        $this->assertArrayHasKey('invariants', $result);
        $this->assertArrayHasKey('invariants_all_true', $result);
        $this->assertIsBool($result['invariants_all_true']);

        $requiredInvariants = [
            'control_plane_present',
            'chain_integrity_present',
            'replay_present',
            'baseline_is_read_only',
            'baseline_does_not_advance_pointer',
            'runtime_safety_all_false',
            'cycle_integrity_ok',
            'terminal_horizon_ok',
            'completion_claim_not_allowed',
            'gate_hash_present',
            'replay_hash_present',
            'chain_integrity_hash_present',
        ];
        foreach ($requiredInvariants as $invariant) {
            $this->assertArrayHasKey($invariant, $result['invariants'], "invariants must include: {$invariant}");
        }
    }

    public function test_build_read_only_guarantees_are_all_false(): void
    {
        $result = $this->build();

        $this->assertTrue($result['read_only']);
        $this->assertFalse($result['execution_allowed']);
        $this->assertFalse($result['dispatch_allowed']);
        $this->assertFalse($result['ledger_write_allowed']);
        $this->assertFalse($result['runtime_write_allowed']);
        $this->assertFalse($result['external_provider_call']);
        $this->assertFalse($result['token_spend']);
        $this->assertFalse($result['process_started']);
        $this->assertFalse($result['self_programming_allowed']);
        $this->assertFalse($result['completion_claim_allowed']);
    }

    // ── Hash refs present ──────────────────────────────────────────────

    public function test_build_contains_required_hash_refs(): void
    {
        $result = $this->build();

        $expectedHashes = [
            'chain_integrity_hash',
            'deterministic_replay_hash',
            'proof_bundle_hash',
            'replay_diff_hash',
            'docs_hash',
            'capability_surface_hash',
            'test_surface_hash',
        ];
        foreach ($expectedHashes as $hashKey) {
            $this->assertArrayHasKey($hashKey, $result, "build() must include hash ref: {$hashKey}");
            $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $result[$hashKey], "{$hashKey} must be a 64-char hex hash");
        }
    }

    public function test_build_returns_human_summary(): void
    {
        $result = $this->build();

        $this->assertArrayHasKey('human_summary', $result);
        $this->assertStringContainsString('Agent Control Plane Certification Baseline', $result['human_summary']);
    }
}
