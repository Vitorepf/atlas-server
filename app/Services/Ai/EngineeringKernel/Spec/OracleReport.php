<?php

declare(strict_types=1);

namespace App\Services\Ai\EngineeringKernel\Spec;

/**
 * Engineering Kernel value: the result of probing a spec's frozen tests against a NO-OP wrong impl.
 *
 * Owns: carrying WHICH behavioral criteria went genuinely RED against a do-nothing implementation
 * (the only honest oracle-adequacy signal), and in what mode the probe ran.
 * Must never own: running the probe (a SpecOracle port does that) or deciding the verdict (the floor).
 */
final readonly class OracleReport
{
    /**
     * @param  string  $mode  SpecProvenance::ORACLE_* — executional | structural_only | unmeasured
     * @param  list<string>  $redCriteriaIds  behavioral AC ids that FAILED (went RED) against the no-op impl
     */
    public function __construct(
        public string $mode,
        public array $redCriteriaIds = [],
    ) {}

    /**
     * @param  list<string>  $redCriteriaIds
     */
    public static function executional(array $redCriteriaIds): self
    {
        return new self(SpecProvenance::ORACLE_EXECUTIONAL, array_values(array_map('strval', $redCriteriaIds)));
    }

    public static function structuralOnly(): self
    {
        return new self(SpecProvenance::ORACLE_STRUCTURAL_ONLY);
    }

    /** No probe could run (e.g. no test runner available) — the floor must HOLD, never fabricate a pass. */
    public static function unmeasured(): self
    {
        return new self(SpecProvenance::ORACLE_UNMEASURED);
    }

    public function discriminatingCount(): int
    {
        return count($this->redCriteriaIds);
    }
}
