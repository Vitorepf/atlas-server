<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Campaign;
use App\Services\Ai\Support\AppendOnlyJsonlStore;

/**
 * File primitives (storage/ledger/lock/heartbeat) for the Atlas loop campaign
 * supervisor.
 *
 * Extracted from AtlasLoopCampaignSupervisor to reduce the god-class. The
 * storage root is passed in as a parameter so this collaborator stays pure
 * (no implicit config or singleton dependency).
 */
final class AtlasLoopCampaignFileStore
{
    public static function storageDir(string $campaignId, ?string $storageRoot = null): string
    {
        $root = $storageRoot ?? storage_path('atlas-loop/campaign');

        return rtrim($root, '/').'/'.$campaignId;
    }

    public static function ensureStorage(string $campaignId, ?string $storageRoot = null): void
    {
        $dir = self::storageDir($campaignId, $storageRoot);
        if (! is_dir($dir)) {
            @mkdir($dir, 0o755, true);
        }
    }

    public static function ledgerPath(string $campaignId, ?string $storageRoot = null): string
    {
        return self::storageDir($campaignId, $storageRoot).'/ledger.jsonl';
    }

    public static function killSwitchPath(string $campaignId, ?string $storageRoot = null): string
    {
        return self::storageDir($campaignId, $storageRoot).'/KILL';
    }

    public static function pausePath(string $campaignId, ?string $storageRoot = null): string
    {
        return self::storageDir($campaignId, $storageRoot).'/PAUSE';
    }

    public static function killFileExists(string $campaignId, ?string $storageRoot = null): bool
    {
        return is_file(self::killSwitchPath($campaignId, $storageRoot));
    }

    public static function pauseFileExists(string $campaignId, ?string $storageRoot = null): bool
    {
        return is_file(self::pausePath($campaignId, $storageRoot));
    }

    public static function writeHeartbeat(string $campaignId, ?int $timestamp = null, ?string $storageRoot = null): void
    {
        @file_put_contents(self::storageDir($campaignId, $storageRoot).'/heartbeat', (string) ($timestamp ?? time()));
    }

    public static function appendLedger(string $campaignId, array $record, ?string $storageRoot = null): void
    {
        $line = json_encode($record, JSON_UNESCAPED_SLASHES);
        AppendOnlyJsonlStore::appendEncodedLineSilently(
            self::ledgerPath($campaignId, $storageRoot),
            $line === false ? '' : $line,
            FILE_APPEND,
            0o755,
        );
    }

    public static function acquireLock(string $campaignId, int $leaseSeconds, ?string $storageRoot = null, ?int $nowOverride = null): bool
    {
        $now = $nowOverride ?? time();
        $existing = self::readLock($campaignId, $storageRoot);
        if ($existing !== null) {
            $alive = isset($existing['pid']) && ! self::lockProcessIsDead((int) $existing['pid']);
            $fresh = (int) ($existing['expires_at'] ?? 0) > $now;
            if ($alive && $fresh) {
                return false; // genuinely held by a live supervisor
            }
        }
        @file_put_contents(self::storageDir($campaignId, $storageRoot).'/lock.json', json_encode([
            'pid' => function_exists('getmypid') ? getmypid() : 0,
            'token' => $campaignId,
            'expires_at' => $now + max(60, $leaseSeconds),
        ], JSON_UNESCAPED_SLASHES));

        return true;
    }

    public static function releaseLock(string $campaignId, ?string $storageRoot = null): void
    {
        @unlink(self::storageDir($campaignId, $storageRoot).'/lock.json');
    }

    public static function readLock(string $campaignId, ?string $storageRoot = null): ?array
    {
        $path = self::storageDir($campaignId, $storageRoot).'/lock.json';
        if (! is_file($path)) {
            return null;
        }
        $data = json_decode((string) @file_get_contents($path), true);

        return is_array($data) ? $data : null;
    }

    public static function lockProcessIsDead(int $pid): bool
    {
        if ($pid <= 0) {
            return true;
        }
        if (! function_exists('posix_kill')) {
            return false; // cannot tell → assume alive (conservative: do not steal the lock)
        }

        return ! @posix_kill($pid, 0);
    }
}
