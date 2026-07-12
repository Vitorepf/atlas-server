<?php

declare(strict_types=1);

namespace App\Services\Ai\Context;

/**
 * ESP-12 — five-layer epistemic evidence bundle.
 *
 * This is a pure pack composer: no DB, no provider, no retrieval pass. It
 * carries the ESP-11 agenda, RAGX-08 span refs, and MAXE-06/MULTH-06 lineage as
 * a report-only bundle so callers can cite exact claim/span/content versions
 * without turning policy into evidence.
 */
final class EpistemicEvidenceBundleComposer
{
    public const SCHEMA_VERSION = 'atlas.aobg.epistemic_evidence_bundle.v1';

    public const FORMULA_VERSION = 'atlas.esp_12.epistemic_evidence_bundle.v1';

    public const MAX_MUST_CARRY_REFS = 3;

    private const MAX_NOVELTY_REFS = 5;

    /** @param array<string,mixed> $pack */
    public function compose(array $pack): array
    {
        $claims = $this->listOfArrays(data_get($pack, 'retrieval_agenda.claims', []));
        $spanClaims = $this->listOfArrays(data_get($pack, 'span_level_retrieval.claims', []));
        $counterSlots = $this->listOfArrays(data_get($pack, 'retrieval_agenda.counter_evidence_slots', []));
        $empty = [
            'schema_version' => self::SCHEMA_VERSION,
            'formula_version' => self::FORMULA_VERSION,
            'present' => false,
            'layers' => [],
            'must_carry' => ['refs' => [], 'status' => 'no_claims'],
            'novelty_pool' => ['refs' => [], 'status' => 'no_claims'],
            'operator_policy' => $this->operatorPolicy(),
            'counter_evidence' => $this->counterEvidence($counterSlots),
            'claim_citations' => ['status' => 'no_claims', 'items' => []],
            'source' => $this->sourceMeta(),
        ];

        if ($claims === [] && $spanClaims === [] && $counterSlots === []) {
            return $empty;
        }

        $claimCitations = $this->claimCitations($spanClaims);
        $mustCarry = $this->mustCarry($claimCitations);
        $noveltyPool = $this->noveltyPool($pack, $mustCarry['refs']);
        $operatorPolicy = $this->operatorPolicy();
        $counterEvidence = $this->counterEvidence($counterSlots);

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'formula_version' => self::FORMULA_VERSION,
            'present' => true,
            'layers' => [
                'must_carry' => $mustCarry,
                'novelty_pool' => $noveltyPool,
                'operator_policy' => $operatorPolicy,
                'counter_evidence' => $counterEvidence,
                'claim_citations' => $claimCitations,
            ],
            'must_carry' => $mustCarry,
            'novelty_pool' => $noveltyPool,
            'operator_policy' => $operatorPolicy,
            'counter_evidence' => $counterEvidence,
            'claim_citations' => $claimCitations,
            'source' => $this->sourceMeta(),
        ];
    }

    /**
     * @param  array<string,mixed>  $claimCitations
     * @return array{refs:array<int,string>,status:string,cap:int}
     */
    private function mustCarry(array $claimCitations): array
    {
        $refs = [];
        foreach ($claimCitations['items'] ?? [] as $item) {
            if (! is_array($item)) {
                continue;
            }
            foreach ((array) ($item['citations'] ?? []) as $citation) {
                if (! is_array($citation)) {
                    continue;
                }
                $ref = trim((string) ($citation['span_ref'] ?? ''));
                if (AtlasCanonicalContextRef::isSpanRef($ref)) {
                    $refs[] = $ref;
                }
            }
        }
        $refs = array_slice(AtlasCanonicalContextRef::uniqueStrings($refs), 0, self::MAX_MUST_CARRY_REFS);

        return [
            'refs' => $refs,
            'status' => $refs === [] ? 'no_claim_spans' : 'capped',
            'cap' => self::MAX_MUST_CARRY_REFS,
        ];
    }

    /**
     * @param  array<string,mixed>  $pack
     * @param  array<int,string>  $mustCarryRefs
     * @return array<string,mixed>
     */
    private function noveltyPool(array $pack, array $mustCarryRefs): array
    {
        $seen = $this->seenRefs($pack);
        $excluded = array_fill_keys(array_merge($seen, $mustCarryRefs), true);
        $novel = [];
        foreach (AtlasCanonicalContextRef::deliveredFromPack($pack) as $ref) {
            if (isset($excluded[$ref])) {
                continue;
            }
            $novel[] = $ref;
        }
        $novel = array_slice(AtlasCanonicalContextRef::uniqueStrings($novel), 0, self::MAX_NOVELTY_REFS);

        return [
            'refs' => $novel,
            'status' => $novel === [] ? 'empty_honest' : 'candidate_refs',
            'lineage' => $this->lineage($pack),
            'seen_ref_count' => count($seen),
            'cap' => self::MAX_NOVELTY_REFS,
        ];
    }

    /** @return array<string,mixed> */
    private function operatorPolicy(): array
    {
        return [
            'layer' => 'operator_policy',
            'mode' => 'report_only',
            'evidence_layer' => false,
            'separate_from_evidence' => true,
            'rule' => 'Use must_carry refs for claims; inspect CONTRAEVIDENCIA before asserting.',
        ];
    }

    /**
     * @param  array<int,array<string,mixed>>  $slots
     * @return array<string,mixed>
     */
    private function counterEvidence(array $slots): array
    {
        $normalized = [];
        foreach ($slots as $slot) {
            $normalized[] = [
                'claim' => (string) ($slot['claim'] ?? ''),
                'source' => (string) ($slot['source'] ?? 'reality_graph'),
                'query' => (string) ($slot['query'] ?? data_get($slot, 'source_query.query', '')),
                'refs_against' => AtlasCanonicalContextRef::uniqueStrings((array) ($slot['refs_against'] ?? [])),
                'refs_found' => (int) ($slot['refs_found'] ?? count((array) ($slot['refs_against'] ?? []))),
                'status' => (string) ($slot['status'] ?? 'empty_honest'),
            ];
        }

        return [
            'label' => 'CONTRAEVIDÊNCIA',
            'slots' => $normalized,
            'status' => $normalized === [] ? 'no_counter_evidence_slots' : 'slots_present',
        ];
    }

    /**
     * @param  array<int,array<string,mixed>>  $spanClaims
     * @return array<string,mixed>
     */
    private function claimCitations(array $spanClaims): array
    {
        if ($spanClaims === []) {
            return [
                'status' => 'span_level_retrieval_absent',
                'items' => [],
            ];
        }

        $items = [];
        foreach ($spanClaims as $claim) {
            $citations = [];
            foreach ($this->listOfArrays($claim['spans'] ?? []) as $span) {
                $citations[] = [
                    'span_ref' => (string) ($span['span_ref'] ?? ''),
                    'parent_ref' => (string) ($span['parent_ref'] ?? ''),
                    'source_type' => (string) ($span['source_type'] ?? ''),
                    'content_version' => (string) ($span['content_version'] ?? ''),
                    'start' => (int) ($span['start'] ?? 0),
                    'end' => (int) ($span['end'] ?? 0),
                    'span_excerpt' => (string) ($span['span_excerpt'] ?? ''),
                    'score' => round((float) ($span['score'] ?? 0.0), 4),
                ];
            }
            $items[] = [
                'claim' => (string) ($claim['claim'] ?? ''),
                'claim_hash' => (string) ($claim['claim_hash'] ?? hash('sha256', (string) ($claim['claim'] ?? ''))),
                'status' => (string) ($claim['status'] ?? ($citations === [] ? 'unresolved' : 'resolved')),
                'citations' => $citations,
            ];
        }

        return [
            'status' => $this->hasCitations($items) ? 'content_versioned_spans' : 'no_resolved_spans',
            'items' => $items,
        ];
    }

    /**
     * @param  array<int,array<string,mixed>>  $items
     */
    private function hasCitations(array $items): bool
    {
        foreach ($items as $item) {
            if ((array) ($item['citations'] ?? []) !== []) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string,mixed> $pack */
    private function seenRefs(array $pack): array
    {
        $refs = [];
        foreach ($this->listOfArrays(data_get($pack, 'obra_working_set.items', [])) as $item) {
            $ref = trim((string) ($item['ref'] ?? ''));
            if (AtlasCanonicalContextRef::isCanonical($ref) || AtlasCanonicalContextRef::isSpanRef($ref)) {
                $refs[] = $ref;
            }
        }

        return AtlasCanonicalContextRef::uniqueStrings($refs);
    }

    /** @param array<string,mixed> $pack */
    private function lineage(array $pack): array
    {
        return [
            'obra_id' => $this->lineageValue($pack, 'obra_id'),
            'decision_id' => $this->lineageValue($pack, 'decision_id'),
            'soak_note' => 'mechanism_landed; novelty quality remains pending_window(obra_id_lineage_soak)',
        ];
    }

    /** @param array<string,mixed> $pack */
    private function lineageValue(array $pack, string $key): ?string
    {
        $value = trim((string) data_get($pack, 'context_delivery_policy.session_working_set.lineage.'.$key, ''));

        return $value !== '' ? $value : null;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function listOfArrays(mixed $value): array
    {
        $out = [];
        foreach ((array) $value as $item) {
            if (is_array($item)) {
                $out[] = $item;
            }
        }

        return $out;
    }

    /** @return array<string,mixed> */
    private function sourceMeta(): array
    {
        return [
            'llm_used' => false,
            'db_touched' => false,
            'record_usage' => false,
            'fabricates_evidence' => false,
            'uses_delivered_pack_only' => true,
            'max_must_carry_refs' => self::MAX_MUST_CARRY_REFS,
            'flag' => 'atlas.aobg.epistemic_evidence_bundle',
            'flag_default' => 'off',
        ];
    }
}
