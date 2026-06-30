<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Pure context distiller. Compacts a full context pack into a budget-constrained
 * high-signal subset for smaller model consumption.
 *
 * Retention priority (higher tier fills budget first):
 *   Tier 1: canonical_decision, ac_definition, anti_proxy_rule,
 *            queue_constraint, target_path              (safety-critical — never drop)
 *   Tier 2: recent_failure                             (retain if budget allows)
 *   Tier 3/4: non-critical sections ranked by composite score:
 *             signal * 0.5 + freshness_score * 0.3 + unblock_value * 0.2
 *             (min composite ≥ 0.50 to be eligible)
 *
 * Stale duplicate collapsing (AC2):
 *   Sections sharing the same canonical_key (defaults to type) are grouped.
 *   The highest-ranked copy is retained; the rest are collapsed into a
 *   canonical summary with provenance_count. The collapsed group is recorded
 *   in duplicate_canonical_summaries.
 *
 * Always omitted:
 *   - type in ['provider_trace', 'stale_summary']
 *   - is_stale === true AND is_duplicate !== true (standalone stale)
 *
 * risk_of_loss:
 *   high   — any Tier-1 section omitted due to budget exhaustion.
 *   medium — any Tier-2 section omitted due to budget exhaustion.
 *   low    — otherwise.
 *
 * Pure, deterministic, no providers, no I/O.
 */
final class AtlasExternalBrainContextBudgetDistiller
{
    public const SCHEMA = 'atlas.external_brain.context_budget_distiller.v1';

    private const DEFAULT_BUDGET = 4000;

    private const ALWAYS_DROP = ['provider_trace', 'stale_summary'];

    private const TIER1 = ['canonical_decision', 'ac_definition', 'anti_proxy_rule', 'queue_constraint', 'target_path'];
    private const TIER2 = ['recent_failure'];

    private const MIN_COMPOSITE = 0.50;

    /**
     * Composite rank: relevance (signal) + freshness + unblock value.
     * Used for Tier 3/4 ordering so smaller models get the most unblocking context first.
     */
    private function compositeRank(float $signal, float $freshness, float $unblock): float
    {
        return $signal * 0.5 + $freshness * 0.3 + $unblock * 0.2;
    }

    /**
     * @param  array<string,mixed>  $facts
     * @return array<string,mixed>
     */
    public function distill(array $facts): array
    {
        $sections = is_array($facts['context_sections'] ?? null) ? $facts['context_sections'] : [];
        $budget   = max(1, (int) ($facts['budget_tokens'] ?? self::DEFAULT_BUDGET));

        $retained                   = [];
        $omitted                    = [];
        $budgetUsed                 = 0;
        $tier1Omitted               = false;
        $tier2Omitted               = false;
        $duplicateCanonicalSummaries = [];

        // Phase 1: parse all sections, collapse stale duplicates by canonical_key.
        $duplicateGroups = [];  // canonical_key → [best_candidate, provenance_count]
        $classified      = [];

        foreach ($sections as $sec) {
            $id           = (string) ($sec['id']              ?? '');
            $type         = strtolower(trim((string) ($sec['type']         ?? 'other')));
            $content      = (string) ($sec['content']         ?? '');
            $signal       = max(0.0, min(1.0, (float) ($sec['signal_score']   ?? 0.0)));
            $freshness    = max(0.0, min(1.0, (float) ($sec['freshness_score'] ?? 0.5)));
            $unblock      = max(0.0, min(1.0, (float) ($sec['unblock_value']   ?? 0.0)));
            $isDuplicate  = (bool) ($sec['is_duplicate']  ?? false);
            $isStale      = (bool) ($sec['is_stale']      ?? false);
            $tokens       = max(0, (int) ($sec['token_count'] ?? 0));
            $canonicalKey = trim((string) ($sec['canonical_key'] ?? $type));

            // Always-drop: forbidden types.
            if (in_array($type, self::ALWAYS_DROP, true)) {
                $omitted[] = ['id' => $id, 'type' => $type, 'reason' => 'always_dropped_type'];
                continue;
            }

            // Stale duplicates → collapse by canonical_key (AC2).
            if ($isDuplicate) {
                $rank = $this->compositeRank($signal, $freshness, $unblock);
                if (! array_key_exists($canonicalKey, $duplicateGroups)
                    || $rank > $duplicateGroups[$canonicalKey]['rank']) {
                    $duplicateGroups[$canonicalKey] = compact('id', 'type', 'content', 'signal', 'freshness', 'unblock', 'tokens', 'rank', 'canonicalKey');
                    $duplicateGroups[$canonicalKey]['provenance_count'] = 1;
                } else {
                    $duplicateGroups[$canonicalKey]['provenance_count']++;
                }
                continue;
            }

            // Standalone stale (non-duplicate) → always drop.
            if ($isStale) {
                $omitted[] = ['id' => $id, 'type' => $type, 'reason' => 'stale'];
                continue;
            }

            $rank         = $this->compositeRank($signal, $freshness, $unblock);
            $classified[] = compact('id', 'type', 'content', 'signal', 'freshness', 'unblock', 'tokens', 'rank');
        }

        // Promote collapsed duplicate winners into classified (with their provenance metadata).
        // Every group — even a singleton — is recorded in duplicate_canonical_summaries. But a
        // singleton "duplicate" (no sibling sharing the canonical_key) has nothing to collapse
        // into — it is just a stale copy with no surviving original, so it is omitted outright
        // rather than promoted into classified for tiered retention.
        foreach ($duplicateGroups as $key => $best) {
            $duplicateCanonicalSummaries[] = [
                'canonical_key'   => $key,
                'retained_id'     => $best['id'],
                'provenance_count' => $best['provenance_count'],
            ];

            if ($best['provenance_count'] <= 1) {
                $omitted[] = ['id' => $best['id'], 'type' => $best['type'], 'reason' => 'duplicate'];
                continue;
            }
            $classified[] = $best;
        }

        // Phase 2: fill budget by tier then by composite rank.
        $placed    = [];

        $tierDefs = [
            ['types' => self::TIER1, 'label' => 't1'],
            ['types' => self::TIER2, 'label' => 't2'],
        ];

        foreach ($tierDefs as $tier) {
            $tierSections = array_filter($classified, static fn ($s) => in_array($s['type'], $tier['types'], true));
            foreach ($tierSections as $s) {
                if ($budgetUsed + $s['tokens'] <= $budget) {
                    $retained[]  = ['id' => $s['id'], 'type' => $s['type'], 'token_count' => $s['tokens'], 'content' => $s['content']];
                    $budgetUsed += $s['tokens'];
                } else {
                    if ($tier['label'] === 't1') {
                        $tier1Omitted = true;
                    } else {
                        $tier2Omitted = true;
                    }
                    $omitted[] = ['id' => $s['id'], 'type' => $s['type'], 'reason' => 'budget_exhausted'];
                }
                $placed[$s['id']] = true;
            }
        }

        // Tier 3/4: non-tiered sections sorted by composite rank (AC1).
        $rest = array_values(array_filter($classified, static fn ($s) => ! isset($placed[$s['id']])));
        usort($rest, static fn ($a, $b) => $b['rank'] <=> $a['rank']);

        foreach ($rest as $s) {
            if ($s['rank'] < self::MIN_COMPOSITE) {
                $omitted[] = ['id' => $s['id'], 'type' => $s['type'], 'reason' => 'low_composite_rank'];
                continue;
            }
            if ($budgetUsed + $s['tokens'] <= $budget) {
                $retained[] = ['id' => $s['id'], 'type' => $s['type'], 'token_count' => $s['tokens'], 'content' => $s['content']];
                $budgetUsed += $s['tokens'];
            } else {
                $omitted[] = ['id' => $s['id'], 'type' => $s['type'], 'reason' => 'budget_exhausted'];
            }
        }

        $distilledContext = implode("\n\n", array_column($retained, 'content'));

        $retainedPublic = array_map(
            static fn ($r) => ['id' => $r['id'], 'type' => $r['type'], 'token_count' => $r['token_count']],
            $retained
        );

        $risk = 'low';
        if ($tier1Omitted) {
            $risk = 'high';
        } elseif ($tier2Omitted) {
            $risk = 'medium';
        }

        $inputTypes = array_unique(array_map(
            static fn ($s) => strtolower(trim((string) ($s['type'] ?? ''))),
            $sections
        ));
        $missingCritical = array_values(array_filter(
            self::TIER1,
            static fn (string $t) => ! in_array($t, $inputTypes, true),
        ));

        $lossReport = $missingCritical === []
            ? "risk_of_loss={$risk}: all tier-1 safety sections present in input."
            : "risk_of_loss={$risk}: missing tier-1 canonical sections: ".implode(', ', $missingCritical).'.';

        return [
            'schema_version'               => self::SCHEMA,
            'distilled_context'            => $distilledContext,
            'retained_sections'            => $retainedPublic,
            'omitted_sections'             => $omitted,
            'budget_used'                  => $budgetUsed,
            'risk_of_loss'                 => $risk,
            'missing_critical_sections'    => $missingCritical,
            'duplicate_canonical_summaries' => $duplicateCanonicalSummaries,
            // Canonical AC1 field names — aliases of the fields above, kept for callers using the
            // literal contract wording (retained/omitted/loss_report/required_expansion_handles).
            'retained'                     => $retainedPublic,
            'omitted'                      => $omitted,
            'loss_report'                  => $lossReport,
            'required_expansion_handles'   => $missingCritical,
        ];
    }
}
