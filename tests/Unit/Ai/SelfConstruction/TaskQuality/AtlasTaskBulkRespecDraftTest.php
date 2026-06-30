<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\TaskQuality;

use App\Services\Ai\SelfConstruction\TaskQuality\AtlasTaskBulkRespecDraft;
use App\Services\Ai\SelfConstruction\TaskQuality\AtlasTaskRespecPlanBuilder;
use PHPUnit\Framework\TestCase;

/**
 * Proves AtlasTaskBulkRespecDraft: mixed clean/poison queue produces drafts only for poison records;
 * duplicate fingerprints (same action+affected+gates) collapse to one draft listing all matching
 * packet_ids; clean queue yields zero drafts; drafts sorted byte-stably; summary counts every action
 * including 'keep'.
 */
final class AtlasTaskBulkRespecDraftTest extends TestCase
{
    public function test_mixed_queue_drafts_only_for_poison_records(): void
    {
        $r = (new AtlasTaskBulkRespecDraft)->draft([
            ['packet_id' => 'p-clean'],
            ['packet_id' => 'p-missing', 'missing_files' => ['app/X.php']],
            ['packet_id' => 'p-quarantine', 'too_many_deficiencies' => true],
        ]);
        $this->assertCount(2, $r['drafts']);
        $this->assertSame(1, $r['summary'][AtlasTaskRespecPlanBuilder::ACTION_KEEP]);
    }

    public function test_duplicate_fingerprints_collapse_into_one_draft(): void
    {
        $r = (new AtlasTaskBulkRespecDraft)->draft([
            ['packet_id' => 'p-a', 'missing_files' => ['app/X.php']],
            ['packet_id' => 'p-b', 'missing_files' => ['app/Y.php']],
            ['packet_id' => 'p-c', 'missing_files' => ['app/Z.php']],
        ]);
        // Same action + same affected_fields + same revalidation_gates ⇒ same fingerprint.
        $this->assertCount(1, $r['drafts']);
        $this->assertSame(['p-a', 'p-b', 'p-c'], $r['drafts'][0]['packet_ids']);
    }

    public function test_clean_queue_produces_zero_drafts(): void
    {
        $r = (new AtlasTaskBulkRespecDraft)->draft([
            ['packet_id' => 'p1'],
            ['packet_id' => 'p2'],
        ]);
        $this->assertSame([], $r['drafts']);
    }

    public function test_drafts_sorted_by_severity_before_action_name(): void
    {
        $r = (new AtlasTaskBulkRespecDraft)->draft([
            ['packet_id' => 'p-mis', 'missing_files' => ['app/X.php']],   // add_missing_allowed_file_candidate (severity 5)
            ['packet_id' => 'p-quar', 'cli_clobber' => true],              // quarantine_candidate (severity 1)
        ]);
        // quarantine has higher severity → must appear first regardless of action name ordering.
        $this->assertSame(AtlasTaskRespecPlanBuilder::ACTION_QUARANTINE, $r['drafts'][0]['action']);
        $this->assertSame(AtlasTaskRespecPlanBuilder::ACTION_ADD_FILE, $r['drafts'][1]['action']);
    }

    public function test_draft_includes_representative_packet_id_as_first_sorted_packet(): void
    {
        $r = (new AtlasTaskBulkRespecDraft)->draft([
            ['packet_id' => 'zz-last', 'missing_files' => ['app/X.php']],
            ['packet_id' => 'aa-first', 'missing_files' => ['app/Y.php']],
        ]);
        $draft = $r['drafts'][0];
        $this->assertArrayHasKey('representative_packet_id', $draft);
        $this->assertSame('aa-first', $draft['representative_packet_id']);
    }

    public function test_packet_ids_capped_and_total_count_preserved(): void
    {
        $cap = AtlasTaskBulkRespecDraft::MAX_PACKET_IDS_PER_FAMILY;
        $records = [];
        for ($i = 0; $i < $cap + 5; $i++) {
            $records[] = ['packet_id' => sprintf('pkt-%03d', $i), 'missing_files' => ['app/X.php']];
        }
        $r = (new AtlasTaskBulkRespecDraft)->draft($records);
        $draft = $r['drafts'][0];
        $this->assertCount($cap, $draft['packet_ids'], 'packet_ids must be capped');
        $this->assertSame($cap + 5, $draft['total_packet_count'], 'total_packet_count must reflect raw count');
    }

    public function test_summary_counts_every_action_including_keep(): void
    {
        $r = (new AtlasTaskBulkRespecDraft)->draft([
            ['packet_id' => 'c1'],
            ['packet_id' => 'c2'],
            ['packet_id' => 'q', 'cli_clobber' => true],
        ]);
        $this->assertSame(2, $r['summary'][AtlasTaskRespecPlanBuilder::ACTION_KEEP]);
        $this->assertSame(1, $r['summary'][AtlasTaskRespecPlanBuilder::ACTION_QUARANTINE]);
    }

    public function test_packet_ids_within_a_draft_are_sorted(): void
    {
        $r = (new AtlasTaskBulkRespecDraft)->draft([
            ['packet_id' => 'zeta', 'missing_files' => ['app/X.php']],
            ['packet_id' => 'alpha', 'missing_files' => ['app/Y.php']],
        ]);
        $this->assertSame(['alpha', 'zeta'], $r['drafts'][0]['packet_ids']);
    }
}
