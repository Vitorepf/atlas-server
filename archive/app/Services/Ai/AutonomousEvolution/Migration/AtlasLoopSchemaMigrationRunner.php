<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Migration;

use App\Services\Ai\AutonomousEvolution\AtlasLoopMasterSwitch;
use InvalidArgumentException;
use RuntimeException;

final class AtlasLoopSchemaMigrationRunner
{
    /**
     * @var array{
     *     is_file: callable(string):bool,
     *     read: callable(string):string|false,
     *     write: callable(string,string):int|false,
     *     rename: callable(string,string):bool,
     *     unlink: callable(string):void
     * }
     */
    private array $fs;

    /**
     * @param  array{
     *     is_file?: callable(string):bool,
     *     read?: callable(string):string|false,
     *     write?: callable(string,string):int|false,
     *     rename?: callable(string,string):bool,
     *     unlink?: callable(string):void
     * }|null  $fs
     */
    public function __construct(
        private readonly AtlasLoopSchemaMigrationRegistry $registry,
        ?array $fs = null,
    ) {
        $this->fs = $fs ?? $this->defaultFilesystem();
    }

    public function approvalTokenFor(string $artifactKind, int $fromVersion, int $toVersion): string
    {
        $seed = strtolower(trim($artifactKind)).'|'.$fromVersion.'|'.$toVersion;
        $hex = substr(hash('sha256', $seed), 0, 32);

        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12),
        );
    }

    public function run(
        string $artifactKind,
        int $currentVersion,
        int $targetVersion,
        string $snapshotPath,
        ?string $approvalToken,
    ): string {
        if (! AtlasLoopMasterSwitch::enabled()) {
            return 'master_off';
        }

        $expectedToken = $this->approvalTokenFor($artifactKind, $currentVersion, $targetVersion);
        if ($approvalToken !== $expectedToken) {
            throw new AtlasLoopSchemaMigrationApprovalRequiredException($artifactKind, $currentVersion, $targetVersion, $expectedToken);
        }

        if (! ($this->fs['is_file'])($snapshotPath)) {
            throw new InvalidArgumentException("Snapshot path \"{$snapshotPath}\" does not exist.");
        }

        $originalBytes = ($this->fs['read'])($snapshotPath);
        if (! is_string($originalBytes)) {
            throw new RuntimeException("Snapshot path \"{$snapshotPath}\" is unreadable.");
        }

        $bytes = $originalBytes;
        foreach ($this->registry->resolveChain($artifactKind, $currentVersion, $targetVersion) as $step) {
            $bytes = $step->transform($bytes);
            if (! $step->verify($bytes)) {
                throw new AtlasLoopSchemaMigrationVerificationFailedException(
                    $artifactKind,
                    $step->fromVersion,
                    $step->toVersion,
                );
            }
        }

        $tmp = $snapshotPath.'.tmp.'.bin2hex(random_bytes(6));
        $written = ($this->fs['write'])($tmp, $bytes);
        if ($written === false || $written !== strlen($bytes)) {
            ($this->fs['unlink'])($tmp);
            throw new RuntimeException("Failed to write migration temp file for \"{$snapshotPath}\".");
        }

        if (! ($this->fs['rename'])($tmp, $snapshotPath)) {
            ($this->fs['unlink'])($tmp);
            throw new RuntimeException("Failed to publish migrated snapshot for \"{$snapshotPath}\".");
        }

        return $bytes;
    }

    /**
     * @return array{
     *     is_file: callable(string):bool,
     *     read: callable(string):string|false,
     *     write: callable(string,string):int|false,
     *     rename: callable(string,string):bool,
     *     unlink: callable(string):void
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

final class AtlasLoopSchemaMigrationApprovalRequiredException extends RuntimeException
{
    public function __construct(string $artifactKind, int $fromVersion, int $toVersion, string $expectedToken)
    {
        parent::__construct(sprintf(
            'Approval required for artifact "%s" migration %d -> %d. Expected token: %s',
            $artifactKind,
            $fromVersion,
            $toVersion,
            $expectedToken,
        ));
    }
}

final class AtlasLoopSchemaMigrationVerificationFailedException extends RuntimeException
{
    public function __construct(string $artifactKind, int $fromVersion, int $toVersion)
    {
        parent::__construct(sprintf(
            'Migration verification failed for artifact "%s" step %d -> %d.',
            $artifactKind,
            $fromVersion,
            $toVersion,
        ));
    }
}
