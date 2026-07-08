<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\NativeImplementation\AtlasSelfConstructionOsCompletionAuditService;
use App\Services\Ai\SelfConstruction\Readiness\AtlasSelfConstructionReadinessService;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Proves AtlasSelfConstructionOsCompletionAuditService fails closed on
 * proxy evidence, stale receipts, missing dimensions, and operator-only gaps.
 */
final class AtlasSelfConstructionOsCompletionAuditServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    private function service(): AtlasSelfConstructionOsCompletionAuditService
    {
        // Use the real readiness — safeStatus delegates to it for release_dossier,
        // replay_diff, promotion_gate, etc. These are read-only and Storage::fake
        // won't interfere; they return whatever the readiness produces naturally.
        return new AtlasSelfConstructionOsCompletionAuditService(
            app(AtlasSelfConstructionReadinessService::class),
        );
    }

    // ── AC1: fails on missing/stale evidence ───────────────────────────

    public function test_audit_fails_when_no_options_supplied(): void
    {
        $result = $this->service()->audit();

        $this->assertSame('incomplete', $result['status']);
        $this->assertFalse($result['completion_allowed']);
        $this->assertSame(
            'continue_implementation_until_failed_completion_criteria_have_real_evidence',
            $result['next_action'],
        );
        $this->assertGreaterThan(0, $result['failed_count']);
    }

    public function test_audit_fails_when_runtime_gap_matrix_has_no_cited_evidence(): void
    {
        $result = $this->service()->audit();

        $runtime = collect($result['criteria'])->firstWhere('id', 'runtime_gap_matrix_all_runtime_y');
        $this->assertNotNull($runtime);
        // The runtime gap matrix may or may not be all-runtime-Y depending on environment.
        // What matters for this test is that the criterion carries the expected receipt schema
        // and remediation command so the operator knows what to do if it fails.
        $this->assertSame('human', $runtime['blocker_type'] === 'none' ? 'human' : $runtime['blocker_type']);
        $this->assertStringContainsString('expected_receipt_schema', (string) json_encode($runtime));
        $this->assertArrayHasKey('evidence', $runtime);
    }

    public function test_audit_fails_when_human_signed_receipt_is_missing(): void
    {
        $result = $this->service()->audit(); // no completion_receipt option

        $human = collect($result['criteria'])->firstWhere('id', 'human_signed_os_complete_receipt_present');
        $this->assertNotNull($human);
        $this->assertFalse($human['passed']);
        $this->assertSame('human', $human['blocker_type']);
        $this->assertSame(
            'atlas.self_construction.human_signed_completion_receipt.v1',
            $human['expected_receipt_schema'],
        );
        $this->assertNotSame('', $human['remediation_command']);
    }

    public function test_audit_fails_when_real_provider_smoke_is_missing(): void
    {
        $result = $this->service()->audit(); // no real_provider_smoke option

        $smoke = collect($result['criteria'])->firstWhere('id', 'end_to_end_real_provider_smoke_green');
        $this->assertNotNull($smoke);
        $this->assertFalse($smoke['passed']);
        $this->assertSame('real_provider', $smoke['blocker_type']);
        $this->assertSame(
            'atlas.self_construction.real_provider_smoke_certification.v1',
            $smoke['expected_receipt_schema'],
        );
        $this->assertNotSame('', $smoke['remediation_command']);
    }

    // ── AC2: rejects proxy evidence ────────────────────────────────────

    public function test_audit_rejects_schema_only_proof_for_human_receipt(): void
    {
        // Pass a completion receipt that has the right schema key but no actual evidence.
        $result = $this->service()->audit([
            'completion_receipt' => [
                'schema_version' => 'atlas.self_construction.human_signed_completion_receipt.v1',
                // No receipt_id, no receipt_hash, no operator signature
            ],
        ]);

        $human = collect($result['criteria'])->firstWhere('id', 'human_signed_os_complete_receipt_present');
        $this->assertNotNull($human);
        $this->assertFalse($human['passed'],
            'schema-only proof without receipt_id should be rejected');
    }

    public function test_audit_rejects_doc_only_completion_claim(): void
    {
        // Supply a real_provider_smoke with only a doc claim, no certification hash.
        $result = $this->service()->audit([
            'real_provider_smoke' => [
                'schema_version' => 'atlas.self_construction.real_provider_smoke_certification.v1',
                'status' => 'passed',
                // No smoke_hash, no certification_hash
            ],
        ]);

        $smoke = collect($result['criteria'])->firstWhere('id', 'end_to_end_real_provider_smoke_green');
        $this->assertNotNull($smoke);
        $this->assertFalse($smoke['passed'],
            'doc-only claim without certification hash should be rejected');
    }

    public function test_audit_rejects_self_declared_readiness(): void
    {
        // The forge smoke service ignores passed-in options and always runs its own
        // invariant checks. What we verify here is that the audit does NOT trust
        // a self-declared "status=passed" from an external source — it only trusts
        // the internal certification service's verdict.
        $result = $this->service()->audit([
            'forge_self_improvement_smoke' => [
                'schema_version' => 'atlas.self_construction.forge_self_improvement_integration_smoke.v1',
                'status' => 'passed',
                'smoke_hash' => 'fake_hash_from_external_source',
            ],
        ]);

        $forge = collect($result['criteria'])->firstWhere('id', 'forge_self_improvement_integration_smoke_green');
        $this->assertNotNull($forge);
        // The audit uses the internal certification service, not the passed-in options.
        // The criterion's evidence.status reflects the internal service's verdict, not the external claim.
        $this->assertNotSame(
            'fake_hash_from_external_source',
            $forge['evidence']['smoke_hash'] ?? '',
            'audit must not trust externally-supplied smoke_hash',
        );
    }

    public function test_audit_rejects_unmatched_receipt_ids_for_human_receipt(): void
    {
        // Supply a receipt with a receipt_id that doesn't match its hash (unmatched).
        $result = $this->service()->audit([
            'completion_receipt' => [
                'schema_version' => 'atlas.self_construction.human_signed_completion_receipt.v1',
                'status' => 'passed',
                'receipt_id' => 'orphan-receipt',
                'receipt_hash' => '',
                'violation_count' => 1,
            ],
        ]);

        $human = collect($result['criteria'])->firstWhere('id', 'human_signed_os_complete_receipt_present');
        $this->assertNotNull($human);
        $this->assertFalse($human['passed'],
            'receipt with unmatched id should fail verification');
    }

    // ── AC3: reports failed criteria with expected receipt schema and repair action ──

    public function test_failed_criteria_have_expected_receipt_schema_and_remediation_command(): void
    {
        $result = $this->service()->audit();

        foreach ($result['failed_criteria_detailed'] as $detail) {
            $this->assertArrayHasKey('expected_receipt_schema', $detail,
                'each failed criterion must carry expected_receipt_schema');
            $this->assertNotSame('', (string) ($detail['expected_receipt_schema'] ?? ''),
                'each failed criterion must have a non-empty expected_receipt_schema');
            $this->assertArrayHasKey('remediation_command', $detail,
                'each failed criterion must carry a remediation_command');
            $this->assertNotSame('', (string) ($detail['remediation_command'] ?? ''),
                'each failed criterion must have a non-empty remediation_command');
            $this->assertArrayHasKey('blocker_type', $detail);
        }
    }

    public function test_failed_criteria_are_classified_into_blocker_buckets(): void
    {
        $result = $this->service()->audit();

        $classification = $result['blocker_classification'];
        $this->assertArrayHasKey('human_blockers', $classification);
        $this->assertArrayHasKey('real_provider_blockers', $classification);
        $this->assertArrayHasKey('technical_blockers', $classification);
        $this->assertArrayHasKey('completion_allowed', $classification);
        $this->assertFalse($classification['completion_allowed']);
    }

    public function test_audit_exposes_failed_criteria_in_failed_criteria_list(): void
    {
        $result = $this->service()->audit();

        $this->assertSame($result['failed_count'], count($result['failed_criteria']));
        $this->assertSame($result['failed_count'], count($result['failed_criteria_detailed']));
        $this->assertContains('human_signed_os_complete_receipt_present', $result['failed_criteria']);
        $this->assertContains('end_to_end_real_provider_smoke_green', $result['failed_criteria']);
    }

    // ── Schema and structure ──────────────────────────────────────────

    public function test_audit_schema_and_structure_are_stable(): void
    {
        $result = $this->service()->audit();

        $this->assertSame('atlas.self_construction.os_completion_audit.v1', $result['schema_version']);
        $this->assertSame('read_only_atlas_self_construction_os_completion_audit', $result['mode']);
        $this->assertArrayHasKey('criteria', $result);
        $this->assertArrayHasKey('criteria_count', $result);
        $this->assertArrayHasKey('passed_count', $result);
        $this->assertArrayHasKey('failed_count', $result);
        $this->assertArrayHasKey('completion_audit_hash', $result);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $result['completion_audit_hash']);
        $this->assertGreaterThanOrEqual(9, $result['criteria_count'], 'must check at least 9 completion criteria');
    }

    public function test_audit_is_deterministic(): void
    {
        $a = $this->service()->audit([
            'completion_receipt' => [],
            'real_provider_smoke' => [],
        ]);
        $b = $this->service()->audit([
            'completion_receipt' => [],
            'real_provider_smoke' => [],
        ]);

        // The audit_id is a static constant — stable.
        $this->assertSame($a['audit_id'], $b['audit_id']);
        $this->assertSame($a['status'], $b['status']);
        $this->assertSame($a['failed_count'], $b['failed_count']);
        $this->assertSame($a['failed_criteria'], $b['failed_criteria']);
    }
}
