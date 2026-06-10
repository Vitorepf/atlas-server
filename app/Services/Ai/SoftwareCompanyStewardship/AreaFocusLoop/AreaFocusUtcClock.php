<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

final class AreaFocusUtcClock
{
    public static function atomNow(int $plusSeconds = 0): string
    {
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        if ($plusSeconds !== 0) {
            $shifted = $now->modify(($plusSeconds > 0 ? '+' : '').$plusSeconds.' seconds');
            if ($shifted instanceof DateTimeImmutable) {
                $now = $shifted;
            }
        }

        return $now->format(DateTimeInterface::ATOM);
    }
}
