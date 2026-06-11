<?php

declare(strict_types=1);

namespace App\Services\Ai\VentureFoundry\Comprehension\Capabilities;

use App\Models\AiVentureComprehensionFinding;
use App\Models\AiVentureComprehensionRun;
use App\Services\Ai\VentureFoundry\Comprehension\ComprehensionCapability;
use App\Services\Ai\VentureFoundry\Comprehension\ComprehensionRecorder;
use App\Services\Ai\VentureFoundry\Comprehension\FindingDraft;
use App\Services\Ai\VentureFoundry\Comprehension\WorkspaceReader;

/**
 * Improvement comprehension capability: deterministic, prioritized, high-leverage
 * improvement opportunities read straight from the venture's source workspace.
 *
 * This is NOT a raw problem detector — every finding is an actionable
 * improvement (an area, a rationale and a recommendation) scored by leverage =
 * impact / effort, so the foundry can attack the cheapest, highest-impact work
 * first. Like the rest of the comprehension subsystem the scanner never invents:
 * each finding is cited (path + line) or derived from cited evidence, and it
 * runs fully offline with zero provider/LLM spend.
 *
 * Areas (category):
 *  - performance         N+1 / unbounded-query / SELECT * hot spots
 *  - reliability         external calls with no try/catch or retry nearby
 *  - security_hardening  hardcoded secrets rolled up to "move secrets to vault"
 *  - test_coverage       Controllers/Services classes with no matching test
 *  - maintainability     oversized files worth splitting
 *  - dx                  developer-experience signals (lingering TODO/FIXME debt)
 */
class VentureImprovementScannerService implements ComprehensionCapability
{
    public const KIND = 'improvement_opportunity';

    public const AREA_PERFORMANCE = 'performance';

    public const AREA_RELIABILITY = 'reliability';

    public const AREA_SECURITY_HARDENING = 'security_hardening';

    public const AREA_TEST_COVERAGE = 'test_coverage';

    public const AREA_MAINTAINABILITY = 'maintainability';

    public const AREA_DX = 'dx';

    /** Files larger than this (LOC) are flagged for a split. */
    private const LARGE_FILE_LOC = 400;

    /** Per-detector cap so a pathological repo can't flood the run. */
    private const MAX_PER_AREA = 25;

    public function __construct(private readonly ComprehensionRecorder $recorder) {}

    public function capability(): string
    {
        return AiVentureComprehensionFinding::CAPABILITY_IMPROVEMENT;
    }

    /**
     * Scan the workspace, persist improvement findings (sorted by leverage desc)
     * and return a structured report.
     *
     * @return array<string,mixed>
     */
    public function scan(AiVentureComprehensionRun $run, WorkspaceReader $reader): array
    {
        /** @var list<FindingDraft> $drafts */
        $drafts = [];

        foreach ($this->detectSecurityHardening($run, $reader) as $draft) {
            $drafts[] = $draft;
        }
        foreach ($this->detectPerformance($reader) as $draft) {
            $drafts[] = $draft;
        }
        foreach ($this->detectReliability($reader) as $draft) {
            $drafts[] = $draft;
        }
        foreach ($this->detectTestCoverage($run, $reader) as $draft) {
            $drafts[] = $draft;
        }
        foreach ($this->detectMaintainability($reader) as $draft) {
            $drafts[] = $draft;
        }
        foreach ($this->detectDeveloperExperience($reader) as $draft) {
            $drafts[] = $draft;
        }

        // Highest leverage first; ties broken by impact then title for stability.
        usort($drafts, static function (FindingDraft $a, FindingDraft $b): int {
            return [$b->leverageScore ?? 0.0, $b->impactScore ?? 0.0, $a->title]
                <=> [$a->leverageScore ?? 0.0, $a->impactScore ?? 0.0, $b->title];
        });

        $recorded = $this->recorder->recordMany($run, $drafts);

        return $this->report($recorded);
    }

    // -- Detectors ---------------------------------------------------------

    /**
     * SECURITY_HARDENING: hardcoded secrets are the single highest-leverage
     * improvement (trivial to fix, catastrophic to leave). Roll every cited
     * occurrence into ONE "move secrets to vault/env" improvement so it sorts
     * to the top instead of fragmenting into per-line noise.
     *
     * @return list<FindingDraft>
     */
    private function detectSecurityHardening(AiVentureComprehensionRun $run, WorkspaceReader $reader): array
    {
        // Unambiguous provider tokens (no value guard) + a guarded generic
        // assignment form. The guard mirrors the problem map: skip test
        // fixtures and require a strong, high-entropy, non-template value so a
        // URL macro (`token: '{keyword}'`) or test stub never inflates the count.
        $unambiguous = [
            '/(?:sk|rk)_(?:live|test)_[A-Za-z0-9]{8,}/',     // Stripe secret keys
            '/\bAKIA[0-9A-Z]{16}\b/',                         // AWS access key id
            '/\bAIza[0-9A-Za-z_\-]{20,}/',                    // Google API key
            '/\bghp_[A-Za-z0-9]{20,}/',                       // GitHub token
            '/-----BEGIN (?:RSA |EC |OPENSSH |DSA )?PRIVATE KEY-----/',
        ];
        $guarded = '/(?<![\w.])(?:password|passwd|pwd|secret|api[_-]?key|client[_-]?secret|access[_-]?token|auth[_-]?token)\s*[:=]\s*[\'"]([^\'"]{8,})[\'"]/i';

        $hits = [];
        foreach ($unambiguous as $pattern) {
            foreach ($reader->grep($pattern, ['php', 'js', 'ts', 'jsx', 'tsx', 'env', 'json', 'yaml', 'yml'], 200) as $match) {
                $hits[$match['path'].':'.$match['line']] = $match;
            }
        }
        foreach ($reader->grep($guarded, ['php', 'js', 'ts', 'jsx', 'tsx', 'env', 'json', 'yaml', 'yml'], 200) as $match) {
            if ($this->isTestPath($match['path'])) {
                continue;
            }
            if (preg_match($guarded, $match['text'], $m) !== 1 || ! $this->looksLikeStrongCredential($m[1] ?? '')) {
                continue;
            }
            $hits[$match['path'].':'.$match['line']] = $match;
        }

        if ($hits === []) {
            return [];
        }

        $hits = array_values($hits);
        usort($hits, static fn (array $a, array $b): int => [$a['path'], $a['line']] <=> [$b['path'], $b['line']]);

        $first = $hits[0];
        $refs = [];
        foreach (array_slice($hits, 0, self::MAX_PER_AREA) as $hit) {
            $refs[] = ['path' => $hit['path'], 'line' => $hit['line']];
        }

        $count = count($hits);
        $title = $count === 1
            ? 'Move hardcoded secret to vault/env'
            : sprintf('Move %d hardcoded secrets to vault/env', $count);

        return [new FindingDraft(
            capability: $this->capability(),
            kind: self::KIND,
            title: $title,
            category: self::AREA_SECURITY_HARDENING,
            detail: 'Secret material is committed inline in source. Rotate the exposed credential and load secrets from a vault/environment so they never ship in the repository.',
            impactScore: 5.0,
            effortScore: 1.0,
            leverageScore: $this->leverage(5.0, 1.0),
            confidence: 0.95,
            evidenceKind: AiVentureComprehensionFinding::EVIDENCE_OBSERVED,
            evidencePath: $first['path'],
            evidenceLine: $first['line'],
            evidenceSnippet: $this->redactSecret($first['text']),
            evidenceRefs: $refs,
            recommendation: 'Rotate the exposed secret immediately, replace inline literals with env()/config() reads, and add a secret scanner to CI to block regressions.',
            payload: ['occurrences' => $count],
        )];
    }

    /**
     * A real leaked credential: printable ASCII, no whitespace, >=16 chars,
     * carrying a digit and a letter, and not a template/placeholder — so a URL
     * macro or test stub never counts as a secret.
     */
    private function looksLikeStrongCredential(string $value): bool
    {
        if (preg_match('/^[\x21-\x7E]{16,200}$/', $value) !== 1) {
            return false; // spaces, accents or too short
        }
        if (preg_match('/[{}<>$%]/', $value) === 1) {
            return false; // template / interpolation marker -> not a secret
        }
        if (preg_match('/(?:test|example|sample|dummy|changeme|placeholder|your[_-]?|lorem|xxxx|\.\.\.|fake|mock)/i', $value) === 1) {
            return false;
        }

        return preg_match('/[0-9]/', $value) === 1 && preg_match('/[A-Za-z]/', $value) === 1;
    }

    private function isTestPath(string $path): bool
    {
        $lower = strtolower($path);

        return preg_match('#(?:^|/)(?:tests?|__tests__|__mocks__|spec)/#', $lower) === 1
            || preg_match('#\.(?:test|spec|stories)\.[a-z]+$#', $lower) === 1
            || str_ends_with($lower, 'test.php');
    }

    /**
     * PERFORMANCE: N+1 and unbounded-query hot spots — `->get()`/`->all()` or a
     * query call invoked inside a `foreach`, plus `SELECT *`.
     *
     * @return list<FindingDraft>
     */
    private function detectPerformance(WorkspaceReader $reader): array
    {
        $drafts = [];

        // N+1: a query terminator / query call inside a foreach loop body.
        $queryInLoop = '/->(get|all|first|find|count|pluck|where)\s*\(/';
        $loopOpen = '/\bforeach\s*\(/';

        foreach ($reader->files(['php']) as $rel) {
            $lines = $reader->lines($rel);
            $loopDepth = 0;
            foreach ($lines as $i => $text) {
                if (@preg_match($loopOpen, $text) === 1) {
                    $loopDepth++;

                    continue;
                }
                if ($loopDepth > 0 && @preg_match($queryInLoop, $text) === 1) {
                    $line = $i + 1;
                    $drafts[] = new FindingDraft(
                        capability: $this->capability(),
                        kind: self::KIND,
                        title: sprintf('Eliminate likely N+1 query in %s', basename($rel)),
                        category: self::AREA_PERFORMANCE,
                        detail: 'A query is issued inside a loop body, which scales linearly with the collection (the classic N+1 pattern).',
                        impactScore: 4.0,
                        effortScore: 2.0,
                        leverageScore: $this->leverage(4.0, 2.0),
                        confidence: 0.6,
                        evidenceKind: AiVentureComprehensionFinding::EVIDENCE_OBSERVED,
                        evidencePath: $rel,
                        evidenceLine: $line,
                        evidenceSnippet: $reader->snippet($rel, $line, 1),
                        evidenceRefs: [['path' => $rel, 'line' => $line]],
                        recommendation: 'Eager-load the relation (with(...)) or batch the lookups outside the loop so the query count is constant.',
                    );
                    if (count($drafts) >= self::MAX_PER_AREA) {
                        return $drafts;
                    }
                }
                // Crude block close: a line that is just a closing brace pops a level.
                if ($loopDepth > 0 && trim($text) === '}') {
                    $loopDepth--;
                }
            }
        }

        // SELECT *: scans more columns than needed, defeats covering indexes.
        foreach ($reader->grep('/SELECT\s+\*\s+FROM/i', ['php', 'sql'], self::MAX_PER_AREA) as $match) {
            $drafts[] = new FindingDraft(
                capability: $this->capability(),
                kind: self::KIND,
                title: sprintf('Replace SELECT * with explicit columns in %s', basename($match['path'])),
                category: self::AREA_PERFORMANCE,
                detail: 'SELECT * reads every column and prevents covering-index plans; selecting only needed columns reduces IO and payload.',
                impactScore: 3.0,
                effortScore: 2.0,
                leverageScore: $this->leverage(3.0, 2.0),
                confidence: 0.7,
                evidenceKind: AiVentureComprehensionFinding::EVIDENCE_OBSERVED,
                evidencePath: $match['path'],
                evidenceLine: $match['line'],
                evidenceSnippet: $match['text'],
                evidenceRefs: [['path' => $match['path'], 'line' => $match['line']]],
                recommendation: 'Select only the columns the query consumes so the database can use covering indexes.',
            );
        }

        return $drafts;
    }

    /**
     * RELIABILITY: external/network calls (Stripe, Http::, curl, Guzzle,
     * file_get_contents over http) with no try/catch or retry within a small
     * window — a single upstream blip then takes the request down.
     *
     * @return list<FindingDraft>
     */
    private function detectReliability(WorkspaceReader $reader): array
    {
        $drafts = [];
        $external = '/(Http::|Guzzle|GuzzleHttp|curl_exec|curl_init|file_get_contents\s*\(\s*[\'"]https?:|Stripe\\\\|\\\\Stripe\\\\|->charges->|->paymentIntents->|Stripe::)/';

        foreach ($reader->files(['php']) as $rel) {
            $lines = $reader->lines($rel);
            $hasGuard = $this->fileHasResilienceGuard($lines);

            foreach ($lines as $i => $text) {
                if (@preg_match($external, $text) !== 1) {
                    continue;
                }
                if ($this->lineWindowGuarded($lines, $i)) {
                    continue;
                }
                $line = $i + 1;
                $drafts[] = new FindingDraft(
                    capability: $this->capability(),
                    kind: self::KIND,
                    title: sprintf('Wrap external call in %s with error handling/retry', basename($rel)),
                    category: self::AREA_RELIABILITY,
                    detail: $hasGuard
                        ? 'An external call has no try/catch or retry in its immediate vicinity even though the file handles errors elsewhere; an upstream failure here is unhandled.'
                        : 'An external call has no try/catch or retry anywhere nearby; a single upstream failure propagates straight to the caller.',
                    impactScore: 4.0,
                    effortScore: 2.0,
                    leverageScore: $this->leverage(4.0, 2.0),
                    confidence: 0.55,
                    evidenceKind: AiVentureComprehensionFinding::EVIDENCE_OBSERVED,
                    evidencePath: $rel,
                    evidenceLine: $line,
                    evidenceSnippet: $reader->snippet($rel, $line, 1),
                    evidenceRefs: [['path' => $rel, 'line' => $line]],
                    recommendation: 'Wrap the call in try/catch, add a bounded retry/backoff, and fail gracefully (timeout + fallback) so one upstream blip does not cascade.',
                );
                if (count($drafts) >= self::MAX_PER_AREA) {
                    return $drafts;
                }
            }
        }

        return $drafts;
    }

    /**
     * TEST_COVERAGE: Controllers/Services classes with no matching *Test file.
     * Each finding cites the untested class itself (the location to cover).
     *
     * @return list<FindingDraft>
     */
    private function detectTestCoverage(AiVentureComprehensionRun $run, WorkspaceReader $reader): array
    {
        // Known test class basenames anywhere in the tree (cheap membership set).
        $testBasenames = [];
        foreach ($reader->files(['php']) as $rel) {
            $base = basename($rel, '.php');
            if (str_ends_with($base, 'Test')) {
                $testBasenames[strtolower(substr($base, 0, -4))] = true;
            }
        }

        $drafts = [];
        foreach ($reader->files(['php']) as $rel) {
            if (! str_contains($rel, 'app/Http/Controllers/') && ! str_contains($rel, 'app/Services/')) {
                continue;
            }
            $base = basename($rel, '.php');
            if (str_ends_with($base, 'Test')) {
                continue;
            }
            if (isset($testBasenames[strtolower($base)])) {
                continue; // already has a matching *Test
            }

            $classLine = $this->firstClassLine($reader, $rel);

            $drafts[] = new FindingDraft(
                capability: $this->capability(),
                kind: self::KIND,
                title: sprintf('Add tests for %s', $base),
                category: self::AREA_TEST_COVERAGE,
                detail: sprintf('%s has no matching %sTest, so regressions in its behavior would ship unnoticed.', $base, $base),
                impactScore: $this->isControllerOrPayment($rel, $base) ? 4.0 : 3.0,
                effortScore: 3.0,
                leverageScore: $this->leverage($this->isControllerOrPayment($rel, $base) ? 4.0 : 3.0, 3.0),
                confidence: 0.8,
                evidenceKind: AiVentureComprehensionFinding::EVIDENCE_OBSERVED,
                evidencePath: $rel,
                evidenceLine: $classLine,
                evidenceSnippet: $reader->snippet($rel, $classLine, 0),
                evidenceRefs: [['path' => $rel, 'line' => $classLine]],
                recommendation: sprintf('Create %sTest covering the public surface, including the unhappy paths.', $base),
            );
            if (count($drafts) >= self::MAX_PER_AREA) {
                break;
            }
        }

        return $drafts;
    }

    /**
     * MAINTAINABILITY: oversized files (> LARGE_FILE_LOC) are hard to change
     * safely; recommend a split.
     *
     * @return list<FindingDraft>
     */
    private function detectMaintainability(WorkspaceReader $reader): array
    {
        $drafts = [];
        foreach ($reader->files(['php', 'js', 'ts', 'tsx', 'jsx', 'py']) as $rel) {
            $lines = $reader->lines($rel);
            $loc = count($lines);
            if ($loc <= self::LARGE_FILE_LOC) {
                continue;
            }
            $classLine = $this->firstClassLine($reader, $rel);
            $impact = $loc > self::LARGE_FILE_LOC * 2 ? 3.0 : 2.0;
            $drafts[] = new FindingDraft(
                capability: $this->capability(),
                kind: self::KIND,
                title: sprintf('Split %s (%d LOC)', basename($rel), $loc),
                category: self::AREA_MAINTAINABILITY,
                detail: sprintf('%s is %d lines — large files concentrate change risk and slow review; extract cohesive collaborators.', basename($rel), $loc),
                impactScore: $impact,
                effortScore: 4.0,
                leverageScore: $this->leverage($impact, 4.0),
                confidence: 0.6,
                evidenceKind: AiVentureComprehensionFinding::EVIDENCE_OBSERVED,
                evidencePath: $rel,
                evidenceLine: $classLine,
                evidenceSnippet: $reader->snippet($rel, $classLine, 0),
                evidenceRefs: [['path' => $rel, 'line' => $classLine]],
                recommendation: 'Extract cohesive responsibilities into smaller collaborators behind clear interfaces.',
            );
            if (count($drafts) >= self::MAX_PER_AREA) {
                break;
            }
        }

        return $drafts;
    }

    /**
     * DX: lingering TODO/FIXME debt rolled up per file as a low-effort cleanup
     * improvement (cite the first occurrence, list the rest).
     *
     * @return list<FindingDraft>
     */
    private function detectDeveloperExperience(WorkspaceReader $reader): array
    {
        $byFile = [];
        foreach ($reader->grep('/\b(TODO|FIXME|HACK|XXX)\b/', ['php', 'js', 'ts', 'tsx', 'jsx', 'py'], 200) as $match) {
            $byFile[$match['path']][] = $match;
        }

        $drafts = [];
        foreach ($byFile as $path => $matches) {
            $first = $matches[0];
            $refs = [];
            foreach (array_slice($matches, 0, self::MAX_PER_AREA) as $hit) {
                $refs[] = ['path' => $hit['path'], 'line' => $hit['line']];
            }
            $count = count($matches);
            $drafts[] = new FindingDraft(
                capability: $this->capability(),
                kind: self::KIND,
                title: sprintf('Resolve %d TODO/FIXME marker%s in %s', $count, $count === 1 ? '' : 's', basename($path)),
                category: self::AREA_DX,
                detail: 'Inline TODO/FIXME markers record known debt; turning them into tracked, resolved work keeps the codebase honest.',
                impactScore: 2.0,
                effortScore: 2.0,
                leverageScore: $this->leverage(2.0, 2.0),
                confidence: 0.7,
                evidenceKind: AiVentureComprehensionFinding::EVIDENCE_OBSERVED,
                evidencePath: $first['path'],
                evidenceLine: $first['line'],
                evidenceSnippet: $first['text'],
                evidenceRefs: $refs,
                recommendation: 'Triage each marker: resolve it or convert it into a tracked task, then remove the inline note.',
                payload: ['markers' => $count],
            );
            if (count($drafts) >= self::MAX_PER_AREA) {
                break;
            }
        }

        return $drafts;
    }

    // -- Helpers -----------------------------------------------------------

    private function leverage(float $impact, float $effort): float
    {
        if ($effort <= 0.0) {
            $effort = 1.0;
        }

        return round($impact / $effort, 3);
    }

    /**
     * Whether the file contains any error-handling primitive at all.
     *
     * @param  list<string>  $lines
     */
    private function fileHasResilienceGuard(array $lines): bool
    {
        foreach ($lines as $text) {
            if (@preg_match('/\b(try|catch|retry|Retry|->retry\(|rescue\()\b/', $text) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether a try/catch/retry sits within a few lines of the external call.
     *
     * @param  list<string>  $lines
     */
    private function lineWindowGuarded(array $lines, int $idx): bool
    {
        $from = max(0, $idx - 4);
        $to = min(count($lines) - 1, $idx + 2);
        for ($i = $from; $i <= $to; $i++) {
            if (@preg_match('/\b(try|catch|retry)\b|->retry\(|rescue\(/', $lines[$i] ?? '') === 1) {
                return true;
            }
        }

        return false;
    }

    private function firstClassLine(WorkspaceReader $reader, string $rel): int
    {
        foreach ($reader->lines($rel) as $i => $text) {
            if (@preg_match('/^\s*(?:final\s+|abstract\s+)?(?:class|interface|trait|enum)\s+\w+/', $text) === 1) {
                return $i + 1;
            }
        }

        return 1;
    }

    private function isControllerOrPayment(string $rel, string $base): bool
    {
        return str_contains($rel, 'Controllers/')
            || str_contains(strtolower($base), 'billing')
            || str_contains(strtolower($base), 'payment')
            || str_contains(strtolower($base), 'commission')
            || str_contains(strtolower($base), 'subscription');
    }

    private function redactSecret(string $text): string
    {
        return (string) preg_replace(
            ['/((sk|pk|rk)_(live|test)_)[A-Za-z0-9]{4,}/', '/(AKIA)[0-9A-Z]{12,}/'],
            ['$1***REDACTED***', '$1***REDACTED***'],
            $text,
        );
    }

    /**
     * @param  array<int,AiVentureComprehensionFinding>  $recorded
     * @return array<string,mixed>
     */
    private function report(array $recorded): array
    {
        $byArea = [];
        $top = [];
        foreach ($recorded as $finding) {
            $area = (string) $finding->category;
            $byArea[$area] = ($byArea[$area] ?? 0) + 1;
        }

        // Already sorted by leverage desc at draft time; preserve that order.
        foreach (array_slice($recorded, 0, 5) as $finding) {
            $top[] = [
                'title' => $finding->title,
                'area' => $finding->category,
                'impact' => (float) $finding->impact_score,
                'effort' => (float) $finding->effort_score,
                'leverage' => (float) $finding->leverage_score,
                'evidence_refs' => $finding->evidence_refs ?? [],
            ];
        }

        arsort($byArea);

        return [
            'capability' => $this->capability(),
            'total' => count($recorded),
            'by_area' => $byArea,
            'top_leverage' => $top,
        ];
    }
}
