<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\LearningTransfer;

use App\Services\Ai\SelfConstruction\LearningTransfer\AtlasSelfConstructionLearningLedger;
use App\Services\Ai\SelfConstruction\LearningTransfer\AtlasSelfConstructionLearningLedgerQuery;
use Tests\TestCase;

class AtlasSelfConstructionLearningLedgerQueryTest extends TestCase
{
    private string $path = '';

    private AtlasSelfConstructionLearningLedger $ledger;

    protected function setUp(): void
    {
        parent::setUp();
        $this->path = sys_get_temp_dir().'/atlas-learning-ledger-query-'.bin2hex(random_bytes(6)).'.jsonl';
        $this->ledger = new AtlasSelfConstructionLearningLedger($this->path);

        $this->ledger->append([
            'lesson_id' => 'L1',
            'class' => 'duplicate_capability',
            'decision' => 'admit',
            'observation_ts' => '2026-06-25T00:00:02Z',
            'reasons' => ['r'],
            'evidence_refs' => ['e1'],
        ]);
        $this->ledger->append([
            'lesson_id' => 'L2',
            'class' => 'forbidden_target',
            'decision' => 'admit',
            'observation_ts' => '2026-06-25T00:00:01Z',
            'reasons' => ['r'],
            'evidence_refs' => ['e2'],
        ]);
        $this->ledger->append([
            'lesson_id' => 'L3',
            'class' => 'duplicate_capability',
            'decision' => 'hold',
            'observation_ts' => '2026-06-25T00:00:03Z',
            'reasons' => ['r'],
            'evidence_refs' => ['e3'],
        ]);
        $this->ledger->append([
            'lesson_id' => 'L4',
            'class' => 'respec_candidate',
            'decision' => 'admit',
            'observation_ts' => '2026-06-25T00:00:04Z',
            'reasons' => ['scope_drift_detected'],
            'evidence_refs' => ['e4'],
            'family' => 'repeated_give_back',
            'source_project' => 'atlas-server',
            'target_project' => 'atlas-mobile',
            'failure_mode' => 'scope_drift',
            'provider_class' => 'codex_cli',
            'model' => 'gpt-5.5',
        ]);
    }

    protected function tearDown(): void
    {
        @unlink($this->path);
        parent::tearDown();
    }

    public function test_all_returns_every_row(): void
    {
        $q = new AtlasSelfConstructionLearningLedgerQuery($this->ledger);
        self::assertCount(4, $q->all());
    }

    public function test_by_class_filters_to_matching_class(): void
    {
        $q = new AtlasSelfConstructionLearningLedgerQuery($this->ledger);
        $rows = $q->byClass('duplicate_capability');
        self::assertCount(2, $rows);
        $ids = array_map(static fn (array $r): string => (string) $r['lesson']['lesson_id'], $rows);
        sort($ids);
        self::assertSame(['L1', 'L3'], $ids);
    }

    public function test_by_decision_filters_to_matching_decision(): void
    {
        $q = new AtlasSelfConstructionLearningLedgerQuery($this->ledger);
        $admits = $q->byDecision('admit');
        self::assertCount(3, $admits);

        $holds = $q->byDecision('hold');
        self::assertCount(1, $holds);
        self::assertSame('L3', $holds[0]['lesson']['lesson_id']);
    }

    public function test_list_chronological_sorts_by_observation_ts_ascending(): void
    {
        $q = new AtlasSelfConstructionLearningLedgerQuery($this->ledger);
        $rows = $q->listChronological();
        $ids = array_map(static fn (array $r): string => (string) $r['lesson']['lesson_id'], $rows);
        self::assertSame(['L2', 'L1', 'L3', 'L4'], $ids);
    }

    public function test_by_family_filters_to_matching_family(): void
    {
        $rows = (new AtlasSelfConstructionLearningLedgerQuery($this->ledger))->byFamily('repeated_give_back');
        self::assertCount(1, $rows);
        self::assertSame('L4', $rows[0]['lesson']['lesson_id']);
    }

    public function test_by_source_project_filters_to_matching_source(): void
    {
        $rows = (new AtlasSelfConstructionLearningLedgerQuery($this->ledger))->bySourceProject('atlas-server');
        self::assertCount(1, $rows);
        self::assertSame('L4', $rows[0]['lesson']['lesson_id']);
    }

    public function test_by_target_project_filters_to_matching_target(): void
    {
        $rows = (new AtlasSelfConstructionLearningLedgerQuery($this->ledger))->byTargetProject('atlas-mobile');
        self::assertCount(1, $rows);
        self::assertSame('L4', $rows[0]['lesson']['lesson_id']);
    }

    public function test_by_failure_mode_filters_to_matching_failure_mode(): void
    {
        $rows = (new AtlasSelfConstructionLearningLedgerQuery($this->ledger))->byFailureMode('scope_drift');
        self::assertCount(1, $rows);
        self::assertSame('L4', $rows[0]['lesson']['lesson_id']);
    }

    public function test_empty_evidence_refs_is_rejected_by_ledger(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('learning_ledger_evidence_refs_empty');
        $this->ledger->append([
            'lesson_id' => 'no-evidence',
            'class' => 'duplicate_capability',
            'decision' => 'admit',
            'observation_ts' => '2026-06-25T00:01:00Z',
            'reasons' => ['r'],
            'evidence_refs' => [],
        ]);
    }

    public function test_forbidden_fields_are_rejected_by_ledger(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('learning_ledger_forbidden_field:raw_prompt');
        $this->ledger->append([
            'lesson_id' => 'leaky',
            'class' => 'duplicate_capability',
            'decision' => 'admit',
            'observation_ts' => '2026-06-25T00:01:00Z',
            'reasons' => ['r'],
            'evidence_refs' => ['e-ref'],
            'raw_prompt' => 'DO NOT PERSIST THIS',
        ]);
    }

    public function test_queries_reconstruct_state_from_jsonl_no_in_memory_cache(): void
    {
        // Append directly to JSONL out-of-band, then verify the query sees it on the next call.
        $direct = [
            'schema_version' => 'atlas.learning_transfer.learning_ledger.v1',
            'lesson_hash' => 'manual-hash',
            'recorded_at' => '2026-06-25T00:00:00Z',
            'lesson' => [
                'lesson_id' => 'L4',
                'class' => 'forbidden_target',
                'decision' => 'reject',
                'observation_ts' => '2026-06-25T00:00:04Z',
                'reasons' => ['r'],
                'evidence_refs' => ['e4'],
            ],
        ];
        file_put_contents($this->path, json_encode($direct, JSON_UNESCAPED_SLASHES)."\n", FILE_APPEND);

        $q = new AtlasSelfConstructionLearningLedgerQuery($this->ledger);
        self::assertCount(5, $q->all());
        self::assertCount(1, $q->byDecision('reject'));
    }

    // ── search(): combined deterministic filters ───────────────────────────────

    public function test_search_combines_family_and_target_project_filters(): void
    {
        $rows = (new AtlasSelfConstructionLearningLedgerQuery($this->ledger))->search([
            'task_family' => 'repeated_give_back',
            'target_project' => 'atlas-mobile',
        ]);

        self::assertCount(1, $rows);
        self::assertSame('L4', $rows[0]['lesson']['lesson_id']);
    }

    public function test_search_filters_by_provider_class_and_model(): void
    {
        $rows = (new AtlasSelfConstructionLearningLedgerQuery($this->ledger))->search([
            'provider_class' => 'codex_cli',
            'model' => 'gpt-5.5',
        ]);

        self::assertCount(1, $rows);
        self::assertSame('L4', $rows[0]['lesson']['lesson_id']);
    }

    public function test_search_mismatched_combined_filters_returns_empty(): void
    {
        $rows = (new AtlasSelfConstructionLearningLedgerQuery($this->ledger))->search([
            'task_family' => 'repeated_give_back',
            'target_project' => 'atlas-desktop',
        ]);

        self::assertSame([], $rows);
    }

    public function test_search_time_window_filters_by_since_and_until(): void
    {
        $q = new AtlasSelfConstructionLearningLedgerQuery($this->ledger);

        $rows = $q->search(['since' => '2026-06-25T00:00:02Z', 'until' => '2026-06-25T00:00:03Z']);
        $ids = array_map(static fn (array $r): string => (string) $r['lesson']['lesson_id'], $rows);
        sort($ids);

        self::assertSame(['L1', 'L3'], $ids);
    }

    public function test_search_with_no_filters_returns_everything(): void
    {
        $rows = (new AtlasSelfConstructionLearningLedgerQuery($this->ledger))->search([]);

        self::assertCount(4, $rows);
    }

    // ── summarize(): counts + representative lessons + latest evidence, never raw transcript ──

    public function test_summarize_returns_count_representative_lessons_and_latest_evidence(): void
    {
        $summary = (new AtlasSelfConstructionLearningLedgerQuery($this->ledger))->summarize([]);

        self::assertSame(4, $summary['count']);
        self::assertNotEmpty($summary['representative_lessons']);
        self::assertNotEmpty($summary['latest_evidence']);
    }

    public function test_summarize_representative_lessons_are_most_recent_first(): void
    {
        $summary = (new AtlasSelfConstructionLearningLedgerQuery($this->ledger))->summarize([]);

        self::assertSame('L4', $summary['representative_lessons'][0]['lesson_id']);
    }

    public function test_summarize_representative_lessons_never_include_raw_provider_fields(): void
    {
        $summary = (new AtlasSelfConstructionLearningLedgerQuery($this->ledger))->summarize([]);

        foreach ($summary['representative_lessons'] as $lesson) {
            self::assertArrayNotHasKey('raw_prompt', $lesson);
            self::assertArrayNotHasKey('provider_trace', $lesson);
            self::assertSame(['lesson_id', 'class', 'decision', 'observation_ts'], array_keys($lesson));
        }
    }

    public function test_summarize_respects_representative_limit(): void
    {
        $summary = (new AtlasSelfConstructionLearningLedgerQuery($this->ledger))->summarize([], representativeLimit: 1);

        self::assertCount(1, $summary['representative_lessons']);
        self::assertSame(4, $summary['count'], 'count reflects the full filtered slice, not the representative cap');
    }

    public function test_summarize_of_empty_slice_has_zero_count_and_no_evidence(): void
    {
        $summary = (new AtlasSelfConstructionLearningLedgerQuery($this->ledger))->summarize(['task_family' => 'nonexistent_family']);

        self::assertSame(0, $summary['count']);
        self::assertSame([], $summary['representative_lessons']);
        self::assertSame([], $summary['latest_evidence']);
    }

    // ── relevantToNextBatch(): bounded, never a whole-ledger dump ──────────────

    public function test_relevant_to_next_batch_matches_by_task_family(): void
    {
        $rows = (new AtlasSelfConstructionLearningLedgerQuery($this->ledger))->relevantToNextBatch([
            'task_families' => ['repeated_give_back'],
        ]);

        self::assertCount(1, $rows);
        self::assertSame('L4', $rows[0]['lesson']['lesson_id']);
    }

    public function test_relevant_to_next_batch_matches_by_target_project(): void
    {
        $rows = (new AtlasSelfConstructionLearningLedgerQuery($this->ledger))->relevantToNextBatch([
            'target_projects' => ['atlas-mobile'],
        ]);

        self::assertCount(1, $rows);
        self::assertSame('L4', $rows[0]['lesson']['lesson_id']);
    }

    public function test_relevant_to_next_batch_with_empty_context_returns_nothing(): void
    {
        $rows = (new AtlasSelfConstructionLearningLedgerQuery($this->ledger))->relevantToNextBatch([]);

        self::assertSame([], $rows, 'an empty batch context must never dump the whole ledger');
    }

    public function test_relevant_to_next_batch_respects_limit(): void
    {
        $rows = (new AtlasSelfConstructionLearningLedgerQuery($this->ledger))->relevantToNextBatch([
            'failure_modes' => ['scope_drift'],
        ], limit: 0);

        self::assertSame([], $rows);
    }
}
