<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

/**
 * Minimal data contract for the E2E contract test count gate in
 * {@see LoopInvariantHarnessService}. Step 1 of 3: shape only — no harness
 * wiring in this class.
 */
final class E2eContractTestCountGateContract
{
    public const SCHEMA = 'atlas.software_company_stewardship.e2e_contract_test_count_gate.v1';

    public const QUALITY_BAR_MATRIX_CANONICAL = 'docs/engineering-knowledge-base/atlas-aaeos-department-quality-bar-matrix.md';

    public const GATE_ID = 'e2e_contract_test_count_gate';

    /** Informational only in step 1 — does not block long-run readiness by itself. */
    public const INFORMATIONAL_SIGNAL_ONLY = true;

    /** QA department contract-test count floors from the canonical quality bar matrix. */
    public const MIN_CONTRACT_TEST_COUNT_L3 = 10;

    public const MIN_CONTRACT_TEST_COUNT_L4 = 30;

    private function __construct(
        public readonly string $areaId,
        public readonly string $focus,
        public readonly string $departmentId,
        public readonly int $contractTestCount,
    ) {}

    public static function defaults(
        string $areaId = 'agentic_engineering_os',
        string $focus = 'dev_forge',
    ): self {
        return new self(
            areaId: $areaId,
            focus: $focus,
            departmentId: 'qa',
            contractTestCount: 0,
        );
    }

    /**
     * @param  array<string,mixed>  $input
     */
    public static function fromArray(array $input): self
    {
        return new self(
            areaId: trim((string) ($input['area_id'] ?? 'agentic_engineering_os')),
            focus: trim((string) ($input['focus'] ?? 'dev_forge')),
            departmentId: trim((string) ($input['department_id'] ?? 'qa')),
            contractTestCount: max(0, (int) ($input['contract_test_count'] ?? 0)),
        );
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        $meetsL3 = $this->contractTestCount >= self::MIN_CONTRACT_TEST_COUNT_L3;
        $meetsL4 = $this->contractTestCount >= self::MIN_CONTRACT_TEST_COUNT_L4;

        return [
            'schema_version' => self::SCHEMA,
            'gate_id' => self::GATE_ID,
            'informational_signal_only' => self::INFORMATIONAL_SIGNAL_ONLY,
            'quality_bar_matrix_canonical' => self::QUALITY_BAR_MATRIX_CANONICAL,
            'min_contract_test_count_l3' => self::MIN_CONTRACT_TEST_COUNT_L3,
            'min_contract_test_count_l4' => self::MIN_CONTRACT_TEST_COUNT_L4,
            'area_id' => $this->areaId,
            'focus' => $this->focus,
            'inputs' => [
                'department_id' => $this->departmentId,
                'contract_test_count' => $this->contractTestCount,
            ],
            'outputs' => [
                'meets_l3_contract_test_floor' => $meetsL3,
                'meets_l4_contract_test_floor' => $meetsL4,
                'below_l4_contract_test_floor' => ! $meetsL4,
                'blocks_long_run_readiness' => false,
            ],
        ];
    }
}
