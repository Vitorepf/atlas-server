<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Recovery;

use RuntimeException;

/**
 * Typed exception raised by {@see AtlasLoopBackupComposer::compose()} when a declared ledger path
 * is missing on disk.
 */
final class AtlasLoopBackupComposerException extends RuntimeException
{
}

/**
 * Snapshots every receipt-ledger file into a single deterministic tar archive plus a manifest.
 *
 * The archive layout (in lexicographic file order):
 *   manifest.json   - {files:[{path, sha256, size, mtime}], archive_sha256}
 *   <ledger files…>
 *
 * INVARIANTS:
 *   - Fail-closed: a missing ledger path throws AtlasLoopBackupComposerException naming the path.
 *     No partial tar is written.
 *   - Two backups of the same on-disk state produce byte-identical manifest.archive_sha256
 *     (the wall-clock filename is the only thing that differs).
 */
final class AtlasLoopBackupComposer
{
    /**
     * @param  list<string>  $ledgerPaths  absolute paths to every receipt ledger this composer must include
     * @param  string|null   $destinationRoot directory under which the tar will be created (default: storage/app/atlas/loop/backups)
     */
    public function __construct(
        private readonly array $ledgerPaths,
        private readonly ?string $destinationRoot = null,
    ) {}

    /**
     * Compose a backup tar. Returns the absolute path of the written tar file.
     */
    public function compose(?string $destinationPath = null): string
    {
        $missing = [];
        foreach ($this->ledgerPaths as $p) {
            if (! is_file($p)) {
                $missing[] = $p;
            }
        }
        if ($missing !== []) {
            throw new AtlasLoopBackupComposerException('missing_ledger_paths:'.implode(',', $missing));
        }

        $files = $this->ledgerPaths;
        sort($files, SORT_STRING);

        $manifestFiles = [];
        $bodyFragments = [];
        foreach ($files as $absolute) {
            $contents = (string) file_get_contents($absolute);
            $sha = hash('sha256', $contents);
            $manifestFiles[] = [
                'path' => $this->relativeName($absolute),
                'sha256' => $sha,
                'size' => strlen($contents),
                'mtime' => (int) filemtime($absolute),
            ];
            $bodyFragments[] = $contents;
        }
        $manifest = [
            'files' => $manifestFiles,
            // Compute the archive_sha256 over the concatenation of (path|sha256) pairs in canonical
            // order — purely structural, so it is robust against mtime drift.
            'archive_sha256' => hash('sha256', implode("\0", array_map(
                static fn (array $f): string => $f['path'].'|'.$f['sha256'],
                $manifestFiles,
            ))),
        ];
        $manifestJson = (string) json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $dest = $destinationPath ?? $this->defaultDestinationPath();
        $dir = \dirname($dest);
        if (! is_dir($dir) && ! @mkdir($dir, 0o755, true) && ! is_dir($dir)) {
            throw new AtlasLoopBackupComposerException('cannot_create_backup_dir:'.$dir);
        }

        $tar = $this->encodeTar($manifestJson, $files, $manifestFiles);
        file_put_contents($dest, $tar);

        return $dest;
    }

    private function defaultDestinationPath(): string
    {
        $root = $this->destinationRoot ?? storage_path('app/atlas/loop/backups');
        $ts = gmdate('Y-m-d\TH-i-s\Z');

        return rtrim($root, '/').'/loop-ledger-'.$ts.'.tar';
    }

    private function relativeName(string $absolute): string
    {
        // Use the basename to make the manifest path stable across hosts.
        return basename($absolute);
    }

    /**
     * Minimal POSIX ustar encoder. Emits manifest.json followed by every ledger file.
     *
     * @param  list<string>  $absolutePaths
     * @param  list<array<string,mixed>>  $manifest
     */
    private function encodeTar(string $manifestJson, array $absolutePaths, array $manifest): string
    {
        $tar = '';
        $tar .= $this->tarEntry('manifest.json', $manifestJson);
        foreach ($absolutePaths as $i => $absolute) {
            $name = $manifest[$i]['path'];
            $tar .= $this->tarEntry((string) $name, (string) file_get_contents($absolute));
        }
        // End-of-archive: two 512-byte zero blocks.
        $tar .= str_repeat("\0", 1024);

        return $tar;
    }

    private function tarEntry(string $name, string $contents): string
    {
        $size = strlen($contents);
        $header = '';
        // 100 bytes: file name
        $header .= str_pad($name, 100, "\0");
        // 8 bytes: mode (0644)
        $header .= str_pad('0000644', 7, '0', STR_PAD_LEFT)."\0";
        // 8 bytes: uid
        $header .= str_pad('0000000', 7, '0', STR_PAD_LEFT)."\0";
        // 8 bytes: gid
        $header .= str_pad('0000000', 7, '0', STR_PAD_LEFT)."\0";
        // 12 bytes: size (octal)
        $header .= str_pad(decoct($size), 11, '0', STR_PAD_LEFT)."\0";
        // 12 bytes: mtime — use 0 so backups of identical content are byte-identical.
        $header .= str_pad('0', 11, '0', STR_PAD_LEFT)."\0";
        // 8 bytes: checksum placeholder (spaces while computing)
        $header .= '        ';
        // 1 byte: typeflag '0' (regular file)
        $header .= '0';
        // 100 bytes: linkname (none)
        $header .= str_repeat("\0", 100);
        // 6 bytes: magic "ustar\0"
        $header .= "ustar\0";
        // 2 bytes: version
        $header .= '00';
        // 32 bytes: uname / 32 gname / 8 devmajor / 8 devminor / 155 prefix / 12 pad
        $header .= str_repeat("\0", 32 + 32 + 8 + 8 + 155 + 12);

        // Compute checksum.
        $checksum = 0;
        for ($i = 0, $n = strlen($header); $i < $n; $i++) {
            $checksum += ord($header[$i]);
        }
        $checksumOctal = str_pad(decoct($checksum), 6, '0', STR_PAD_LEFT)."\0 ";
        $header = substr_replace($header, $checksumOctal, 148, 8);

        $padding = str_repeat("\0", (512 - ($size % 512)) % 512);

        return $header.$contents.$padding;
    }
}
