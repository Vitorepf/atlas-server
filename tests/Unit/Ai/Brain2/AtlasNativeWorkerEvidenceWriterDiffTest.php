<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Brain2;

use App\Services\Ai\SelfConstruction\NativeWorker\AtlasNativeWorkerEvidenceWriter;
use PHPUnit\Framework\TestCase;

/**
 * Proves AtlasNativeWorkerEvidenceWriter persists per-file unified diffs
 * and a canonical diff hash, and validates that non-empty files_changed
 * carries non-empty diffs covering every file.
 */
final class AtlasNativeWorkerEvidenceWriterDiffTest extends TestCase
{
    private string $ledgerPath = '';

    protected function tearDown(): void
    {
        if ($this->ledgerPath !== '' && is_file($this->ledgerPath)) {
            unlink($this->ledgerPath);
        }
    }

    private function writer(): AtlasNativeWorkerEvidenceWriter
    {
        $this->ledgerPath = sys_get_temp_dir().'/atlas_evidence_diff_'.bin2hex(random_bytes(6)).'.jsonl';

        return new AtlasNativeWorkerEvidenceWriter($this->ledgerPath);
    }

    private function validAttempt(): array
    {
        return [
            'task_packet_id' => 'diff-test-pkt',
            'envelope_hash' => hash('sha256', 'diff-test-env'),
            'runtime_owner' => 'atlas_native',
            'files_changed' => ['src/Foo.php', 'tests/FooTest.php'],
            'file_diffs' => [
                'src/Foo.php' => "--- a/src/Foo.php\n+++ b/src/Foo.php\n@@ -1,3 +1,4 @@\n+// new line\n class Foo {}",
                'tests/FooTest.php' => "--- a/tests/FooTest.php\n+++ b/tests/FooTest.php\n@@ -5,6 +5,7 @@\n+// test addition\n class FooTest {}",
            ],
            'commands_run' => [
                ['command' => 'php artisan test tests/FooTest.php', 'exit_code' => 0],
            ],
            'tests_or_gates_result' => ['passed' => true],
        ];
    }

    public function test_valid_attempt_persists_file_diffs_and_diff_hash(): void
    {
        $result = $this->writer()->append($this->validAttempt());

        $this->assertSame('ok', $result['status']);
        $this->assertArrayHasKey('file_diffs', $result['row']);
        $this->assertCount(2, $result['row']['file_diffs']);
        $this->assertArrayHasKey('src/Foo.php', $result['row']['file_diffs']);
        $this->assertArrayHasKey('tests/FooTest.php', $result['row']['file_diffs']);
        $this->assertStringContainsString('// new line', $result['row']['file_diffs']['src/Foo.php']);
        $this->assertArrayHasKey('diff_hash', $result['row']);
        $this->assertNotEmpty($result['row']['diff_hash']);
    }

    public function test_duplicate_attempt_with_diffs_still_returns_already_recorded(): void
    {
        $w = $this->writer();
        $attempt = $this->validAttempt();
        $w->append($attempt);

        $result = $w->append($attempt);

        $this->assertSame('already_recorded', $result['status']);
    }

    public function test_missing_file_diffs_throws(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('file_diffs must not be empty');

        $attempt = $this->validAttempt();
        unset($attempt['file_diffs']);
        $this->writer()->append($attempt);
    }

    public function test_empty_file_diffs_throws(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('file_diffs must not be empty');

        $attempt = $this->validAttempt();
        $attempt['file_diffs'] = [];
        $this->writer()->append($attempt);
    }

    public function test_file_diffs_missing_a_changed_file_throws(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('files_changed without corresponding diff');

        $attempt = $this->validAttempt();
        $attempt['file_diffs'] = ['src/Foo.php' => 'diff content'];
        // tests/FooTest.php is in files_changed but not in file_diffs
        $this->writer()->append($attempt);
    }

    public function test_diff_hash_is_deterministic(): void
    {
        $w = $this->writer();
        $attempt = $this->validAttempt();
        $r1 = $w->append($attempt);

        $w2 = $this->writer();
        $r2 = $w2->append($attempt);

        $this->assertSame($r1['row']['diff_hash'], $r2['row']['diff_hash']);
    }

    public function test_diff_hash_changes_when_diff_content_changes(): void
    {
        $w = $this->writer();
        $attempt1 = $this->validAttempt();
        $r1 = $w->append($attempt1);

        $attempt2 = $this->validAttempt();
        $attempt2['file_diffs']['src/Foo.php'] = '--- a/src/Foo.php'."\n".'+++ b/src/Foo.php'."\n".'@@ -1 +1 @@'."\n".'-old'."\n".'+new';
        $attempt2['envelope_hash'] = hash('sha256', 'different-env');
        $w2 = $this->writer();
        $r2 = $w2->append($attempt2);

        $this->assertNotSame($r1['row']['diff_hash'], $r2['row']['diff_hash']);
    }
}
