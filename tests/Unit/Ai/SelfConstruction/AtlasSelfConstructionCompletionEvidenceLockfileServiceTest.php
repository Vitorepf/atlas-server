<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\AtlasSelfConstructionCompletionEvidenceLockfileService;
use PHPUnit\Framework\TestCase;

final class AtlasSelfConstructionCompletionEvidenceLockfileServiceTest extends TestCase
{
    private function svc(): AtlasSelfConstructionCompletionEvidenceLockfileService
    {
        return new AtlasSelfConstructionCompletionEvidenceLockfileService;
    }

    private function fullEvidence(): array
    {
        return [
            'bundle_hash' => 'abc',
            'completion_audit_hash' => 'audit-123',
            'runtime_gap_matrix_hash' => 'gap-456',
            'receipt_hash' => 'receipt-789',
            'smoke_hash' => 'smoke-012',
            'release_dossier_hash' => 'dossier-345',
            'replay_diff_hash' => 'replay-678',
        ];
    }

    public function test_clean_lockfile_passes(): void
    {
        $svc = $this->svc();
        $evidence = $this->fullEvidence();
        $lockfile = $svc->build($evidence);

        $result = $svc->verify($lockfile, $evidence);

        $this->assertSame('passed', $result['status']);
        $this->assertSame(0, $result['violation_count']);
    }

    public function test_mutated_safety_flags_block(): void
    {
        $svc = $this->svc();
        $evidence = $this->fullEvidence();
        $lockfile = $svc->build($evidence);

        // Mutate: set execution_allowed to true
        $lockfile['safety_flags']['execution_allowed'] = true;
        // Recompute hash so self-consistency check passes — safety check is independent
        $lockfile['lockfile_hash'] = hash('sha256', 'tampered');

        $result = $svc->verify($lockfile, $evidence);

        $this->assertSame('blocked', $result['status']);
        $codes = array_column($result['violations'], 'code');
        $this->assertContains('lockfile_safety_flags_mismatch', $codes);
    }

    public function test_persistence_allowed_true_blocks(): void
    {
        $svc = $this->svc();
        $evidence = $this->fullEvidence();
        $lockfile = $svc->build($evidence);

        $lockfile['persistence_allowed'] = true;
        $lockfile['lockfile_hash'] = hash('sha256', 'tampered');

        $result = $svc->verify($lockfile, $evidence);

        $this->assertSame('blocked', $result['status']);
        $codes = array_column($result['violations'], 'code');
        $this->assertContains('lockfile_safety_flags_mismatch', $codes);
    }

    public function test_existing_violations_still_detected(): void
    {
        $svc = $this->svc();
        $evidence = $this->fullEvidence();
        $lockfile = $svc->build($evidence);

        // Corrupt schema AND safety_flags
        $lockfile['schema_version'] = 'wrong';
        $lockfile['safety_flags']['execution_allowed'] = true;
        $lockfile['lockfile_hash'] = hash('sha256', 'tampered');

        $result = $svc->verify($lockfile, $evidence);

        $this->assertSame('blocked', $result['status']);
        $codes = array_column($result['violations'], 'code');
        $this->assertContains('lockfile_schema_invalid', $codes);
        $this->assertContains('lockfile_safety_flags_mismatch', $codes);
    }

    // ── AC2: missing or modified evidence fields ─────────────────────────────

    public function test_missing_required_evidence_field_blocks(): void
    {
        $svc = $this->svc();
        $evidence = $this->fullEvidence();
        $lockfile = $svc->build($evidence);

        // Remove a required evidence field
        unset($lockfile['evidence_hashes']['completion_audit_hash']);
        $lockfile['lockfile_hash'] = hash('sha256', 'tampered');

        $result = $svc->verify($lockfile, $evidence);

        $this->assertSame('blocked', $result['status']);
        $codes = array_column($result['violations'], 'code');
        $this->assertContains('lockfile_missing_required_evidence_field', $codes);
    }

    public function test_modified_evidence_field_blocks(): void
    {
        $svc = $this->svc();
        $evidence = $this->fullEvidence();
        $lockfile = $svc->build($evidence);

        // Modify a required evidence field after lockfile creation
        $lockfile['evidence_hashes']['bundle_hash'] = 'tampered_hash';
        $lockfile['lockfile_hash'] = hash('sha256', 'tampered');

        $result = $svc->verify($lockfile, $evidence);

        $this->assertSame('blocked', $result['status']);
        $codes = array_column($result['violations'], 'code');
        $this->assertContains('lockfile_evidence_field_modified', $codes);
    }

    // ── AC3: volatile fields don't change substantive hash ────────────────────

    public function test_volatile_fields_do_not_change_substantive_hash(): void
    {
        $svc = $this->svc();
        $evidence = $this->fullEvidence();

        $lock1 = $svc->build($evidence);
        // Simulate different generated_at by building again
        $lock2 = $svc->build($evidence);

        // The lockfile_hash should be the same despite different generated_at timestamps
        $this->assertSame($lock1['lockfile_hash'], $lock2['lockfile_hash']);
    }

    // ── AC4: raw payloads redacted ────────────────────────────────────────────

    public function test_lockfile_redacts_raw_prompt(): void
    {
        $svc = $this->svc();
        $evidence = $this->fullEvidence();
        $evidence['raw_prompt'] = 'sensitive prompt data';
        $evidence['prompt_text'] = 'more prompt data';

        $lockfile = $svc->build($evidence);

        $this->assertArrayNotHasKey('raw_prompt', $lockfile);
        $this->assertArrayNotHasKey('prompt_text', $lockfile);
        $this->assertContains('raw_prompt', $lockfile['redacted_fields']);
        $this->assertContains('prompt_text', $lockfile['redacted_fields']);
    }

    public function test_lockfile_redacts_raw_trace(): void
    {
        $svc = $this->svc();
        $evidence = $this->fullEvidence();
        $evidence['raw_trace'] = ['trace' => 'data'];
        $evidence['trace_data'] = ['more' => 'trace'];

        $lockfile = $svc->build($evidence);

        $this->assertArrayNotHasKey('raw_trace', $lockfile);
        $this->assertArrayNotHasKey('trace_data', $lockfile);
    }

    public function test_lockfile_redacts_raw_provider_payload(): void
    {
        $svc = $this->svc();
        $evidence = $this->fullEvidence();
        $evidence['raw_provider_payload'] = ['provider' => 'data'];
        $evidence['provider_request_body'] = 'request body';
        $evidence['provider_response_body'] = 'response body';

        $lockfile = $svc->build($evidence);

        $this->assertArrayNotHasKey('raw_provider_payload', $lockfile);
        $this->assertArrayNotHasKey('provider_request_body', $lockfile);
        $this->assertArrayNotHasKey('provider_response_body', $lockfile);
    }

    public function test_verify_detects_leaked_raw_payload(): void
    {
        $svc = $this->svc();
        $evidence = $this->fullEvidence();
        $lockfile = $svc->build($evidence);

        // Inject a raw payload field that should have been redacted
        $lockfile['raw_prompt'] = 'leaked prompt';
        $lockfile['lockfile_hash'] = hash('sha256', 'tampered');

        $result = $svc->verify($lockfile, $evidence);

        $this->assertSame('blocked', $result['status']);
        $codes = array_column($result['violations'], 'code');
        $this->assertContains('lockfile_raw_payload_leaked', $codes);
    }
}
