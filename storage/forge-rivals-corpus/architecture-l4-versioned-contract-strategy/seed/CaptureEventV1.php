<?php

declare(strict_types=1);

namespace App\Domain\Captures\Events;

/**
 * V1 of the external CaptureEvent payload.
 *
 * Canonical fields:
 *   - schema_version = "capture_event.v1"
 *   - capture_id (string)
 *   - tenant_id (string)
 *   - body (string)
 *   - tags (list<string>)
 *
 * The arm must NOT modify this file. Author CaptureEventV2 + a
 * gateway that accepts both, then document the compatibility window.
 */
final class CaptureEventV1
{
    public const SCHEMA_VERSION = 'capture_event.v1';

    /** @return array{schema_version:string,capture_id:string,tenant_id:string,body:string,tags:list<string>} */
    public static function shape(): array
    {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'capture_id' => '',
            'tenant_id' => '',
            'body' => '',
            'tags' => [],
        ];
    }
}
