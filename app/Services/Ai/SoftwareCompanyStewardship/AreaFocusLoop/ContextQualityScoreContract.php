<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\Context\AtlasContextQualityCertificationService;

/**
 * Minimal data contract for the context quality score input seam in
 * {@see StewardshipPriorityEngineService}. Step 1 of 3: shape only — no
 * priority engine wiring in this class.
 *
 * Boosts findings tagged {@see self::FINDING_KIND_CONTEXT_MEMORY_RETRIEVAL_GAP}
 * when {@see AtlasContextQualityCertificationService} reports degraded quality.
 */
final class ContextQualityScoreContract
{
    public const SCHEMA = 'atlas.software_company_stewardship.context_quality_score.v1';

    public const GAP_MATRIX_CANONICAL = 'docs/engineering-knowledge-base/atlas-agentic-engineering-os-runtime-gap-matrix.md';

    public const CERTIFICATION_SCHEMA = AtlasContextQualityCertificationService::SCHEMA_VERSION;

    public const FINDING_KIND_CONTEXT_MEMORY_RETRIEVAL_GAP = 'context_memory_retrieval_gap';

    /** Matches {@see AtlasContextQualityCertificationService} default target floor. */
    public const DEFAULT_TARGET_SCORE = 9.8;

    /** Priority boost applied when degraded context quality meets a context gap finding. */
    public const PRIORITY_BOOST_WHEN_DEGRADED = 25;

    private function __construct(
        public readonly string $areaId,
        public readonly string $focus,
        public readonly float $certificationQualityScore,
        public readonly float $certificationTargetScore,
        public readonly string $certificationStatus,
        public readonly string $findingKind,
    ) {}

    public static function defaults(
        string $areaId = 'agentic_engineering_os',
        string $focus = 'dev_forge',
    ): self {
        return new self(
            areaId: $areaId,
            focus: $focus,
            certificationQualityScore: self::DEFAULT_TARGET_SCORE,
            certificationTargetScore: self::DEFAULT_TARGET_SCORE,
            certificationStatus: 'ready',
            findingKind: '',
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
            certificationQualityScore: self::clampScore((float) ($input['certification_quality_score'] ?? self::DEFAULT_TARGET_SCORE)),
            certificationTargetScore: self::clampScore((float) ($input['certification_target_score'] ?? self::DEFAULT_TARGET_SCORE)),
            certificationStatus: strtolower(trim((string) ($input['certification_status'] ?? 'ready'))),
            findingKind: trim((string) ($input['finding_kind'] ?? '')),
        );
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        $degraded = $this->certificationStatus === 'blocked'
            || $this->certificationQualityScore < $this->certificationTargetScore;
        $boostsContextGapFinding = $degraded
            && $this->findingKind === self::FINDING_KIND_CONTEXT_MEMORY_RETRIEVAL_GAP;
        $priorityBoostPoints = $boostsContextGapFinding ? self::PRIORITY_BOOST_WHEN_DEGRADED : 0;

        return [
            'schema_version' => self::SCHEMA,
            'certification_schema' => self::CERTIFICATION_SCHEMA,
            'finding_kind_context_memory_retrieval_gap' => self::FINDING_KIND_CONTEXT_MEMORY_RETRIEVAL_GAP,
            'default_target_score' => self::DEFAULT_TARGET_SCORE,
            'priority_boost_when_degraded' => self::PRIORITY_BOOST_WHEN_DEGRADED,
            'gap_matrix_canonical' => self::GAP_MATRIX_CANONICAL,
            'area_id' => $this->areaId,
            'focus' => $this->focus,
            'inputs' => [
                'certification_quality_score' => $this->certificationQualityScore,
                'certification_target_score' => $this->certificationTargetScore,
                'certification_status' => $this->certificationStatus,
                'finding_kind' => $this->findingKind,
            ],
            'outputs' => [
                'context_quality_score' => $this->certificationQualityScore,
                'context_quality_degraded' => $degraded,
                'boosts_context_memory_retrieval_gap_finding' => $boostsContextGapFinding,
                'priority_boost_points' => $priorityBoostPoints,
            ],
        ];
    }

    private static function clampScore(float $score): float
    {
        return max(0.0, min(10.0, $score));
    }
}
