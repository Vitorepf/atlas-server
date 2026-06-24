<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution;

use App\Services\Ai\AutonomousEvolution\Aael\Execution\TraceReplay\AtlasAaelExecutionTraceRecorder;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class AtlasAaelExecutionTraceRecorderTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmpDir = sys_get_temp_dir().'/atlas-aael-trace-'.bin2hex(random_bytes(5));
        mkdir($this->tmpDir, 0775, true);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tmpDir)) {
            exec('rm -rf '.escapeshellarg($this->tmpDir));
        }

        parent::tearDown();
    }

    public function test_begin_emits_manifest_once_and_record_step_appends_one_jsonl_line_per_call(): void
    {
        $recorder = $this->recorder(
            ['2026-06-24T08:00:00+00:00', '2026-06-24T08:00:01+00:00', '2026-06-24T08:00:02+00:00'],
            'trace-test',
            'abc123+dirty',
        );

        $this->assertSame('trace-test', $recorder->begin(['allowed_files' => ['app/Foo.php']], 'root-commit'));
        $record = $recorder->recordStep($this->payload());

        $this->assertSame(1, $record->stepIndex);

        $lines = $this->readLines();
        $this->assertCount(2, $lines);
        $this->assertSame('manifest', $lines[0]['record_type']);
        $this->assertSame('trace_step', $lines[1]['record_type']);
    }

    public function test_finish_emits_close_stamp_with_total_steps(): void
    {
        $recorder = $this->recorder(
            ['2026-06-24T08:00:00+00:00', '2026-06-24T08:00:01+00:00', '2026-06-24T08:00:02+00:00', '2026-06-24T08:00:03+00:00'],
            'trace-test',
            'abc123',
        );

        $recorder->begin(['scope' => ['app/Foo.php']], 'root-commit');
        $recorder->recordStep($this->payload());
        $recorder->recordStep($this->payload(['action_name' => 'step-2']));
        $finish = $recorder->finish('success');

        $this->assertSame(2, $finish['total_steps']);

        $lines = $this->readLines();
        $this->assertSame('finish', $lines[3]['record_type']);
        $this->assertSame(2, $lines[3]['total_steps']);
    }

    public function test_record_step_after_finish_throws(): void
    {
        $recorder = $this->recorder(
            ['2026-06-24T08:00:00+00:00', '2026-06-24T08:00:01+00:00', '2026-06-24T08:00:02+00:00'],
            'trace-test',
            'abc123',
        );

        $recorder->begin(['scope' => ['app/Foo.php']], 'root-commit');
        $recorder->finish('success');

        $this->expectException(RuntimeException::class);
        $recorder->recordStep($this->payload());
    }

    public function test_same_input_payload_produces_the_same_sha256_fingerprint(): void
    {
        $recorder = $this->recorder(
            ['2026-06-24T08:00:00+00:00', '2026-06-24T08:00:01+00:00', '2026-06-24T08:00:02+00:00'],
            'trace-test',
            'abc123',
        );

        $recorder->begin(['scope' => ['app/Foo.php']], 'root-commit');
        $first = $recorder->recordStep($this->payload());
        $second = $recorder->recordStep($this->payload(['action_name' => 'step-2']));

        $this->assertSame($first->inputFingerprint, $second->inputFingerprint);
        $this->assertSame($first->outputFingerprint, $second->outputFingerprint);
    }

    public function test_file_bytes_do_not_change_after_record_step_returns_even_if_caller_mutates_the_input(): void
    {
        $recorder = $this->recorder(
            ['2026-06-24T08:00:00+00:00', '2026-06-24T08:00:01+00:00'],
            'trace-test',
            'abc123',
        );

        $payload = $this->payload();
        $recorder->begin(['scope' => ['app/Foo.php']], 'root-commit');
        $recorder->recordStep($payload);
        $before = hash_file('sha256', $this->tracePath());

        $payload['input']['foo'] = 'mutated-after-write';
        $payload['output']['result'] = 'mutated-after-write';

        $after = hash_file('sha256', $this->tracePath());

        $this->assertSame($before, $after);
    }

    public function test_trace_file_is_parseable_and_step_indices_are_monotonic(): void
    {
        $recorder = $this->recorder(
            ['2026-06-24T08:00:00+00:00', '2026-06-24T08:00:01+00:00', '2026-06-24T08:00:02+00:00', '2026-06-24T08:00:03+00:00'],
            'trace-test',
            'abc123',
        );

        $recorder->begin(['scope' => ['app/Foo.php']], 'root-commit');
        $recorder->recordStep($this->payload());
        $recorder->recordStep($this->payload(['action_name' => 'step-2']));
        $recorder->finish('success');

        $lines = $this->readLines();
        $this->assertSame([0, 1, 2, 3], array_column($lines, 'step_index'));
    }

    /**
     * @param  list<string>  $timestamps
     */
    private function recorder(array $timestamps, string $traceId, string $workingTreeHash): AtlasAaelExecutionTraceRecorder
    {
        $timeIndex = 0;

        return new AtlasAaelExecutionTraceRecorder(
            traceRoot: $this->tmpDir,
            clock: function () use (&$timeIndex, $timestamps): string {
                $value = $timestamps[min($timeIndex, count($timestamps) - 1)];
                $timeIndex++;

                return $value;
            },
            traceIdGenerator: fn (): string => $traceId,
            workingTreeHashResolver: fn (): string => $workingTreeHash,
        );
    }

    /**
     * @param  array<string,mixed>  $overrides
     * @return array<string,mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_replace_recursive([
            'action_name' => 'step-1',
            'input' => ['foo' => 'bar', 'nested' => ['a' => 1]],
            'output' => ['result' => 'ok'],
            'provider_id' => 'provider.test',
            'exit_code' => 0,
            'stdout' => 'hello',
            'stderr' => 'warning',
            'decision_context_id' => 'ctx-1',
        ], $overrides);
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function readLines(): array
    {
        return array_map(
            static fn (string $line): array => json_decode($line, true, flags: JSON_THROW_ON_ERROR),
            array_values(array_filter(array_map('trim', explode("\n", (string) file_get_contents($this->tracePath()))))),
        );
    }

    private function tracePath(): string
    {
        return $this->tmpDir.'/trace-test.jsonl';
    }
}
