<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Services\Ai\SelfConstruction\Maestro\Provenance\AtlasMaestroPacketProvenanceComposer;
use App\Services\Ai\SelfConstruction\Maestro\Provenance\AtlasMaestroPacketProvenanceReceiptLedger;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

final class AtlasTaskMaestroProvenanceCommandTest extends TestCase
{
    private string $ledgerPath = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->ledgerPath = sys_get_temp_dir().'/atlas-prov-cli-'.bin2hex(random_bytes(6)).'.jsonl';
        app()->instance(
            AtlasMaestroPacketProvenanceReceiptLedger::class,
            new AtlasMaestroPacketProvenanceReceiptLedger($this->ledgerPath),
        );
    }

    protected function tearDown(): void
    {
        @unlink($this->ledgerPath);
        parent::tearDown();
    }

    private function composeRecord(string $packet): array
    {
        return (new AtlasMaestroPacketProvenanceComposer())->compose($packet, [
            'origin_kind' => 'operator_intent',
            'origin_id' => 'op-1',
            'chain' => [
                ['source_kind' => 'operator_intent', 'source_id' => 'op-1', 'captured_at' => '2026-06-25T05:00:00Z'],
            ],
        ]);
    }

    private function canonicalRecordJson(array $record): string
    {
        $canon = [
            'chain' => $record['chain'] ?? [],
            'origin_id' => (string) ($record['origin_id'] ?? ''),
            'origin_kind' => (string) ($record['origin_kind'] ?? ''),
            'packet_id' => (string) ($record['packet_id'] ?? ''),
        ];

        return (string) json_encode($this->sortDeep($canon), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private function sortDeep(mixed $v): mixed
    {
        if (! is_array($v)) {
            return $v;
        }
        if (array_is_list($v)) {
            return array_map(fn ($x) => $this->sortDeep($x), $v);
        }
        ksort($v, SORT_STRING);
        foreach ($v as $k => $vv) {
            $v[$k] = $this->sortDeep($vv);
        }
        return $v;
    }

    private function seedReceipt(string $packet, array $record, int $sequence = 0, ?string $written = null): array
    {
        $ledger = app(AtlasMaestroPacketProvenanceReceiptLedger::class);

        return $ledger->append(
            $packet,
            $sequence,
            hash('sha256', (string) json_encode($record, JSON_UNESCAPED_SLASHES)),
            $record,
            $written ?? '2026-06-25T05:00:00Z',
        );
    }

    public function test_verify_ok_for_a_well_formed_record_exits_zero(): void
    {
        $packet = 'pkt-A';
        $record = $this->composeRecord($packet);
        $this->seedReceipt($packet, $record);

        $exit = Artisan::call('atlas:task:maestro:provenance', ['action' => 'verify', '--packet' => $packet, '--json' => true]);
        $this->assertSame(0, $exit);
        $verdict = json_decode(trim(Artisan::output()), true);
        $this->assertTrue($verdict['ok']);
        $this->assertSame('OK', $verdict['reason_code']);
    }

    public function test_verify_hash_mismatch_for_tampered_record_exits_non_zero(): void
    {
        $packet = 'pkt-B';
        $record = $this->composeRecord($packet);
        // Tamper: invalidate the link content_hash so verifier returns HASH_MISMATCH.
        $record['chain'][0]['content_hash'] = str_repeat('0', 64);
        $this->seedReceipt($packet, $record);

        $exit = Artisan::call('atlas:task:maestro:provenance', ['action' => 'verify', '--packet' => $packet, '--json' => true]);
        $this->assertNotSame(0, $exit);
        $verdict = json_decode(trim(Artisan::output()), true);
        $this->assertFalse($verdict['ok']);
        $this->assertSame('HASH_MISMATCH', $verdict['reason_code']);
    }

    public function test_trace_json_matches_canonical_serialization(): void
    {
        $packet = 'pkt-C';
        $record = $this->composeRecord($packet);
        $this->seedReceipt($packet, $record);

        $exit = Artisan::call('atlas:task:maestro:provenance', ['action' => 'trace', '--packet' => $packet, '--json' => true]);
        $this->assertSame(0, $exit);
        $output = trim(Artisan::output());
        $this->assertSame($this->canonicalRecordJson($record), $output);

        $decoded = json_decode($output, true);
        $keys = array_keys($decoded);
        sort($keys);
        $this->assertSame(['chain', 'origin_id', 'origin_kind', 'packet_id'], $keys);
    }

    public function test_history_with_packet_returns_last_three_in_sequence_order(): void
    {
        $packet = 'pkt-D';
        $base = $this->composeRecord($packet);
        for ($i = 0; $i < 5; $i++) {
            $r = $base;
            $r['_seq'] = $i;
            $this->seedReceipt($packet, $r, $i, '2026-06-25T05:0'.$i.':00Z');
        }
        $exit = Artisan::call('atlas:task:maestro:provenance', ['action' => 'history', '--packet' => $packet, '--limit' => 3, '--json' => true]);
        $this->assertSame(0, $exit);
        $rows = json_decode(trim(Artisan::output()), true);
        $this->assertCount(3, $rows);
        $this->assertSame([2, 3, 4], array_map(static fn (array $r): int => (int) $r['sequence_no'], $rows));
    }

    public function test_trace_json_without_packet_refuses_with_json_envelope(): void
    {
        // Regression: trace --json without --packet must emit JSON, not raw error text.
        $exit = Artisan::call('atlas:task:maestro:provenance', ['action' => 'trace', '--json' => true]);

        $this->assertSame(2, $exit);
        $payload = json_decode(trim(Artisan::output()), true);
        $this->assertIsArray($payload, 'output must be valid JSON on refusal with --json');
        $this->assertSame('refused', $payload['status']);
        $this->assertNotEmpty($payload['reason']);
    }

    public function test_verify_json_without_packet_or_receipt_refuses_with_json_envelope(): void
    {
        // Regression: verify --json without --packet/--receipt must emit JSON.
        $exit = Artisan::call('atlas:task:maestro:provenance', ['action' => 'verify', '--json' => true]);

        $this->assertSame(2, $exit);
        $payload = json_decode(trim(Artisan::output()), true);
        $this->assertIsArray($payload, 'output must be valid JSON on refusal with --json');
        $this->assertSame('refused', $payload['status']);
        $this->assertNotEmpty($payload['reason']);
    }

    public function test_trace_without_json_flag_prints_human_readable_error(): void
    {
        // Preserve: without --json, refusal stays as plain text (not JSON).
        $exit = Artisan::call('atlas:task:maestro:provenance', ['action' => 'trace']);

        $this->assertSame(2, $exit);
        $this->assertNull(json_decode(trim(Artisan::output())));
    }

    public function test_history_global_tail_truncates_to_limit(): void
    {
        for ($i = 0; $i < 4; $i++) {
            $packet = 'pkt-G'.$i;
            $r = $this->composeRecord($packet);
            $this->seedReceipt($packet, $r, 0, '2026-06-25T05:'.str_pad((string) $i, 2, '0', STR_PAD_LEFT).':00Z');
        }
        $exit = Artisan::call('atlas:task:maestro:provenance', ['action' => 'history', '--limit' => 2, '--json' => true]);
        $this->assertSame(0, $exit);
        $rows = json_decode(trim(Artisan::output()), true);
        $this->assertCount(2, $rows);
    }
}
