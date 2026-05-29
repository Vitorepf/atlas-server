<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

/**
 * Minimal data contract for the landed merge hash cross-link in
 * {@see AreaFocusEvidencePackService}. Step 1 of 3: shape only — no evidence
 * pack wiring in this class.
 *
 * Records the merge commit that landed on main plus the branch ref so an
 * evidence pack manifest can be verified against git history.
 */
final class TheLandedMergeHashContract
{
    public const SCHEMA = 'atlas.software_company_stewardship.landed_merge_hash.v1';

    public const GAP_MATRIX_CANONICAL = 'docs/engineering-knowledge-base/atlas-agentic-engineering-os-runtime-gap-matrix.md';

    public const CONTRACT_ID = 'landed_merge_hash_cross_link';

    public const FINDING_ID = 'aaeos_evidence_pack_merge_hash_cross_link';

    public const EVIDENCE_PACK_SCHEMA = AreaFocusEvidencePackService::PACK_SCHEMA;

    private function __construct(
        public readonly string $areaId,
        public readonly string $focus,
        public readonly string $cycleId,
        public readonly string $mergeHash,
        public readonly string $branchRef,
    ) {}

    public static function defaults(
        string $areaId = 'agentic_engineering_os',
        string $focus = 'dev_forge',
    ): self {
        return new self(
            areaId: $areaId,
            focus: $focus,
            cycleId: '',
            mergeHash: '',
            branchRef: '',
        );
    }

    /**
     * @param  array<string,mixed>  $input
     */
    public static function fromArray(array $input): self
    {
        $loopReceipt = is_array($input['loop_receipt'] ?? null) ? $input['loop_receipt'] : [];

        return new self(
            areaId: trim((string) ($input['area_id'] ?? 'agentic_engineering_os')),
            focus: trim((string) ($input['focus'] ?? 'dev_forge')),
            cycleId: trim((string) ($input['cycle_id'] ?? '')),
            mergeHash: trim((string) ($input['merge_hash'] ?? ($loopReceipt['merge_hash'] ?? ''))),
            branchRef: trim((string) ($input['branch_ref'] ?? '')),
        );
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        $landedMergeHash = $this->mergeHash;
        $branchRef = $this->branchRef;
        $crossLinkReady = $landedMergeHash !== '' && $branchRef !== '';

        return [
            'schema_version' => self::SCHEMA,
            'contract_id' => self::CONTRACT_ID,
            'finding_id' => self::FINDING_ID,
            'gap_matrix_canonical' => self::GAP_MATRIX_CANONICAL,
            'evidence_pack_schema' => self::EVIDENCE_PACK_SCHEMA,
            'area_id' => $this->areaId,
            'focus' => $this->focus,
            'inputs' => [
                'cycle_id' => $this->cycleId,
                'merge_hash' => $this->mergeHash,
                'branch_ref' => $this->branchRef,
            ],
            'outputs' => [
                'landed_merge_hash' => $landedMergeHash,
                'branch_ref' => $branchRef,
                'merge_hash_cross_link_ready' => $crossLinkReady,
                'provably_tied_to_git_commit' => $crossLinkReady,
            ],
        ];
    }
}
