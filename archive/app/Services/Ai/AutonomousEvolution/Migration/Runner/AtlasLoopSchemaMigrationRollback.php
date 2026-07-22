<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Migration\Runner;

use RuntimeException;

final class AtlasLoopSchemaMigrationRollback
{
    /**
     * @var array{
     *   is_file: callable(string):bool,
     *   read: callable(string):string|false,
     *   write: callable(string,string):int|false,
     *   rename: callable(string,string):bool,
     *   unlink: callable(string):void
     * }
     */
    private array $fs;

    /**
     * @param  callable(string):?array<string,mixed>  $checkpointResolver
     * @param  array{
     *   is_file?: callable(string):bool,
     *   read?: callable(string):string|false,
     *   write?: callable(string,string):int|false,
     *   rename?: callable(string,string):bool,
     *   unlink?: callable(string):void
     * }|null  $fs
     */
    public function __construct(
        private readonly mixed $checkpointResolver,
        ?array $fs = null,
    ) {
        $this->fs = $fs ?? $this->defaultFilesystem();
    }

    public function rollback(string $checkpointId): RollbackReceipt
    {
        $checkpoint = is_callable($this->checkpointResolver)
            ? call_user_func($this->checkpointResolver, $checkpointId)
            : null;
        if (! is_array($checkpoint)) {
            return new RollbackReceipt([], [], 'checkpoint_not_found');
        }

        $targets = is_array($checkpoint['targets'] ?? null) ? $checkpoint['targets'] : [];
        $restorePlan = [];
        $skipped = [];

        foreach ($targets as $path => $target) {
            if (! is_array($target)) {
                continue;
            }

            $capturedBytes = (string) ($target['bytes'] ?? '');
            $expectedPreSha = (string) ($target['pre_sha256'] ?? '');
            $knownPostShas = array_values(array_filter(
                is_array($target['known_post_sha256s'] ?? null) ? $target['known_post_sha256s'] : [],
                static fn (mixed $value): bool => is_string($value) && trim($value) !== '',
            ));

            if ($expectedPreSha === '' || hash('sha256', $capturedBytes) !== $expectedPreSha) {
                return new RollbackReceipt([], [], 'checkpoint_manifest_mismatch');
            }

            $currentBytes = ($this->fs['is_file'])((string) $path)
                ? ($this->fs['read'])((string) $path)
                : false;
            $currentBytes = is_string($currentBytes) ? $currentBytes : '';
            $currentSha = hash('sha256', $currentBytes);

            if ($currentSha === $expectedPreSha) {
                $skipped[] = (string) $path;

                continue;
            }

            if (! in_array($currentSha, $knownPostShas, true)) {
                return new RollbackReceipt([], [], 'unknown_post_image');
            }

            $restorePlan[(string) $path] = $capturedBytes;
        }

        $restored = [];
        foreach ($restorePlan as $path => $bytes) {
            $tmp = $path.'.rollback.'.bin2hex(random_bytes(4));
            $written = ($this->fs['write'])($tmp, $bytes);
            if ($written === false || $written !== strlen($bytes)) {
                ($this->fs['unlink'])($tmp);
                throw new RuntimeException("Failed to write rollback temp file for [{$path}].");
            }

            if (! ($this->fs['rename'])($tmp, $path)) {
                ($this->fs['unlink'])($tmp);
                throw new RuntimeException("Failed to publish rollback target [{$path}].");
            }

            $restored[] = $path;
        }

        return new RollbackReceipt($restored, $skipped, null);
    }

    /**
     * @return array{
     *   is_file: callable(string):bool,
     *   read: callable(string):string|false,
     *   write: callable(string,string):int|false,
     *   rename: callable(string,string):bool,
     *   unlink: callable(string):void
     * }
     */
    private function defaultFilesystem(): array
    {
        return [
            'is_file' => static fn (string $path): bool => is_file($path),
            'read' => static fn (string $path): string|false => @file_get_contents($path),
            'write' => static fn (string $path, string $contents): int|false => @file_put_contents($path, $contents, LOCK_EX),
            'rename' => static fn (string $from, string $to): bool => @rename($from, $to),
            'unlink' => static function (string $path): void {
                if (is_file($path)) {
                    @unlink($path);
                }
            },
        ];
    }
}

final readonly class RollbackReceipt
{
    /**
     * @param  list<string>  $restoredTargets
     * @param  list<string>  $skippedTargets
     */
    public function __construct(
        public array $restoredTargets,
        public array $skippedTargets,
        public ?string $refusedReason,
    ) {}

    /**
     * @return array{
     *   restored_targets:list<string>,
     *   skipped_targets:list<string>,
     *   refused_reason:?string
     * }
     */
    public function toArray(): array
    {
        return [
            'restored_targets' => $this->restoredTargets,
            'skipped_targets' => $this->skippedTargets,
            'refused_reason' => $this->refusedReason,
        ];
    }
}
