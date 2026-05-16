<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\AtlasDev\Surface;

/**
 * HTTP-boundary redactor.
 *
 * Atlas Dev keeps absolute filesystem paths inside its DTOs and canonical
 * artifacts because the core needs them to read/write/discover code. None of
 * those absolute paths may leak into an HTTP response (F-04). This class is
 * the single point that converts every internal path into one of three
 * provider-safe forms before it crosses the wire:
 *
 *   1. storage artifact refs — `receipts/<run_id>/<filename>`
 *      Surfaces use these as authorized handles; the backend resolves them
 *      against the real storage base when serving the artifact.
 *   2. workspace-relative paths — `<workspace_label>/<rest>`
 *      Strips the absolute prefix (e.g. `/Users/op/code/`) so the UI shows
 *      `atlas-server/app/Foo.php` instead of leaking `$HOME`.
 *   3. workspace_label — `basename($workspace)`
 *      Replaces the absolute workspace string in summary fields; the
 *      authoritative reference is `workspace_hash` (already provider-safe).
 *
 * Internal callers (storage, discovery, telemetry) keep using absolute paths.
 * This redactor is invoked only by SurfaceResponseFormatter / controllers when
 * building the outgoing JSON body.
 */
final class HttpResponseRedactor
{
    public const ARTIFACT_REF_PREFIX = 'receipts/';

    /**
     * Build a relative artifact ref for a single persisted artifact.
     */
    public function artifactRef(string $runId, string $filename): string
    {
        return self::ARTIFACT_REF_PREFIX.$runId.'/'.basename($filename);
    }

    /**
     * @param  array<string, string>  $absoluteByName  artifact_name => absolute_path
     * @return array<string, string>  artifact_name => `receipts/<run_id>/<basename>`
     */
    public function artifactRefs(string $runId, array $absoluteByName): array
    {
        $refs = [];
        foreach ($absoluteByName as $name => $path) {
            if (! is_string($path) || $path === '') {
                continue;
            }
            $refs[(string) $name] = $this->artifactRef($runId, $path);
        }

        return $refs;
    }

    /**
     * basename($workspace) — empty string when input is empty.
     */
    public function workspaceLabel(string $workspace): string
    {
        $workspace = trim($workspace);
        if ($workspace === '') {
            return '';
        }
        $label = basename($workspace);

        return $label === '' || $label === DIRECTORY_SEPARATOR ? '' : $label;
    }

    /**
     * Walk a payload recursively and rewrite any string that starts with the
     * absolute workspace path so the response never exposes the operator's
     * home directory.
     *
     *   /Users/op/code/atlas-server/app/Foo.php   →   atlas-server/app/Foo.php
     *   /Users/op/code/atlas-server               →   atlas-server
     *
     * Strings that don't begin with the workspace are passed through verbatim.
     * Non-string values (ints, floats, bools, nulls) are passed through too.
     *
     * @param  array<mixed, mixed>  $payload
     * @return array<mixed, mixed>
     */
    public function redactWorkspaceIn(array $payload, string $workspace): array
    {
        $workspace = rtrim(trim($workspace), DIRECTORY_SEPARATOR);
        if ($workspace === '') {
            return $payload;
        }

        $label = $this->workspaceLabel($workspace);

        // Build the set of workspace prefixes that should be redacted. On
        // macOS the symlink form (`/var/folders/...`) and the realpath form
        // (`/private/var/folders/...`) point to the same directory; we must
        // recognise both so embedded strings (e.g. mini_spec.rollback) are
        // sanitised regardless of which form they captured.
        $prefixes = [$workspace];
        $real = @realpath($workspace);
        if (is_string($real) && $real !== '' && $real !== $workspace) {
            $prefixes[] = rtrim($real, DIRECTORY_SEPARATOR);
        }

        return $this->walk($payload, function ($value) use ($prefixes, $label) {
            if (! is_string($value)) {
                return $value;
            }

            foreach ($prefixes as $prefix) {
                if ($value === $prefix) {
                    return $label;
                }
                $prefixWithSlash = $prefix.DIRECTORY_SEPARATOR;
                if (str_starts_with($value, $prefixWithSlash)) {
                    $rest = substr($value, strlen($prefixWithSlash));

                    return $label === '' ? $rest : $label.'/'.$rest;
                }
                // Substring sweep: catch cases where the absolute prefix is
                // embedded mid-string (e.g. `git checkout -- <workspace>/foo`).
                $pos = strpos($value, $prefixWithSlash);
                if ($pos !== false) {
                    $replaceWith = $label === '' ? '' : $label.'/';
                    $value = substr($value, 0, $pos).$replaceWith.substr($value, $pos + strlen($prefixWithSlash));
                }
            }

            return $value;
        });
    }

    /**
     * Walk a payload recursively and rewrite any string that points inside
     * the storage receipts base into the canonical ref form. Strings outside
     * the storage base are passed through.
     *
     *   /var/atlas-dev/receipts/<rid>/foo.json   →   receipts/<rid>/foo.json
     *
     * @param  array<mixed, mixed>  $payload
     * @return array<mixed, mixed>
     */
    public function redactStorageIn(array $payload, string $storageBase): array
    {
        $storageBase = rtrim(trim($storageBase), DIRECTORY_SEPARATOR);
        if ($storageBase === '') {
            return $payload;
        }

        $prefix = $storageBase.DIRECTORY_SEPARATOR;

        return $this->walk($payload, function ($value) use ($prefix) {
            if (! is_string($value) || ! str_starts_with($value, $prefix)) {
                return $value;
            }

            $relative = ltrim(substr($value, strlen($prefix)), DIRECTORY_SEPARATOR);

            // The storage base may already terminate at the parent of
            // `receipts/`, in which case `$relative` already starts with the
            // canonical artifact-ref prefix. Avoid double-prefixing into
            // `receipts/receipts/...`.
            if (str_starts_with($relative, self::ARTIFACT_REF_PREFIX)) {
                return $relative;
            }

            return self::ARTIFACT_REF_PREFIX.$relative;
        });
    }

    /**
     * @param  array<mixed, mixed>  $payload
     * @param  callable(mixed): mixed  $rewriter
     * @return array<mixed, mixed>
     */
    private function walk(array $payload, callable $rewriter): array
    {
        $out = [];
        foreach ($payload as $key => $value) {
            if (is_array($value)) {
                $out[$key] = $this->walk($value, $rewriter);

                continue;
            }
            $out[$key] = $rewriter($value);
        }

        return $out;
    }
}
