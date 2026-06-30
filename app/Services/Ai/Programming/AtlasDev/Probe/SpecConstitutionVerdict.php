<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\AtlasDev\Probe;

use App\Services\Ai\Programming\AtlasDev\Schemas\MiniProgrammingSpec;

/**
 * Verdict from the E6 Spec-Driven Constitution Gate.
 *
 * The gate evaluates the diff's touched files against the task's
 * {@see MiniProgrammingSpec}
 * and produces one of three outcomes:
 *
 *   - NO-OP    ({@see self::noOp()}):    there is no constitution to validate
 *                                        (no acceptance criteria, non-goals,
 *                                        forbidden files, or expected
 *                                        behavior declared). E6 surfaces
 *                                        nothing — byte-identical to a
 *                                        pre-E6 run (VAL-M2-034).
 *   - PASS     ({@see self::pass()}):    the diff honors the spec/constitution
 *                                        (stays in-scope, no forbidden files,
 *                                        no non-goals implemented). No flag,
 *                                        no trip (VAL-M2-023).
 *   - TRIPPED  ({@see self::tripped()}): the diff violates the spec/constitution
 *                                        (forbidden file touched, non-goal
 *                                        implemented, or out-of-spec scope
 *                                        creep). The honesty flag and reasons
 *                                        are carried for the executor to route
 *                                        through the advisory/hard channels
 *                                        (VAL-M2-021/022/024).
 *   - UNEVALUABLE ({@see self::unevaluable()}): the spec could not be read or
 *                                        the evaluation errored. Honest ceiling
 *                                        (VAL-M2-033): never silently green.
 *                                        The executor routes this through the
 *                                        same advisory/hard channels with the
 *                                        {@see SpecDrivenConstitutionGate::FLAG_SPEC_UNEVALUABLE} flag.
 */
final class SpecConstitutionVerdict
{
    /**
     * @param  list<string>  $honestyFlags
     * @param  list<string>  $reasons
     */
    public function __construct(
        public readonly bool $tripped,
        public readonly bool $isUnevaluable,
        public readonly bool $isNoOp,
        public readonly array $honestyFlags,
        public readonly array $reasons,
    ) {}

    public static function noOp(): self
    {
        return new self(
            tripped: false,
            isUnevaluable: false,
            isNoOp: true,
            honestyFlags: [],
            reasons: [],
        );
    }

    public static function pass(): self
    {
        return new self(
            tripped: false,
            isUnevaluable: false,
            isNoOp: false,
            honestyFlags: [],
            reasons: [],
        );
    }

    public static function unevaluable(string $reason): self
    {
        return new self(
            tripped: false,
            isUnevaluable: true,
            isNoOp: false,
            honestyFlags: [SpecDrivenConstitutionGate::FLAG_SPEC_UNEVALUABLE],
            reasons: [$reason],
        );
    }

    /**
     * @param  list<string>  $honestyFlags
     * @param  list<string>  $reasons
     */
    public static function tripped(array $honestyFlags, array $reasons): self
    {
        return new self(
            tripped: true,
            isUnevaluable: false,
            isNoOp: false,
            honestyFlags: $honestyFlags,
            reasons: $reasons,
        );
    }
}
