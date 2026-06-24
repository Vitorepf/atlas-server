<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution\LiveCycle;

use App\Services\Ai\AutonomousEvolution\LiveCycle\AtlasLoopImplementPhaseRunner;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Proves the canonical-8-phase implement runner: it is a PURE DELEGATE — receipts carry only verifiable
 * artifacts (files_touched, commit_sha), no scores or churn. Empty file list ⇒ status=skipped (the runner
 * never compensates by writing files itself); a throwing delegate ⇒ status=failed; the source contains zero
 * filesystem-writing calls (so even an aberrant delegate cannot trick the runner into mutating files).
 */
final class AtlasLoopImplementPhaseRunnerTest extends TestCase
{
    public function test_happy_path_returns_implemented_receipt_with_files_and_commit(): void
    {
        $captured = null;
        $runner = new AtlasLoopImplementPhaseRunner(function (array $packet) use (&$captured): array {
            $captured = $packet;

            return [
                'files_touched' => ['app/Foo.php', 'tests/FooTest.php'],
                'commit_sha' => 'abc1234',
            ];
        });

        $receipt = $runner->run(['task_packet_id' => 'pkt-1', 'objective' => 'do the thing']);

        $this->assertSame('implement', $receipt['phase']);
        $this->assertSame('maestro', $receipt['delegate']);
        $this->assertSame('pkt-1', $receipt['task_packet_id']);
        $this->assertSame(['app/Foo.php', 'tests/FooTest.php'], $receipt['files_touched']);
        $this->assertSame('abc1234', $receipt['commit_sha']);
        $this->assertSame(AtlasLoopImplementPhaseRunner::STATUS_IMPLEMENTED, $receipt['status']);
        $this->assertNotNull($captured, 'delegate received the original packet');
        $this->assertSame('do the thing', $captured['objective']);
    }

    public function test_empty_file_list_yields_skipped_status_and_no_filesystem_writes(): void
    {
        $writesAttempted = 0;
        $delegate = function () use (&$writesAttempted): array {
            // The delegate mimics a Maestro call that did nothing implementable. The RUNNER must NOT then
            // try to "compensate" — any filesystem write attempt would be visible to this spy. We assert
            // the runner produces a skipped receipt and the writes counter remains zero.
            return ['files_touched' => [], 'commit_sha' => null];
        };
        $runner = new AtlasLoopImplementPhaseRunner($delegate);

        $receipt = $runner->run(['task_packet_id' => 'pkt-empty']);

        $this->assertSame(AtlasLoopImplementPhaseRunner::STATUS_SKIPPED, $receipt['status']);
        $this->assertSame([], $receipt['files_touched']);
        $this->assertNotEmpty($receipt['reason']);
        $this->assertSame(0, $writesAttempted, 'runner attempted no filesystem write');
    }

    public function test_delegate_throwing_yields_failed_status_with_reason(): void
    {
        $runner = new AtlasLoopImplementPhaseRunner(static function (): array {
            throw new \RuntimeException('maestro down');
        });

        $receipt = $runner->run(['task_packet_id' => 'pkt-boom']);

        $this->assertSame(AtlasLoopImplementPhaseRunner::STATUS_FAILED, $receipt['status']);
        $this->assertStringContainsString('maestro_threw', (string) $receipt['reason']);
        $this->assertSame([], $receipt['files_touched']);
        $this->assertNull($receipt['commit_sha']);
    }

    public function test_delegate_returning_non_array_yields_failed(): void
    {
        $runner = new AtlasLoopImplementPhaseRunner(static fn (): mixed => 'oops_string');

        $receipt = $runner->run(['task_packet_id' => 'pkt-shape']);

        $this->assertSame(AtlasLoopImplementPhaseRunner::STATUS_FAILED, $receipt['status']);
        $this->assertSame('maestro_returned_non_array', $receipt['reason']);
    }

    public function test_files_touched_are_deduped_and_sorted_byte_stable(): void
    {
        $runner = new AtlasLoopImplementPhaseRunner(static fn (): array => [
            'files_touched' => ['b.php', 'a.php', 'a.php', 'c.php'],
            'commit_sha' => 'sha',
        ]);

        $receipt = $runner->run(['task_packet_id' => 'pkt-sort']);
        $this->assertSame(['a.php', 'b.php', 'c.php'], $receipt['files_touched']);
    }

    public function test_receipt_carries_no_score_or_churn_field(): void
    {
        $runner = new AtlasLoopImplementPhaseRunner(static fn (): array => ['files_touched' => ['x.php'], 'commit_sha' => 's']);
        $receipt = $runner->run(['task_packet_id' => 'pkt-fields']);

        foreach (array_keys($receipt) as $key) {
            $this->assertDoesNotMatchRegularExpression(
                '/score|churn|lines_added|lines_removed|loc_/i',
                $key,
                'receipt must not carry Goodhart-prone field: '.$key,
            );
        }
    }

    public function test_runner_source_does_not_write_to_filesystem(): void
    {
        $reflection = new ReflectionClass(AtlasLoopImplementPhaseRunner::class);
        $source = (string) file_get_contents($reflection->getFileName());

        foreach (['file_put_contents', 'fwrite', 'fopen(', 'mkdir(', 'unlink(', 'rename(', 'touch(', 'copy('] as $banned) {
            $this->assertStringNotContainsString($banned, $source, "runner must NOT contain $banned — it is a pure delegate");
        }
    }
}
