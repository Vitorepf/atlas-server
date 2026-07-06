<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction;

use Illuminate\Support\Arr;
use InvalidArgumentException;

/**
 * Shared normalize() method for PostStart gate invokers.
 *
 * Origin: consolidation wave 05/07 — 5 byte-identical copies extracted
 * (hash 095cedd1b6c2bac8) from AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStart*GateInvoker classes.
 * Divergent copies (if any arise) stay local in their class.
 */
trait NormalizesPostStartGateInput
{
    private function normalize(array $input): array
    {
        foreach (self::FORBIDDEN_TRUE_FLAGS as $flag) {
            if (Arr::has($input, $flag) && (bool) $input[$flag] === true) {
                throw new InvalidArgumentException('forbidden_true_flag_'.$flag);
            }
        }

        $required = array_merge(self::IDENTIFIER_FIELDS, self::HASH_FIELDS);

        foreach ($required as $field) {
            if (! Arr::has($input, $field) || $input[$field] === null || $input[$field] === '') {
                throw new InvalidArgumentException('missing_'.$field);
            }
        }

        $hashes = [];
        foreach (self::HASH_FIELDS as $field) {
            $hashes[$field] = strtolower(trim((string) $input[$field]));

            if (preg_match('/^[a-f0-9]{64}$/', $hashes[$field]) !== 1) {
                throw new InvalidArgumentException('invalid_'.$field);
            }
        }

        $normalized = [];
        foreach (self::IDENTIFIER_FIELDS as $field) {
            $normalized[$field] = (string) $input[$field];
        }
        foreach (self::HASH_FIELDS as $field) {
            $normalized[$field] = $hashes[$field];
        }

        return $normalized;
    }
}
