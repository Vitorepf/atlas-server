<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Discovery\Cortex\Memory;

/**
 * FACT-only analog retrieval over the EpisodicLedger.
 *
 * Given one current FACT, returns prior episodes whose exact dimensions match. Matching is set
 * membership on the exact fields the FACT carries — NEVER fuzzy similarity, NEVER embedding,
 * NEVER a learned "usefulness" score. Rank is reverse-chronological proximity (last_seen first).
 * If no episode matches, the result is an empty list — not a guess.
 *
 * Accepted current FACT shapes:
 *
 *   {kind:'inventory_item', item_id:string, fingerprint:string}
 *   {kind:'blind_spot',     gap_id:string,  kind:string}                  // sub-kind under the outer kind
 *   {kind:'intent',         intent_id:string, canonical_form:string}
 *
 * The returned rows carry:
 *
 *   {episode_cycle_id, captured_at, match_dimensions:[exact_id|exact_fingerprint|exact_canonical_form], matched_payload}
 */
final class AtlasCortexMemoryRetrievalService
{
    public function __construct(private readonly AtlasCortexMemoryEpisodicLedger $ledger) {}

    /**
     * @param  array<string,mixed>  $currentFact
     * @return list<array{episode_cycle_id:string, captured_at:int, match_dimensions:list<string>, matched_payload:array<string,mixed>}>
     */
    public function retrieve(array $currentFact, int $limit = 10): array
    {
        if ($limit < 1) {
            return [];
        }

        $kind = (string) ($currentFact['kind'] ?? '');
        $matches = match ($kind) {
            'inventory_item' => $this->matchInventoryItem($currentFact),
            'blind_spot' => $this->matchBlindSpot($currentFact),
            'intent' => $this->matchIntent($currentFact),
            default => [],
        };

        // Reverse-chronological proximity: last_seen (highest captured_at) first.
        // Tie-break by cycle_id lex DESC so two reads on a frozen ledger are byte-identical.
        usort($matches, static function (array $a, array $b): int {
            return $b['captured_at'] <=> $a['captured_at']
                ?: strcmp((string) $b['episode_cycle_id'], (string) $a['episode_cycle_id']);
        });

        return array_slice($matches, 0, $limit);
    }

    /**
     * @param  array<string,mixed>  $fact
     * @return list<array{episode_cycle_id:string, captured_at:int, match_dimensions:list<string>, matched_payload:array<string,mixed>}>
     */
    private function matchInventoryItem(array $fact): array
    {
        $itemId = (string) ($fact['item_id'] ?? '');
        $fingerprint = (string) ($fact['fingerprint'] ?? '');
        if ($itemId === '' || $fingerprint === '') {
            return [];
        }

        $out = [];
        foreach ($this->ledger->iterate() as $row) {
            foreach ((array) ($row['inventory_items'] ?? []) as $item) {
                if (! is_array($item)) {
                    continue;
                }
                if ((string) ($item['item_id'] ?? '') === $itemId && (string) ($item['fingerprint'] ?? '') === $fingerprint) {
                    $out[] = [
                        'episode_cycle_id' => (string) ($row['cycle_id'] ?? ''),
                        'captured_at' => (int) ($row['captured_at'] ?? 0),
                        'match_dimensions' => ['exact_id', 'exact_fingerprint'],
                        'matched_payload' => $item,
                    ];

                    break; // one hit per episode
                }
            }
        }

        return $out;
    }

    /**
     * @param  array<string,mixed>  $fact
     * @return list<array{episode_cycle_id:string, captured_at:int, match_dimensions:list<string>, matched_payload:array<string,mixed>}>
     */
    private function matchBlindSpot(array $fact): array
    {
        $gapId = (string) ($fact['gap_id'] ?? '');
        $subKind = (string) ($fact['kind'] ?? '');
        // The inner kind here is the sub-kind under the outer 'kind:blind_spot'. If the caller passed
        // 'kind:blind_spot' as the outer envelope they may also pass a 'gap_kind' alias for clarity.
        if ($subKind === 'blind_spot') {
            $subKind = (string) ($fact['gap_kind'] ?? '');
        }
        if ($gapId === '') {
            return [];
        }

        $out = [];
        foreach ($this->ledger->iterate() as $row) {
            foreach ((array) ($row['blind_spots'] ?? []) as $spot) {
                if (! is_array($spot)) {
                    continue;
                }
                $idMatch = (string) ($spot['gap_id'] ?? '') === $gapId;
                $kindMatch = $subKind === '' || (string) ($spot['kind'] ?? '') === $subKind;
                if ($idMatch && $kindMatch) {
                    $out[] = [
                        'episode_cycle_id' => (string) ($row['cycle_id'] ?? ''),
                        'captured_at' => (int) ($row['captured_at'] ?? 0),
                        'match_dimensions' => ['exact_id'],
                        'matched_payload' => $spot,
                    ];

                    break;
                }
            }
        }

        return $out;
    }

    /**
     * @param  array<string,mixed>  $fact
     * @return list<array{episode_cycle_id:string, captured_at:int, match_dimensions:list<string>, matched_payload:array<string,mixed>}>
     */
    private function matchIntent(array $fact): array
    {
        $intentId = (string) ($fact['intent_id'] ?? '');
        $canonical = (string) ($fact['canonical_form'] ?? '');
        if ($intentId === '' || $canonical === '') {
            return [];
        }

        $out = [];
        foreach ($this->ledger->iterate() as $row) {
            foreach ((array) ($row['intent_interpretations'] ?? []) as $intent) {
                if (! is_array($intent)) {
                    continue;
                }
                if ((string) ($intent['intent_id'] ?? '') === $intentId && (string) ($intent['canonical_form'] ?? '') === $canonical) {
                    $out[] = [
                        'episode_cycle_id' => (string) ($row['cycle_id'] ?? ''),
                        'captured_at' => (int) ($row['captured_at'] ?? 0),
                        'match_dimensions' => ['exact_id', 'exact_canonical_form'],
                        'matched_payload' => $intent,
                    ];

                    break;
                }
            }
        }

        return $out;
    }
}
