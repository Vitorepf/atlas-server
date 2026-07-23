<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\AtlasDev\Differential\Shadow;


/**
 * E4 -- Outcome of a single {@see ShadowDiffHarness::shadowDiff()} call.
 *
 * The harness produces this; the {@see ShadowDiffService} compares the
 * captured outputs and decides divergence.
 *
 * Channels:
 *   - EXECUTED:  both function versions ran without fatal error across all
 *                probe inputs. {@see $oldOutputs} and {@see $newOutputs}
 *                are non-empty parallel arrays of serialized return values
 *                (one entry per probe input). The SERVICE diffs them.
 *   - FAILED:    the harness could not execute one or both versions
 *                (parse error, runtime error, timeout). {@see $error} is a
 *                non-empty reason. Outputs are empty. The SERVICE treats
 *                this as a skip with reason (never fabricate a divergence
 *                and never crash — VAL-E4-011 honest ceiling).
 *
 * Each output entry is the return value serialized to a stable string
 * (var_export for arrays/scalars, class-name for objects, '(null)' for
 * null). The serialization is the harness's responsibility; the service
 * compares strings for byte-equality (VAL-E4-008: identical outputs across
 * all inputs => behavior-preserving refactor, no flag).
 *
 * Canonical: mission architecture.md (Atlas Dev Elevation v2, M5 / E4,
 * e4-shadow-diff-pure-functions feature).
 */
final class ShadowDiffHarnessResult
{
    /**
     * @param  list<string>  $oldOutputs  serialized return values of the OLD version, one per probe input.
     * @param  list<string>  $newOutputs  serialized return values of the NEW version, one per probe input.
     */
    public function __construct(
        public readonly bool $executed,
        public readonly string $error,
        public readonly array $oldOutputs,
        public readonly array $newOutputs,
    ) {}

    /**
     * Both function versions executed without fatal error.
     *
     * @param  list<string>  $oldOutputs
     * @param  list<string>  $newOutputs
     */
    public static function executed(array $oldOutputs, array $newOutputs): self
    {
        return new self(
            executed: true,
            error: '',
            oldOutputs: $oldOutputs,
            newOutputs: $newOutputs,
        );
    }

    /**
     * The harness could not execute one or both versions. Outputs are empty;
     * the service treats this as a skip with the given reason.
     */
    public static function failed(string $reason): self
    {
        return new self(
            executed: false,
            error: $reason,
            oldOutputs: [],
            newOutputs: [],
        );
    }
}
