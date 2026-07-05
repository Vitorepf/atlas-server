<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Brain2;

use App\Services\Ai\SelfConstruction\NativeWorker\AtlasNativeWorkerContextAssembler;
use PHPUnit\Framework\TestCase;

final class AtlasNativeWorkerContextAssemblerTest extends TestCase
{
    public function test_assemble_builds_context_envelope_from_packets(): void
    {
        $assembler = new AtlasNativeWorkerContextAssembler;
        $result = $assembler->assemble([
            [
                'sibling_tests' => [
                    ['test_path' => 'tests/Unit/FooTest.php'],
                    ['test_path' => 'tests/Feature/BarTest.php'],
                ],
                'green_run_exemplars' => [
                    ['commit_sha' => 'abc123', 'task_packet_id' => 'pkt-1'],
                    ['commit_sha' => 'def456', 'task_packet_id' => 'pkt-2'],
                ],
                'known_lessons' => [
                    ['lesson_key' => 'avoid_truncate_in_migration'],
                    ['lesson_key' => 'always_add_reversible_down'],
                ],
            ],
        ]);

        $this->assertSame(AtlasNativeWorkerContextAssembler::SCHEMA, $result['schema']);
        $this->assertSame(
            ['tests/Feature/BarTest.php', 'tests/Unit/FooTest.php'],
            $result['sibling_test_paths'],
        );
        $this->assertSame(
            ['abc123', 'def456', 'pkt-1', 'pkt-2'],
            $result['exemplar_patch_ids'],
        );
        $this->assertSame(
            ['always_add_reversible_down', 'avoid_truncate_in_migration'],
            $result['learned_failure_anti_patterns'],
        );
    }

    public function test_assemble_empty_packets_returns_empty_lists(): void
    {
        $assembler = new AtlasNativeWorkerContextAssembler;

        $result = $assembler->assemble([]);

        $this->assertSame([], $result['sibling_test_paths']);
        $this->assertSame([], $result['exemplar_patch_ids']);
        $this->assertSame([], $result['learned_failure_anti_patterns']);
    }

    public function test_assemble_deduplicates_and_sorts(): void
    {
        $assembler = new AtlasNativeWorkerContextAssembler;
        $result = $assembler->assemble([
            [
                'sibling_tests' => [
                    ['test_path' => 'tests/ZTest.php'],
                    ['test_path' => 'tests/ATest.php'],
                    ['test_path' => 'tests/ZTest.php'], // duplicate
                ],
                'green_run_exemplars' => [
                    ['commit_sha' => 'bbb'],
                    ['commit_sha' => 'aaa'],
                    ['commit_sha' => 'bbb'], // duplicate
                ],
                'known_lessons' => [
                    ['lesson_key' => 'z_last'],
                    ['lesson_key' => 'a_first'],
                    ['lesson_key' => 'z_last'], // duplicate
                ],
            ],
        ]);

        $this->assertSame(['tests/ATest.php', 'tests/ZTest.php'], $result['sibling_test_paths']);
        $this->assertSame(['aaa', 'bbb'], $result['exemplar_patch_ids']);
        $this->assertSame(['a_first', 'z_last'], $result['learned_failure_anti_patterns']);
    }

    public function test_assemble_skips_empty_or_malformed_entries(): void
    {
        $assembler = new AtlasNativeWorkerContextAssembler;
        $result = $assembler->assemble([
            [
                'sibling_tests' => [
                    ['test_path' => ''],
                    ['test_path' => 'tests/OnlyValid.php'],
                    ['test_path' => '   '],
                    'not_an_array',
                ],
                'green_run_exemplars' => [
                    ['commit_sha' => ''],       // empty sha
                    ['commit_sha' => 'sha1'],   // valid sha
                    ['task_packet_id' => 'pid'], // valid packet id (no sha)
                    'not_an_array',
                ],
                'known_lessons' => [
                    ['lesson_key' => 'key1'],
                    ['lesson_key' => ''],
                    [],
                    'not_an_array',
                ],
            ],
        ]);

        $this->assertSame(['tests/OnlyValid.php'], $result['sibling_test_paths']);
        $this->assertSame(['pid', 'sha1'], $result['exemplar_patch_ids']);
        $this->assertSame(['key1'], $result['learned_failure_anti_patterns']);
    }
}
