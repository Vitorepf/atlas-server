<?php

namespace App\Services\Ai\Kernel\Surface;

final class SurfaceAttachmentKind
{
    public const ATTACHMENT = 'attachment';
    public const FILE = 'file';
    public const IMAGE = 'image';

    /**
     * @return array<int,string>
     */
    public static function all(): array
    {
        return [
            self::ATTACHMENT,
            self::FILE,
            self::IMAGE,
        ];
    }

    public static function isKnown(string $kind): bool
    {
        return in_array($kind, self::all(), true);
    }
}
