<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\LoopLedgerArchiveService;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * AP-810 · LHL-14 — LoopLedgerArchiveService.
 *
 * Read-only / deterministic / input-seam driven. No provider, no merge, no
 * destructive git, no raw deletion. Covers every "Tests:" bullet:
 *   - compact seals segments with hashes + builds index;
 *   - replay manifest reconstructs across segments;
 *   - simulated compaction failure => paused (no data loss);
 *   - retention respected;
 *   - stable report_hash (determinism).
 */
final class LoopLedgerArchiveServiceTest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmp = sys_get_temp_dir().'/atlas_lhl14_'.uniqid('', true);
        File::ensureDirectoryExists($this->tmp);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->tmp);
        parent::tearDown();
    }

    private function service(): LoopLedgerArchiveService
    {
        $service = app(LoopLedgerArchiveService::class);
        $service->setStorageRootForTesting($this->tmp.'/ledger');

        return $service;
    }

    /**
     * Four named segments, each with cycle records. With retain_raw_segments=1
     * the three oldest become sealable.
     *
     * @return list<array<string,mixed>>
     */
    private function segments(): array
    {
        return [
            ['name' => 'seg-0001.jsonl', 'records' => [
                ['cycle_id' => 'c1', 'cycle_hash' => 'sha256:h1', 'kind' => 'merged', 'merge_performed' => true],
                ['cycle_id' => 'c2', 'cycle_hash' => 'sha256:h2', 'kind' => 'blocked', 'blocked' => true],
            ]],
            ['name' => 'seg-0002.jsonl', 'records' => [
                ['cycle_id' => 'c3', 'cycle_hash' => 'sha256:h3', 'provider_invoked' => true],
            ]],
            ['name' => 'seg-0003.jsonl', 'records' => [
                ['cycle_id' => 'c4', 'cycle_hash' => 'sha256:h4', 'kind' => 'merged', 'merge_performed' => true],
            ]],
            ['name' => 'seg-0004.jsonl', 'records' => [
                ['cycle_id' => 'c5', 'cycle_hash' => 'sha256:h5'],
            ]],
        ];
    }

    public function test_compact_seals_segments_with_hashes_and_builds_index(): void
    {
        $report = $this->service()->compact([
            'segments' => $this->segments(),
            'retain_raw_segments' => 1,
        ]);

        $this->assertSame(LoopLedgerArchiveService::STATUS_OK, $report['status']);
        $this->assertSame('atlas.software_company_stewardship.loop_ledger_archive.v1', $report['schema_version']);
        $this->assertSame('LHL-14', $report['slice_id']);

        // 3 oldest sealed; newest (seg-0004) retained raw.
        $this->assertSame(3, $report['sealed_segment_count']);
        $this->assertSame(['seg-0004.jsonl'], $report['retained_raw_segments']);

        // Every sealed segment carries a real sha256 seal + preserves raw.
        foreach ($report['sealed_segments'] as $sealed) {
            $this->assertStringStartsWith('sha256:', (string) $sealed['sealed_hash']);
            $this->assertTrue($sealed['raw_preserved']);
        }

        // Compact index references the exact raw cycle ids (truth unchanged).
        $indexedIds = [];
        foreach ($report['compact_index'] as $entry) {
            foreach ($entry['cycles'] as $c) {
                $indexedIds[] = $c['cycle_id'];
            }
        }
        $this->assertSame(['c1', 'c2', 'c3', 'c4'], $indexedIds);
        $this->assertSame(4, $report['cycles_indexed']);
        $this->assertStringStartsWith('sha256:', (string) $report['compact_index_hash']);

        // Commit/blocker/provider summary computed from records.
        $this->assertSame(2, $report['summary']['commit_count']);
        $this->assertSame(1, $report['summary']['blocked_count']);
        $this->assertSame(1, $report['summary']['provider_call_count']);

        $this->assertTrue($report['replay_available']);
        $this->assertSame([], $report['blockers']);
    }

    public function test_replay_manifest_reconstructs_across_archive_and_raw_segments(): void
    {
        $service = $this->service();

        // Seal the oldest three; then replay must stitch archive + the raw tail.
        $compacted = $service->compact([
            'segments' => $this->segments(),
            'retain_raw_segments' => 1,
        ]);

        $replay = $service->replayManifest([
            'segments' => $this->segments(),
            'retain_raw_segments' => 1,
            'compact_index' => $compacted['compact_index'],
        ]);

        $this->assertSame(LoopLedgerArchiveService::STATUS_OK, $replay['status']);
        $this->assertTrue($replay['replay_complete']);

        // Reconstructs the full ordered stream across BOTH sources.
        $this->assertSame(['c1', 'c2', 'c3', 'c4', 'c5'], $replay['cycle_order']);
        $this->assertSame(5, $replay['total_cycles']);
        $this->assertTrue($replay['spans_archive_segments']);
        $this->assertTrue($replay['spans_raw_segments']);
        $this->assertTrue($replay['crosses_archive_and_raw']);
        $this->assertSame([], $replay['blockers']);
    }

    public function test_replay_works_across_segments_without_explicit_index(): void
    {
        // No compact_index seam supplied: the service derives the index from the
        // sealable segments itself and still replays across segments.
        $replay = $this->service()->replayManifest([
            'segments' => $this->segments(),
            'retain_raw_segments' => 1,
        ]);

        $this->assertSame(LoopLedgerArchiveService::STATUS_OK, $replay['status']);
        $this->assertSame(['c1', 'c2', 'c3', 'c4', 'c5'], $replay['cycle_order']);
        $this->assertTrue($replay['crosses_archive_and_raw']);
    }

    public function test_replay_pauses_on_archive_integrity_gap_and_preserves_evidence(): void
    {
        // A tampered sealed_hash (does not match the cycles) must PAUSE replay,
        // never return a silently-incomplete-but-ok stream.
        $tampered = [[
            'segment' => 'seg-0001.jsonl',
            'sealed_hash' => 'sha256:TAMPERED',
            'cycles' => [['cycle_id' => 'c1', 'record_hash' => 'sha256:h1']],
        ]];

        $replay = $this->service()->replayManifest([
            'segments' => $this->segments(),
            'retain_raw_segments' => 1,
            'compact_index' => $tampered,
        ]);

        $this->assertSame(LoopLedgerArchiveService::STATUS_PAUSED, $replay['status']);
        $this->assertFalse($replay['replay_complete']);
        $this->assertContains('archive_segment_integrity_failed:seg-0001.jsonl', $replay['blockers']);
        $this->assertContains('replay_incomplete_raw_evidence_preserved', $replay['warnings']);
    }

    public function test_simulated_compaction_failure_pauses_with_no_data_loss(): void
    {
        // Inject a seal failure on the middle segment.
        $report = $this->service()->compact([
            'segments' => $this->segments(),
            'retain_raw_segments' => 1,
            'seal_failures' => ['seg-0002.jsonl'],
        ]);

        // Paused — never dressed as ok.
        $this->assertSame(LoopLedgerArchiveService::STATUS_PAUSED, $report['status']);
        $this->assertNotSame(LoopLedgerArchiveService::STATUS_OK, $report['status']);
        $this->assertContains('compaction_failed_segment:seg-0002.jsonl', $report['blockers']);

        // No data loss: raw is preserved, replay not advertised, and the
        // claim_policy proves raw is never deleted before sealed.
        $this->assertFalse($report['replay_available']);
        $this->assertContains('raw_evidence_preserved_no_deletion_on_pause', $report['warnings']);
        $this->assertFalse($report['claim_policy']['deletes_raw_before_sealed']);
        $this->assertTrue($report['claim_policy']['paused_never_dressed_as_ok']);

        // Sealing stops AT the hole (no sealing past a corrupt segment).
        $this->assertSame(1, $report['sealed_segment_count']);
    }

    public function test_corrupt_jsonl_tail_on_disk_pauses_compaction(): void
    {
        // Real fixture ledger dir: a segment file with a corrupt tail line must
        // be treated as unsealable so compaction pauses (no inline records seam).
        $dir = $this->tmp.'/ledger';
        File::ensureDirectoryExists($dir);
        File::put($dir.'/seg-0001.jsonl', json_encode(['cycle_id' => 'c1', 'cycle_hash' => 'sha256:h1']).PHP_EOL.'{not valid json'.PHP_EOL);
        File::put($dir.'/seg-0002.jsonl', json_encode(['cycle_id' => 'c2', 'cycle_hash' => 'sha256:h2']).PHP_EOL);
        File::put($dir.'/seg-0003.jsonl', json_encode(['cycle_id' => 'c3', 'cycle_hash' => 'sha256:h3']).PHP_EOL);

        $report = $this->service()->compact(['retain_raw_segments' => 1]);

        $this->assertSame(LoopLedgerArchiveService::STATUS_PAUSED, $report['status']);
        $this->assertContains('compaction_failed_segment:seg-0001.jsonl', $report['blockers']);
        // Corrupt file was NOT deleted (raw preserved).
        $this->assertFileExists($dir.'/seg-0001.jsonl');
    }

    public function test_retention_respected_retains_recent_raw_segments(): void
    {
        // retain_raw_segments=2 => only the two oldest are sealable; two newest stay raw.
        $report = $this->service()->compact([
            'segments' => $this->segments(),
            'retain_raw_segments' => 2,
        ]);

        $this->assertSame(LoopLedgerArchiveService::STATUS_OK, $report['status']);
        $this->assertSame(2, $report['sealed_segment_count']);
        $this->assertSame(['seg-0003.jsonl', 'seg-0004.jsonl'], $report['retained_raw_segments']);
        $this->assertSame(2, $report['retention']['retain_raw_segments']);

        // plan() agrees on the same retention split and computes total bytes.
        $plan = $this->service()->plan([
            'segments' => $this->segments(),
            'retain_raw_segments' => 2,
        ]);
        $this->assertSame(LoopLedgerArchiveService::STATUS_OK, $plan['status']);
        $this->assertSame(['seg-0001.jsonl', 'seg-0002.jsonl'], $plan['sealable_segments']);
        $this->assertSame(['seg-0003.jsonl', 'seg-0004.jsonl'], $plan['retained_raw_segments']);
        $this->assertSame('compact', $plan['next_action']);
    }

    public function test_plan_flags_disk_pressure_when_over_ceiling_with_nothing_sealable(): void
    {
        // All segments retained raw (retain >= count) but over the disk ceiling:
        // honest warning + nothing_to_compact, never a false ok-with-room claim.
        $plan = $this->service()->plan([
            'segments' => $this->segments(),
            'retain_raw_segments' => 10,
            'disk_ceiling_bytes' => 1,
        ]);

        $this->assertSame(LoopLedgerArchiveService::STATUS_OK, $plan['status']);
        $this->assertTrue($plan['disk_pressure']);
        $this->assertSame([], $plan['sealable_segments']);
        $this->assertSame('nothing_to_compact', $plan['next_action']);
        $this->assertContains('disk_ceiling_reached_with_no_sealable_segment', $plan['warnings']);
    }

    public function test_empty_ledger_is_ok_with_no_sealable_segments(): void
    {
        $report = $this->service()->compact([]);

        $this->assertSame(LoopLedgerArchiveService::STATUS_OK, $report['status']);
        $this->assertSame(0, $report['sealed_segment_count']);
        $this->assertContains('no_sealable_segment', $report['warnings']);
    }

    public function test_report_hash_is_deterministic_for_same_input(): void
    {
        $input = ['segments' => $this->segments(), 'retain_raw_segments' => 1];

        $planA = $this->service()->plan($input);
        $planB = $this->service()->plan($input);
        $this->assertSame($planA['report_hash'], $planB['report_hash']);

        $compactA = $this->service()->compact($input);
        $compactB = $this->service()->compact($input);
        $this->assertSame($compactA['report_hash'], $compactB['report_hash']);

        $replayInput = $input + ['compact_index' => $compactA['compact_index']];
        $replayA = $this->service()->replayManifest($replayInput);
        $replayB = $this->service()->replayManifest($replayInput);
        $this->assertSame($replayA['report_hash'], $replayB['report_hash']);

        // Volatile fields excluded from the hash.
        $this->assertStringStartsWith('sha256:', (string) $compactA['report_hash']);
    }

    public function test_claim_policy_never_runs_provider_or_merge_or_deletes(): void
    {
        $report = $this->service()->compact(['segments' => $this->segments(), 'retain_raw_segments' => 1]);
        $policy = $report['claim_policy'];

        $this->assertTrue($policy['read_only']);
        $this->assertFalse($policy['runs_provider']);
        $this->assertFalse($policy['runs_merge']);
        $this->assertFalse($policy['deletes_branches']);
        $this->assertFalse($policy['mutates_cycle_truth']);
        $this->assertTrue($policy['blocked_never_dressed_as_ready']);
    }
}
