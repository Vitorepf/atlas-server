<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Tiny helper for schedule when() guards (EVI-04): does a JSONL series already
 * contain a row for YYYY-MM-DD? Read-only, fail-open (missing file ⇒ false).
 */
final class AtlasJsonlDatePresence
{
    public static function hasDate(string $path, string $date): bool
    {
        if ($date === '' || ! is_file($path)) {
            return false;
        }
        $fh = @fopen($path, 'rb');
        if ($fh === false) {
            return false;
        }
        $needle = '"date":"'.$date.'"';
        $needleSpaced = '"date": "'.$date.'"';
        while (($line = fgets($fh)) !== false) {
            if (str_contains($line, $needle) || str_contains($line, $needleSpaced)) {
                fclose($fh);

                return true;
            }
        }
        fclose($fh);

        return false;
    }
}
