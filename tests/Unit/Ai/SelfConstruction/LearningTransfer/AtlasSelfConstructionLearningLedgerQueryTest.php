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
}
