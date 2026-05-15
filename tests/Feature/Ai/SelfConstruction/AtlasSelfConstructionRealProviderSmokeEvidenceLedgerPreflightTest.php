<?php

namespace Tests\Feature\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\AtlasSelfConstructionRealProviderSmokeEvidenceLedgerPreflightService;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class AtlasSelfConstructionRealProviderSmokeEvidenceLedgerPreflightTest extends TestCase
{
    public function test_empty_input_is_blocked(): void
    {
        $result = $this->preflight()->preflight([]);

        $this->assertSame(AtlasSelfConstructionRealProviderSmokeEvidenceLedgerPreflightService::SCHEMA_VERSION, $result['schema_version']);
        $this->assertSame('blocked', $result['status']);
        $this->assertFalse((bool) $result['input_present']);
        $this->assertFalse((bool) $result['can_call_certifier']);
    }

    public function test_blocks_when_cost_event_hash_is_missing(): void
    {
        $payload = $this->fullyHashedPayload();
        unset($payload['cost_event_hash']);

        $result = $this->preflight()->preflight($payload);

        $this->assertContains('cost_event_hash', $result['missing_hash_fields']);
        $this->assertSame('blocked', $result['status']);
    }

    public function test_blocks_when_work_product_manifest_hash_is_missing(): void
    {
        $payload = $this->fullyHashedPayload();
        unset($payload['work_product_manifest_hash']);

        $result = $this->preflight()->preflight($payload);

        $this->assertContains('work_product_manifest_hash', $result['missing_hash_fields']);
        $this->assertSame('blocked', $result['status']);
    }

    public function test_blocks_when_continuation_summary_hash_is_missing(): void
    {
        $payload = $this->fullyHashedPayload();
        unset($payload['continuation_summary_hash']);

        $result = $this->preflight()->preflight($payload);

        $this->assertContains('continuation_summary_hash', $result['missing_hash_fields']);
        $this->assertSame('blocked', $result['status']);
    }

    public function test_blocks_when_evidence_ledger_hash_is_missing(): void
    {
        $payload = $this->fullyHashedPayload();
        unset($payload['evidence_ledger_hash']);

        $result = $this->preflight()->preflight($payload);

        $this->assertContains('evidence_ledger_hash', $result['missing_hash_fields']);
        $this->assertSame('blocked', $result['status']);
    }

    public function test_blocks_when_provider_response_hash_is_missing(): void
    {
        $payload = $this->fullyHashedPayload();
        unset($payload['provider_response_hash']);

        $result = $this->preflight()->preflight($payload);

        $this->assertContains('provider_response_hash', $result['missing_hash_fields']);
        $this->assertSame('blocked', $result['status']);
    }

    public function test_blocks_when_operator_approval_receipt_hash_is_missing(): void
    {
        $payload = $this->fullyHashedPayload();
        unset($payload['operator_approval_receipt_hash']);

        $result = $this->preflight()->preflight($payload);

        $this->assertContains('operator_approval_receipt_hash', $result['missing_hash_fields']);
        $this->assertSame('blocked', $result['status']);
    }

    public function test_blocks_when_hash_contains_placeholder_prefix(): void
    {
        $payload = $this->fullyHashedPayload();
        $payload['cost_event_hash'] = '<placeholder_64_hex>';

        $result = $this->preflight()->preflight($payload);

        $this->assertContains('cost_event_hash', $result['placeholder_hash_fields']);
        $this->assertSame('blocked', $result['status']);
    }

    public function test_blocks_when_hash_is_not_64_hex(): void
    {
        $payload = $this->fullyHashedPayload();
        $payload['cost_event_hash'] = 'not-hex-and-too-short';

        $result = $this->preflight()->preflight($payload);

        $this->assertContains('cost_event_hash', $result['invalid_hash_fields']);
        $this->assertSame('blocked', $result['status']);
    }

    public function test_blocks_when_forbidden_flag_is_true(): void
    {
        $payload = $this->fullyHashedPayload();
        $payload['provider_called_by_atlas'] = true;

        $result = $this->preflight()->preflight($payload);

        $this->assertContains('provider_called_by_atlas', $result['forbidden_flags_true']);
        $this->assertSame('blocked', $result['status']);
    }

    public function test_blocks_when_synthetic_marker_is_present(): void
    {
        $payload = $this->fullyHashedPayload();
        $payload['provider_run_id'] = 'synthetic-mock-run-id';

        $result = $this->preflight()->preflight($payload);

        $markers = array_column((array) $result['synthetic_markers'], 'field');
        $this->assertContains('provider_run_id', $markers);
        $this->assertSame('blocked', $result['status']);
    }

    public function test_passes_when_all_hashes_present_and_clean(): void
    {
        $result = $this->preflight()->preflight($this->fullyHashedPayload());

        $this->assertSame('evidence_ledger_complete', $result['status']);
        $this->assertTrue((bool) $result['all_required_hashes_present']);
        $this->assertTrue((bool) $result['can_call_certifier']);
        $this->assertSame([], $result['missing_hash_fields']);
        $this->assertSame([], $result['placeholder_hash_fields']);
        $this->assertSame([], $result['invalid_hash_fields']);
        $this->assertSame([], $result['synthetic_markers']);
        $this->assertSame([], $result['forbidden_flags_true']);
    }

    public function test_preflight_does_not_persist(): void
    {
        Storage::fake('local');
        $this->preflight()->preflight($this->fullyHashedPayload());

        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    private function preflight(): AtlasSelfConstructionRealProviderSmokeEvidenceLedgerPreflightService
    {
        return new AtlasSelfConstructionRealProviderSmokeEvidenceLedgerPreflightService;
    }

    /** @return array<string, mixed> */
    private function fullyHashedPayload(): array
    {
        $hash = str_repeat('a', 64);

        return [
            'provider_run_id' => 'run-2026-05-15-001',
            'task_packet_id' => 'packet-2026-05-15-001',
            'observed_by' => 'Real Operator',
            'approval_reason' => 'Operator approved one real provider claim-to-completion smoke.',
            'evidence_ledger_hash' => $hash,
            'cost_event_hash' => $hash,
            'work_product_manifest_hash' => $hash,
            'continuation_summary_hash' => $hash,
            'provider_response_hash' => $hash,
            'operator_approval_receipt_hash' => $hash,
        ];
    }
}
