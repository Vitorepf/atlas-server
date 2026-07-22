<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Recovery;

use Throwable;

/**
 * Extracts a backup tar produced by {@see AtlasLoopBackupComposer} into a TEMP directory, replays
 * every ledger through {@see AtlasLoopReceiptReplayer}, and verifies the resulting state hash
 * matches the operator-supplied checkpoint.
 *
 * INVARIANTS:
 *   - NEVER writes under storage/app/atlas/loop/ledgers/ (the temp dir is system-scoped).
 *   - Temp directory is cleaned up on BOTH success and exception paths (try/finally).
 *   - Delegates replay to AtlasLoopReceiptReplayer (constructor-injected) — same code path that
 *     operators rely on at runtime.
 */
final class AtlasLoopRestoreVerifier
{
    public function __construct(private readonly AtlasLoopReceiptReplayer $replayer) {}

    /**
     * @param  array{expected_state_hash:string, expected_state?:array<string,mixed>}  $checkpoint
     */
    public function verify(string $tarPath, array $checkpoint): AtlasLoopRestoreVerificationResult
    {
        $expectedHash = (string) ($checkpoint['expected_state_hash'] ?? '');
        $expectedState = is_array($checkpoint['expected_state'] ?? null) ? $checkpoint['expected_state'] : null;

        $tempDir = sys_get_temp_dir().'/atlas-restore-'.bin2hex(random_bytes(8));
        @mkdir($tempDir, 0o700, true);

        try {
            $extracted = $this->extractTar($tarPath, $tempDir);
            $combinedState = [];
            $eventCount = 0;
            foreach ($extracted as $name => $path) {
                if ($name === 'manifest.json') {
                    continue;
                }
                $result = $this->replayer->replay($path);
                $eventCount += count((array) ($result['audit_trail'] ?? []));
                foreach ((array) ($result['state'] ?? []) as $key => $row) {
                    $combinedState[(string) $key] = $row;
                }
            }
            ksort($combinedState);
            $actualHash = hash('sha256', (string) json_encode($combinedState, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            $mismatched = [];
            if ($expectedState !== null) {
                foreach ($expectedState as $key => $expectedRow) {
                    if (! array_key_exists((string) $key, $combinedState) || $combinedState[(string) $key] !== $expectedRow) {
                        $mismatched[] = (string) $key;
                    }
                }
            }
            $ok = $actualHash === $expectedHash && $mismatched === [];

            return new AtlasLoopRestoreVerificationResult(
                $ok,
                $actualHash,
                $expectedHash,
                $mismatched,
                $eventCount,
                $ok ? '' : ($actualHash !== $expectedHash ? 'state_hash_mismatch' : 'state_keys_mismatched'),
            );
        } catch (Throwable $e) {
            return new AtlasLoopRestoreVerificationResult(
                false,
                '',
                $expectedHash,
                [],
                0,
                'replay_failed:'.$e->getMessage(),
            );
        } finally {
            $this->rrmdir($tempDir);
        }
    }

    /**
     * Minimal POSIX ustar extractor. Returns map of name → absolute path of the extracted file.
     *
     * @return array<string,string>
     */
    private function extractTar(string $tarPath, string $destDir): array
    {
        $raw = (string) file_get_contents($tarPath);
        $files = [];
        $offset = 0;
        $totalLength = strlen($raw);
        while ($offset + 512 <= $totalLength) {
            $header = substr($raw, $offset, 512);
            $name = trim(substr($header, 0, 100), "\0");
            if ($name === '') {
                break;
            }
            $sizeOctal = trim(substr($header, 124, 12), "\0 ");
            $size = (int) octdec($sizeOctal);
            $offset += 512;
            $contents = substr($raw, $offset, $size);
            $absolute = $destDir.'/'.$name;
            @mkdir(\dirname($absolute), 0o700, true);
            file_put_contents($absolute, $contents);
            $files[$name] = $absolute;
            $offset += $size;
            // Round up to next 512-byte boundary.
            $padding = (512 - ($size % 512)) % 512;
            $offset += $padding;
        }

        return $files;
    }

    private function rrmdir(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        foreach ((array) scandir($dir) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $full = $dir.'/'.$entry;
            if (is_dir($full)) {
                $this->rrmdir($full);
            } else {
                @unlink($full);
            }
        }
        @rmdir($dir);
    }
}
