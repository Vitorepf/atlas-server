<?php

declare(strict_types=1);

namespace App\Services\Ai\Cognitive\Failure;

use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopFailureHandleSource;
use Throwable;

/**
 * S3 — the PRODUCER of REAL bug-fix work supply. Reads a phpunit JSON report, reuses
 * {@see SuiteRedTriageHelper::classifyTestFailure()} to keep ONLY 'real_failure' reds (the
 * flaky-filter DROPS 'environmental' and 'unknown'), resolves the target file path from the
 * test path/classname CONSERVATIVELY (ambiguous => dropped), and writes a runnable handle via
 * {@see AtlasLoopFailureHandleSource::upsertHandle()} so the already-wired bug-fix lane has
 * something deterministic to grind.
 *
 * DEFAULT-OFF (flag `atlas.loop.failure_handle_harvest_enabled`): like the auto-feed harvester,
 * a `--force` run is the operator's manual run. The honest cut: this lane only manufactures work
 * for a red that (a) is a real_failure by the deterministic classifier AND (b) carries a runnable
 * handle (a reproducing test path) AND (c) resolves to ONE unambiguous target file. Everything
 * else is dropped — a fabricated handle is not work, it is noise.
 */
final class SuiteRedTestHandleHarvester
{
    public function __construct(
        private readonly ?SuiteRedTriageHelper $triage = null,
        private readonly ?AtlasLoopFailureHandleSource $handles = null,
    ) {}

    private function triage(): SuiteRedTriageHelper
    {
        return $this->triage ?? new SuiteRedTriageHelper();
    }

    private function handles(): AtlasLoopFailureHandleSource
    {
        return $this->handles ?? new AtlasLoopFailureHandleSource();
    }

    /**
     * Harvest runnable real-failure handles from a phpunit JSON report.
     *
     * @return array{
     *     status:string,
     *     scanned:int,
     *     harvested:int,
     *     dropped_environmental:int,
     *     dropped_unknown:int,
     *     dropped_unrunnable:int,
     *     dropped_ambiguous_target:int,
     *     write_failed:int
     * }
     */
    public function harvest(?string $reportPath, bool $force = false): array
    {
        $enabled = (bool) config('atlas.loop.failure_handle_harvest_enabled', false);
        if (! $enabled && ! $force) {
            return $this->report('disabled');
        }
        if ($reportPath === null || $reportPath === '' || ! is_file($reportPath)) {
            return $this->report('report_missing');
        }
        $decoded = json_decode((string) @file_get_contents($reportPath), true);
        if (! is_array($decoded)) {
            return $this->report('report_json_invalid');
        }

        $rows = $this->testRows($decoded);
        $scanned = 0;
        $harvested = 0;
        $droppedEnvironmental = 0;
        $droppedUnknown = 0;
        $droppedUnrunnable = 0;
        $droppedAmbiguous = 0;
        $writeFailed = 0;

        foreach ($rows as $row) {
            $status = mb_strtolower(trim((string) ($row['status'] ?? '')));
            if (! in_array($status, ['failed', 'failure', 'error'], true)) {
                continue;
            }
            $scanned++;

            $message = (string) ($row['message'] ?? $row['error'] ?? $row['output'] ?? '');
            // FLAKY-FILTER: reuse the deterministic classifier; keep ONLY real_failure.
            $class = $this->triage()->classifyTestFailure($message)['classification'];
            if ($class === 'environmental') {
                $droppedEnvironmental++;

                continue;
            }
            if ($class !== 'real_failure') {
                $droppedUnknown++; // 'unknown' (or any non-real) => not manufactured into work

                continue;
            }

            // A runnable handle MUST carry a reproducing test path.
            $testPath = $this->resolveTestPath($row);
            if ($testPath === null) {
                $droppedUnrunnable++;

                continue;
            }

            // Resolve the target file CONSERVATIVELY: explicit target wins; else map the test
            // path to its production file. Ambiguous / unresolvable => DROP (never guess a target).
            $target = $this->resolveTargetPath($row, $testPath);
            if ($target === null) {
                $droppedAmbiguous++;

                continue;
            }

            $assertion = $this->cleanString($row['failing_assertion'] ?? $row['name'] ?? $row['test'] ?? null);
            $ok = $this->handles()->upsertHandle($target, $testPath, null, $assertion, $message !== '' ? $message : null);
            if ($ok) {
                $harvested++;
            } else {
                $writeFailed++;
            }
        }

        return [
            'status' => 'ok',
            'scanned' => $scanned,
            'harvested' => $harvested,
            'dropped_environmental' => $droppedEnvironmental,
            'dropped_unknown' => $droppedUnknown,
            'dropped_unrunnable' => $droppedUnrunnable,
            'dropped_ambiguous_target' => $droppedAmbiguous,
            'write_failed' => $writeFailed,
        ];
    }

    /**
     * @param  array<string,mixed>  $decoded
     * @return list<array<string,mixed>>
     */
    private function testRows(array $decoded): array
    {
        $rows = $decoded['tests'] ?? $decoded['failures'] ?? $decoded;
        if (! is_array($rows)) {
            return [];
        }
        $out = [];
        foreach ($rows as $row) {
            if (is_array($row)) {
                $out[] = $row;
            }
        }

        return $out;
    }

    /**
     * The reproducing test path: an explicit `test_path`/`file` field that points at a tests/*.php
     * file. Conservative: only a repo-relative-looking PHP test path is accepted.
     *
     * @param  array<string,mixed>  $row
     */
    private function resolveTestPath(array $row): ?string
    {
        foreach (['test_path', 'file', 'test_file'] as $key) {
            $candidate = $this->cleanString($row[$key] ?? null);
            if ($candidate === null) {
                continue;
            }
            $rel = $this->toRepoRelative($candidate);
            if ($rel !== null && str_ends_with($rel, '.php') && str_starts_with($rel, 'tests/')) {
                return $rel;
            }
        }

        return null;
    }

    /**
     * Conservatively resolve the TARGET production file the bug lives in.
     *   1. an explicit `target_path` row field wins (the harvest source already resolved it);
     *   2. else map `tests/Feature/Foo/BarTest.php` (or .../Unit/...) -> `app/Foo/Bar.php`.
     * Anything that does not resolve to ONE concrete app/*.php path is DROPPED (return null).
     *
     * @param  array<string,mixed>  $row
     */
    private function resolveTargetPath(array $row, string $testPath): ?string
    {
        $explicit = $this->cleanString($row['target_path'] ?? null);
        if ($explicit !== null) {
            $rel = $this->toRepoRelative($explicit);
            if ($rel !== null && str_ends_with($rel, '.php')) {
                return $rel;
            }

            return null; // an explicit-but-malformed target is ambiguous => drop
        }

        // Map a convention test path to its production sibling. tests/<Feature|Unit>/<...>/<Name>Test.php
        if (! preg_match('#^tests/(?:Feature|Unit)/(.+)Test\.php$#', $testPath, $m)) {
            return null; // non-convention test name => cannot conservatively resolve a target
        }
        $inner = $m[1]; // e.g. Services/Calculator
        if ($inner === '' || str_contains($inner, '..')) {
            return null;
        }

        return 'app/'.$inner.'.php';
    }

    /** Strip an absolute-path prefix down to a repo-relative app/ or tests/ path; else return trimmed. */
    private function toRepoRelative(string $value): ?string
    {
        $clean = $this->cleanString($value);
        if ($clean === null) {
            return null;
        }
        // If an absolute path embeds an app/ or tests/ segment, keep from there on.
        if (preg_match('#(?:^|/)((?:app|tests)/.+)$#', $clean, $m)) {
            return ltrim($m[1], '/');
        }

        return ltrim($clean, '/');
    }

    private function cleanString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    /**
     * @return array{status:string, scanned:int, harvested:int, dropped_environmental:int, dropped_unknown:int, dropped_unrunnable:int, dropped_ambiguous_target:int, write_failed:int}
     */
    private function report(string $status): array
    {
        return [
            'status' => $status,
            'scanned' => 0,
            'harvested' => 0,
            'dropped_environmental' => 0,
            'dropped_unknown' => 0,
            'dropped_unrunnable' => 0,
            'dropped_ambiguous_target' => 0,
            'write_failed' => 0,
        ];
    }
}
