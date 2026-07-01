<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Support;

use Illuminate\Support\Arr;
use InvalidArgumentException;

/**
 * Pure helper: hardens one-shot scheduler tick input validation shared across
 * AgentAutomaticDispatchScheduler* classes — required-field presence and
 * 64-hex hash normalization.
 *
 * Hash fields are lowercased and trimmed of SPACES/TABS ONLY (never newlines) —
 * uppercase/trailing-space drift from a copy-paste is safe to normalize, but a
 * trailing newline is a sign the hash was copied wrong and must fail loudly
 * rather than silently pass a regex that also strips \n.
 *
 * Pure: no I/O, no provider or queue side effects.
 */
final class OneShotTickInputNormalizer
{
    private const TRIM_CHARS = " \t";

    /**
     * @param  array<string,mixed>  $input
     * @param  list<string>  $requiredFields
     * @param  list<string>  $hashFields
     * @return array<string,mixed>
     */
    public function normalize(array $input, array $requiredFields, array $hashFields): array
    {
        foreach ($requiredFields as $field) {
            if (! Arr::has($input, $field) || $input[$field] === null || $input[$field] === '') {
                throw new InvalidArgumentException('missing_'.$field);
            }
        }

        foreach ($hashFields as $field) {
            if (! Arr::has($input, $field)) {
                continue;
            }

            $normalized = strtolower(trim((string) $input[$field], self::TRIM_CHARS));

            if (preg_match('/\A[a-f0-9]{64}\z/', $normalized) !== 1) {
                throw new InvalidArgumentException('invalid_'.$field);
            }

            $input[$field] = $normalized;
        }

        return $input;
    }
}
