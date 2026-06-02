<?php

namespace Tests\Unit\Ai\Hermes\Mesh;

use App\Services\Ai\Hermes\Mesh\HermesSessionEvidenceImporter;
use Tests\TestCase;

class HermesSessionEvidenceImporterTest extends TestCase
{
    private function importer(): HermesSessionEvidenceImporter
    {
        return new HermesSessionEvidenceImporter();
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function listing(): array
    {
        return [
            ['id' => 'sess-001', 'turn_count' => 12, 'created_at' => '2026-06-01T10:00:00Z', 'title' => 'secret goal'],
            ['session_id' => 'sess-002', 'turns' => '3'],
        ];
    }

    public function test_approved_path_imports_evidence_candidates(): void
    {
        $receipt = $this->importer()->import($this->listing(), ['enabled' => true]);

        $this->assertSame('schema_version', array_key_first($receipt));
        $this->assertSame('atlas.hermes.session_evidence_import.v1', $receipt['schema_version']);
        $this->assertTrue($receipt['enabled']);
        $this->assertSame('atlas', $receipt['authority']);
        $this->assertFalse($receipt['hermes_session_can_decide']);
        $this->assertFalse($receipt['promotion_allowed_now']);
        $this->assertSame(2, $receipt['imported_count']);
        $this->assertNull($receipt['blocked_reason']);
        $this->assertSame('evidence_candidates_imported', $receipt['status']);
        $this->assertCount(2, $receipt['candidates']);

        $first = $receipt['candidates'][0];
        $this->assertSame('evidence_candidate', $first['class']);
        $this->assertSame(12, $first['turn_count']);
        $this->assertSame('2026-06-01T10:00:00Z', $first['created_at']);
        // turns string coerced to int
        $this->assertSame(3, $receipt['candidates'][1]['turn_count']);
    }

    public function test_no_raw_id_title_or_content_leaks_into_receipt(): void
    {
        $receipt = $this->importer()->import($this->listing(), ['enabled' => true]);

        $encoded = json_encode($receipt);
        $this->assertStringNotContainsString('sess-001', $encoded);
        $this->assertStringNotContainsString('sess-002', $encoded);
        $this->assertStringNotContainsString('secret goal', $encoded);

        // id is present only as a sha256 hash
        $expectedHash = hash('sha256', json_encode(['sess-001'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $this->assertSame($expectedHash, $receipt['candidates'][0]['session_id_hash']);
    }

    public function test_default_off_policy_fails_closed(): void
    {
        $receipt = $this->importer()->import($this->listing(), []);

        $this->assertFalse($receipt['enabled']);
        $this->assertSame([], $receipt['candidates']);
        $this->assertSame(0, $receipt['imported_count']);
        $this->assertFalse($receipt['promotion_allowed_now']);
        $this->assertFalse($receipt['hermes_session_can_decide']);
        $this->assertSame('atlas', $receipt['authority']);
        $this->assertSame('session_evidence_policy_not_atlas_adapter', $receipt['blocked_reason']);
        $this->assertSame('evidence_import_blocked', $receipt['status']);
    }

    public function test_empty_or_invalid_listing_fails_closed_even_when_enabled(): void
    {
        $empty = $this->importer()->import([], ['enabled' => true]);
        $this->assertFalse($empty['enabled']);
        $this->assertSame(0, $empty['imported_count']);
        $this->assertSame('session_listing_empty_or_invalid', $empty['blocked_reason']);

        // entries with no usable identifier are dropped -> still blocked
        $invalid = $this->importer()->import([['title' => 'x'], 'not-an-array'], ['enabled' => true]);
        $this->assertFalse($invalid['enabled']);
        $this->assertSame(0, $invalid['imported_count']);
        $this->assertSame([], $invalid['candidates']);
        $this->assertSame('session_listing_empty_or_invalid', $invalid['blocked_reason']);
    }

    public function test_invalid_entries_are_clamped_out_but_valid_ones_kept(): void
    {
        $listing = [
            'not-an-array',
            ['title' => 'no id here'],
            ['id' => '  sess-keep  ', 'turn_count' => -5],
        ];

        $receipt = $this->importer()->import($listing, ['enabled' => true]);

        $this->assertTrue($receipt['enabled']);
        $this->assertSame(1, $receipt['imported_count']);
        // negative turn count clamped to 0
        $this->assertSame(0, $receipt['candidates'][0]['turn_count']);
        // trimmed id hashed; no created_at key when absent
        $this->assertArrayNotHasKey('created_at', $receipt['candidates'][0]);
        $this->assertSame(
            hash('sha256', json_encode(['sess-keep'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)),
            $receipt['candidates'][0]['session_id_hash'],
        );
    }

    public function test_accepts_sessions_wrapper_shape(): void
    {
        $receipt = $this->importer()->import(['sessions' => $this->listing()], ['enabled' => true]);

        $this->assertTrue($receipt['enabled']);
        $this->assertSame(2, $receipt['imported_count']);
    }

    public function test_receipt_hash_present_deterministic_and_promotion_off_when_disabled(): void
    {
        $a = $this->importer()->import($this->listing(), ['enabled' => true]);
        $b = $this->importer()->import($this->listing(), ['enabled' => true]);

        $this->assertArrayHasKey('receipt_hash', $a);
        $this->assertSame('receipt_hash', array_key_last($a));
        $this->assertSame($a['receipt_hash'], $b['receipt_hash']);

        $blocked = $this->importer()->import($this->listing(), ['enabled' => false]);
        $this->assertArrayHasKey('receipt_hash', $blocked);
        $this->assertFalse($blocked['promotion_allowed_now']);
        $this->assertNotSame($a['receipt_hash'], $blocked['receipt_hash']);

        // receipt_hash matches a recompute over the receipt minus the hash key
        $clone = $a;
        unset($clone['receipt_hash']);
        $this->assertSame(
            hash('sha256', json_encode($clone, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)),
            $a['receipt_hash'],
        );
    }
}
