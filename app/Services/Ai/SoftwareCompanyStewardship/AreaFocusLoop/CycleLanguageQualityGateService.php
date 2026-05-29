<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

/**
 * HARD LAW (operator mandate, 2026-05-29): EXTREME diff-scoped language-quality
 * gate for the autonomous loop. Runs real LOCAL static-analysis tools against
 * ONLY this cycle's changed files (never the whole codebase). Fail-CLOSED: a
 * touched language with no configured/wired toolchain BLOCKS the merge — the
 * loop must never merge code it cannot verify. The exact tool output is recorded
 * on the cycle (operator-visible + evidence) and the blocker is non-terminal, so
 * the finding is retried until it passes, then review-locked after the runner's
 * bounded attempt cap.
 *
 * Default enforcement is 'off' so the existing suite (which resolves the REAL
 * service via the container) is byte-identical — the gate runs nothing and
 * blocks nothing. The unattended loop runs with ATLAS_STEWARDSHIP_LANGUAGE_QUALITY
 * =enforce (set per-run / in the runner), gating every cycle.
 *
 * Scope today: PHP static analysis (PHPStan/Larastan, baselined) is the wired
 * blocking tier. Python/Go/Swift/TS/JS are fail-closed (BLOCK) until their local
 * OSS toolchains are wired. Mutation (Infection) and format (Pint) are deferred
 * tiers (Infection needs a coverage driver; Pint needs a baseline) — NOT shipped
 * inert here, per the no-scaffold law.
 */
final class CycleLanguageQualityGateService
{
    public const BLOCKER = 'language_quality_gate_failed';

    /** Extension => language token. */
    private const LANGUAGE_BY_EXT = [
        'php' => 'php',
        'py' => 'python', 'pyi' => 'python',
        'go' => 'go',
        'swift' => 'swift',
        'ts' => 'ts', 'tsx' => 'ts', 'mts' => 'ts', 'cts' => 'ts',
        'js' => 'js', 'jsx' => 'js', 'mjs' => 'js', 'cjs' => 'js',
    ];

    /** Non-executable extensions that carry no merge risk and never gate. */
    private const NON_EXECUTABLE_EXT = [
        'json', 'yaml', 'yml', 'toml', 'md', 'neon', 'xml', 'env',
        'sql', 'css', 'scss', 'html', 'lock', 'txt', 'stub', 'blade',
    ];

    /** Paths that never gate (generated / vendored). */
    private const IGNORED_PREFIXES = [
        'vendor/', 'node_modules/', '.git/', 'storage/', 'bootstrap/cache/',
        'dist/', 'build/', 'coverage/', 'public/build/',
    ];

    public function __construct(private readonly CyclePhpTierRunner $runner) {}

    /**
     * @param  list<string>  $changedFiles  repo-relative changed paths (in the worktree)
     * @return array{passed:bool,blocker:?string,enforcement:string,languages:list<string>,fail_closed_languages:list<string>,unknown_files:list<string>,tool_results:list<array<string,mixed>>}
     */
    public function assess(string $repoRoot, string $worktree, array $changedFiles): array
    {
        $enforcement = (string) config('atlas.software_company_stewardship.language_quality.enforcement', 'off');

        // Test/default no-op: runs nothing, blocks nothing. Byte-identical flow.
        if ($enforcement !== 'enforce') {
            return $this->pass($enforcement, [], []);
        }

        $classified = $this->classify($changedFiles);
        $languages = array_keys($classified['languages']);

        // Fail-closed (a): an unknown executable file class can never slip through.
        if ($classified['unknown'] !== []) {
            return [
                'passed' => false,
                'blocker' => self::BLOCKER,
                'enforcement' => $enforcement,
                'languages' => $languages,
                'fail_closed_languages' => [],
                'unknown_files' => $classified['unknown'],
                'tool_results' => [],
            ];
        }

        if ($languages === []) {
            return $this->pass($enforcement, [], []); // only docs/config touched
        }

        /** @var array<string,array<string,mixed>> $toolchains */
        $toolchains = (array) config('atlas.software_company_stewardship.language_quality.toolchains', []);

        $passed = true;
        $failClosed = [];
        $toolResults = [];

        foreach ($classified['languages'] as $language => $files) {
            // Fail-closed (b): touched language with no wired toolchain => BLOCK.
            if ($language !== 'php' || ! array_key_exists($language, $toolchains)) {
                $failClosed[] = $language;
                $passed = false;

                continue;
            }

            foreach ((array) ($toolchains['php']['tools'] ?? []) as $tool) {
                $result = $this->runner->run((string) $tool, $repoRoot, $worktree, array_values($files));
                $ok = in_array((string) ($result['status'] ?? ''), ['passed', 'nothing_to_analyze'], true);
                $passed = $passed && $ok;
                $toolResults[] = [
                    'language' => 'php',
                    'tool' => $result['tool'] ?? $tool,
                    'status' => $result['status'] ?? 'crashed',
                    'exit_code' => $result['exit_code'] ?? 255,
                    'ok' => $ok,
                    'analyzed' => $result['analyzed'] ?? 0,
                    'output_excerpt' => $result['output'] ?? '',
                ];
            }
        }

        return [
            'passed' => $passed,
            'blocker' => $passed ? null : self::BLOCKER,
            'enforcement' => $enforcement,
            'languages' => $languages,
            'fail_closed_languages' => array_values(array_unique($failClosed)),
            'unknown_files' => [],
            'tool_results' => $toolResults,
        ];
    }

    /**
     * @param  list<string>  $languages
     * @param  list<array<string,mixed>>  $toolResults
     * @return array{passed:bool,blocker:null,enforcement:string,languages:list<string>,fail_closed_languages:list<string>,unknown_files:list<string>,tool_results:list<array<string,mixed>>}
     */
    private function pass(string $enforcement, array $languages, array $toolResults): array
    {
        return [
            'passed' => true,
            'blocker' => null,
            'enforcement' => $enforcement,
            'languages' => $languages,
            'fail_closed_languages' => [],
            'unknown_files' => [],
            'tool_results' => $toolResults,
        ];
    }

    /**
     * @param  list<string>  $changedFiles
     * @return array{languages:array<string,list<string>>,unknown:list<string>}
     */
    private function classify(array $changedFiles): array
    {
        $languages = [];
        $unknown = [];

        foreach ($changedFiles as $raw) {
            $path = trim((string) $raw);
            // git porcelain may quote paths with spaces/unicode ("a b.php"); the
            // runner skips non-existent paths, but normalize the obvious case here.
            if (strlen($path) >= 2 && $path[0] === '"' && substr($path, -1) === '"') {
                $path = substr($path, 1, -1);
            }
            $path = ltrim($path, '/');
            if ($path === '' || $this->isIgnored($path)) {
                continue;
            }

            $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
            if ($ext !== '' && in_array($ext, self::NON_EXECUTABLE_EXT, true)) {
                continue;
            }

            $language = self::LANGUAGE_BY_EXT[$ext] ?? null;
            if ($language === null) {
                $unknown[] = $path; // .rs/.kt/.rb/extensionless/binary => fail-closed

                continue;
            }
            $languages[$language][] = $path;
        }

        return ['languages' => $languages, 'unknown' => array_values($unknown)];
    }

    private function isIgnored(string $path): bool
    {
        foreach (self::IGNORED_PREFIXES as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return true;
            }
        }

        return str_ends_with($path, '.min.js');
    }
}
