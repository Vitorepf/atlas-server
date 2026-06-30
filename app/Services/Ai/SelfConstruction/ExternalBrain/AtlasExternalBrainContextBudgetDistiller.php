<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Pure context distiller. Compacts a full context pack into a budget-constrained
 * high-signal subset for smaller model consumption.
 *
 * Retention priority (AC2 — higher tier fills budget first):
 *   Tier 1: canonical_decision, ac_definition, anti_proxy_rule,
 *            queue_constraint, target_path              (never drop unless duplicate)
 *   Tier 2: recent_failure                             (retain if budget allows)
 *   Tier 3: non-critical sections with signal >= 0.70
 *   Tier 4: non-critical sections with signal >= 0.50
 *
 * Always omitted (AC3):
 *   - type in ['provider_trace', 'stale_summary']
 *   - is_stale === true
 *   - is_duplicate === true
 *
 * risk_of_loss (AC4):
 *   high   — any Tier-1 section omitted due to budget exhaustion.
 *   medium — any Tier-2 section omitted due to budget exhaustion.
 *   low    — otherwise.
 *
 * AC4 outputs: distilled_context, retained_sections, omitted_sections,
 *   budget_used, risk_of_loss.
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

    private const HIGH_SIGNAL  = 0.70;
    private const MED_SIGNAL   = 0.50;

    /**
     * @param  array<string,mixed>  $facts
     * @return array<string,mixed>
     */
    public function distill(array $facts): array
    {
        $sections = is_array($facts['context_sections'] ?? null) ? $facts['context_sections'] : [];
        $budget   = max(1, (int) ($facts['budget_tokens'] ?? self::DEFAULT_BUDGET));

        $retained        = [];
        $omitted         = [];
        $budgetUsed      = 0;
        $tier1Omitted    = false;
        $tier2Omitted    = false;

        // Pre-classify each section.
        $classified = [];
        foreach ($sections as $sec) {
            $id         = (string) ($sec['id']           ?? '');
            $type       = strtolower(trim((string) ($sec['type']        ?? 'other')));
            $content    = (string) ($sec['content']      ?? '');
            $signal     = max(0.0, min(1.0, (float) ($sec['signal_score'] ?? 0.0)));
            $isDuplicate = (bool) ($sec['is_duplicate']  ?? false);
            $isStale    = (bool) ($sec['is_stale']       ?? false);
            $tokens     = max(0, (int) ($sec['token_count'] ?? 0));

            // Always-drop conditions (AC3).
            if (in_array($type, self::ALWAYS_DROP, true)) {
                $omitted[]  = ['id' => $id, 'type' => $type, 'reason' => 'always_dropped_type'];
                continue;
            }
            if ($isStale) {
                $omitted[] = ['id' => $id, 'type' => $type, 'reason' => 'stale'];
                continue;
            }
            if ($isDuplicate) {
                $omitted[] = ['id' => $id, 'type' => $type, 'reason' => 'duplicate'];
                continue;
            }

            $classified[] = compact('id', 'type', 'content', 'signal', 'tokens');
        }

        // Build ordered candidate list by tier.
        $tiers = [
            ['types' => self::TIER1, 'label' => 't1'],
            ['types' => self::TIER2, 'label' => 't2'],
        ];

        $remaining = $classified;
        $placed    = [];

        foreach ($tiers as $tier) {
            $tierSections = array_filter($remaining, static fn ($s) => in_array($s['type'], $tier['types'], true));
            foreach ($tierSections as $s) {
                if ($budgetUsed + $s['tokens'] <= $budget) {
                    $retained[]  = ['id' => $s['id'], 'type' => $s['type'], 'token_count' => $s['tokens'], 'content' => $s['content']];
                    $budgetUsed += $s['tokens'];
                } else {
                    if ($tier['label'] === 't1') {
                        $tier1Omitted = true;
                    } elseif ($tier['label'] === 't2') {
                        $tier2Omitted = true;
                    }
                    $omitted[] = ['id' => $s['id'], 'type' => $s['type'], 'reason' => 'budget_exhausted'];
                }
                $placed[$s['id']] = true;
            }
        }

        // Tier 4 and 5: non-tiered sections by signal.
        $rest = array_filter($remaining, static fn ($s) => ! isset($placed[$s['id']]));
        usort($rest, static fn ($a, $b) => $b['signal'] <=> $a['signal']);

        foreach ($rest as $s) {
            if ($s['signal'] < self::MED_SIGNAL) {
                $omitted[] = ['id' => $s['id'], 'type' => $s['type'], 'reason' => 'low_signal'];
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

        // Strip content from public output.
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

        // Types present in input (pre-drop) — to identify truly absent critical sections.
        $inputTypes = array_unique(array_map(
            static fn ($s) => strtolower(trim((string) ($s['type'] ?? ''))),
            $sections
        ));
        $missingCritical = array_values(array_filter(
            self::TIER1,
            static fn (string $t) => ! in_array($t, $inputTypes, true),
        ));

        return [
            'schema_version'          => self::SCHEMA,
            'distilled_context'       => $distilledContext,
            'retained_sections'       => $retainedPublic,
            'omitted_sections'        => $omitted,
            'budget_used'             => $budgetUsed,
            'risk_of_loss'            => $risk,
            'missing_critical_sections' => $missingCritical,
        ];
    }
}
