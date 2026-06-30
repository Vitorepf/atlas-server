<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\MergeGovernor;

use App\Services\Ai\SelfConstruction\MergeGovernor\AtlasMergeGovernorAdmissionPolicy;
use App\Services\Ai\SelfConstruction\MergeGovernor\AtlasMergeGovernorReleaseDecisionLedger;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Proves AtlasMergeGovernorReleaseDecisionLedger: valid payload appends one canonical JSONL row;
 * duplicate decision_hash returns already_recorded without a second row; invalid decision string
 * throws; missing verification_hash throws; listChronological filters by --since/--until and returns
 * newest-first.
 */
final class AtlasMergeGovernorReleaseDecisionLedgerTest extends TestCase
{
    private string $ledgerPath;

    private AtlasMergeGovernorReleaseDecisionLedger $ledger;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ledgerPath = sys_get_temp_dir().'/atlas_merge_decisions_'.bin2hex(random_bytes(6)).'.jsonl';
        $this->ledger = new AtlasMergeGovernorReleaseDecisionLedger($this->ledgerPath);
    }

    protected function tearDown(): void
    {
        @unlink($this->ledgerPath);
        parent::tearDown();
    }

    private function payload(string $taskId = 'pkt-1', string $decidedAt = '2026-06-25T00:00:00Z', array $overrides = []): array
    {
        return $overrides + [
            'task_packet_id' => $taskId,
            'candidate_hash' => 'cand-h-1',
            'decision' => AtlasMergeGovernorAdmissionPolicy::DECISION_ADMITTED,
            'reasons' => ['service_or_test_change'],
            'risk_level' => 'low',
            'verification_hash' => 'ver-h-1',
            'rollback_hash' => 'rb-h-1',
            'changed_files_hash' => 'cfh-1',
            'project_lane' => ['project_id' => 'demo'],
            'decided_at' => $decidedAt,
        ];
    }

    public function test_append_valid_payload_writes_one_canonical_row(): void
    {
        $res = $this->ledger->append($this->payload());

        $this->assertSame(AtlasMergeGovernorReleaseDecisionLedger::STATUS_OK, $res['status']);
        $this->assertSame(64, strlen($res['row']['decision_hash']));
        $this->assertCount(1, file($this->ledgerPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));
    }

    public function test_duplicate_decision_hash_idempotent_no_second_row(): void
    {
        $this->ledger->append($this->payload());
        $sizeAfterFirst = filesize($this->ledgerPath);

        $res2 = $this->ledger->append($this->payload());
        $this->assertSame(AtlasMergeGovernorReleaseDecisionLedger::STATUS_ALREADY, $res2['status']);
        $this->assertSame($sizeAfterFirst, filesize($this->ledgerPath));
    }

    public function test_invalid_decision_throws(): void
    {
        $p = $this->payload();
        $p['decision'] = 'merge_now_yolo';
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/invalid decision/');
        $this->ledger->append($p);
    }

    public function test_missing_verification_hash_throws(): void
    {
        $p = $this->payload();
        $p['verification_hash'] = '';
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/missing verification_hash/');
        $this->ledger->append($p);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('hashBindingProvider')]
    public function test_decision_hash_changes_when_bound_field_changes(string $field, string $newValue): void
    {
        $base = $this->ledger->append($this->payload()  );
        $mutated = $this->ledger->append($this->payload('pkt-1', '2026-06-25T00:00:00Z', [$field => $newValue]));
        $this->assertNotSame($base['row']['decision_hash'], $mutated['row']['decision_hash']);
    }

    public static function hashBindingProvider(): array
    {
        return [
            'task_packet_id' => ['task_packet_id', 'pkt-DIFFERENT'],
            'risk_level' => ['risk_level', 'high'],
            'verification_hash' => ['verification_hash', 'ver-h-DIFFERENT'],
            'rollback_hash' => ['rollback_hash', 'rb-h-DIFFERENT'],
            'changed_files_hash' => ['changed_files_hash', 'cfh-DIFFERENT'],
        ];
    }

    public function test_replay_verifies_valid_ledger_sequence(): void
    {
        $this->ledger->append($this->payload('pkt-a', '2026-06-25T00:00:00Z'));
        $this->ledger->append($this->payload('pkt-b', '2026-06-26T00:00:00Z'));

        $result = $this->ledger->replay();

        $this->assertTrue($result['valid']);
        $this->assertSame(2, $result['entry_count']);
        $this->assertSame([], $result['issues']);
    }

    public function test_replay_rejects_mutated_entry(): void
    {
        $this->ledger->append($this->payload());

        $lines = file($this->ledgerPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        $entry = json_decode((string) $lines[0], true);
        $entry['risk_level'] = 'blocked'; // tamper
        file_put_contents($this->ledgerPath, json_encode($entry)."\n");

        $result = $this->ledger->replay();

        $this->assertFalse($result['valid']);
        $this->assertCount(1, $result['issues']);
        $this->assertSame('hash_mismatch', $result['issues'][0]['reason']);
    }

    public function test_chronological_list_filters_and_sorts_newest_first(): void
    {
        $this->ledger->append($this->payload('pkt-a', '2026-06-25T00:00:00Z'));
        $this->ledger->append($this->payload('pkt-b', '2026-06-26T00:00:00Z'));
        $this->ledger->append($this->payload('pkt-c', '2026-06-27T00:00:00Z'));

        $all = $this->ledger->listChronological();
        $this->assertSame(['pkt-c', 'pkt-b', 'pkt-a'], array_column($all, 'task_packet_id'));

        $filtered = $this->ledger->listChronological(sinceIso: '2026-06-26T00:00:00Z');
        $this->assertSame(['pkt-c', 'pkt-b'], array_column($filtered, 'task_packet_id'));
    }
}
