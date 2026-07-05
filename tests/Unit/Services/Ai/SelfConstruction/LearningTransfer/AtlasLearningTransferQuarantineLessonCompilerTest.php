<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Ai\SelfConstruction\LearningTransfer;

use App\Services\Ai\SelfConstruction\LearningTransfer\AtlasLearningTransferQuarantineLessonCompiler;
use Tests\TestCase;

final class AtlasLearningTransferQuarantineLessonCompilerTest extends TestCase
{
    private function compiler(): AtlasLearningTransferQuarantineLessonCompiler
    {
        return new AtlasLearningTransferQuarantineLessonCompiler;
    }

    // ── AC: forbidden target becomes a distinct negative lesson ──

    public function test_forbidden_target_becomes_negative_lesson(): void
    {
        $result = $this->compiler()->compile([
            ['reason' => 'forbidden_self_target', 'target_family' => 'self_prog', 'task_id' => 't1'],
        ]);

        $this->assertCount(1, $result['lessons']);
        $this->assertSame('forbidden_target', $result['lessons'][0]['lesson_type']);
        $this->assertSame('self_prog', $result['lessons'][0]['target_family']);
        $this->assertSame('do_not_reintroduce', $result['lessons'][0]['action']);
    }

    // ── AC: malformed scope becomes a distinct negative lesson ──

    public function test_malformed_scope_becomes_negative_lesson(): void
    {
        $result = $this->compiler()->compile([
            ['reason' => 'missing_scope', 'target_family' => 'incomplete', 'task_id' => 't2'],
        ]);

        $this->assertSame('malformed_scope', $result['lessons'][0]['lesson_type']);
    }

    // ── AC: duplicate satisfied work becomes a distinct negative lesson ──

    public function test_duplicate_satisfied_work_becomes_negative_lesson(): void
    {
        $result = $this->compiler()->compile([
            ['reason' => 'duplicate_satisfied_work', 'target_family' => 'already_done', 'task_id' => 't3'],
        ]);

        $this->assertSame('duplicate_satisfied_work', $result['lessons'][0]['lesson_type']);
    }

    // ── distinct lesson types are separated ──

    public function test_distinct_lesson_types_separated(): void
    {
        $result = $this->compiler()->compile([
            ['reason' => 'forbidden_self_target', 'target_family' => 'a', 'task_id' => 't1'],
            ['reason' => 'missing_scope', 'target_family' => 'b', 'task_id' => 't2'],
            ['reason' => 'duplicate_satisfied_work', 'target_family' => 'c', 'task_id' => 't3'],
        ]);

        $this->assertCount(1, $result['forbidden_target_lessons']);
        $this->assertCount(1, $result['malformed_scope_lessons']);
        $this->assertCount(1, $result['duplicate_satisfied_lessons']);
        $this->assertSame(3, $result['total_lessons']);
    }

    // ── deduplication ──

    public function test_duplicate_lessons_deduplicated(): void
    {
        $result = $this->compiler()->compile([
            ['reason' => 'forbidden_self_target', 'target_family' => 'a', 'task_id' => 't1'],
            ['reason' => 'forbidden_self_target', 'target_family' => 'a', 'task_id' => 't2'],
        ]);

        $this->assertCount(1, $result['lessons']);
    }

    // ── output structure ──

    public function test_output_has_required_keys(): void
    {
        $result = $this->compiler()->compile([]);

        $this->assertSame(AtlasLearningTransferQuarantineLessonCompiler::SCHEMA, $result['schema_version']);
        $this->assertArrayHasKey('lessons', $result);
        $this->assertArrayHasKey('forbidden_target_lessons', $result);
        $this->assertArrayHasKey('malformed_scope_lessons', $result);
        $this->assertArrayHasKey('duplicate_satisfied_lessons', $result);
    }

    public function test_result_is_deterministic(): void
    {
        $history = [
            ['reason' => 'forbidden_self_target', 'target_family' => 'b', 'task_id' => 't1'],
            ['reason' => 'missing_scope', 'target_family' => 'a', 'task_id' => 't2'],
        ];

        $a = $this->compiler()->compile($history);
        $b = $this->compiler()->compile($history);

        $this->assertSame(json_encode($a), json_encode($b));
    }
}
