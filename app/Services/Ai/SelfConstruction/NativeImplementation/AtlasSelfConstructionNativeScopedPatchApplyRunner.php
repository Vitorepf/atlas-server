<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\NativeImplementation;

/**
 * Scoped patch apply runner. Writes preflight-ALLOWED proposed file contents to disk under a project
 * root, using ATOMIC writes (temp file + rename), with captured preimage hashes for rollback. NEVER
 * runs git, NEVER spawns a process, NEVER calls a provider.
 *
 * Refuses when:
 *   - preflight.decision != 'allow'
 *   - any output path resolves outside the project root
 *   - any output path is not in allowed_files
 *   - the on-disk file's hash differs from the expected preimage hash (drift)
 *
 * Output:
 *   {schema_version, applied:list<{path, mode, bytes_written}>, refused:bool, blockers:list<string>}
 */
final class AtlasSelfConstructionNativeScopedPatchApplyRunner
{
    public const SCHEMA = 'atlas.native_implementation.scoped_apply.v1';

    public const HASH_ALGO = 'sha256';

    public function __construct(private readonly string $projectRoot)
    {
    }

    /**
     * @param  array<string,mixed>  $preflight                    output of AtlasSelfConstructionNativeImplementationReleasePreflight
     * @param  array<string,mixed>  $proposal                     {allowed_files, files:list<{path, contents, expected_preimage_hash?, mode?}>}
     * @return array<string,mixed>
     */
    public function apply(array $preflight, array $proposal): array
    {
        $blockers = [];

        if ((string) ($preflight['decision'] ?? '') !== 'allow') {
            $blockers[] = 'preflight_not_allow:'.(string) ($preflight['decision'] ?? 'unknown');
        }
        $allowed = array_values((array) ($proposal['allowed_files'] ?? []));
        $files = array_values((array) ($proposal['files'] ?? []));

        if ($blockers !== []) {
            return $this->envelope([], $blockers);
        }

        $applied = [];
        $projectRoot = rtrim($this->projectRoot, '/');

        foreach ($files as $file) {
            if (! is_array($file)) {
                $blockers[] = 'malformed_file_row';

                continue;
            }
            $path = (string) ($file['path'] ?? '');
            $contents = (string) ($file['contents'] ?? '');
            $mode = (string) ($file['mode'] ?? 'create');
            $expectedPreimage = (string) ($file['expected_preimage_hash'] ?? '');

            if ($path === '' || str_contains($path, '..')) {
                $blockers[] = 'path_traversal_or_empty:'.$path;

                continue;
            }
            if (! in_array($path, $allowed, true)) {
                $blockers[] = 'outside_allowed_files:'.$path;

                continue;
            }
            $absolutePath = $projectRoot.'/'.$path;
            if (! str_starts_with($absolutePath, $projectRoot.'/')) {
                $blockers[] = 'outside_project_root:'.$path;

                continue;
            }

            if ($mode === 'modify') {
                if (! is_file($absolutePath)) {
                    $blockers[] = 'modify_target_missing:'.$path;

                    continue;
                }
                $currentHash = hash_file(self::HASH_ALGO, $absolutePath);
                if ($expectedPreimage !== '' && $currentHash !== $expectedPreimage) {
                    $blockers[] = 'preimage_drift:'.$path;

                    continue;
                }
            }

            $dir = \dirname($absolutePath);
            if (! is_dir($dir) && ! @mkdir($dir, 0o755, true) && ! is_dir($dir)) {
                $blockers[] = 'mkdir_failed:'.$path;

                continue;
            }
            // Atomic write — temp file + rename.
            $tempPath = $absolutePath.'.atlas-tmp.'.bin2hex(random_bytes(4));
            $bytes = @file_put_contents($tempPath, $contents, LOCK_EX);
            if ($bytes === false) {
                $blockers[] = 'write_failed:'.$path;

                continue;
            }
            if (! @rename($tempPath, $absolutePath)) {
                @unlink($tempPath);
                $blockers[] = 'rename_failed:'.$path;

                continue;
            }
            $applied[] = ['path' => $path, 'mode' => $mode, 'bytes_written' => (int) $bytes];
        }

        return $this->envelope($applied, $blockers);
    }

    /**
     * @param  list<array<string,mixed>>  $applied
     * @param  list<string>  $blockers
     * @return array<string,mixed>
     */
    private function envelope(array $applied, array $blockers): array
    {
        return [
            'schema_version' => self::SCHEMA,
            'applied' => $applied,
            'refused' => $blockers !== [],
            'blockers' => array_values($blockers),
        ];
    }
}
