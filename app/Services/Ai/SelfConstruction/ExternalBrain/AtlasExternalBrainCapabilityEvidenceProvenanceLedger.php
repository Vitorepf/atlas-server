<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Pure provenance ledger — makes every claimed brain CAPABILITY traceable to its evidence TYPE,
 * FRESHNESS, and DOWNSTREAM USE, so the brain can never treat a stale doc, a raw queue count, or an
 * unverified screenshot as equivalent to runnable proof.
 *
 * INPUT per capability:
 *   { capability_id, evidence:list<{type:string, age_days:int, source_surface?:string}>,
 *     downstream_uses?:list<string> }
 *
 * evidence `type` values (strongest → weakest provenance):
 *   runnable_test_or_gate > docs_only > screenshot > raw_queue_count > (unknown)
 *
 * OUTPUT:
 *   { schema, capability_id, evidence_tier, freshness_status, confidence, refresh_required,
 *     leverage_proven }
 *
 * leverage_proven: 'proven' when downstream_uses is non-empty, else 'unproven_for_leverage' — a
 * capability with evidence but NO downstream consumer is not yet real leverage.
 *
 * Pure: no I/O, no clock reads — age_days is supplied as a fact, not computed from wall time.
 */
final class AtlasExternalBrainCapabilityEvidenceProvenanceLedger
{
    public const SCHEMA = 'atlas.self_construction.external_brain.capability_evidence_provenance_ledger.v1';

    public const TIER_RUNNABLE_PROOF = 'runnable_proof';

    public const TIER_DOCS_ONLY = 'docs_only';

    public const TIER_SCREENSHOT = 'screenshot';

    public const TIER_RAW_QUEUE_COUNT = 'raw_queue_count';

    public const TIER_NO_EVIDENCE = 'no_evidence';

    public const FRESHNESS_FRESH = 'fresh';

    public const FRESHNESS_STALE = 'stale';

    public const FRESHNESS_UNKNOWN = 'unknown';

    public const LEVERAGE_PROVEN = 'proven';

    public const LEVERAGE_UNPROVEN = 'unproven_for_leverage';

    public const STALE_THRESHOLD_DAYS = 30;

    /** evidence type → [tier constant, rank (lower = stronger)]. */
    private const TYPE_TIER_RANK = [
        'runnable_test_or_gate' => [self::TIER_RUNNABLE_PROOF, 0],
        'docs_only' => [self::TIER_DOCS_ONLY, 1],
        'screenshot' => [self::TIER_SCREENSHOT, 2],
        'raw_queue_count' => [self::TIER_RAW_QUEUE_COUNT, 3],
    ];

    /** evidence tier → base confidence before freshness downgrade. */
    private const TIER_BASE_CONFIDENCE = [
        self::TIER_RUNNABLE_PROOF => 'high',
        self::TIER_DOCS_ONLY => 'medium',
        self::TIER_SCREENSHOT => 'low',
        self::TIER_RAW_QUEUE_COUNT => 'very_low',
        self::TIER_NO_EVIDENCE => 'none',
    ];

    /** one-notch downgrade applied when the chosen evidence is stale. */
    private const CONFIDENCE_DOWNGRADE = [
        'high' => 'medium',
        'medium' => 'low',
        'low' => 'very_low',
        'very_low' => 'very_low',
        'none' => 'none',
    ];

    /**
     * @param  array<string,mixed>  $capability
     * @return array{schema:string, capability_id:string, evidence_tier:string, freshness_status:string,
     *     confidence:string, refresh_required:bool, leverage_proven:string}
     */
    public function assess(array $capability): array
    {
        $id = (string) ($capability['capability_id'] ?? '');
        $evidenceList = is_array($capability['evidence'] ?? null) ? $capability['evidence'] : [];
        $downstreamUses = array_values(array_map('strval', (array) ($capability['downstream_uses'] ?? [])));

        $strongest = $this->strongestEvidence($evidenceList);

        if ($strongest === null) {
            $tier = self::TIER_NO_EVIDENCE;
            $freshness = self::FRESHNESS_UNKNOWN;
            $confidence = self::TIER_BASE_CONFIDENCE[self::TIER_NO_EVIDENCE];
            $refreshRequired = false;
        } else {
            $tier = $strongest['tier'];
            $ageDays = (int) ($strongest['age_days'] ?? 0);
            $stale = $ageDays > self::STALE_THRESHOLD_DAYS;
            $freshness = $stale ? self::FRESHNESS_STALE : self::FRESHNESS_FRESH;
            $baseConfidence = self::TIER_BASE_CONFIDENCE[$tier];
            $confidence = $stale ? self::CONFIDENCE_DOWNGRADE[$baseConfidence] : $baseConfidence;
            $refreshRequired = $stale;
        }

        return [
            'schema' => self::SCHEMA,
            'capability_id' => $id,
            'evidence_tier' => $tier,
            'freshness_status' => $freshness,
            'confidence' => $confidence,
            'refresh_required' => $refreshRequired,
            'leverage_proven' => $downstreamUses !== [] ? self::LEVERAGE_PROVEN : self::LEVERAGE_UNPROVEN,
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $evidenceList
     * @return array{tier:string, age_days:int}|null
     */
    private function strongestEvidence(array $evidenceList): ?array
    {
        $best = null;
        $bestRank = PHP_INT_MAX;

        foreach ($evidenceList as $e) {
            if (! is_array($e)) {
                continue;
            }
            $type = (string) ($e['type'] ?? '');
            [$tier, $rank] = self::TYPE_TIER_RANK[$type] ?? [self::TIER_RAW_QUEUE_COUNT, 99];
            if ($rank < $bestRank) {
                $bestRank = $rank;
                $best = ['tier' => $tier, 'age_days' => (int) ($e['age_days'] ?? 0)];
            }
        }

        return $best;
    }
}
