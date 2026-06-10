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
     * Resolve an input that may be EITHER a filesystem path OR an already-stable
     * workspace id, to a workspace id — the "path OR id" contract the gateway surfaces
     * (AOBG context-pack / write-back / workspace-status) document and the operator + the
     * hook both rely on.
     *
     * An existing filesystem path is resolved via {@see resolve()} (path → id). A value
     * that is NOT a path but already LOOKS like a stable id — a single normalized token
     * with no path separators (e.g. 'atlas-server', 'owner-repo', 'pkg-1a2b3c4d', or a
     * 'base::sub' monorepo id) — is passed through normalized verbatim, so feeding back a
     * previously-resolved id scopes to the SAME graph instead of deriving a new (empty)
     * one. Anything else (an unsaved/relative path-shaped string) falls back to {@see
     * resolve()} so its derivation is unchanged. Pure of DB + clock, same as resolve().
     */
    public function resolveWorkspaceOrId(?string $value): string
    {
        if ($value === null || trim($value) === '') {
            return $this->default();
        }
        $value = trim($value);

        // A real existing path is unambiguously a path → resolve path → id.
        if ($this->canonicalPath($value) !== '' && file_exists($value)) {
            return $this->resolve($value);
        }

        // No path separators and id-shaped → treat as an already-stable id (passthrough,
        // normalized). Honour the primary alias so the configured default id round-trips.
        if (! str_contains($value, '/') && ! str_contains($value, '\\') && $this->looksLikeWorkspaceId($value)) {
            $normalized = $this->normalizeWorkspaceId($value);

            return $normalized === $this->default() ? $this->default() : $normalized;
        }

        // Path-shaped but not (yet) existing → keep resolve()'s derivation unchanged.
        return $this->resolve($value);
    }

    /**
     * Whether a separator-free value is shaped like a stable workspace id (one or more
     * normalized tokens, optionally a single 'base::sub' monorepo split). Conservative:
     * only id-charset tokens pass, so a stray free-text string still falls to resolve().
     */
    private function looksLikeWorkspaceId(string $value): bool
    {
        foreach (explode('::', $value, 2) as $segment) {
            $segment = trim($segment);
            if ($segment === '' || preg_match('/^[a-z0-9._-]+$/i', $segment) !== 1) {
                return false;
            }
        }

        return true;
    }

    /**
     * Normalize a 'base::sub' or bare id with the same token rules as the rest of the
     * class (so a passthrough id matches a stored/derived one byte for byte).
     */
    private function normalizeWorkspaceId(string $value): string
    {
        if (str_contains($value, '::')) {
            [$base, $sub] = explode('::', $value, 2);

            return $this->sub($base, $sub);
        }

        return $this->normalize($value);
    }

    /**
     * Whether the path is the primary (running app) workspace.
     */
    public function isPrimaryWorkspace(?string $path): bool
    {
        $canonical = $this->canonicalPath($path);

        return $canonical === '' || $canonical === $this->canonicalPath(base_path());
    }

    /**
     * AP-815 W-7 — a monorepo SUB-workspace id: "<workspace>::<sub-package>".
     *
     * One repo can host N logical workspaces (packages/api, packages/web, services/auth)
     * that key the graph independently while sharing the repo's stable identity. Returns
     * the base id unchanged when the sub-package is empty/degenerate.
     */
    public function sub(string $workspaceId, string $subPackage): string
    {
        $base = $this->normalize($workspaceId !== '' ? $workspaceId : $this->default());
        $sub = $this->normalize($subPackage);

        if ($sub === '' || $sub === 'workspace') {
            return $base;
        }

        return $base.'::'.$sub;
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
