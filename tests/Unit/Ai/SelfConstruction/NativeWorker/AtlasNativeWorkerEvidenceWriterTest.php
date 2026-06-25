<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\NativeWorker;

use App\Services\Ai\SelfConstruction\NativeWorker\AtlasNativeWorkerEvidenceWriter;
use App\Services\Ai\SelfConstruction\NativeWorker\AtlasNativeWorkerExecutionEnvelopeBuilder;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Proves AtlasNativeWorkerEvidenceWriter: valid attempt appends one canonical JSONL row; an attempt
 * with the same (task_packet_id, envelope_hash) returns status=already_recorded and does NOT add a
 * second row; missing gate result throws; non Atlas-native runtime_owner throws; append order is
 * preserved across multiple writes.
 */
final class AtlasNativeWorkerEvidenceWriterTest extends TestCase
{
    private string $ledgerPath;

    private AtlasNativeWorkerEvidenceWriter $writer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ledgerPath = sys_get_temp_dir().'/atlas_native_evidence_'.bin2hex(random_bytes(6)).'.jsonl';
        $this->writer = new AtlasNativeWorkerEvidenceWriter($this->ledgerPath, static fn (): string => '2026-06-25T00:00:00Z');
    }

    protected function tearDown(): void
    {
        @unlink($this->ledgerPath);
        parent::tearDown();
    }

    private function validAttempt(string $packetId = 'pkt-a', string $envelopeHash = 'env-hash-1'): array
    {
        return [
            'task_packet_id' => $packetId,
            'envelope_hash' => $envelopeHash,
            'runtime_owner' => AtlasNativeWorkerExecutionEnvelopeBuilder::RUNTIME_OWNER,
            'files_changed' => ['app/Foo.php'],
            'commands_run' => [['name' => 'phpunit', 'exit_code' => 0]],
            'tests_or_gates_result' => ['passed' => true, 'gate' => 'phpunit', 'count' => 1],
            'scope_deviations' => [],
            'residual_risks' => ['none'],
        ];
    }

    public function test_valid_attempt_appends_one_canonical_jsonl_row(): void
    {
        $res = $this->writer->append($this->validAttempt());

        $this->assertSame(AtlasNativeWorkerEvidenceWriter::STATUS_OK, $res['status']);
        $this->assertArrayHasKey('row', $res);
        $this->assertSame(64, strlen($res['row']['content_hash']));

        $lines = file($this->ledgerPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $this->assertCount(1, $lines);
        $decoded = json_decode($lines[0], true);
        $this->assertSame(AtlasNativeWorkerEvidenceWriter::SCHEMA, $decoded['schema']);
    }

    public function test_duplicate_task_packet_id_envelope_hash_tuple_returns_already_recorded_without_second_row(): void
    {
        $this->writer->append($this->validAttempt());
        $sizeAfterFirst = filesize($this->ledgerPath);

        $res2 = $this->writer->append($this->validAttempt());
        $sizeAfterSecond = filesize($this->ledgerPath);

        $this->assertSame(AtlasNativeWorkerEvidenceWriter::STATUS_ALREADY, $res2['status']);
        $this->assertSame($sizeAfterFirst, $sizeAfterSecond, 'duplicate must not append a second row');
    }

    public function test_missing_gate_result_throws(): void
    {
        $a = $this->validAttempt();
        unset($a['tests_or_gates_result']);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/missing tests_or_gates_result/');
        $this->writer->append($a);
    }

    public function test_non_atlas_native_runtime_owner_throws(): void
    {
        $a = $this->validAttempt();
        $a['runtime_owner'] = 'external_provider';
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/non Atlas-native runtime_owner/');
        $this->writer->append($a);
    }

    public function test_unacknowledged_scope_deviation_throws(): void
    {
        $a = $this->validAttempt();
        $a['scope_deviations'] = [['path' => 'app/Bar.php', 'acknowledged' => false, 'reason' => 'oops']];
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/unacknowledged scope deviation/');
        $this->writer->append($a);
    }

    public function test_append_order_is_preserved_across_multiple_writes(): void
    {
        $this->writer->append($this->validAttempt('pkt-a', 'h-a'));
        $this->writer->append($this->validAttempt('pkt-b', 'h-b'));
        $this->writer->append($this->validAttempt('pkt-c', 'h-c'));

        $rows = $this->writer->all();
        $ids = array_column($rows, 'task_packet_id');
        $this->assertSame(['pkt-a', 'pkt-b', 'pkt-c'], $ids);
    }
}
