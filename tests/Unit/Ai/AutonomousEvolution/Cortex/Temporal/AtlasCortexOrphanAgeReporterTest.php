<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution\Cortex\Temporal;

use App\Services\Ai\AutonomousEvolution\Discovery\Cortex\ApiDiff\AtlasCortexApiSurfaceExtractor;
use App\Services\Ai\AutonomousEvolution\Discovery\Cortex\Temporal\AtlasCortexOrphanAgeReporter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class AtlasCortexOrphanAgeReporterTest extends TestCase
{
    public function test_it_reports_unwired_since_at_for_a_real_fqcn(): void
    {
        $fqcn = AtlasCortexApiSurfaceExtractor::class;
        $headTimestamp = $this->git(['log', '-1', '--format=%cI', 'HEAD']);
        $reporter = new AtlasCortexOrphanAgeReporter($this->repoRoot(), fn (): string => $headTimestamp);

        $rows = $reporter->report([$fqcn]);
        $row = $rows[$fqcn];

        $this->assertTrue($row['resolved']);
        $this->assertSame(
            $this->normalizeTimestamp($this->git(['log', '--diff-filter=A', '--follow', '--format=%cI', '--', $this->relativePath((string) $row['file_path'])], true)),
            $row['unwired_since_at']
        );
        $this->assertSame(
            $this->normalizeTimestamp($this->git(['log', '-1', '--format=%cI', '--', $this->relativePath((string) $row['file_path'])])),
            $row['last_modified_at']
        );
    }

    public function test_it_reports_unwired_days_from_a_fixed_head_timestamp(): void
    {
        $fqcn = AtlasCortexApiSurfaceExtractor::class;
        $headTimestamp = '2026-06-24T00:00:00Z';
        $reporter = new AtlasCortexOrphanAgeReporter($this->repoRoot(), fn (): string => $headTimestamp);

        $rows = $reporter->report([$fqcn]);
        $row = $rows[$fqcn];

        $unwiredSinceUnix = (new \DateTimeImmutable((string) $row['unwired_since_at'], new \DateTimeZone('UTC')))->getTimestamp();
        $headUnix = (new \DateTimeImmutable($headTimestamp, new \DateTimeZone('UTC')))->getTimestamp();
        $expected = (int) floor(max(0, $headUnix - $unwiredSinceUnix) / 86400);

        $this->assertSame($expected, $row['unwired_days']);
    }

    public function test_it_fail_closes_for_unresolvable_fqcns(): void
    {
        $fqcn = 'App\\Definitely\\Missing\\GhostOrphan';
        $rows = (new AtlasCortexOrphanAgeReporter($this->repoRoot()))->report([$fqcn]);

        $this->assertSame([
            $fqcn => [
                'fqcn' => $fqcn,
                'resolved' => false,
                'reason' => 'fqcn_not_resolved_via_composer_autoload',
            ],
        ], $rows);
        $this->assertArrayNotHasKey('unwired_since_at', $rows[$fqcn]);
        $this->assertArrayNotHasKey('unwired_days', $rows[$fqcn]);
        $this->assertArrayNotHasKey('last_modified_at', $rows[$fqcn]);
    }

    public function test_source_exposes_no_forbidden_tokens(): void
    {
        $source = file_get_contents($this->repoRoot().'/app/Services/Ai/AutonomousEvolution/Discovery/Cortex/Temporal/AtlasCortexOrphanAgeReporter.php');

        $this->assertIsString($source);
        $this->assertStringNotContainsString('score', $source);
        $this->assertStringNotContainsString('rank', $source);
        $this->assertStringNotContainsString('rotting', $source);
        $this->assertStringNotContainsString('staleness', $source);
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
