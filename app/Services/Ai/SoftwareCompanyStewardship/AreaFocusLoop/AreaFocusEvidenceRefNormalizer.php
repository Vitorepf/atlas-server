<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

final class AreaFocusEvidenceRefNormalizer
{
    public static function gatePassed(mixed $raw): bool
    {
        if (is_bool($raw)) {
            return $raw;
        }

        if (is_array($raw)) {
            foreach (['passed', 'met', 'pass'] as $flag) {
                if (array_key_exists($flag, $raw)) {
                    return $raw[$flag] === true;
                }
            }
        }

        return false;
    }

    public static function gateEvidenceRef(mixed $raw, string $prefix, bool $passed): string
    {
        if (is_array($raw)) {
            foreach (['evidence_ref', 'ref'] as $refKey) {
                $candidate = $raw[$refKey] ?? null;
                if (is_string($candidate) && $candidate !== '') {
                    return $candidate;
                }
            }
        }

        return $passed ? $prefix.'met' : $prefix.'blocked';
    }
}
