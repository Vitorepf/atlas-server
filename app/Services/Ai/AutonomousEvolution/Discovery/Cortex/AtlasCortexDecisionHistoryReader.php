<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Discovery\Cortex;

use Symfony\Component\Process\Process;
use Throwable;

final class AtlasCortexDecisionHistoryReader
{
    /** @var array<string,DecisionHistoryFact> */
    private array $cache = [];

    /**
     * @param  callable(list<string>):array{exit_code:int, output:string}|null  $runner
     */
    public function __construct(
        private $runner = null,
        private readonly ?string $workingDirectory = null,
    ) {
    }

    public function read(string $filePath): DecisionHistoryFact
    {
        $head = $this->headSha();
        $mtime = is_file($filePath) ? (int) filemtime($filePath) : 0;
        $cacheKey = sha1($filePath.'|'.$mtime.'|'.$head);
        if (isset($this->cache[$cacheKey])) {
            return $this->cache[$cacheKey];
        }

        $fqcn = $this->fqcn(is_file($filePath) ? (string) file_get_contents($filePath) : '');
        $result = $this->run(['git', 'log', '--follow', '--format=%H%x1f%s%x1f%b%x1f%at', '--', $filePath]);
        if (($result['exit_code'] ?? 1) !== 0) {
            return $this->cache[$cacheKey] = new DecisionHistoryFact($fqcn, $filePath, 0, []);
        }

        $decisions = $this->parse((string) ($result['output'] ?? ''));

        return $this->cache[$cacheKey] = new DecisionHistoryFact(
            fqcn: $fqcn,
            filePath: $filePath,
            commitCount: count($decisions),
            decisions: $decisions,
        );
    }

    private function headSha(): string
    {
        $result = $this->run(['git', 'rev-parse', 'HEAD']);
        if (($result['exit_code'] ?? 1) !== 0) {
            return 'unknown';
        }

        return trim((string) ($result['output'] ?? '')) ?: 'unknown';
    }

    /**
     * @param  list<string>  $command
     * @return array{exit_code:int, output:string}
     */
    private function run(array $command): array
    {
        if (is_callable($this->runner)) {
            return (array) call_user_func($this->runner, $command);
        }

        try {
            $process = new Process($command, $this->workingDirectory ?? getcwd());
            $process->setTimeout(10);
            $process->run();

            return [
                'exit_code' => $process->getExitCode() ?? 1,
                'output' => $process->getOutput(),
            ];
        } catch (Throwable) {
            return ['exit_code' => 1, 'output' => ''];
        }
    }

    /**
     * @return list<array{sha:string, subject:string, decided_on:int, keyword_hit:bool}>
     */
    private function parse(string $output): array
    {
        $rows = preg_split('/\\R+/', trim($output)) ?: [];
        $decisions = [];
        foreach ($rows as $row) {
            $parts = explode("\x1f", $row);
            if (count($parts) < 4) {
                continue;
            }
            $sha = trim((string) $parts[0]);
            $subject = trim((string) $parts[1]);
            $body = trim((string) $parts[2]);
            $decidedOn = (int) trim((string) $parts[3]);
            if ($sha === '') {
                continue;
            }

            $decisions[] = [
                'sha' => $sha,
                'subject' => $subject,
                'decided_on' => $decidedOn,
                'keyword_hit' => $this->keywordHit($subject.' '.$body),
            ];
        }

        return $decisions;
    }

    private function keywordHit(string $text): bool
    {
        return preg_match('/(decision|petreo|pétreo|invariant|invariante|sentinel|fix|consolidat|governance)/iu', $text) === 1;
    }

    private function fqcn(string $bytes): string
    {
        preg_match('/^\\s*namespace\\s+([^;]+);/m', $bytes, $namespaceMatch);
        preg_match('/(?:^|\\n)\\s*(?:final\\s+|abstract\\s+|readonly\\s+)*(?:class|interface|trait|enum)\\s+([A-Za-z_][A-Za-z0-9_]*)/m', $bytes, $classMatch);
        $namespace = trim((string) ($namespaceMatch[1] ?? ''));
        $class = trim((string) ($classMatch[1] ?? ''));
        if ($class === '') {
            return $namespace;
        }

        return ltrim($namespace.'\\'.$class, '\\');
    }
}
