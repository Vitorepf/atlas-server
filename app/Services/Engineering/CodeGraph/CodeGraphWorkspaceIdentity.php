<?php

declare(strict_types=1);

namespace App\Services\Engineering\CodeGraph;

/**
 * AP-815 · W-1 / W-7 — Stable workspace identity for the code graph.
 *
 * Resolves a workspace path to a STABLE `workspace_id` used to key the
 * code-intelligence read-model (so a second project never collides with the primary
 * atlas-server graph). Identity rules, in order:
 *
 *   1. The primary workspace (the running app, base_path()) is ALWAYS the configured
 *      default ('atlas-server') — path-independent across machines.
 *   2. Any other path → its git-remote slug ("owner-repo", machine-independent and
 *      stable across clones) when a remote is readable.
 *   3. Fallback → directory basename + a short path hash (deterministic, collision-safe).
 *
 * Pure of DB + clock. The only side effect is a best-effort read of `<path>/.git/config`
 * (cached per path). This is [php] by the runtime-language boundary: identity/orchestration,
 * not heavy data.
 */
class CodeGraphWorkspaceIdentity
{
    /** @var array<string,string> */
    private array $cache = [];

    /**
     * The configured primary workspace id (default 'atlas-server').
     */
    public function default(): string
    {
        $configured = config('atlas.code_graph.default_workspace_id', 'atlas-server');

        return is_string($configured) && trim($configured) !== ''
            ? $this->normalize($configured)
            : 'atlas-server';
    }

    /**
     * Resolve a workspace path to its stable workspace_id.
     */
    public function resolve(?string $path = null): string
    {
        $canonical = $this->canonicalPath($path);

        if ($canonical === '' || $this->isPrimaryWorkspace($canonical)) {
            return $this->default();
        }

        return $this->cache[$canonical] ??= $this->derive($canonical);
    }

    /**
     * Whether the path is the primary (running app) workspace.
     */
    public function isPrimaryWorkspace(?string $path): bool
    {
        $canonical = $this->canonicalPath($path);

        return $canonical === '' || $canonical === $this->canonicalPath(base_path());
    }

    private function derive(string $path): string
    {
        $remote = $this->gitRemoteSlug($path);
        if ($remote !== null && $remote !== '') {
            return $remote;
        }

        $base = basename($path);
        $base = $this->normalize($base !== '' ? $base : 'workspace');
        $hash = substr(hash('sha256', $path), 0, 8);

        return "{$base}-{$hash}";
    }

    private function gitRemoteSlug(string $path): ?string
    {
        $config = $path.'/.git/config';
        if (! is_file($config) || ! is_readable($config)) {
            return null;
        }

        $contents = (string) @file_get_contents($config);
        if ($contents === '' || preg_match('#url\s*=\s*(.+)#', $contents, $m) !== 1) {
            return null;
        }

        $url = trim($m[1]);
        // git@host:owner/repo(.git)  OR  https://host/owner/repo(.git)
        if (preg_match('#[:/]([^/:]+)/([^/]+?)(?:\.git)?$#', $url, $mm) === 1) {
            return $this->normalize($mm[1].'-'.$mm[2]);
        }

        return null;
    }

    private function canonicalPath(?string $path): string
    {
        if ($path === null || trim($path) === '') {
            return '';
        }

        $real = realpath($path);

        return rtrim($real !== false ? $real : $path, '/');
    }

    private function normalize(string $value): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9._-]+/', '-', $value) ?? $value;
        $value = trim($value, '-_.');

        return $value !== '' ? $value : 'workspace';
    }
}
