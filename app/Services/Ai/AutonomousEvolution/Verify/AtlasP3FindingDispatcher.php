<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Verify;

/**
 * THE P3 DISPATCHER — the bridge that makes the 4 points interlink.
 *
 * It runs the verifier oracles repo-wide as DISCOVERY and turns each finding into a
 * TYPED, disposition-aware record. The disposition is the honest law of the loop:
 *
 *   • dead-code (unused private member)  → AUTO_LOOP. Removal is behavior-preserving,
 *     the frozen verifier (deadcode==0) proves it, a holdout backstops it. The loop may
 *     close these autonomously, propose-only.
 *
 *   • fake-implemented (phantom App\ class / artisan command a doc claims) → FLAG. The
 *     resolution is a JUDGMENT call — implement it (P4), mark the doc `planned` (P2), or
 *     rename — so the loop must NOT "fix" it by deleting the claim (that would erase a
 *     real plan). It becomes a visible backlog item routed to a human / the Forge arm.
 *     The verifier still serves as the acceptance GATE for whoever closes it.
 *
 * This is why P3 connects P1/P2/P4: it diffs code against docs and emits work that is
 * either auto-closable here or dispatched to the right arm — one scan feeding every point.
 *
 * Pure discovery + task-shaping; it never edits a single file.
 */
final class AtlasP3FindingDispatcher
{
    public const SCHEMA = 'atlas.loop.p3_dispatch.v1';

    public function __construct(
        private readonly AtlasDeadCodeAnalyzer $deadCode,
        private readonly AtlasDocClaimAnalyzer $docClaim,
        private readonly AtlasDocStructureAnalyzer $docStructure,
    ) {}

    /**
     * @param  array{code_roots?:list<string>, docs_roots?:list<string>, max_deadcode?:int, max_files?:int, exclude_substrings?:list<string>}  $options
     * @return array{
     *     schema_version:string,
     *     repo_root:string,
     *     auto_loop:list<array<string,mixed>>,
     *     flags:list<array<string,mixed>>,
     *     summary:array<string,mixed>
     * }
     */
    public function scan(string $repoRoot, array $options = []): array
    {
        $repoRoot = rtrim($repoRoot, '/');
        $codeRoots = $options['code_roots'] ?? ['app'];
        $docsRoots = $options['docs_roots'] ?? ['docs/engineering-knowledge-base'];
        $maxDead = max(1, (int) ($options['max_deadcode'] ?? 50));
        $maxFiles = max(1, (int) ($options['max_files'] ?? 6000));
        $exclude = $options['exclude_substrings'] ?? ['/archive/', '/vendor/', '/node_modules/'];

        $autoLoop = [];
        $flags = [];

        // Authoritative command registry → the doc-claim oracle never false-flags a real
        // command just because its registration style hides it from a static grep.
        try {
            $this->docClaim->useRegisteredCommands(array_keys(app(\Illuminate\Contracts\Console\Kernel::class)->all()));
        } catch (\Throwable) {
            // headless / no kernel — analyzer falls back to its grep index
        }

        // --- DEAD CODE → auto-loop ---
        $deadFilesScanned = 0;
        foreach ($this->phpFiles($repoRoot, $codeRoots, $maxFiles, $exclude) as $abs) {
            $deadFilesScanned++;
            $report = $this->deadCode->analyzeFile($abs);
            if (! $report['parseable'] || $report['dead'] === []) {
                continue;
            }
            $rel = $this->relative($repoRoot, $abs);
            $autoLoop[] = [
                'id' => 'deadcode:'.hash('crc32b', $rel),
                'mode' => 'deadcode',
                'disposition' => 'auto_loop',
                'path' => $rel,
                'dead_members' => $report['dead'],
                'count' => count($report['dead']),
                'detail' => $this->deadDetail($report['dead']),
            ];
        }
        usort($autoLoop, static fn (array $a, array $b): int => $b['count'] <=> $a['count']);
        $autoLoop = array_slice($autoLoop, 0, $maxDead);

        // --- DOCS: structure (auto-loop, safe) + fake-implemented (flag, judgment) in one pass ---
        $docsScanned = 0;
        foreach ($this->mdFiles($repoRoot, $docsRoots, $maxFiles, $exclude) as $abs) {
            $docsScanned++;
            $rel = $this->relative($repoRoot, $abs);

            // doc STRUCTURE → auto-loop, but ONLY for docs that already declare themselves
            // canonical modules and are merely incomplete (adding sections is behavior-free).
            // We never turn a non-module doc into a module — that is an authoring decision.
            $structure = $this->docStructure->analyzeFile($abs);
            if ($structure['is_module'] && $structure['violations'] !== []) {
                $autoLoop[] = [
                    'id' => 'docstruct:'.hash('crc32b', $rel),
                    'mode' => 'docs_structure',
                    'disposition' => 'auto_loop',
                    'path' => $rel,
                    'violations' => $structure['violations'],
                    'count' => count($structure['violations']),
                    'detail' => implode(', ', $structure['violations']),
                ];
            }

            // doc CLAIM (fake-implemented) → flag, never auto-edit.
            $claim = $this->docClaim->analyzeFile($abs, $repoRoot);
            if ($claim['readable'] && $claim['phantoms'] !== []) {
                $flags[] = [
                    'id' => 'phantom:'.hash('crc32b', $rel),
                    'mode' => 'fake_implemented',
                    'disposition' => 'flag',
                    'path' => $rel,
                    'phantoms' => $claim['phantoms'],
                    'count' => count($claim['phantoms']),
                    'route' => $this->routeFor($rel, $claim['phantoms']),
                    'detail' => $this->phantomDetail($claim['phantoms']),
                ];
            }
        }
        usort($flags, static fn (array $a, array $b): int => $b['count'] <=> $a['count']);

        $deadFindings = array_values(array_filter($autoLoop, static fn (array $f): bool => $f['mode'] === 'deadcode'));
        $docStructFindings = array_values(array_filter($autoLoop, static fn (array $f): bool => $f['mode'] === 'docs_structure'));
        $deadMembers = array_sum(array_map(static fn (array $f): int => (int) $f['count'], $deadFindings));
        $phantomCount = array_sum(array_map(static fn (array $f): int => (int) $f['count'], $flags));

        return [
            'schema_version' => self::SCHEMA,
            'repo_root' => $repoRoot,
            'auto_loop' => $autoLoop,
            'flags' => $flags,
            'summary' => [
                'code_files_scanned' => $deadFilesScanned,
                'docs_scanned' => $docsScanned,
                'auto_loop_findings' => count($autoLoop),
                'deadcode_findings' => count($deadFindings),
                'auto_loop_dead_members' => $deadMembers,
                'docs_structure_findings' => count($docStructFindings),
                'flag_findings' => count($flags),
                'flag_phantoms' => $phantomCount,
            ],
        ];
    }

    /**
     * Shape a doc-structure finding into a metric-shaped evolution task: the loop edits the
     * doc until the frozen per-file linter reports zero violations. Content quality is
     * backstopped by propose-only human review (the loop can make a doc structurally
     * compliant; a human certifies the prose is real).
     *
     * @param  array<string,mixed>  $finding
     * @return array{task:array<string,mixed>, cleanup:callable}|null
     */
    public function toDocStructureTask(array $finding, string $repoRoot, ?string $provider = null): ?array
    {
        $rel = (string) ($finding['path'] ?? '');
        $abs = rtrim($repoRoot, '/').'/'.ltrim($rel, '/');
        if ($rel === '' || ! is_file($abs)) {
            return null;
        }
        $content = (string) @file_get_contents($abs);
        if ($content === '') {
            return null;
        }

        $base = sys_get_temp_dir().'/atlas-loop-docstruct-'.bin2hex(random_bytes(5));
        if (! mkdir($base, 0o755, true) && ! is_dir($base)) {
            return null;
        }
        $targetRel = 'target.md';
        file_put_contents($base.'/'.$targetRel, $content);
        $cleanup = static function () use ($base): void {
            if (is_dir($base)) {
                (new \Symfony\Component\Process\Process(['rm', '-rf', $base]))->run();
            }
        };

        $artisan = base_path('artisan');
        $verifier = sprintf('php %s atlas:docs:lint-file --path=%s', escapeshellarg($artisan), $targetRel);
        $violations = implode(', ', (array) ($finding['violations'] ?? []));

        $task = [
            'objective' => sprintf(
                'The canonical-module doc %s is structurally incomplete: %s. Add the missing canonical '.
                'sections with REAL, accurate content describing this module (do not invent capabilities; '.
                'describe what the code actually does), and fix the graph_layer if invalid. Keep the existing '.
                'frontmatter and content. Edit only %s.',
                $rel,
                $violations,
                $targetRel,
            ),
            'base_workspace' => $base,
            'allowed_files' => [$targetRel],
            'validation_commands' => [$verifier],
            'acceptance' => [
                'commands' => [$verifier],
                'allowed_globs' => [$targetRel],
                'frozen_globs' => [],
                'metric_kind' => 'gate',
            ],
            'origin_path' => $rel,
        ];
        if ($provider !== null && $provider !== '') {
            $task['provider'] = $provider;
        }

        return ['task' => $task, 'cleanup' => $cleanup];
    }

    /**
     * Shape a dead-code finding into a metric-shaped evolution task with a freshly
     * prepared, self-contained base_workspace. The acceptance invokes the REPO's frozen
     * verifier against the workspace target (proven pattern), so the candidate can only
     * win by genuinely removing the dead member; the engine's RED baseline + the gate
     * mean a no-op cannot pass.
     *
     * @param  array<string,mixed>  $finding
     * @return array{task:array<string,mixed>, cleanup:callable}|null
     */
    public function toDeadCodeTask(array $finding, string $repoRoot, ?string $provider = null): ?array
    {
        $rel = (string) ($finding['path'] ?? '');
        $abs = rtrim($repoRoot, '/').'/'.ltrim($rel, '/');
        if ($rel === '' || ! is_file($abs)) {
            return null;
        }
        $content = (string) @file_get_contents($abs);
        if ($content === '') {
            return null;
        }

        $base = sys_get_temp_dir().'/atlas-loop-p3-'.bin2hex(random_bytes(5));
        if (! mkdir($base, 0o755, true) && ! is_dir($base)) {
            return null;
        }
        $targetRel = 'target.php';
        file_put_contents($base.'/'.$targetRel, $content);
        $cleanup = static function () use ($base): void {
            if (is_dir($base)) {
                (new \Symfony\Component\Process\Process(['rm', '-rf', $base]))->run();
            }
        };

        $artisan = base_path('artisan');
        $names = implode(', ', array_map(
            static fn (array $d): string => $d['kind'].' '.$d['name'],
            (array) ($finding['dead_members'] ?? []),
        ));
        $verifier = sprintf('php %s atlas:code:deadcode-check --path=%s', escapeshellarg($artisan), $targetRel);

        $task = [
            'objective' => sprintf(
                'The file %s contains UNUSED private members with zero references in their class: %s. '.
                'Remove exactly those dead members and nothing else — do not change behavior, signatures, or any other code. '.
                'Edit only %s.',
                $rel,
                $names,
                $targetRel,
            ),
            'base_workspace' => $base,
            'allowed_files' => [$targetRel],
            'validation_commands' => [$verifier],
            'acceptance' => [
                'commands' => [$verifier],
                'allowed_globs' => [$targetRel],
                'frozen_globs' => [],
                'metric_kind' => 'gate',
            ],
            'origin_path' => $rel,
        ];
        if ($provider !== null && $provider !== '') {
            $task['provider'] = $provider;
        }

        return ['task' => $task, 'cleanup' => $cleanup];
    }

    /**
     * @param  list<array{kind:string,symbol:string,reason:string}>  $phantoms
     */
    private function routeFor(string $rel, array $phantoms): string
    {
        $base = strtolower(basename($rel));
        // Spec/blueprint/proposal docs describe planned work → implement (P4) or mark planned (P2).
        if (preg_match('/(blueprint|spec|contract|proposal|charter|plan|backlog|roadmap)/', $base) === 1) {
            return 'implement_or_mark_planned';
        }

        return 'reconcile_doc_or_implement';
    }

    /**
     * @param  list<array{kind:string,name:string,line:int,class:string}>  $dead
     */
    private function deadDetail(array $dead): string
    {
        return implode('; ', array_map(
            static fn (array $d): string => sprintf('%s %s::%s@%d', $d['kind'], $d['class'], $d['name'], $d['line']),
            $dead,
        ));
    }

    /**
     * @param  list<array{kind:string,symbol:string,reason:string}>  $phantoms
     */
    private function phantomDetail(array $phantoms): string
    {
        return implode('; ', array_map(
            static fn (array $p): string => $p['kind'].' '.$p['symbol'],
            $phantoms,
        ));
    }

    /**
     * @param  list<string>  $roots
     * @param  list<string>  $exclude
     * @return iterable<string>
     */
    private function phpFiles(string $repoRoot, array $roots, int $maxFiles, array $exclude): iterable
    {
        yield from $this->filesByExt($repoRoot, $roots, 'php', $maxFiles, $exclude);
    }

    /**
     * @param  list<string>  $roots
     * @param  list<string>  $exclude
     * @return iterable<string>
     */
    private function mdFiles(string $repoRoot, array $roots, int $maxFiles, array $exclude): iterable
    {
        yield from $this->filesByExt($repoRoot, $roots, 'md', $maxFiles, $exclude);
    }

    /**
     * @param  list<string>  $roots
     * @param  list<string>  $exclude
     * @return iterable<string>
     */
    private function filesByExt(string $repoRoot, array $roots, string $ext, int $maxFiles, array $exclude): iterable
    {
        $count = 0;
        foreach ($roots as $root) {
            $base = $repoRoot.'/'.trim((string) $root, '/');
            if (! is_dir($base)) {
                continue;
            }
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS));
            foreach ($it as $file) {
                if ($count >= $maxFiles) {
                    return;
                }
                if (! ($file instanceof \SplFileInfo) || ! $file->isFile() || $file->getExtension() !== $ext) {
                    continue;
                }
                $path = $file->getPathname();
                foreach ($exclude as $needle) {
                    if ($needle !== '' && str_contains($path, $needle)) {
                        continue 2;
                    }
                }
                $count++;
                yield $path;
            }
        }
    }

    private function relative(string $repoRoot, string $abs): string
    {
        return ltrim(str_replace($repoRoot, '', $abs), '/');
    }
}
