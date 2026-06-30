<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Services\Ai\AutonomousEvolution\Discovery\Cortex\AtlasCortexDecisionHistoryReader;
use App\Services\Ai\AutonomousEvolution\Discovery\Cortex\DecisionHistoryFact;
use PHPUnit\Framework\TestCase;

final class AtlasCortexDecisionHistoryReaderTest extends TestCase
{
    // A git log row: sha\x1fsubject\x1fbody\x1ftimestamp\x1e
    private function gitRow(string $sha, string $subject, string $body = '', int $ts = 1700000000): string
    {
        return $sha."\x1f".$subject."\x1f".$body."\x1f".$ts."\x1e";
    }

    private function fakeRunner(array $responses): callable
    {
        // Responses keyed by first command token ('git' + second token 'rev-parse'|'log')
        return function (array $command) use ($responses): array {
            $key = implode(' ', array_slice($command, 0, 2));
            return $responses[$key] ?? ['exit_code' => 0, 'output' => ''];
        };
    }

    private function reader(callable $runner): AtlasCortexDecisionHistoryReader
    {
        return new AtlasCortexDecisionHistoryReader($runner);
    }

    // ── AC2: git log rows → DecisionHistoryFact fields parsed correctly ───────

    public function test_decision_entry_has_sha_and_subject(): void
    {
        $row = $this->gitRow('abc123', 'fix: decision to retire old gateway');
        $reader = $this->reader($this->fakeRunner([
            'git rev-parse' => ['exit_code' => 0, 'output' => 'deadbeef'],
            'git log'       => ['exit_code' => 0, 'output' => $row],
        ]));

        $fact = $reader->read('non-existing-file.php');

        $this->assertInstanceOf(DecisionHistoryFact::class, $fact);
        $this->assertSame(1, $fact->commitCount);
        $this->assertSame('abc123', $fact->decisions[0]['sha']);
        $this->assertSame('fix: decision to retire old gateway', $fact->decisions[0]['subject']);
    }

    public function test_decision_entry_has_decided_on_timestamp(): void
    {
        $row = $this->gitRow('sha1', 'some commit', '', 1750000000);
        $reader = $this->reader($this->fakeRunner([
            'git rev-parse' => ['exit_code' => 0, 'output' => 'head1'],
            'git log'       => ['exit_code' => 0, 'output' => $row],
        ]));

        $fact = $reader->read('non-existing-file.php');

        $this->assertSame(1750000000, $fact->decisions[0]['decided_on']);
    }

    public function test_keyword_hit_true_when_subject_contains_decision(): void
    {
        $row = $this->gitRow('sha1', 'decision: retire auth module');
        $reader = $this->reader($this->fakeRunner([
            'git rev-parse' => ['exit_code' => 0, 'output' => 'head1'],
            'git log'       => ['exit_code' => 0, 'output' => $row],
        ]));

        $fact = $reader->read('non-existing-file.php');

        $this->assertTrue($fact->decisions[0]['keyword_hit']);
    }

    public function test_keyword_hit_false_for_unrelated_commit(): void
    {
        $row = $this->gitRow('sha1', 'add unit test for foo');
        $reader = $this->reader($this->fakeRunner([
            'git rev-parse' => ['exit_code' => 0, 'output' => 'head1'],
            'git log'       => ['exit_code' => 0, 'output' => $row],
        ]));

        $fact = $reader->read('non-existing-file.php');

        $this->assertFalse($fact->decisions[0]['keyword_hit']);
    }

    public function test_multiple_rows_produce_multiple_decisions(): void
    {
        $output = $this->gitRow('sha1', 'first commit')
                . $this->gitRow('sha2', 'second commit');
        $reader = $this->reader($this->fakeRunner([
            'git rev-parse' => ['exit_code' => 0, 'output' => 'head1'],
            'git log'       => ['exit_code' => 0, 'output' => $output],
        ]));

        $fact = $reader->read('non-existing-file.php');

        $this->assertSame(2, $fact->commitCount);
        $this->assertCount(2, $fact->decisions);
    }

    // ── AC3: runner failure → commit_count=0, empty decisions, no throw ───────

    public function test_git_log_failure_returns_empty_fact(): void
    {
        $reader = $this->reader($this->fakeRunner([
            'git rev-parse' => ['exit_code' => 0, 'output' => 'head1'],
            'git log'       => ['exit_code' => 1, 'output' => ''],
        ]));

        $fact = $reader->read('non-existing-file.php');

        $this->assertSame(0, $fact->commitCount);
        $this->assertEmpty($fact->decisions);
    }

    public function test_missing_file_returns_empty_fact(): void
    {
        $reader = $this->reader($this->fakeRunner([
            'git rev-parse' => ['exit_code' => 0, 'output' => 'head1'],
            'git log'       => ['exit_code' => 0, 'output' => ''],
        ]));

        $fact = $reader->read('/tmp/this-file-does-not-exist-ever.php');

        $this->assertSame(0, $fact->commitCount);
        $this->assertEmpty($fact->decisions);
    }

    // ── AC4: caching — second read reuses cached fact ─────────────────────────

    public function test_repeated_read_returns_cached_fact_without_second_git_call(): void
    {
        $calls = 0;
        $runner = function (array $command) use (&$calls): array {
            if (implode(' ', array_slice($command, 0, 2)) === 'git log') {
                $calls++;
            }
            return match (implode(' ', array_slice($command, 0, 2))) {
                'git rev-parse' => ['exit_code' => 0, 'output' => 'same-head'],
                default         => ['exit_code' => 0, 'output' => $this->gitRow('sha1', 'first')],
            };
        };

        $reader = new AtlasCortexDecisionHistoryReader($runner);
        $reader->read('non-existing-file.php');
        $reader->read('non-existing-file.php');

        $this->assertSame(1, $calls, 'git log should be called only once for the same cache key');
    }
}
