<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution\Cortex;

use App\Services\Ai\AutonomousEvolution\Discovery\Cortex\AtlasCortexDecisionHistoryReader;
use App\Services\Ai\AutonomousEvolution\Discovery\Cortex\DecisionHistoryFact;
use PHPUnit\Framework\TestCase;

final class AtlasCortexDecisionHistoryReaderTest extends TestCase
{
    public function test_reader_invokes_follow_log_and_returns_decision_history_fact(): void
    {
        $commands = [];
        $reader = new AtlasCortexDecisionHistoryReader(function (array $command) use (&$commands): array {
            $commands[] = $command;
            if ($command === ['git', 'rev-parse', 'HEAD']) {
                return ['exit_code' => 0, 'output' => 'head-sha'];
            }

            return [
                'exit_code' => 0,
                'output' => implode("\x1f", ['abc123', 'fix invariant sentinel', 'body', '1710000000'])."\x1e",
            ];
        });
        $path = $this->phpFixture();

        $fact = $reader->read($path);

        $this->assertInstanceOf(DecisionHistoryFact::class, $fact);
        $this->assertSame('Tests\Fixtures\CortexHistory\HistoryFixture', $fact->fqcn);
        $this->assertSame($path, $fact->filePath);
        $this->assertSame(1, $fact->commitCount);
        $this->assertSame('abc123', $fact->decisions[0]['sha']);
        $this->assertSame('fix invariant sentinel', $fact->decisions[0]['subject']);
        $this->assertSame(1710000000, $fact->decisions[0]['decided_on']);
        $this->assertTrue($fact->decisions[0]['keyword_hit']);
        $this->assertSame(['git', 'log', '--follow', '--format=%H%x1f%s%x1f%b%x1f%at%x1e', '--', $path], $commands[1]);
    }

    public function test_keyword_set_is_detected_case_insensitively(): void
    {
        $keywords = ['decision', 'petreo', 'pétreo', 'invariant', 'invariante', 'sentinel', 'fix', 'consolidat', 'governance'];
        foreach ($keywords as $keyword) {
            $reader = new AtlasCortexDecisionHistoryReader(function (array $command) use ($keyword): array {
                if ($command === ['git', 'rev-parse', 'HEAD']) {
                    return ['exit_code' => 0, 'output' => 'head-sha'];
                }

                return ['exit_code' => 0, 'output' => implode("\x1f", ['abc123', strtoupper($keyword).' subject', '', '1710000000'])."\x1e"];
            });

            $this->assertTrue($reader->read($this->phpFixture())->decisions[0]['keyword_hit'], $keyword);
        }
    }

    public function test_untracked_path_returns_empty_history_without_exception(): void
    {
        $reader = new AtlasCortexDecisionHistoryReader(function (array $command): array {
            if ($command === ['git', 'rev-parse', 'HEAD']) {
                return ['exit_code' => 0, 'output' => 'head-sha'];
            }

            return ['exit_code' => 128, 'output' => ''];
        });

        $fact = $reader->read($this->phpFixture());

        $this->assertSame(0, $fact->commitCount);
        $this->assertSame([], $fact->decisions);
    }

    public function test_reader_caches_by_mtime_and_head(): void
    {
        $calls = 0;
        $reader = new AtlasCortexDecisionHistoryReader(function (array $command) use (&$calls): array {
            $calls++;
            if ($command === ['git', 'rev-parse', 'HEAD']) {
                return ['exit_code' => 0, 'output' => 'head-sha'];
            }

            return ['exit_code' => 0, 'output' => implode("\x1f", ['abc123', 'decision cached', '', '1710000000'])."\x1e"];
        });
        $path = $this->phpFixture();

        $first = $reader->read($path)->toArray();
        $second = $reader->read($path)->toArray();

        $this->assertSame($first, $second);
        $this->assertSame(3, $calls, 'HEAD is checked each time; log output is cached for the same file mtime and HEAD.');
    }

    public function test_multiline_body_commit_is_parsed_not_dropped(): void
    {
        $reader = new AtlasCortexDecisionHistoryReader(function (array $command): array {
            if ($command === ['git', 'rev-parse', 'HEAD']) {
                return ['exit_code' => 0, 'output' => 'head-sha'];
            }

            $multiLineBody = "first line\nsecond line\nthird line";
            $record1 = implode("\x1f", ['sha1', 'subject one', $multiLineBody, '1710000000'])."\x1e";
            $record2 = implode("\x1f", ['sha2', 'subject two', 'simple body', '1710000001'])."\x1e";

            return ['exit_code' => 0, 'output' => $record1.$record2];
        });

        $fact = $reader->read($this->phpFixture());

        $this->assertSame(2, $fact->commitCount, 'multi-line body commit must not be dropped');
        $this->assertSame('sha1', $fact->decisions[0]['sha']);
        $this->assertSame('subject one', $fact->decisions[0]['subject']);
        $this->assertSame('sha2', $fact->decisions[1]['sha']);
    }

    public function test_implementation_contains_no_mutating_git_command_phrases(): void
    {
        $implementation = (string) file_get_contents(__DIR__.'/../../../../../app/Services/Ai/AutonomousEvolution/Discovery/Cortex/AtlasCortexDecisionHistoryReader.php');

        foreach (['git commit', 'git reset', 'git checkout', 'git push'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $implementation);
        }
    }

    private function phpFixture(): string
    {
        $root = sys_get_temp_dir().'/atlas-cortex-history-'.bin2hex(random_bytes(6));
        mkdir($root, 0775, true);
        $path = $root.'/HistoryFixture.php';
        file_put_contents($path, <<<'PHP'
<?php

namespace Tests\Fixtures\CortexHistory;

final class HistoryFixture
{
}
PHP);

        return $path;
    }
}
