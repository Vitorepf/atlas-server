<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution\Cortex\Temporal;

use App\Services\Ai\AutonomousEvolution\Discovery\Cortex\ApiDiff\AtlasCortexApiSurfaceExtractor;
use App\Services\Ai\AutonomousEvolution\Discovery\Cortex\Temporal\AtlasCortexSymbolAgeReporter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class AtlasCortexSymbolAgeReporterTest extends TestCase
{
    public function test_it_reports_git_facts_for_a_real_fqcn(): void
    {
        $fqcn = AtlasCortexApiSurfaceExtractor::class;
        $rows = (new AtlasCortexSymbolAgeReporter($this->repoRoot()))->report([$fqcn]);

        $this->assertArrayHasKey($fqcn, $rows);
        $row = $rows[$fqcn];

        $this->assertTrue($row['resolved']);
        $this->assertIsString($row['file_path']);
        $this->assertIsString($row['last_modified_at']);
        $this->assertSame(
            $this->normalizeTimestamp($this->git(['log', '-1', '--format=%cI', '--', $this->relativePath((string) $row['file_path'])])),
            $row['last_modified_at']
        );
        $this->assertSame(
            $this->normalizeTimestamp($this->git(['log', '--diff-filter=A', '--follow', '--format=%cI', '--', $this->relativePath((string) $row['file_path'])], true)),
            $row['first_seen_at']
        );
        $this->assertGreaterThanOrEqual(0, $row['modification_count_30d']);
        $this->assertArrayNotHasKey('score', $row);
        $this->assertArrayNotHasKey('rank', $row);
        $this->assertArrayNotHasKey('staleness_label', $row);
    }

    public function test_it_fail_closes_when_a_fqcn_cannot_be_resolved(): void
    {
        $fqcn = 'App\\Definitely\\Missing\\GhostClass';
        $rows = (new AtlasCortexSymbolAgeReporter($this->repoRoot()))->report([$fqcn]);

        $this->assertSame([
            $fqcn => [
                'fqcn' => $fqcn,
                'resolved' => false,
                'reason' => 'fqcn_not_resolved_via_composer_autoload',
            ],
        ], $rows);

        $this->assertArrayNotHasKey('last_modified_at', $rows[$fqcn]);
        $this->assertArrayNotHasKey('last_tested_at', $rows[$fqcn]);
        $this->assertArrayNotHasKey('first_seen_at', $rows[$fqcn]);
    }

    public function test_it_is_deterministic_across_two_consecutive_calls(): void
    {
        $fqcn = AtlasCortexApiSurfaceExtractor::class;
        $reporter = new AtlasCortexSymbolAgeReporter($this->repoRoot());

        $first = $reporter->report([$fqcn, 'App\\Definitely\\Missing\\GhostClass']);
        $second = $reporter->report([$fqcn, 'App\\Definitely\\Missing\\GhostClass']);

        $this->assertSame(
            json_encode($first, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            json_encode($second, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)
        );
    }

    public function test_source_exposes_no_forbidden_tokens(): void
    {
        $source = file_get_contents($this->repoRoot().'/app/Services/Ai/AutonomousEvolution/Discovery/Cortex/Temporal/AtlasCortexSymbolAgeReporter.php');

        $this->assertIsString($source);
        $this->assertStringNotContainsString('score', $source);
        $this->assertStringNotContainsString('rank', $source);
        $this->assertStringNotContainsString('stale_score', $source);
        $this->assertStringNotContainsString('staleness_score', $source);
    }

    private function git(array $args, bool $lastLine = false): string
    {
        $process = new Process(array_merge(['git'], $args), $this->repoRoot());
        $process->mustRun();

        $output = trim($process->getOutput());
        if (! $lastLine) {
            return $output;
        }

        $lines = array_values(array_filter(array_map('trim', explode("\n", $output)), static fn (string $line): bool => $line !== ''));

        return $lines[array_key_last($lines)];
    }

    private function normalizeTimestamp(string $value): string
    {
        return (new \DateTimeImmutable($value))
            ->setTimezone(new \DateTimeZone('UTC'))
            ->format('Y-m-d\TH:i:s\Z');
    }

    private function relativePath(string $path): string
    {
        $root = rtrim($this->repoRoot(), DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;

        return str_starts_with($path, $root)
            ? substr($path, strlen($root))
            : $path;
    }

    private function repoRoot(): string
    {
        return dirname(__DIR__, 6);
    }
}
