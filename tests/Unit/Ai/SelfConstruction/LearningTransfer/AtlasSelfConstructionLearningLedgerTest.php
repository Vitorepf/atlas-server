<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\LearningTransfer;

use App\Services\Ai\SelfConstruction\LearningTransfer\AtlasSelfConstructionLearningLedger;
use RuntimeException;
use Tests\TestCase;

class AtlasSelfConstructionLearningLedgerTest extends TestCase
{
    private string $path = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->path = sys_get_temp_dir().'/atlas-learning-ledger-'.bin2hex(random_bytes(6)).'.jsonl';
    }

    protected function tearDown(): void
    {
        @unlink($this->path);
        parent::tearDown();
    }

    private function lesson(string $id = 'lesson-1', string $class = 'duplicate_capability', string $decision = 'admit', string $ts = '2026-06-25T00:00:00Z'): array
    {
        return [
            'lesson_id' => $id,
            'class' => $class,
            'decision' => $decision,
            'observation_ts' => $ts,
            'reasons' => ['observed_3_times'],
            'evidence_refs' => ['evidence://a', 'evidence://b'],
        ];
    }

    public function test_append_writes_one_row_with_required_fields(): void
    {
        $ledger = new AtlasSelfConstructionLearningLedger($this->path);
        $result = $ledger->append($this->lesson());
        self::assertSame('recorded', $result['status']);

        $lines = file($this->path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        self::assertCount(1, $lines);
        $row = json_decode($lines[0], true);
        foreach (['schema_version', 'lesson_hash', 'recorded_at', 'lesson'] as $field) {
            self::assertArrayHasKey($field, $row);
        }
        self::assertSame('lesson-1', $row['lesson']['lesson_id']);
    }

    public function test_duplicate_hash_returns_already_recorded_without_appending(): void
    {
        $ledger = new AtlasSelfConstructionLearningLedger($this->path);
        $first = $ledger->append($this->lesson());
        $second = $ledger->append($this->lesson());
        self::assertSame('recorded', $first['status']);
        self::assertSame('already_recorded', $second['status']);

        $lines = file($this->path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        self::assertCount(1, $lines);
    }

    public function test_validation_rejects_missing_required_field(): void
    {
        $ledger = new AtlasSelfConstructionLearningLedger($this->path);
        $lesson = $this->lesson();
        unset($lesson['evidence_refs']);
        $this->expectException(RuntimeException::class);
        $ledger->append($lesson);
    }

    public function test_validation_rejects_empty_lesson_id(): void
    {
        $ledger = new AtlasSelfConstructionLearningLedger($this->path);
        $lesson = $this->lesson(id: '');
        $this->expectException(RuntimeException::class);
        $ledger->append($lesson);
    }

    public function test_validation_rejects_non_array_reasons(): void
    {
        $ledger = new AtlasSelfConstructionLearningLedger($this->path);
        $lesson = $this->lesson();
        $lesson['reasons'] = 'not-an-array';
        $this->expectException(RuntimeException::class);
        $ledger->append($lesson);
    }

    public function test_default_path_falls_back_to_storage_path_or_tmp(): void
    {
        $ledger = new AtlasSelfConstructionLearningLedger();
        self::assertStringContainsString('learning-ledger.jsonl', $ledger->path());
    }

    public function test_constructor_accepts_custom_path_override(): void
    {
        $ledger = new AtlasSelfConstructionLearningLedger($this->path);
        self::assertSame($this->path, $ledger->path());
    }

    // ── new AC: poison_pattern requires structured fields ─────────────────────

    private function poisonLesson(array $overrides = []): array
    {
        return array_merge($this->lesson(class: 'poison_pattern'), [
            'give_back_root' => 'duplicate_capability',
            'prevented_future_failure' => 'stops re-implementing FooService',
            'repair_strategy' => 'quarantine_and_dedup',
        ], $overrides);
    }

    public function test_poison_pattern_lesson_with_all_fields_is_recorded(): void
    {
        $ledger = new AtlasSelfConstructionLearningLedger($this->path);
        $result = $ledger->append($this->poisonLesson());
        self::assertSame('recorded', $result['status']);
    }

    public function test_poison_pattern_missing_give_back_root_is_rejected(): void
    {
        $ledger = new AtlasSelfConstructionLearningLedger($this->path);
        $lesson = $this->poisonLesson(['give_back_root' => '']);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/give_back_root/');
        $ledger->append($lesson);
    }

    public function test_poison_pattern_missing_prevented_future_failure_is_rejected(): void
    {
        $ledger = new AtlasSelfConstructionLearningLedger($this->path);
        $lesson = $this->poisonLesson();
        unset($lesson['prevented_future_failure']);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/prevented_future_failure/');
        $ledger->append($lesson);
    }

    public function test_poison_pattern_missing_repair_strategy_is_rejected(): void
    {
        $ledger = new AtlasSelfConstructionLearningLedger($this->path);
        $lesson = $this->poisonLesson(['repair_strategy' => '']);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/repair_strategy/');
        $ledger->append($lesson);
    }

    // ── new AC: success_pattern requires structured fields ─────────────────────

    private function successLesson(array $overrides = []): array
    {
        return array_merge($this->lesson(class: 'success_pattern'), [
            'task_family' => 'engineering.brain',
            'green_commit_ref' => 'abc1234',
            'reusable_design_path' => 'fact-injected pure gate pattern',
        ], $overrides);
    }

    public function test_success_pattern_lesson_with_all_fields_is_recorded(): void
    {
        $ledger = new AtlasSelfConstructionLearningLedger($this->path);
        $result = $ledger->append($this->successLesson());
        self::assertSame('recorded', $result['status']);
    }

    public function test_success_pattern_missing_task_family_is_rejected(): void
    {
        $ledger = new AtlasSelfConstructionLearningLedger($this->path);
        $lesson = $this->successLesson(['task_family' => '']);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/task_family/');
        $ledger->append($lesson);
    }

    public function test_success_pattern_missing_green_commit_ref_is_rejected(): void
    {
        $ledger = new AtlasSelfConstructionLearningLedger($this->path);
        $lesson = $this->successLesson();
        unset($lesson['green_commit_ref']);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/green_commit_ref/');
        $ledger->append($lesson);
    }

    public function test_success_pattern_missing_reusable_design_path_is_rejected(): void
    {
        $ledger = new AtlasSelfConstructionLearningLedger($this->path);
        $lesson = $this->successLesson(['reusable_design_path' => '']);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/reusable_design_path/');
        $ledger->append($lesson);
    }

    // ── new AC: placeholder evidence_refs rejected ──────────────────────────────

    public function test_placeholder_evidence_ref_todo_is_rejected(): void
    {
        $ledger = new AtlasSelfConstructionLearningLedger($this->path);
        $lesson = $this->lesson();
        $lesson['evidence_refs'] = ['TODO: add real evidence'];
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/placeholder/');
        $ledger->append($lesson);
    }

    public function test_placeholder_evidence_ref_fake_is_rejected(): void
    {
        $ledger = new AtlasSelfConstructionLearningLedger($this->path);
        $lesson = $this->lesson();
        $lesson['evidence_refs'] = ['fake-evidence-1'];
        $this->expectException(RuntimeException::class);
        $ledger->append($lesson);
    }

    public function test_empty_evidence_ref_entry_is_rejected(): void
    {
        $ledger = new AtlasSelfConstructionLearningLedger($this->path);
        $lesson = $this->lesson();
        $lesson['evidence_refs'] = ['evidence://a', ''];
        $this->expectException(RuntimeException::class);
        $ledger->append($lesson);
    }

    public function test_real_evidence_refs_still_accepted(): void
    {
        $ledger = new AtlasSelfConstructionLearningLedger($this->path);
        $result = $ledger->append($this->lesson());
        self::assertSame('recorded', $result['status']);
    }

    // ── forbidden fields still rejected ─────────────────────────────────────────

    public function test_forbidden_provider_field_still_rejected(): void
    {
        $ledger = new AtlasSelfConstructionLearningLedger($this->path);
        $lesson = $this->lesson();
        $lesson['raw_prompt'] = 'leaked prompt text';
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/forbidden_field/');
        $ledger->append($lesson);
    }

    // ── new AC: lessonHash changes with structured poison/success fields ───────

    public function test_lesson_hash_changes_when_poison_fields_change(): void
    {
        $ledger = new AtlasSelfConstructionLearningLedger($this->path);
        $a = $ledger->lessonHash($this->poisonLesson());
        $b = $ledger->lessonHash($this->poisonLesson(['repair_strategy' => 'different_strategy']));
        self::assertNotSame($a, $b);
    }

    public function test_lesson_hash_changes_when_success_fields_change(): void
    {
        $ledger = new AtlasSelfConstructionLearningLedger($this->path);
        $a = $ledger->lessonHash($this->successLesson());
        $b = $ledger->lessonHash($this->successLesson(['green_commit_ref' => 'def5678']));
        self::assertNotSame($a, $b);
    }

    public function test_lesson_hash_is_deterministic_across_key_order(): void
    {
        $ledger = new AtlasSelfConstructionLearningLedger($this->path);
        $a = $ledger->lessonHash($this->lesson());
        $reordered = [
            'evidence_refs' => ['evidence://a', 'evidence://b'],
            'reasons' => ['observed_3_times'],
            'observation_ts' => '2026-06-25T00:00:00Z',
            'decision' => 'admit',
            'class' => 'duplicate_capability',
            'lesson_id' => 'lesson-1',
        ];
        $b = $ledger->lessonHash($reordered);
        self::assertSame($a, $b);
    }
}
