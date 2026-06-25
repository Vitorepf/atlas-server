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
