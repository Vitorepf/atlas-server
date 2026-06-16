<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution;

use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopNodeInterfaceExtractor;
use Symfony\Component\Process\Process;

/**
 * ACDE Leap 7 (spec/index-completeness ceiling) — the CHANGED-PUBLIC-SYMBOL coverage census (refuse-until-named).
 *
 * Today a changed PUBLIC symbol that carries no frozen acceptance command and no wired consumer contract
 * emits ZERO completeness criteria and certifies FAIL-OPEN — an un-tested new public method ships invisibly.
 * This census closes that hole with a machine anchor: every public method DECLARED in the diff's added lines
 * must be NAMED (whole-word) in the coverage corpus — the concatenated source of the test files the frozen
 * acceptance commands actually run. An uncovered new public symbol => 'changed_symbol_uncovered:<file>::<m>'.
 * Container-string / reflection dispatch sites are RECORDED as an audit signal (graph blind spots made visible).
 *
 * Anchored on AST (the extractor) + the literal test corpus + exit codes — never a model claim. HONEST LIMIT
 * (by design, stated): name-reference is NECESSARY, not SUFFICIENT — a thin/zero-assertion test that merely
 * names the symbol satisfies it (true sufficiency needs a mutation-kill / coverage-line anchor, a separate
 * axis; phpunit.xml configures no coverage driver, so faking it would be dishonest). Short-name matching can
 * false-PASS on homonyms across classes; deleted symbols + pure metaprogramming are invisible. It converts
 * "un-named new public symbol" from undetectable to machine-detectable — a real, bounded lift.
 *
 * Pure aside from git/file reads on the given workspace. No provider, no DB, no mutation.
 */
final class AtlasLoopChangedSymbolCoverageCensus
{
    public function __construct(private readonly ?AtlasLoopNodeInterfaceExtractor $extractor = null) {}

    /**
     * @param  list<string>  $acceptanceCommands  frozen commands whose referenced .php test files form the corpus
     * @return array{passed:bool, uncovered:list<string>, examined:int, dynamic_dispatch_sites:list<string>}
     */
    public function evaluate(string $workspace, array $acceptanceCommands): array
    {
        $extractor = $this->extractor ?? new AtlasLoopNodeInterfaceExtractor;
        $changed = $this->changedPhpFiles($workspace);
        $empty = ['passed' => true, 'uncovered' => [], 'examined' => 0, 'dynamic_dispatch_sites' => []];
        if ($changed === []) {
            return $empty;
        }

        $corpus = $this->coverageCorpus($workspace, $acceptanceCommands);

        $uncovered = [];
        $examined = 0;
        $dynamic = [];
        foreach ($changed as $file) {
            $path = $workspace.'/'.$file;
            $src = is_file($path) ? (string) @file_get_contents($path) : '';
            if (trim($src) === '') {
                continue; // deleted / unreadable — outside this census (stated limit)
            }
            $iface = $extractor->extract($src);
            if (($iface['parsed'] ?? false) !== true) {
                continue;
            }
            $publicMethods = [];
            foreach ($iface['types'] as $t) {
                foreach ($t['public_methods'] as $m) {
                    $publicMethods[mb_strtolower($m)] = $m;
                }
            }
            $addedDecls = $this->addedMethodDeclarations($workspace, $file);
            foreach ($addedDecls as $decl) {
                $key = mb_strtolower($decl);
                if (! isset($publicMethods[$key])) {
                    continue; // a private/protected added method is not a PUBLIC-surface completeness obligation
                }
                $examined++;
                if (! $this->corpusNames($corpus, $publicMethods[$key])) {
                    $uncovered[$file.'::'.$publicMethods[$key]] = true;
                }
            }
            foreach (($iface['service_refs'] ?? []) as $ref) {
                $dynamic[$file.':'.$ref] = true;
            }
            foreach ($this->reflectionSites($src) as $site) {
                $dynamic[$file.':'.$site] = true;
            }
        }

        return [
            'passed' => $uncovered === [],
            'uncovered' => array_keys($uncovered),
            'examined' => $examined,
            'dynamic_dispatch_sites' => array_keys($dynamic),
        ];
    }

    /**
     * Changed + untracked .php files in the workspace (the same dirty-tree surface the certifier measures).
     *
     * @return list<string>
     */
    private function changedPhpFiles(string $workspace): array
    {
        $files = [];
        foreach ([
            ['git', 'diff', '--name-only', '--no-ext-diff'],
            ['git', 'ls-files', '--others', '--exclude-standard'],
        ] as $argv) {
            $p = new Process($argv, $workspace, null, null, 30.0);
            $p->run();
            if (! $p->isSuccessful() && $p->getExitCode() !== 1) {
                continue;
            }
            foreach (preg_split('/\R/', trim((string) $p->getOutput())) ?: [] as $line) {
                $line = trim($line);
                if ($line !== '' && str_ends_with($line, '.php')) {
                    $files[$line] = true;
                }
            }
        }

        return array_keys($files);
    }

    /**
     * Public method names declared on the diff's ADDED lines of a file (`+ ... function name(`). These are
     * the new/modified declarations the census holds to a coverage obligation.
     *
     * @return list<string>
     */
    private function addedMethodDeclarations(string $workspace, string $file): array
    {
        $p = new Process(['git', 'diff', '--unified=0', '--no-ext-diff', '--', $file], $workspace, null, null, 30.0);
        $p->run();
        $out = $p->getOutput();
        // Untracked file => the whole content is "added".
        if (trim($out) === '') {
            $out = (string) @file_get_contents($workspace.'/'.$file);
            $lines = preg_split('/\R/', $out) ?: [];
        } else {
            $lines = array_values(array_filter(
                preg_split('/\R/', $out) ?: [],
                static fn (string $l): bool => str_starts_with($l, '+') && ! str_starts_with($l, '+++'),
            ));
        }

        $names = [];
        foreach ($lines as $line) {
            if (preg_match_all('/\bfunction\s+([A-Za-z_][A-Za-z0-9_]*)\s*\(/', $line, $m)) {
                foreach ($m[1] as $name) {
                    $names[$name] = true;
                }
            }
        }

        return array_keys($names);
    }

    /** The concatenated source of the .php files referenced by the acceptance commands (the test corpus). */
    private function coverageCorpus(string $workspace, array $acceptanceCommands): string
    {
        $corpus = '';
        $seen = [];
        foreach ($acceptanceCommands as $cmd) {
            if (! is_string($cmd)) {
                continue;
            }
            $corpus .= "\n".$cmd; // the command string itself is part of the corpus (it may name a method/filter)
            if (preg_match_all('/[A-Za-z0-9_\/.\-]+\.php\b/', $cmd, $m)) {
                foreach ($m[0] as $rel) {
                    $rel = ltrim($rel, '/');
                    if (isset($seen[$rel])) {
                        continue;
                    }
                    $seen[$rel] = true;
                    $path = $workspace.'/'.$rel;
                    if (is_file($path)) {
                        $corpus .= "\n".(string) @file_get_contents($path);
                    }
                }
            }
        }

        return $corpus;
    }

    /** Whole-word (case-insensitive) presence of a symbol name in the coverage corpus. */
    private function corpusNames(string $corpus, string $symbol): bool
    {
        if (trim($corpus) === '' || trim($symbol) === '') {
            return false;
        }

        return preg_match('/\b'.preg_quote($symbol, '/').'\b/i', $corpus) === 1;
    }

    /**
     * Reflection / dynamic-dispatch sites (recorded as audit flags — the code-graph's blind spots made
     * visible). Token scan, not gated.
     *
     * @return list<string>
     */
    private function reflectionSites(string $src): array
    {
        $out = [];
        foreach (['ReflectionClass', 'ReflectionMethod', 'call_user_func', 'call_user_func_array', 'method_exists', '->{$', '::{$'] as $needle) {
            if (str_contains($src, $needle)) {
                $out['reflection:'.ltrim($needle, '-:>')] = true;
            }
        }

        return array_keys($out);
    }
}
