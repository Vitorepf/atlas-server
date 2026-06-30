<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\NativeImplementation;

/**
 * Native rollback runner. Restores the preimage contents of allowed_files when post-apply
 * verification asks for rollback. NEVER calls git, NEVER spawns a process.
 *
 * Receipt row contract:
 *   { path: string, mode: 'create'|'modify',
 *     preimage_contents?: string | null,  // null/absent when mode=create
 *     preimage_hash?: string,             // hash of preimage_contents OR empty when mode=create
 *     post_hash: string                   // hash of file AFTER apply (sanity check)
 *   }
 *
 * Refuses when:
 *   - the current on-disk file hash doesn't match the receipt's post_hash (drift since apply)
 *   - the receipt path is not in allowed_files
 *
 * Output:
 *   { schema_version, restored:list<{path,mode,bytes_restored}>, removed:list<string>,
 *     refused:bool, blockers:list<string> }
 */
final class AtlasSelfConstructionNativePatchRollbackRunner
{
    public const SCHEMA = 'atlas.native_implementation.rollback.v1';
    public const HASH_ALGO = 'sha256';

    public function __construct(private readonly string $projectRoot) {}

    /**
     * @param  array<string,mixed>  $facts {allowed_files:list<string>, receipts:list<array<string,mixed>>}
     * @return array<string,mixed>
     */
    public function rollback(array $facts): array
    {
        $allowed = array_values(array_map('strval', (array) ($facts['allowed_files'] ?? [])));
        $receipts = array_values((array) ($facts['receipts'] ?? []));

        $root = rtrim($this->projectRoot, '/');
        $restored = [];
        $removed = [];
        $blockers = [];

        // Pre-flight: structural checks before any disk access.
        $seenPaths = [];
        foreach ($receipts as $r) {
            if (! is_array($r)) {
                continue;
            }
            $rPath = (string) ($r['path'] ?? '');
            $rMode = (string) ($r['mode'] ?? '');
            if ($rPath === '' || str_contains($rPath, '..') || ! in_array($rPath, $allowed, true)) {
                continue; // caught in main loop
            }
            if (isset($seenPaths[$rPath])) {
                $blockers[] = 'duplicate_receipt_path:'.$rPath;
            }
            $seenPaths[$rPath] = true;
            if (! in_array($rMode, ['create', 'modify'], true)) {
                $blockers[] = 'unknown_mode:'.$rMode;
            }
            if ($rMode === 'modify' && (string) ($r['preimage_hash'] ?? '') === '') {
                $blockers[] = 'modify_missing_preimage_hash:'.$rPath;
            }
        }
        if ($blockers !== []) {
            return ['schema_version' => self::SCHEMA, 'restored' => [], 'removed' => [], 'refused' => true, 'blockers' => array_values($blockers)];
        }

        foreach ($receipts as $r) {
            if (! is_array($r)) {
                $blockers[] = 'malformed_receipt_row';

                continue;
            }
            $path = (string) ($r['path'] ?? '');
            $mode = (string) ($r['mode'] ?? '');
            $expectedPost = (string) ($r['post_hash'] ?? '');

            if ($path === '' || str_contains($path, '..')) {
                $blockers[] = 'path_traversal_or_empty:'.$path;

                continue;
            }
            if (! in_array($path, $allowed, true)) {
                $blockers[] = 'outside_allowed_files:'.$path;

                continue;
            }

            $abs = $root.'/'.$path;
            $currentHash = is_file($abs) ? hash_file(self::HASH_ALGO, $abs) : '';
            if ($expectedPost !== '' && $currentHash !== $expectedPost) {
                $blockers[] = 'post_hash_mismatch:'.$path;

                continue;
            }

            if ($mode === 'create') {
                if (is_file($abs)) {
                    if (! @unlink($abs)) {
                        $blockers[] = 'unlink_failed:'.$path;

                        continue;
                    }
                }
                $removed[] = $path;

                continue;
            }

            // mode === 'modify' (also handles deleted-file restoration when preimage_contents is provided)
            $preContents = array_key_exists('preimage_contents', $r) ? (string) $r['preimage_contents'] : '';
            $preHash = (string) ($r['preimage_hash'] ?? '');
            if ($preHash !== '' && hash(self::HASH_ALGO, $preContents) !== $preHash) {
                $blockers[] = 'preimage_hash_mismatch:'.$path;

                continue;
            }
            $dir = \dirname($abs);
            if (! is_dir($dir) && ! @mkdir($dir, 0o755, true) && ! is_dir($dir)) {
                $blockers[] = 'mkdir_failed:'.$path;

                continue;
            }
            $tmp = $abs.'.atlas-rollback.'.bin2hex(random_bytes(4));
            $bytes = @file_put_contents($tmp, $preContents, LOCK_EX);
            if ($bytes === false) {
                $blockers[] = 'write_failed:'.$path;

                continue;
            }
            if (! @rename($tmp, $abs)) {
                @unlink($tmp);
                $blockers[] = 'rename_failed:'.$path;

                continue;
            }
            $restored[] = ['path' => $path, 'mode' => $mode, 'bytes_restored' => (int) $bytes];
        }

        return [
            'schema_version' => self::SCHEMA,
            'restored' => $restored,
            'removed' => $removed,
            'refused' => $blockers !== [],
            'blockers' => array_values($blockers),
        ];
    }
}
