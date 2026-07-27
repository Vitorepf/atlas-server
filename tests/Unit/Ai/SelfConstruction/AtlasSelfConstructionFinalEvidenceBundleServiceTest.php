<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\NativeImplementation\AtlasSelfConstructionFinalEvidenceBundleService;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Unit tests for AtlasSelfConstructionFinalEvidenceBundleService: build()
 * reports missing receipt schemas, marks bundle_ready=false on partial evidence,
 * and emits next_action_shell_packet + blockers deterministically.
 */
final class AtlasSelfConstructionFinalEvidenceBundleServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    private function service(): AtlasSelfConstructionFinalEvidenceBundleService
    {
        return app(AtlasSelfConstructionFinalEvidenceBundleService::class);
    }

    private function minimalBuildOptions(): array
    {
        return [
            'runtime_gap_matrix' => [
                'runtime_gap_matrix_hash' => 'test-hash',
                'all_runtime_y' => false,
                'runtime_promotion_receipt' => ['status' => 'not_supplied'],
            ],
            'human_receipt' => [
                'receipt_hash' => 'test-receipt-hash',
                'status' => 'not_supplied',
            ],
            'real_provider_smoke_result' => [
                'smoke_hash' => 'test-smoke-hash',
                'status' => 'not_supplied',
            ],
            'operator_action_packet' => [
                'status' => 'blocked',
            ],
            'completion_evidence_hash_composer' => [
                'evidence_hash' => 'test-evidence-hash',
            ],
            'completion_audit' => [
                'completion_audit_hash' => 'test-audit-hash',
                'status' => 'incomplete',
                'completion_allowed' => false,
                'failed_criteria' => ['runtime_promotion_receipt_missing'],
            ],
            'runtime_promotion_receipt' => [],
            'completion_receipt' => [],
            'real_provider_smoke' => [],
        ];
    }

    // ── AC: build() reports missing expected receipt schemas ───────────────

    public function test_build_reports_missing_runtime_promotion_receipt_schema(): void
    {
        $result = $this->service()->build($this->minimalBuildOptions());

        $closureSequence = $result['closure_artifact_sequence'] ?? [];
        $runtimePromotionEntry = null;
        foreach ($closureSequence as $entry) {
            if (($entry['artifact'] ?? '') === 'runtime_promotion_receipt') {
                $runtimePromotionEntry = $entry;
            }
        }
        $this->assertNotNull($runtimePromotionEntry);
        $this->assertNotEmpty($runtimePromotionEntry['expected_receipt_schema']);
        $this->assertFalse($runtimePromotionEntry['passed']);
    }

    public function test_build_reports_missing_real_provider_smoke_schema(): void
    {
        $result = $this->service()->build($this->minimalBuildOptions());

        $closureSequence = $result['closure_artifact_sequence'] ?? [];
        $smokeEntry = null;
        foreach ($closureSequence as $entry) {
            if (($entry['artifact'] ?? '') === 'real_provider_smoke') {
                $smokeEntry = $entry;
            }
        }
        $this->assertNotNull($smokeEntry);
        $this->assertNotEmpty($smokeEntry['expected_receipt_schema']);
        $this->assertFalse($smokeEntry['passed']);
    }

    public function test_build_reports_missing_human_completion_receipt_schema(): void
    {
        $result = $this->service()->build($this->minimalBuildOptions());

        $closureSequence = $result['closure_artifact_sequence'] ?? [];
        $humanEntry = null;
        foreach ($closureSequence as $entry) {
            if (($entry['artifact'] ?? '') === 'human_completion_receipt') {
                $humanEntry = $entry;
            }
        }
        $this->assertNotNull($humanEntry);
        $this->assertNotEmpty($humanEntry['expected_receipt_schema']);
        $this->assertFalse($humanEntry['passed']);
    }

    public function test_build_reports_missing_os_audit_schema(): void
    {
        $result = $this->service()->build($this->minimalBuildOptions());

        $closureSequence = $result['closure_artifact_sequence'] ?? [];
        $auditEntry = null;
        foreach ($closureSequence as $entry) {
            if (($entry['artifact'] ?? '') === 'final_completion_audit') {
                $auditEntry = $entry;
            }
        }
        $this->assertNotNull($auditEntry);
        $this->assertNotEmpty($auditEntry['expected_receipt_schema']);
    }

    public function test_build_reports_terminal_loop_proof_binding_expected_schema(): void
    {
        $result = $this->service()->build($this->minimalBuildOptions());

        $this->assertArrayHasKey('final_operator_packet', $result);
        $this->assertArrayHasKey('terminal_loop_operational_proof_expected_binding_schema', $result['final_operator_packet']);
        $this->assertNotEmpty($result['final_operator_packet']['terminal_loop_operational_proof_expected_binding_schema']);
    }

    // ── AC: build() marks bundle_ready=false on partial evidence ───────────

    public function test_build_marks_final_completion_allowed_false_on_empty_evidence(): void
    {
        $result = $this->service()->build($this->minimalBuildOptions());

        $this->assertFalse($result['final_readiness_map']['final_completion_allowed']);
        $this->assertFalse($result['final_readiness_map']['runtime_promotion_ready']);
        $this->assertFalse($result['final_readiness_map']['real_provider_smoke_ready']);
        $this->assertFalse($result['final_readiness_map']['human_completion_receipt_ready']);
    }

    public function test_build_does_not_overclaim_readiness_from_partial_evidence(): void
    {
        $result = $this->service()->build($this->minimalBuildOptions());

        // Even if some components are available, final_completion_allowed must be false.
        $this->assertFalse($result['final_readiness_map']['final_completion_allowed']);
        $this->assertFalse($result['machine_status']['completion_claim_allowed']);
    }

    // ── AC: build() emits next_action_shell_packet and blockers ─────────────

    public function test_build_emits_next_action_shell_packet(): void
    {
        $result = $this->service()->build($this->minimalBuildOptions());

        $this->assertArrayHasKey('final_operator_packet', $result);
        $this->assertArrayHasKey('next_action_shell_packet', $result['final_operator_packet']);
        $shellPacket = $result['final_operator_packet']['next_action_shell_packet'];
        $this->assertIsArray($shellPacket);
        $this->assertArrayHasKey('exact_command', $shellPacket);
        $this->assertArrayHasKey('persist_command', $shellPacket);
    }

    public function test_build_emits_blockers_deterministically(): void
    {
        $options = $this->minimalBuildOptions();
        $a = $this->service()->build($options);
        $b = $this->service()->build($options);

        $this->assertSame(
            $a['final_operator_packet']['what_is_blocked'],
            $b['final_operator_packet']['what_is_blocked'],
        );
    }

    public function test_build_next_action_shell_packet_is_deterministic(): void
    {
        $options = $this->minimalBuildOptions();
        $a = $this->service()->build($options);
        $b = $this->service()->build($options);

        $this->assertSame(
            $a['final_operator_packet']['next_action_shell_packet']['shell_packet_hash'],
            $b['final_operator_packet']['next_action_shell_packet']['shell_packet_hash'],
        );
    }

    // ── AC: build() includes all required receipt classes in closure sequence ─

    public function test_closure_artifact_sequence_includes_all_required_receipt_classes(): void
    {
        $result = $this->service()->build($this->minimalBuildOptions());

        $artifacts = array_column($result['closure_artifact_sequence'], 'artifact');
        $this->assertContains('runtime_promotion_receipt', $artifacts);
        $this->assertContains('real_provider_smoke', $artifacts);
        $this->assertContains('human_completion_receipt', $artifacts);
        $this->assertContains('final_completion_audit', $artifacts);
    }

    // ── safety invariants ──────────────────────────────────────────────────

    public function test_build_safety_invariants_all_true(): void
    {
        $result = $this->service()->build($this->minimalBuildOptions());

        $invariants = $result['safety_invariants'];
        $this->assertTrue($invariants['no_execution']);
        $this->assertTrue($invariants['no_provider_call']);
        $this->assertTrue($invariants['no_token_spend']);
        $this->assertTrue($invariants['no_dispatch']);
        $this->assertTrue($invariants['no_completion_claim']);
    }

    public function test_build_non_execution_guarantees_present(): void
    {
        $result = $this->service()->build($this->minimalBuildOptions());

        $this->assertNotEmpty($result['non_execution_guarantees']);
    }

    // ── missing real evidence ──────────────────────────────────────────────

    public function test_build_reports_missing_real_evidence(): void
    {
        $result = $this->service()->build($this->minimalBuildOptions());

        $missingReal = $result['evidence_dependencies']['missing_real_evidence'] ?? [];
        $this->assertIsArray($missingReal);
    }

    public function test_build_machine_status_next_action_is_collect_evidence_when_not_ready(): void
    {
        $result = $this->service()->build($this->minimalBuildOptions());

        $this->assertStringContainsString('collect_missing_real_evidence', $result['machine_status']['next_action']);
    }
}
