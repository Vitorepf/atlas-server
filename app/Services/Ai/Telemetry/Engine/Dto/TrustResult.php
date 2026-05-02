<?php

namespace App\Services\Ai\Telemetry\Engine\Dto;

/**
 * Output of TrustGateService::evaluate().
 *
 * The 3 primitives the spine consumes (per Architecture Conductor contract):
 *   - trustScore (float 0.0-1.0)
 *   - coverage (float 0.0-1.0)
 *   - usableForAttribution (bool)
 *
 * Plus richer context for the report and downstream layers:
 *   - trustLevel: human-readable band ('insufficient' < 0.40, 'low' < 0.60,
 *                 'moderate' < 0.80, 'sufficient' >= 0.80)
 *   - dimensions: per-dimension usable_for_attribution map consumed by Agent 4
 *   - topGaps: actionable gap list seeded into Agent 5 as recommendations
 *   - skipped: true when fail-open path engaged (Decisão #12)
 *
 * Per Decisão #5: cost confidence uses TRACE FRACTION (recorded in audit_trail
 * for transparency).
 *
 * Per Decisão #12: when computation fails or the audit table is unavailable,
 * a TrustResult with skipped=true is returned (trust assumed safe to keep the
 * report flowing). Downstream layers can optionally honor or ignore.
 */
final readonly class TrustResult
{
    /**
     * @param  array<string,bool>  $dimensions  per-dimension usable_for_attribution flags
     *                                          (provider, model, agent_slug, task_type, ...)
     * @param  array<int,array{dimension:string,gap:string,action:string}>  $topGaps
     * @param  array<string,float>  $scoreComponents  {volume, coverage, cost_confidence, version_purity}
     * @param  array<string,mixed>  $auditTrail  raw evaluation details for debug/persistence
     */
    public function __construct(
        public float $trustScore,
        public float $coverage,
        public bool $usableForAttribution,
        public string $trustLevel,
        public string $aggregatorVersion,
        public bool $mixedAggregatorVersions,
        public int $sampleCount,
        public array $dimensions,
        public array $topGaps,
        public array $scoreComponents,
        public array $auditTrail,
        public bool $skipped = false,
        public ?string $skipReason = null,
    ) {}

    /**
     * Fail-open factory — used when computation cannot proceed (table missing,
     * exception during evaluate). Returns a result that DOES NOT block the
     * report. Trust-all per Decisão #12.
     */
    public static function skipped(string $reason, ?int $sampleCount = null): self
    {
        return new self(
            trustScore: 0.5,                          // neutral default — neither high nor low
            coverage: 0.0,
            usableForAttribution: true,               // fail-open: don't block downstream
            trustLevel: 'low',                        // honest about the absence of measurement
            aggregatorVersion: 'unknown',
            mixedAggregatorVersions: false,
            sampleCount: $sampleCount ?? 0,
            dimensions: [],
            topGaps: [],
            scoreComponents: [],
            auditTrail: ['skip_reason' => $reason],
            skipped: true,
            skipReason: $reason,
        );
    }
}
