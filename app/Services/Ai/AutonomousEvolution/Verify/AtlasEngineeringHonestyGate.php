<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Verify;

use App\Services\Ai\AutonomousEvolution\AtlasEvolutionFrozenJudge;
use App\Services\Ai\AutonomousEvolution\AtlasLoopHermeticCommandEnvironment;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\Parser;
use PhpParser\ParserFactory;
use Symfony\Component\Process\Process;

/**
 * THE ENGINEERING HONESTY GATE — the post-frozen-judge holdout, mirror of the
 * TradingHonestyGate that closed the trading loop's overfitting hole.
 *
 * The per-scenario frozen judge proves "the candidate flips the verifier RED→GREEN and
 * earned the diff". That is necessary but NOT sufficient to surface a proposal honestly:
 * a candidate optimizes against exactly one narrow metric and nothing else. So before a
 * winner becomes a certified-for-review proposal, this gate runs HOLDOUT checks the
 * candidate never saw — the engineering analog of trading's sealed out-of-sample window:
 *
 *   dead-code removal must survive ALL of:
 *     • PARSES        — the edited file is still `php -l` clean;
 *     • RE-PROVED     — re-analyzing the proposed content shows 0 dead members;
 *     • REMOVED-ONLY  — every flagged member is gone AND nothing else was added
 *                       (net deletion; no new public surface, no new declarations);
 *     • SURVIVORS-UNCHANGED — every member that stays is byte-identical (no const value
 *                       gutted, no threshold/boolean/URL flipped under cover of removal);
 *     • REPO-CLEAN    — each removed member has ZERO references anywhere else in the
 *                       repository (defense in depth beyond the private-scope guarantee).
 *
 * Declarations and member bodies are read from the SAME PhpParser AST the verifier trusts
 * (never a regex), so a `/**​/` comment splice cannot hide an added/removed/mutated member.
 *
 * Like the trading gate, a FAILED holdout is the system working — the honest verdict is
 * "not certified, here is exactly why", never a number to paper over. Win conditions are
 * deterministic and visible; an optional adversarial re-proof hook can layer LLM skeptics
 * on top for higher-stakes modes, but the deterministic holdout already makes a wrongful
 * dead-code removal practically impossible to certify.
 */
final class AtlasEngineeringHonestyGate
{
    public const SCHEMA = 'atlas.loop.engineering_honesty_verdict.v1';

    private readonly Parser $parser;

    private readonly NodeFinder $finder;

    public function __construct(private readonly AtlasDeadCodeAnalyzer $deadCode)
    {
        // The gate must reason about declarations with the SAME AST the verifier trusts —
        // a regex view is comment/whitespace-fragile and an adversarial editor can split any
        // two tokens with /**/ to hide an added/removed member. Parse, never grep.
        $this->parser = (new ParserFactory)->createForHostVersion();
        $this->finder = new NodeFinder;
    }

    /**
     * Holdout-evaluate a dead-code removal proposal.
     *
     * @param  list<array{kind:string,name:string,line:int,class:string}>  $deadMembers  the members the task asked to remove
     * @return array{certified:bool, reasons:list<string>, report:array<string,mixed>}
     */
    public function evaluateDeadCodeRemoval(
        string $repoRoot,
        string $originRelPath,
        string $originalContent,
        string $proposedContent,
        array $deadMembers,
    ): array {
        $reasons = [];

        // 0 — the change must be a real, net reduction (a no-op or growth is not a removal).
        if ($proposedContent === $originalContent) {
            $reasons[] = 'no_change';
        } elseif (strlen($proposedContent) >= strlen($originalContent)) {
            $reasons[] = 'not_a_net_reduction';
        }

        // 0b — FLAGGED-ACTUALLY-DEAD: NEVER trust the caller's $deadMembers list. Re-derive
        // deadness from the FRESH original at gate time and require every flagged member to be
        // genuinely dead. A member referenced by ANY surviving method (public OR private) is not
        // dead — removing it dangles a caller. This closes the stale-finding / TOCTOU / wrong-
        // source path: the analyzer already counts a public caller's reference, so a still-used
        // member never appears in its dead[] set.
        $origAnalysis = $this->analyzeContent($originalContent);
        $actuallyDead = $origAnalysis === null
            ? null
            : array_map(static fn (array $d): string => $d['kind'].':'.$d['name'], $origAnalysis['dead']);
        foreach ($deadMembers as $m) {
            $key = $m['kind'].':'.$m['name'];
            if ($actuallyDead === null || ! in_array($key, $actuallyDead, true)) {
                $reasons[] = 'flagged_member_not_actually_dead('.$m['name'].')';
            }
        }

        // 1 — PARSES: proposed file is still syntactically valid PHP.
        if (! $this->phpLintClean($proposedContent)) {
            $reasons[] = 'proposed_not_lint_clean';
        }

        // 2 — RE-PROVED: re-analyze the proposed content; the metric must genuinely be 0.
        $reanalyzed = $this->analyzeContent($proposedContent);
        if ($reanalyzed === null) {
            $reasons[] = 'proposed_unparseable_for_reanalysis';
        } elseif ($reanalyzed['dead'] !== []) {
            $reasons[] = 'still_has_dead_members_after_change('.count($reanalyzed['dead']).')';
        }

        // 3 — REMOVED-ONLY: every flagged member is gone, and nothing new was introduced.
        $origDecls = $this->declarations($originalContent);
        $propDecls = $this->declarations($proposedContent);
        foreach ($deadMembers as $m) {
            $key = $m['kind'].':'.$m['name'];
            if (in_array($key, $propDecls, true)) {
                $reasons[] = 'flagged_member_still_declared('.$m['name'].')';
            }
        }
        $added = array_values(array_diff($propDecls, $origDecls));
        if ($added !== []) {
            $reasons[] = 'introduced_new_declarations('.implode(',', array_slice($added, 0, 5)).')';
        }
        $removed = array_values(array_diff($origDecls, $propDecls));
        $flaggedKeys = array_map(static fn (array $m): string => $m['kind'].':'.$m['name'], $deadMembers);
        $unexpectedRemovals = array_values(array_diff($removed, $flaggedKeys));
        if ($unexpectedRemovals !== []) {
            $reasons[] = 'removed_unflagged_members('.implode(',', array_slice($unexpectedRemovals, 0, 5)).')';
        }

        // 3b — SURVIVORS-UNCHANGED: a dead-code removal may ONLY delete the flagged members.
        // Every member present in BOTH original and proposed must be byte-identical — no const
        // value gutting, no threshold/boolean/URL flip, no method-body edit smuggled under cover
        // of the removal. (Names alone are not enough; bodies must match.)
        $origSrc = $this->memberSources($originalContent);
        $propSrc = $this->memberSources($proposedContent);
        $mutated = [];
        foreach ($propSrc as $key => $txt) {
            if (isset($origSrc[$key]) && $origSrc[$key] !== $txt) {
                $mutated[] = $key;
            }
        }
        if ($mutated !== []) {
            $reasons[] = 'mutated_surviving_member('.implode(',', array_slice($mutated, 0, 5)).')';
        }

        // 3c — PURE-DELETION: the catch-all the member-level checks cannot see. A dead-code
        // removal must ONLY delete lines — every non-blank line of the proposed file must appear,
        // in order, in the original. This rejects ANY added content, including top-level code
        // injected OUTSIDE a class member (e.g. an `eval()` or a global side effect) that the
        // member-scoped checks above would miss. Honest removals are a strict subsequence; only
        // an addition or an edit to surviving content breaks it.
        if (! $this->isPureDeletion($originalContent, $proposedContent)) {
            $reasons[] = 'not_a_pure_deletion';
        }

        // 3d — NO-DANGLING-REFERENCE (post-state): after removal, NO surviving code may still
        // reference a removed member. A surviving PUBLIC caller is invisible to the private-only
        // re-proof (3) — this catches it directly by scanning the proposed AST for any call/fetch
        // of each removed member's name.
        $dangling = [];
        foreach ($deadMembers as $m) {
            if ($this->referencesMember($proposedContent, (string) $m['kind'], (string) $m['name'])) {
                $dangling[] = $m['name'];
            }
        }
        if ($dangling !== []) {
            $reasons[] = 'removed_member_still_referenced_in_file('.implode(',', array_slice($dangling, 0, 5)).')';
        }

        // 4 — REPO-CLEAN: a NON-private removed member must be referenced nowhere else in the
        // repo. A PRIVATE member is class-scoped — a cross-file name match is a COLLISION with an
        // unrelated class's member, never a real reference — so grepping the bare name would
        // false-reject a safe removal (e.g. a common field name). For private members the
        // in-file checks above (re-derived deadness + dangling-reference) are already complete
        // and sound; only non-private members need the repo-wide grep (defensive — the analyzer
        // only ever flags private, so this is a backstop for non-analyzer finding sources).
        $visibility = $this->memberVisibility($originalContent);
        $stillReferenced = [];
        foreach ($deadMembers as $m) {
            $vis = $visibility[$m['kind'].':'.$m['name']] ?? 'private';
            if ($vis !== 'private' && $this->referencedElsewhere($repoRoot, $originRelPath, $m['name'])) {
                $stillReferenced[] = $m['name'];
            }
        }
        if ($stillReferenced !== []) {
            $reasons[] = 'member_referenced_elsewhere_in_repo('.implode(',', $stillReferenced).')';
        }

        $certified = $reasons === [];

        return [
            'certified' => $certified,
            'reasons' => $certified ? ['certified'] : $reasons,
            'report' => [
                'schema_version' => self::SCHEMA,
                'mode' => 'deadcode',
                'origin' => $originRelPath,
                'members_targeted' => count($deadMembers),
                'bytes_removed' => max(0, strlen($originalContent) - strlen($proposedContent)),
                'declarations_added' => count($added),
                'unexpected_removals' => count($unexpectedRemovals),
                'mutated_survivors' => count($mutated),
                'repo_references_remaining' => count($stillReferenced),
                'holdouts' => [
                    'parses' => ! in_array('proposed_not_lint_clean', $reasons, true),
                    're_proved_zero_dead' => $reanalyzed !== null && $reanalyzed['dead'] === [],
                    'removed_only' => $added === [] && $unexpectedRemovals === [],
                    'survivors_unchanged' => $mutated === [],
                    'pure_deletion' => ! in_array('not_a_pure_deletion', $reasons, true),
                    'repo_clean' => $stillReferenced === [],
                ],
            ],
        ];
    }

    /**
     * Holdout-evaluate a documentation edit. The candidate optimized only its narrow
     * per-file verifier; the holdout is that the edit stayed inside the doc and the file
     * is still a non-empty, well-formed markdown doc (the global docs-health ratchet is
     * the campaign-level monotonic holdout layered above this).
     *
     * @return array{certified:bool, reasons:list<string>, report:array<string,mixed>}
     */
    public function evaluateDocEdit(string $originRelPath, string $originalContent, string $proposedContent): array
    {
        $reasons = [];
        if ($proposedContent === $originalContent) {
            $reasons[] = 'no_change';
        }
        if (trim($proposedContent) === '') {
            $reasons[] = 'doc_emptied';
        }
        // A doc edit must not nuke the canonical frontmatter to "win" a structural check.
        $hadFrontmatter = preg_match('/^---\r?\n/', $originalContent) === 1;
        $keepsFrontmatter = preg_match('/^---\r?\n/', $proposedContent) === 1;
        if ($hadFrontmatter && ! $keepsFrontmatter) {
            $reasons[] = 'dropped_frontmatter';
        }
        // Guard against degenerate shrink-to-pass: losing >40% of the body is suspicious.
        if (strlen($originalContent) > 0 && strlen($proposedContent) < (int) (strlen($originalContent) * 0.6)) {
            $reasons[] = 'suspicious_content_loss';
        }

        $certified = $reasons === [];

        return [
            'certified' => $certified,
            'reasons' => $certified ? ['certified'] : $reasons,
            'report' => [
                'schema_version' => self::SCHEMA,
                'mode' => 'docs',
                'origin' => $originRelPath,
                'bytes_delta' => strlen($proposedContent) - strlen($originalContent),
                'keeps_frontmatter' => $keepsFrontmatter,
            ],
        ];
    }

    /**
     * Holdout-evaluate a small implementation proposal.
     *
     * This is intentionally NOT the dead-code gate: implementation may add or mutate code.
     * The P4-small contract is: a git workspace with a real diff, target frozen acceptance
     * GREEN, revert-recheck RED, scope/frozen paths clean, and the sealed broader suite GREEN.
     *
     * @param  array<string,mixed>  $targetAcceptance
     * @param  list<string>  $sealedHoldoutCommands
     * @return array{certified:bool, reasons:list<string>, report:array<string,mixed>}
     */
    public function evaluateImplementation(string $workspace, array $targetAcceptance, array $sealedHoldoutCommands = []): array
    {
        $reasons = [];
        $changedFiles = [];
        $targetVerdict = null;
        $sealedResults = [];
        $sealedPassed = true;

        if (! is_dir($workspace)) {
            $reasons[] = 'workspace_missing';
        } elseif (! $this->isGitWorkspace($workspace)) {
            $reasons[] = 'workspace_not_git';
        } else {
            $changedFiles = $this->workspaceChangedFiles($workspace);
            if ($changedFiles === []) {
                $reasons[] = 'no_change';
            }

            $acceptance = $targetAcceptance;
            // DIFF-EARNED (anti-fake for NEW behavior): force the revert-recheck so a passing diff
            // must EARN its green (reverting it turns the target test RED). EXCEPTION — a
            // behavior-PRESERVING refactor (complexity_proof=true AND metric_kind=minimize) stays
            // GREEN with the diff reverted by design, so diff-earned does NOT apply; its anti-fake
            // proof is the REAL AST complexity DROP enforced by the semantic certifier instead. This
            // is NOT a weakening: the refactor still must keep the frozen test GREEN (behavior
            // preserved, re-run by the judge here), pass scope/tamper guards, pass sealed holdouts,
            // AND prove a measured complexity drop downstream. Only the inapplicable diff-earned
            // check is skipped for refactors; the (default) new-behavior path is untouched.
            $isRefactor = (bool) ($targetAcceptance['complexity_proof'] ?? false)
                && (string) ($targetAcceptance['metric_kind'] ?? '') === AtlasEvolutionFrozenJudge::METRIC_MINIMIZE;
            if (! $isRefactor) {
                $acceptance['revert_recheck'] = true;
            }
            $targetVerdict = (new AtlasEvolutionFrozenJudge)->score($workspace, $acceptance);
            if (! (bool) ($targetVerdict['passed'] ?? false)) {
                $reason = (string) ($targetVerdict['details']['reason'] ?? 'unknown');
                $reasons[] = 'target_acceptance_failed('.$reason.')';
            }

            $timeout = max(1, (int) ($targetAcceptance['timeout_seconds'] ?? 600));
            foreach ($this->listStrings($sealedHoldoutCommands) as $i => $command) {
                $result = $this->runHoldoutCommand($command, $workspace, $timeout);
                $sealedResults[] = $result;
                if (! $result['passed']) {
                    $sealedPassed = false;
                    $reasons[] = 'sealed_holdout_failed('.($i + 1).')';
                    break;
                }
            }
        }

        $certified = $reasons === [];

        return [
            'certified' => $certified,
            'reasons' => $certified ? ['certified'] : $reasons,
            'report' => [
                'schema_version' => self::SCHEMA,
                'mode' => 'implementation',
                'workspace' => $workspace,
                'changed_files' => $changedFiles,
                'changed_file_count' => count($changedFiles),
                'target_acceptance' => $targetVerdict,
                'sealed_holdout_results' => $sealedResults,
                'holdouts' => [
                    'workspace_git' => is_dir($workspace) && $this->isGitWorkspace($workspace),
                    'has_diff' => $changedFiles !== [],
                    'target_frozen_passed' => (bool) ($targetVerdict['passed'] ?? false),
                    'diff_earned' => (($targetVerdict['details']['diff_earned'] ?? null) === true),
                    'scope_clean' => (($targetVerdict['details']['reason'] ?? null) !== 'out_of_scope_change'),
                    'frozen_untampered' => (($targetVerdict['details']['reason'] ?? null) !== 'frozen_path_tampered'),
                    'sealed_holdout_passed' => $sealedPassed,
                    'merged_to_main' => false,
                ],
            ],
        ];
    }

    /**
     * @return array{schema_version:string,path:string,parseable:bool,dead:list<array{kind:string,name:string,line:int,class:string}>,note?:string}|null
     */
    private function analyzeContent(string $content): ?array
    {
        $tmp = tempnam(sys_get_temp_dir(), 'atlas-honesty-');
        if ($tmp === false) {
            return null;
        }
        file_put_contents($tmp, $content);
        try {
            return $this->deadCode->analyzeFile($tmp);
        } finally {
            @unlink($tmp);
        }
    }

    /**
     * Declared method/const/property names (kind:name), from the AST — the SAME
     * representation the verifier uses, so a `/**​/` comment splice or whitespace trick
     * can never hide an added or removed member from the removed-only / flagged-member
     * holdouts. Fails CLOSED: an unparseable proposal yields a never-matching sentinel
     * (forcing a diff), but it is also rejected upstream by the lint/re-proof holdouts.
     *
     * @return list<string>
     */
    private function declarations(string $code): array
    {
        return array_keys($this->memberAst($code, false));
    }

    /**
     * True iff $proposed is obtainable from $original by DELETIONS only — every non-blank,
     * right-trimmed line of $proposed occurs, in order, in $original. Blank-line reflow is
     * tolerated; any added or edited non-blank line breaks the subsequence. This is the
     * complete "only the dead members were removed, nothing was added anywhere" invariant.
     */
    private function isPureDeletion(string $original, string $proposed): bool
    {
        $lines = static function (string $code): array {
            $out = [];
            foreach (explode("\n", $code) as $line) {
                $line = rtrim($line);
                if ($line !== '') {
                    $out[] = $line;
                }
            }

            return $out;
        };
        $orig = $lines($original);
        $prop = $lines($proposed);

        $i = 0;
        $n = count($orig);
        foreach ($prop as $line) {
            while ($i < $n && $orig[$i] !== $line) {
                $i++;
            }
            if ($i >= $n) {
                return false; // a proposed line is not present in the remaining original → an addition/edit
            }
            $i++;
        }

        return true;
    }

    /**
     * Exact source slice per declared member (kind:name => verbatim code), from the AST.
     * Used to prove every SURVIVING member is byte-identical — only the flagged dead
     * members may disappear; no surviving const value, method body, or property default
     * may be mutated under cover of a dead-code removal.
     *
     * @return array<string,string>
     */
    private function memberSources(string $code): array
    {
        return $this->memberAst($code, true);
    }

    /**
     * Does $code contain any reference to the given member (call/fetch by name, or a string
     * literal equal to a method name for callable arrays)? Used to prove a removed member is
     * not still called by surviving code. Fails CLOSED on a parse error (treats as referenced).
     */
    private function referencesMember(string $code, string $kind, string $name): bool
    {
        try {
            $stmts = $this->parser->parse($code);
        } catch (\Throwable) {
            return true; // cannot prove safe → assume referenced (fail closed)
        }
        if ($stmts === null) {
            return true;
        }
        $lname = strtolower($name);

        $hit = $this->finder->findFirst($stmts, function (Node $n) use ($kind, $name, $lname): bool {
            if ($kind === 'method') {
                if (($n instanceof Node\Expr\MethodCall || $n instanceof Node\Expr\StaticCall || $n instanceof Node\Expr\NullsafeMethodCall)
                    && $n->name instanceof Node\Identifier && strtolower($n->name->toString()) === $lname) {
                    return true;
                }
                if ($n instanceof Node\Scalar\String_ && strtolower($n->value) === $lname) {
                    return true; // [$this, 'name'] / first-class callable string
                }

                return false;
            }
            if ($kind === 'const') {
                return $n instanceof Node\Expr\ClassConstFetch
                    && $n->name instanceof Node\Identifier && $n->name->toString() === $name;
            }
            // property
            return ($n instanceof Node\Expr\PropertyFetch || $n instanceof Node\Expr\StaticPropertyFetch || $n instanceof Node\Expr\NullsafePropertyFetch)
                && ! ($n->name instanceof Node\Expr) && $n->name->toString() === $name;
        });

        return $hit !== null;
    }

    /**
     * Visibility of each declared member (kind:name => 'private'|'protected'|'public'), from
     * the AST. Used so REPO-CLEAN only greps non-private members (a private member cannot be
     * referenced cross-file, so a name match elsewhere is a collision, not a reference).
     *
     * @return array<string,string>
     */
    private function memberVisibility(string $code): array
    {
        try {
            $stmts = $this->parser->parse($code);
        } catch (\Throwable) {
            $stmts = null;
        }
        if ($stmts === null) {
            return [];
        }
        $vis = static fn (Node\Stmt\ClassMethod|Node\Stmt\Property|Node\Stmt\ClassConst $n): string => $n->isPrivate() ? 'private' : ($n->isProtected() ? 'protected' : 'public');

        $out = [];
        /** @var list<Node\Stmt\ClassLike> $classes */
        $classes = $this->finder->findInstanceOf($stmts, Node\Stmt\ClassLike::class);
        foreach ($classes as $class) {
            foreach ($class->getMethods() as $m) {
                $out['method:'.$m->name->toString()] = $vis($m);
            }
            foreach ($class->stmts as $stmt) {
                if ($stmt instanceof Node\Stmt\ClassConst) {
                    foreach ($stmt->consts as $c) {
                        $out['const:'.$c->name->toString()] = $vis($stmt);
                    }
                }
                if ($stmt instanceof Node\Stmt\Property) {
                    foreach ($stmt->props as $p) {
                        $out['property:'.$p->name->toString()] = $vis($stmt);
                    }
                }
            }
        }

        return $out;
    }

    /**
     * @return array<string,string|true>  kind:name => source slice (when $withSource) or true
     */
    private function memberAst(string $code, bool $withSource): array
    {
        try {
            $stmts = $this->parser->parse($code);
        } catch (\Throwable) {
            $stmts = null;
        }
        if ($stmts === null) {
            // never-matching sentinel: forces a declarations diff / empty source map so a
            // change can never be certified on an unparseable proposal.
            return ['__unparseable__' => $withSource ? '__unparseable__' : true];
        }

        $out = [];
        $slice = static function (Node $n) use ($code): string {
            $start = $n->getStartFilePos();
            $end = $n->getEndFilePos();

            return ($start >= 0 && $end >= $start) ? substr($code, $start, $end - $start + 1) : '';
        };

        /** @var list<Node\Stmt\ClassLike> $classes */
        $classes = $this->finder->findInstanceOf($stmts, Node\Stmt\ClassLike::class);
        foreach ($classes as $class) {
            foreach ($class->getMethods() as $m) {
                $out['method:'.$m->name->toString()] = $withSource ? $slice($m) : true;
            }
            foreach ($class->stmts as $stmt) {
                if ($stmt instanceof Node\Stmt\ClassConst) {
                    foreach ($stmt->consts as $c) {
                        $out['const:'.$c->name->toString()] = $withSource ? $slice($c) : true;
                    }
                }
                if ($stmt instanceof Node\Stmt\Property) {
                    foreach ($stmt->props as $p) {
                        $out['property:'.$p->name->toString()] = $withSource ? $slice($p) : true;
                    }
                }
            }
        }

        return $out;
    }

    private function referencedElsewhere(string $repoRoot, string $originRelPath, string $member): bool
    {
        // grep the repo for the member token; any hit OUTSIDE the origin file means it is
        // not safe to remove. `-l` lists matching FILE PATHS only (not every matching line) —
        // a common member name can match millions of lines, and reading that output OOMs the
        // long-running loop process. File-list output is bounded to ~one line per file.
        $process = new Process([
            'grep', '-rlw', '--include=*.php', $member, $repoRoot.'/app',
        ], null, null, null, 60.0);
        $process->run();
        $out = (string) $process->getOutput();
        if (trim($out) === '') {
            return false;
        }
        foreach (preg_split('/\R/', trim($out)) ?: [] as $path) {
            $rel = ltrim(str_replace($repoRoot, '', trim($path)), '/');
            if ($rel !== '' && $rel !== $originRelPath) {
                return true; // referenced in some other file
            }
        }

        return false;
    }

    private function phpLintClean(string $content): bool
    {
        $tmp = tempnam(sys_get_temp_dir(), 'atlas-lint-');
        if ($tmp === false) {
            return false;
        }
        file_put_contents($tmp, $content);
        try {
            $process = new Process([PHP_BINARY, '-l', $tmp], null, null, null, 20.0);
            $process->run();

            return $process->isSuccessful();
        } finally {
            @unlink($tmp);
        }
    }

    private function isGitWorkspace(string $workspace): bool
    {
        $process = new Process(['git', 'rev-parse', '--is-inside-work-tree'], $workspace, null, null, 20.0);
        $process->run();

        return $process->isSuccessful() && trim((string) $process->getOutput()) === 'true';
    }

    /**
     * @return list<string>
     */
    private function workspaceChangedFiles(string $workspace): array
    {
        $files = [];
        foreach ([
            ['git', 'diff', '--name-only', '--no-ext-diff'],
            ['git', 'ls-files', '--others', '--exclude-standard'],
        ] as $argv) {
            $process = new Process($argv, $workspace, null, null, 30.0);
            $process->run();
            foreach (preg_split('/\R/', trim((string) $process->getOutput())) ?: [] as $line) {
                $line = trim($line);
                if ($line !== '') {
                    $files[$line] = true;
                }
            }
        }

        return array_keys($files);
    }

    /**
     * @return array{command:string,passed:bool,exit_code:int,stdout:string,stderr:string}
     */
    private function runHoldoutCommand(string $command, string $workspace, int $timeout): array
    {
        $process = Process::fromShellCommandline(
            $command,
            $workspace,
            AtlasLoopHermeticCommandEnvironment::forAcceptance(),
            null,
            (float) $timeout,
        );
        $process->run();
        $exit = $process->getExitCode() ?? 1;

        return [
            'command' => $command,
            'passed' => $exit === 0,
            'exit_code' => $exit,
            'stdout' => $this->excerpt((string) $process->getOutput()),
            'stderr' => $this->excerpt((string) $process->getErrorOutput()),
        ];
    }

    private function excerpt(string $value, int $max = 4000): string
    {
        return strlen($value) <= $max ? $value : substr($value, 0, $max).'…';
    }

    /**
     * @param  list<string>  $values
     * @return list<string>
     */
    private function listStrings(array $values): array
    {
        return array_values(array_filter(array_map(
            static fn (mixed $v): string => is_string($v) ? trim($v) : '',
            $values,
        ), static fn (string $v): bool => $v !== ''));
    }
}
