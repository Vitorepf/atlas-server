<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution;

use App\Services\Ai\Programming\AtlasDev\Gate\PatchApplier;
use App\Services\Ai\Programming\AtlasDev\Gate\PatchApplyResult;
use App\Services\Ai\Programming\AtlasDev\Provider\DiffParser;
use App\Services\Ai\Programming\AtlasDev\Provider\DiffParseResult;
use PhpParser\ParserFactory;
use Throwable;

/**
 * The missing primitive that makes a TEXT/HTTP provider (e.g. MiniMax M3) agentic inside
 * the evolution loop.
 *
 * A CLI provider (codex/hermes) edits files in the scenario workspace DIRECTLY, so the
 * loop driver sees a non-empty `changed_files` and the frozen judge can score a real diff.
 * An HTTP provider only RETURNS TEXT — its completion describes the change, but nothing
 * applies it, so the workspace stays untouched (`changed_files: []`), every scenario scores
 * `complexity_not_reduced`, and the loop certifies nothing. That single gap blocked the
 * entire "run the loop on the weakest/cheapest engine" thesis: in live MiniMax campaign
 * 019ecd81 every scenario produced diff 0 because the model's change was never applied.
 *
 * This applier closes the gap, accepting TWO delivery formats (it tries them in order):
 *
 *   1. FULL-FILE blocks — `*** ATLAS_FILE: path *** … *** ATLAS_END ***`. The model emits
 *      the COMPLETE new contents of each file; the applier writes them verbatim. This is the
 *      robust primary path for a weak engine: there is no hunk/context matching to get wrong
 *      (a weak model routinely miscounts unified-diff context), so it cannot half-apply.
 *   2. UNIFIED DIFF — reuses the proven Atlas Dev edit path ({@see DiffParser} +
 *      {@see PatchApplier}, with git-apply / `-p0` / `patch` / recount fallbacks) for engines
 *      that prefer (and get right) a real patch.
 *
 * SAFETY: every target path is contained inside the workspace (no absolute paths, no `..`
 * traversal) and, when the caller passes an allowed-files scope, restricted to it — an
 * out-of-scope or escaping path is skipped, never written. The applier NEVER throws and
 * returns a clean "nothing applied" result on any unparseable / out-of-scope / apply-failed
 * outcome, so it is fail-safe to call after every provider invocation. It names no provider.
 */
final class AtlasLoopProviderEditApplier
{
    /** Full-file delivery block: the whole new contents of one file, framed by sentinels. */
    private const FULL_FILE_PATTERN = '/\*\*\*\s*ATLAS_FILE:\s*(?<path>[^\r\n*]+?)\s*\*\*\*\r?\n(?<body>.*?)\r?\n\*\*\*\s*ATLAS_END\s*\*\*\*/s';

    public function __construct(
        private readonly DiffParser $parser = new DiffParser,
        private readonly PatchApplier $applier = new PatchApplier,
    ) {}

    /**
     * Parse a provider's stdout for file edits (full-file blocks or a unified diff) and apply
     * them to the scenario workspace.
     *
     * @param  list<string>  $allowedFiles  optional scope; when non-empty, a write outside it is skipped
     * @return array{applied: bool, changed_files: list<string>, status: string, reason: ?string}
     */
    public function applyFromText(string $providerStdout, string $workspace, int $timeoutSeconds = 60, array $allowedFiles = []): array
    {
        if (trim($providerStdout) === '' || ! is_dir($workspace)) {
            return ['applied' => false, 'changed_files' => [], 'status' => 'no_text', 'reason' => null];
        }

        try {
            $fullFile = $this->applyFullFileBlocks($providerStdout, $workspace, $allowedFiles);
            if ($fullFile !== null) {
                return $fullFile;
            }

            return $this->applyUnifiedDiff($providerStdout, $workspace, $timeoutSeconds);
        } catch (Throwable $e) {
            return ['applied' => false, 'changed_files' => [], 'status' => 'applier_threw', 'reason' => mb_substr($e->getMessage(), 0, 160)];
        }
    }

    /**
     * @param  list<string>  $allowedFiles
     * @return array{applied: bool, changed_files: list<string>, status: string, reason: ?string}|null null when no full-file block is present
     */
    private function applyFullFileBlocks(string $stdout, string $workspace, array $allowedFiles): ?array
    {
        if (preg_match_all(self::FULL_FILE_PATTERN, $stdout, $matches, PREG_SET_ORDER) === false || $matches === []) {
            return null;
        }

        $root = rtrim((string) (realpath($workspace) ?: $workspace), '/');
        $changed = [];
        $rejected = [];
        $parseRejected = false;
        foreach ($matches as $block) {
            $rel = $this->normalizeRelativePath((string) $block['path']);
            if ($rel === null || ! $this->withinScope($rel, $allowedFiles)) {
                $rejected[] = (string) $block['path'];

                continue;
            }
            $target = $root.'/'.$rel;
            if (! $this->isContained($root, $target)) {
                $rejected[] = $rel;

                continue;
            }
            $dir = dirname($target);
            if (! is_dir($dir)) {
                @mkdir($dir, 0o755, true);
            }
            $body = $this->stripTrailingFence((string) $block['body']);
            // ACDE X2 — deterministic parse-gate. A weak engine emitting a full-file rewrite of a large .php
            // file routinely introduces a duplicate method / unbalanced brace; written verbatim it poisons the
            // whole scenario workspace (fatal autoload), so every scenario then scores diff-0 and nothing
            // certifies. When armed, a .php body that does not parse is REJECTED here (never written) — a free,
            // zero-model pre-acceptance oracle. Default OFF => the check is skipped => writes are byte-identical.
            if (str_ends_with($rel, '.php') && (bool) config('atlas.loop.parse_gate_enabled', false) && ! $this->phpBodyParses($body)) {
                $rejected[] = $rel;
                $parseRejected = true;

                continue;
            }
            if (file_put_contents($target, $body) === false) {
                $rejected[] = $rel;

                continue;
            }
            $changed[] = $rel;
        }

        if ($changed === []) {
            $reason = $rejected === [] ? null : ($parseRejected ? 'parse_gate_rejected' : 'out_of_scope_or_unwritable');

            return ['applied' => false, 'changed_files' => [], 'status' => 'full_file_all_rejected', 'reason' => $reason];
        }

        return [
            'applied' => true,
            'changed_files' => array_values(array_unique($changed)),
            'status' => $rejected === [] ? 'applied_full_file' : 'applied_full_file_partial',
            'reason' => null,
        ];
    }

    /**
     * @return array{applied: bool, changed_files: list<string>, status: string, reason: ?string}
     */
    private function applyUnifiedDiff(string $stdout, string $workspace, int $timeoutSeconds): array
    {
        $parsed = $this->parser->parse($stdout);
        if ($parsed->mode !== DiffParseResult::MODE_PATCH) {
            return ['applied' => false, 'changed_files' => [], 'status' => $parsed->mode, 'reason' => $parsed->reason ?? $parsed->question];
        }

        $apply = $this->applier->apply($parsed, $workspace, $timeoutSeconds);
        if ($apply->status !== PatchApplyResult::STATUS_APPLIED) {
            return ['applied' => false, 'changed_files' => [], 'status' => 'apply_'.$apply->status, 'reason' => $apply->reason];
        }

        return ['applied' => true, 'changed_files' => array_values($parsed->changedFiles), 'status' => 'applied_diff', 'reason' => null];
    }

    /**
     * Deterministic syntax oracle (ACDE X2): does this body parse as PHP? Uses the in-process nikic parser
     * (no shell-out, no temp file). Fail-CLOSED — any parser Error or Throwable means not-parseable, so the
     * body is rejected and never written. Only consulted for .php targets when the parse-gate flag is armed.
     */
    private function phpBodyParses(string $body): bool
    {
        try {
            return (new ParserFactory)->createForHostVersion()->parse($body) !== null;
        } catch (Throwable) {
            return false;
        }
    }

    /** A trailing markdown fence (```) left inside a full-file block body is not file content. */
    private function stripTrailingFence(string $body): string
    {
        return (string) preg_replace('/\n?```[a-zA-Z0-9]*\s*$/', '', $body);
    }

    private function normalizeRelativePath(string $path): ?string
    {
        $path = trim($path);
        // Drop a leading a/ or b/ (diff-style) and surrounding quotes/backticks.
        $path = trim($path, "`'\" \t");
        $path = (string) preg_replace('#^(?:a|b)/#', '', $path);
        if ($path === '' || str_starts_with($path, '/') || preg_match('#(^|/)\.\.(/|$)#', $path) === 1) {
            return null;
        }

        return $path;
    }

    /** @param  list<string>  $allowedFiles */
    private function withinScope(string $rel, array $allowedFiles): bool
    {
        if ($allowedFiles === []) {
            return true;
        }
        foreach ($allowedFiles as $allowed) {
            if ($rel === ltrim((string) $allowed, './')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Lexical containment — collapses any `.`/`..` segment WITHOUT touching the filesystem
     * (so a brand-new nested path whose directory does not exist yet is still validated) and
     * confirms the result stays under the workspace root. Defense-in-depth on top of
     * {@see normalizeRelativePath}, which already rejects absolute paths and `..`.
     */
    private function isContained(string $root, string $target): bool
    {
        $parts = [];
        foreach (explode('/', $target) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                array_pop($parts);

                continue;
            }
            $parts[] = $segment;
        }
        $normalized = '/'.implode('/', $parts);

        return $normalized === $root || str_starts_with($normalized.'/', $root.'/');
    }
}
