<?php

declare(strict_types=1);

namespace App\Services\Ai\EngineeringKernel\Spec;

/**
 * Engineering Kernel value: the provenance a spec decision binds — sealed into the frozen_hash so a
 * later certification (Obra #1) cannot launder a spec across lanes or witnesses.
 *
 * Owns: recording the frozen_hash (computed over the real criteria), the shadow-advisor divergence
 * state, the source-independence witness, the oracle mode actually run, and the ambiguity findings.
 * Must never own: deciding the verdict (SovereignSpecFloor).
 */
final readonly class SpecProvenance
{
    public const ORACLE_EXECUTIONAL = 'executional';

    public const ORACLE_STRUCTURAL_ONLY = 'structural_only';

    public const ORACLE_UNMEASURED = 'unmeasured';

    /**
     * @param  string  $frozenHash  sha256(canonicalize(criteria)) — computed here, never accepted from upstream
     * @param  list<string>  $ambiguityFindings  findings the deterministic producer emitted
     */
    public function __construct(
        public string $frozenHash,
        public DivergenceStatus $divergenceStatus,
        public SpecSourceIndependence $specSourceIndependence,
        public string $oracleMode,
        public array $ambiguityFindings = [],
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'frozen_hash' => $this->frozenHash,
            'divergence_status' => $this->divergenceStatus->value,
            'spec_source_independence' => $this->specSourceIndependence->value,
            'oracle_mode' => $this->oracleMode,
            'ambiguity_findings' => $this->ambiguityFindings,
        ];
    }
}
