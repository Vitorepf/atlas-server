<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

final class AreaFocusAppendOnlyJsonlRecorder
{
    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    public static function maybeRecord(
        array $payload,
        bool $record,
        string $path,
        string $schemaVersion,
        string $recordedAt,
        string $storageStatusKey,
    ): array {
        if (! $record) {
            return $payload + [$storageStatusKey => 'projected'];
        }

        $recordPayload = [
            'schema_version' => $schemaVersion,
            'recorded_at' => $recordedAt,
        ] + $payload;

        self::append($path, $recordPayload);

        return $recordPayload + [$storageStatusKey => 'recorded'];
    }

    /**
     * @param  array<string,mixed>  $recordPayload
     */
    public static function append(string $path, array $recordPayload): void
    {
        AreaFocusJsonlWriter::append($path, $recordPayload);
    }
}
