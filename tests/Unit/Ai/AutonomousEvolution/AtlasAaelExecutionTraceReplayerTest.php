<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution;

use App\Services\Ai\AutonomousEvolution\Aael\Execution\TraceReplay\AaelStepActor;
use App\Services\Ai\AutonomousEvolution\Aael\Execution\TraceReplay\AtlasAaelExecutionTraceReplayer;
use RuntimeException;
use Tests\TestCase;

final class AtlasAaelExecutionTraceReplayerTest extends TestCase
{
    private string $root = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().'/atlas-replay-'.bin2hex(random_bytes(6));
        @mkdir($this->root, 0o755, true);
    }

    protected function tearDown(): void
    {
        foreach ((array) glob($this->root.'/*') as $f) {
            @unlink((string) $f);
        }
        @rmdir($this->root);
        parent::tearDown();
    }

    /**
     * @param  list<array{action:string, input:mixed, output:string}>  $steps
     */
    private function writeTrace(array $steps, string $terminalStatus = 'closed-ok'): string
    {
        $path = $this->root.'/trace.jsonl';
        $lines = [
            (string) json_encode(['_type' => 'manifest', 'trace_id' => 'T1', 'root_commit' => 'rec-commit', 'terminal_status' => $terminalStatus]),
        ];
        foreach ($steps as $i => $s) {
            $lines[] = (string) json_encode([
                '_type' => 'step',
                'step_index' => $i,
                'action' => $s['action'],
                'input' => $s['input'],
                'output_b64' => base64_encode($s['output']),
                'output_fingerprint' => hash('sha256', $s['output']),
            ]);
        }
        file_put_contents($path, implode("\n", $lines)."\n");

        return $path;
    }

    private function actorThatReturnsRecorded(string $tracePath): AaelStepActor
    {
        return new class($tracePath) implements AaelStepActor {
            public function __construct(private string $path) {}

            public function perform(int $stepIndex, string $action, mixed $input): string
            {
                foreach ((array) file($this->path, FILE_IGNORE_NEW_LINES) as $line) {
                    $d = json_decode((string) $line, true);
                    if (is_array($d) && (string) ($d['_type'] ?? '') === 'step' && (int) $d['step_index'] === $stepIndex) {
                        return (string) base64_decode((string) $d['output_b64'], true);
                    }
                }
                throw new RuntimeException('actor_step_missing');
            }
        };
    }

    public function test_deterministic_actor_yields_zero_divergences(): void
    {
        $path = $this->writeTrace([
            ['action' => 'a', 'input' => 1, 'output' => 'aaa'],
            ['action' => 'b', 'input' => 2, 'output' => 'bbb'],
        ]);
        $hashBefore = sha1_file($path);

        $r = new AtlasAaelExecutionTraceReplayer('rep-commit');
        $report = $r->replay($path, $this->actorThatReturnsRecorded($path));

        $this->assertSame(2, $report->totalStepsReplayed);
        $this->assertSame(0, $report->divergedStepCount);
        $this->assertNull($report->firstDivergedStepIndex);
        $this->assertSame('rec-commit', $report->rootCommitAtRecord);
        $this->assertSame('rep-commit', $report->rootCommitAtReplay);
        $this->assertSame($hashBefore, sha1_file($path), 'trace must be byte-identical after replay');
    }

    public function test_actor_mutating_step_k_reports_first_byte_diff_offset(): void
    {
        $path = $this->writeTrace([
            ['action' => 'a', 'input' => 1, 'output' => 'AAAAAA'],
            ['action' => 'b', 'input' => 2, 'output' => 'BBBBBB'],
            ['action' => 'c', 'input' => 3, 'output' => 'CCCCCC'],
        ]);

        $actor = new class implements AaelStepActor {
            public function perform(int $stepIndex, string $action, mixed $input): string
            {
                return match ($stepIndex) {
                    0 => 'AAAAAA',
                    1 => 'BBXBBB',   // diverges at offset 2
                    2 => 'CCCCCC',
                    default => '',
                };
            }
        };

        $r = new AtlasAaelExecutionTraceReplayer('rep-commit');
        $report = $r->replay($path, $actor);

        $this->assertSame(3, $report->totalStepsReplayed);
        $this->assertSame(1, $report->divergedStepCount);
        $this->assertSame(1, $report->firstDivergedStepIndex);
        $this->assertTrue($report->divergences[1]->diverged);
        $this->assertSame(2, $report->divergences[1]->firstByteDiffOffset);
    }

    public function test_missing_manifest_throws(): void
    {
        $path = $this->root.'/no-manifest.jsonl';
        file_put_contents($path, json_encode(['_type' => 'step', 'step_index' => 0, 'action' => 'x', 'input' => null, 'output_b64' => '', 'output_fingerprint' => 'abc'])."\n");

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/manifest/i');
        (new AtlasAaelExecutionTraceReplayer('r'))->replay($path, $this->actorThatReturnsRecorded($path));
    }

    public function test_aborted_manifest_refuses(): void
    {
        $path = $this->writeTrace([['action' => 'a', 'input' => 1, 'output' => 'x']], 'aborted-during-record');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/aborted/');
        (new AtlasAaelExecutionTraceReplayer('r'))->replay($path, $this->actorThatReturnsRecorded($path));
    }

    public function test_from_to_step_bounds_only_replay_slice(): void
    {
        $path = $this->writeTrace([
            ['action' => 'a', 'input' => 1, 'output' => 'A'],
            ['action' => 'b', 'input' => 2, 'output' => 'B'],
            ['action' => 'c', 'input' => 3, 'output' => 'C'],
            ['action' => 'd', 'input' => 4, 'output' => 'D'],
        ]);

        $r = new AtlasAaelExecutionTraceReplayer('rep');
        $report = $r->replay($path, $this->actorThatReturnsRecorded($path), fromStep: 1, toStep: 2);

        $this->assertSame(2, $report->totalStepsReplayed);
        $this->assertSame(0, $report->divergedStepCount);
    }

    public function test_no_score_or_grade_keys_in_report(): void
    {
        $path = $this->writeTrace([['action' => 'a', 'input' => 1, 'output' => 'x']]);
        $report = (new AtlasAaelExecutionTraceReplayer('r'))->replay($path, $this->actorThatReturnsRecorded($path));
        $keys = array_keys($report->toArray());
        sort($keys);
        $this->assertSame([
            'diverged_step_count', 'divergences', 'first_diverged_step_index',
            'root_commit_at_record', 'root_commit_at_replay', 'total_steps_replayed',
        ], $keys);
    }
}
