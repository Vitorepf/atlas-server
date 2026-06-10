<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\Support\AppendOnlyJsonlStore;

final class AreaFocusJsonlWriter
{
    /**
     * @param  array<string,mixed>  $recordPayload
     */
    public static function append(string $path, array $recordPayload): void
    {
        AppendOnlyJsonlStore::append($path, $recordPayload);
    }

    /**
     * @param  list<array<string,mixed>>  $records
     */
    public static function rewrite(string $path, array $records): void
    {
        AppendOnlyJsonlStore::rewrite($path, $records);
    }
}
