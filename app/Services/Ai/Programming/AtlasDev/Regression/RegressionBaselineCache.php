<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\AtlasDev\Regression;

use App\Services\Ai\Programming\AtlasDev\Schemas\Support\CanonicalJson;

/**
 * E5 -- The immutable pre-patch regression baseline cache.
 *
 * Captured ONCE on the clean tree (before the patch is applied) by
 * {@see RegressionBaselineService::captureBaseline()}: it runs the scoped
 * suite on the pre-patch workspace and records each test command's
 * pass/fail outcome. The cache is then REUSED byte-identical across all
 * M2 repair iterations so each iteration's regression diff compares
 * against the ORIGINAL clean-tree baseline, never a tree polluted by a
 * prior failed attempt (VAL-E5-012).
 *
 * The cache is a pure, immutable value object:
 *   - {@see $results} is the command -> pass(true)/fail(false) map. The
 *     command string is the test identifier (mirrors TestRun.command).
 *   - {@see $contentHash} is a deterministic SHA-256 over the canonical
 *     JSON of the results map. It is stable across diff calls and proves
 *     the baseline was not recaptured (VAL-E5-012: byte-identical across
 *     iterations). Identical results => identical hash; a single bit flip
 *     => a different hash.
 *   - {@see $captureOrder} is a monotonic counter the executor sets to
 *     PROVE the cache was captured before the patch was applied
 *     (VAL-E5-001: cache exists, populated strictly before patch
 *     application). The executor passes captureOrder=0 (the baseline is
 *     captured before the repair loop; the patch is applied inside the
 *     loop at a strictly later point).
 *
 * Canonical: mission architecture.md (Atlas Dev Elevation v2, M4 / E5,
 * e5-regression-baseline feature).
 */
final class RegressionBaselineCache
{
    /**
     * @param  array<string,bool>  $results  command -> ok (true=passed, false=failed)
     * @param  string  $contentHash  deterministic SHA-256 over canonical JSON of results
     * @param  int  $captureOrder  monotonic counter proving before-patch capture
     */
    public function __construct(
        public readonly array $results,
        public readonly string $contentHash,
        public readonly int $captureOrder,
    ) {}

    /**
     * Capture a baseline from a results map. Computes the deterministic
     * content hash so callers do not have to.
     *
     * @param  array<string,bool>  $results  command -> ok
     */
    public static function capture(array $results, int $captureOrder): self
    {
        // Normalise keys + boolean values so the hash is deterministic
        // regardless of key order or boolean coercion.
        $normalised = [];
        foreach ($results as $command => $ok) {
            if (! is_string($command) || $command === '') {
                continue;
            }
            $normalised[$command] = (bool) $ok;
        }
        ksort($normalised, SORT_STRING);

        return new self(
            results: $normalised,
            contentHash: hash('sha256', CanonicalJson::encode($normalised)),
            captureOrder: $captureOrder,
        );
    }

    /**
     * The list of commands that were run during capture (the test
     * identifiers in the baseline). Exposed for auditability / evidence.
     *
     * @return list<string>
     */
    public function commands(): array
    {
        return array_keys($this->results);
    }
}
