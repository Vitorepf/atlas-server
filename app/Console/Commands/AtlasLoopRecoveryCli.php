<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AutonomousEvolution\Recovery\AtlasLoopBackupComposer;
use App\Services\Ai\AutonomousEvolution\Recovery\AtlasLoopReceiptReplayer;
use App\Services\Ai\AutonomousEvolution\Recovery\AtlasLoopRestoreVerifier;
use Illuminate\Console\Command;
use Throwable;

/**
 * Operator-facing loop recovery CLI:
 *   atlas:loop:recovery replay  --ledger=PATH                          → reconstructed state JSON
 *   atlas:loop:recovery backup  --ledger=PATH[,PATH...]                 → tar path + archive_sha256
 *   atlas:loop:recovery verify  --tar=PATH --checkpoint=JSON|PATH       → verification result JSON
 *   atlas:loop:recovery restore --tar=PATH --target=DIR [--i-know-what-im-doing]
 *
 * Read-only over the live tree by default; restore refuses to write under the live ledger root
 * unless the safety flag is set.
 */
final class AtlasLoopRecoveryCli extends Command
{
    /** @var string */
    protected $signature = 'atlas:loop:recovery
        {action : replay|backup|verify|restore}
        {--ledger=}
        {--tar=}
        {--checkpoint=}
        {--target=}
        {--i-know-what-im-doing}
        {--json}';

    /** @var string */
    protected $description = 'Loop recovery CLI: replay | backup | verify | restore.';

    public function handle(): int
    {
        $action = (string) $this->argument('action');

        return match ($action) {
            'replay' => $this->replay(),
            'backup' => $this->backup(),
            'verify' => $this->verify(),
            'restore' => $this->restore(),
            default => $this->emit(['error' => 'unknown_action:'.$action], self::FAILURE),
        };
    }

    private function replay(): int
    {
        $ledger = (string) ($this->option('ledger') ?? '');
        if ($ledger === '') {
            return $this->emit(['error' => 'ledger_path_required'], self::FAILURE);
        }
        $replayer = $this->getLaravel()->make(AtlasLoopReceiptReplayer::class);
        try {
            $result = $replayer->replay($ledger);
        } catch (Throwable $e) {
            return $this->emit(['error' => 'replay_failed', 'reason' => $e->getMessage()], self::FAILURE);
        }

        return $this->emit(['state' => $result['state'], 'event_count' => count((array) ($result['audit_trail'] ?? []))]);
    }

    private function backup(): int
    {
        $ledgerOption = (string) ($this->option('ledger') ?? '');
        $ledgers = $ledgerOption === '' ? $this->defaultLedgers() : array_values(array_filter(explode(',', $ledgerOption)));
        if ($ledgers === []) {
            return $this->emit(['error' => 'no_ledger_paths_resolved'], self::FAILURE);
        }
        try {
            $composer = new AtlasLoopBackupComposer($ledgers);
            $tarPath = $composer->compose();
        } catch (Throwable $e) {
            return $this->emit(['error' => 'backup_failed', 'reason' => $e->getMessage()], self::FAILURE);
        }
        $archiveSha = $this->readArchiveSha($tarPath);

        return $this->emit(['tar_path' => $tarPath, 'archive_sha256' => $archiveSha]);
    }

    private function verify(): int
    {
        $tar = (string) ($this->option('tar') ?? '');
        if ($tar === '') {
            return $this->emit(['error' => 'tar_required'], self::FAILURE);
        }
        $checkpointRaw = (string) ($this->option('checkpoint') ?? '');
        $checkpoint = $this->parseCheckpoint($checkpointRaw);
        if ($checkpoint === null) {
            return $this->emit(['error' => 'checkpoint_required_or_unparseable'], self::FAILURE);
        }
        $verifier = $this->getLaravel()->make(AtlasLoopRestoreVerifier::class);
        $result = $verifier->verify($tar, $checkpoint);
        $payload = $result->toArray();

        return $this->emit($payload, $result->ok ? self::SUCCESS : 2);
    }

    private function restore(): int
    {
        $tar = (string) ($this->option('tar') ?? '');
        $target = (string) ($this->option('target') ?? '');
        if ($tar === '' || $target === '') {
            return $this->emit(['error' => 'tar_and_target_required'], self::FAILURE);
        }
        $liveRoot = storage_path('app/atlas/loop/ledgers');
        $resolvedTarget = rtrim($target, '/');
        $insideLive = str_starts_with($resolvedTarget, $liveRoot);
        if ($insideLive && ! (bool) $this->option('i-know-what-im-doing')) {
            return $this->emit([
                'error' => 'refusing_to_write_into_live_ledger_root',
                'target' => $resolvedTarget,
                'hint' => 'pass --i-know-what-im-doing to override',
            ], self::FAILURE);
        }
        if (! is_dir($target) && ! @mkdir($target, 0o755, true)) {
            return $this->emit(['error' => 'cannot_create_target', 'target' => $target], self::FAILURE);
        }
        $extracted = $this->extractTar($tar, $target);

        return $this->emit(['target' => $target, 'restored' => array_keys($extracted)]);
    }

    /**
     * @return list<string>
     */
    private function defaultLedgers(): array
    {
        $root = storage_path('app/atlas/loop/ledgers');
        $paths = [];
        foreach ((array) glob($root.'/*.jsonl') as $p) {
            if (is_string($p)) {
                $paths[] = $p;
            }
        }
        sort($paths, SORT_STRING);

        return $paths;
    }

    /**
     * @return array<string,mixed>|null
     */
    private function parseCheckpoint(string $raw): ?array
    {
        if ($raw === '') {
            return null;
        }
        $contents = is_file($raw) ? (string) file_get_contents($raw) : $raw;
        $decoded = json_decode($contents, true);

        return is_array($decoded) ? $decoded : null;
    }

    private function readArchiveSha(string $tarPath): string
    {
        $raw = (string) file_get_contents($tarPath);
        if (strlen($raw) < 1024) {
            return '';
        }
        $name = trim(substr($raw, 0, 100), "\0");
        if ($name !== 'manifest.json') {
            return '';
        }
        $sizeOctal = trim(substr($raw, 124, 12), "\0 ");
        $size = (int) octdec($sizeOctal);
        $manifest = json_decode(substr($raw, 512, $size), true);

        return is_array($manifest) ? (string) ($manifest['archive_sha256'] ?? '') : '';
    }

    /**
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
            $absolute = rtrim($destDir, '/').'/'.$name;
            @mkdir(\dirname($absolute), 0o755, true);
            file_put_contents($absolute, $contents);
            $files[$name] = $absolute;
            $offset += $size;
            $padding = (512 - ($size % 512)) % 512;
            $offset += $padding;
        }

        return $files;
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function emit(array $payload, int $exit = self::SUCCESS): int
    {
        $this->line((string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return $exit;
    }
}
