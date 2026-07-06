<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\Concerns;

/**
 * Shared storage-root override for stewardship services that persist under
 * storage/atlas. Consumers declare `private const STORAGE_SUBPATH = '...'`.
 *
 * Extracted from 29 byte-identical copies (Obra #8 census).
 */
trait HasStewardshipStorageRoot
{
    private ?string $storageRootOverride = null;

    public function setStorageRootForTesting(?string $dir): void
    {
        $this->storageRootOverride = $dir;
    }

    public function storageDir(): string
    {
        if ($this->storageRootOverride !== null) {
            return $this->storageRootOverride;
        }

        return function_exists('storage_path')
            ? storage_path(self::STORAGE_SUBPATH)
            : sys_get_temp_dir().'/'.self::STORAGE_SUBPATH;
    }
}
