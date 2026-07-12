<?php

declare(strict_types=1);

namespace App\Services\Ai\Context;

/**
 * ESP-11 — Retrieval Agenda epistemológica (claims, unknowns, counter-evidence).
 *
 * Frontier plan §2977-2981 (M5):
 *  - Evolves MAXC-01 facet extractor with a CLAIMS layer (verifiable assertions
 *    the operator explicitly names) + UNKNOWNS layer (essential questions).
 *  - Emits a report-only agenda block the pack CAN consume; the caller decides
 *    when to wire it into `packFor` (behind existing `atlas.aobg.facet_retrieval`
 *    flag, default-OFF — this slice never flips it).
 *  - Counter-evidence slot appears (even honest-empty) so retrieval does not
 *    default to confirmation-only.
 *  - **Negative case pétreo (§2980)**: task WITHOUT claims ⇒ agenda vazia
 *    (`present=false`) and byte-identical to the MAXC baseline.
 *
 * Rules (all pinned in source; caller cannot fabricate agenda content):
 *  - claim requires an explicit CLAIM verb ("is", "must", "should", "will",
 *    "cannot", "must not", "é", "deve", "não pode") + a subject.
 *  - unknown requires an explicit UNKNOWN verb ("what", "how", "why", "when",
 *    "which", "where", "quem", "quando", "como", "por que", "qual").
 *  - counter_evidence slot is always emitted when at least one claim exists,
 *    with `sources_expected=1` (never zero — anti-confirmation §2979).
 *  - phrase-quoted claims are given verbatim to preserve exact wording.
 *
 * The classifier is DETERMINISTIC: same input ⇒ same output; no clock, DB,
 * randomness, service calls, or LLM.
 */
final class RetrievalAgendaComposer
{
    public const SCHEMA_VERSION = 'atlas.aobg.retrieval_agenda.v1';

    public const FORMULA_VERSION = 'atlas.esp_11.retrieval_agenda.v1';

    /** Cap agenda items to keep budget honest (§2981 "cabe no budget de sub-queries"). */
    public const MAX_CLAIMS = 6;

    public const MAX_UNKNOWNS = 6;

    /**
     * @param  array<string,mixed>  $context  optional:
     *                                        - facets: list from MAXC-01 TaskFacetExtractor
     *                                        - facet_coverage: MAXC-02 per-facet refs_delivered report
     *                                        - sufficiency: MAXC-02 block; facets are used as coverage
     *                                        - wired_into_packfor: bool — caller stamps pack wiring
     * @return array<string,mixed>
     */
    public function compose(string $task, array $context = []): array
    {
        $raw = trim($task);
        $wired = (bool) ($context['wired_into_packfor'] ?? false);
        $empty = [
            'schema_version' => self::SCHEMA_VERSION,
            'formula_version' => self::FORMULA_VERSION,
            'present' => false,
            'claims' => [],
            'unknowns' => [],
            'counter_evidence_slots' => [],
            'not_enough_context' => false,
            'named_unknown_gaps' => [],
            'source' => $this->sourceMeta($wired),
        ];
        if ($raw === '') {
            return $empty;
        }

        $facets = is_array($context['facets'] ?? null) ? $context['facets'] : [];
        $claims = $this->extractClaims($raw);
        $unknowns = $this->extractUnknowns($raw);
        $facetCoverage = $this->facetCoverage($context);

        // Pétreo: task WITHOUT claims AND WITHOUT unknowns ⇒ empty agenda (byte-identical to MAXC baseline).
        if ($claims === [] && $unknowns === []) {
            return $empty;
        }

        $counter = $this->counterEvidenceSlots($claims);

        $namedGaps = $this->essentialUnknownGaps($unknowns, $facets, $facetCoverage);

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'formula_version' => self::FORMULA_VERSION,
            'present' => true,
            'claims' => $claims,
            'unknowns' => $unknowns,
            'counter_evidence_slots' => $counter,
            'not_enough_context' => $namedGaps !== [],
            'named_unknown_gaps' => $namedGaps,
            'source' => $this->sourceMeta($wired),
        ];
    }

    /**
     * @return list<array{claim:string,expected_source:string,verb:string,kind:string,source_query:array<string,mixed>}>
     */
    private function extractClaims(string $task): array
    {
        $out = [];

        // Phrase-quoted claims are preserved verbatim (§2979).
        if (preg_match_all('/(?<![\\w\\/])(["\'])([^"\'\\r\\n]{8,200})\\1/u', $task, $quoted)) {
            foreach ($quoted[2] as $phrase) {
                if (count($out) >= self::MAX_CLAIMS) {
                    break;
                }
                $phrase = trim($phrase);
                if ($phrase === '') {
                    continue;
                }
                $out[] = $this->claimItem($phrase, 'quoted', 'quoted');
            }
        }

        // Verbs that mark a verifiable claim (EN + PT). Ordered by strength.
        $verbs = [
            'must not' => 'normative',
            'must' => 'normative',
            'should not' => 'normative',
            'should' => 'normative',
            'cannot' => 'normative',
            'will' => 'assertion',
            'is not' => 'assertion',
            'is' => 'assertion',
            'não pode' => 'normative',
            'deve não' => 'normative',
            'deve' => 'normative',
            'não deve' => 'normative',
            'é' => 'assertion',
        ];
        // Split into sentences (crude but deterministic).
        $sentences = preg_split('/[\.!\?\n]+/u', $task) ?: [];
        foreach ($sentences as $sentence) {
            if (count($out) >= self::MAX_CLAIMS) {
                break;
            }
            $sentence = trim($sentence);
            if (mb_strlen($sentence) < 8) {
                continue;
            }
            $found = null;
            $foundVerb = '';
            foreach ($verbs as $verb => $kind) {
                if (preg_match('/(^|\s)'.preg_quote($verb, '/').'(\s|$)/iu', $sentence)) {
                    $found = $kind;
                    $foundVerb = $verb;
                    break;
                }
            }
            if ($found === null) {
                continue;
            }
            $claimText = mb_substr($sentence, 0, 200);
            if ($this->claimAlreadyCaptured($out, $claimText)) {
                continue;
            }
            $out[] = $this->claimItem($claimText, $foundVerb, $found);
        }

        return $out;
    }

    /**
     * @return array{claim:string,expected_source:string,verb:string,kind:string,source_query:array<string,mixed>}
     */
    private function claimItem(string $claim, string $verb, string $kind): array
    {
        $source = $this->guessExpectedSource($claim);

        return [
            'claim' => $claim,
            'expected_source' => $source,
            'verb' => $verb,
            'kind' => $kind,
            'source_query' => $this->sourceQuery('claim_verification', $source, $claim),
        ];
    }

    /**
     * @param  list<array{claim:string,expected_source:string,verb:string,kind:string,source_query:array<string,mixed>}>  $claims
     */
    private function claimAlreadyCaptured(array $claims, string $candidate): bool
    {
        $lower = mb_strtolower($candidate);
        foreach ($claims as $claim) {
            $existing = mb_strtolower((string) ($claim['claim'] ?? ''));
            if ($existing !== '' && ($existing === $lower || str_contains($lower, $existing) || str_contains($existing, $lower))) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<array{unknown:string,essential:bool,taxonomy:string,name:string,expected_source:string,source_query:array<string,mixed>}>
     */
    private function extractUnknowns(string $task): array
    {
        $questionMarkers = [
            'what', 'how', 'why', 'when', 'which', 'where',
            'quem', 'quando', 'como', 'por que', 'porque', 'qual',
        ];
        $out = [];
        $sentences = preg_split('/[\.\?\n]+/u', $task) ?: [];
        foreach ($sentences as $sentence) {
            if (count($out) >= self::MAX_UNKNOWNS) {
                break;
            }
            $sentence = trim($sentence);
            if (mb_strlen($sentence) < 6) {
                continue;
            }
            $lower = mb_strtolower($sentence);
            $marker = null;
            foreach ($questionMarkers as $q) {
                if (preg_match('/(^|\s)'.preg_quote($q, '/').'(\s|$)/u', $lower)) {
                    $marker = $q;
                    break;
                }
            }
            if ($marker === null) {
                continue;
            }
            $unknown = mb_substr($sentence, 0, 160);
            $taxonomy = $this->unknownTaxonomy($marker);
            $name = $this->unknownName($unknown, $marker);
            $source = $this->guessExpectedSource($unknown);
            $out[] = [
                'unknown' => $unknown,
                'essential' => in_array($taxonomy, ['mechanism', 'rationale'], true),
                'taxonomy' => $taxonomy,
                'name' => $name,
                'expected_source' => $source,
                'source_query' => $this->sourceQuery('unknown_resolution', $source, $unknown),
            ];
        }

        return $out;
    }

    private function unknownTaxonomy(string $marker): string
    {
        return match ($marker) {
            'how', 'como' => 'mechanism',
            'why', 'por que', 'porque' => 'rationale',
            'when', 'quando' => 'temporal',
            'where' => 'location',
            'quem' => 'actor',
            'what', 'which', 'qual' => 'identification',
            default => 'unknown',
        };
    }

    private function unknownName(string $unknown, string $marker): string
    {
        $name = preg_replace('/^\s*'.preg_quote($marker, '/').'\b\s*/iu', '', $unknown) ?? $unknown;
        for ($i = 0; $i < 4; $i++) {
            $next = preg_replace('/^\s*(?:does|do|did|is|are|can|could|should|must|will|would|the|a|an|o|os|as|uma|um)\b\s*/iu', '', $name) ?? $name;
            if ($next === $name) {
                break;
            }
            $name = $next;
        }
        $name = trim((string) preg_replace('/\s+/u', ' ', $name), " \t\n\r\0\x0B?.!,;:");

        return $name !== '' ? mb_substr($name, 0, 120) : mb_substr($unknown, 0, 120);
    }

    private function guessExpectedSource(string $sentence): string
    {
        $lower = mb_strtolower($sentence);
        if (preg_match('/\b(?:file|arquivo|caminho|path)\b/', $lower) || str_contains($lower, '/')) {
            return 'code_graph';
        }
        if (preg_match('/\b(?:decision|decisão|policy|invariant|memoria|memória|memory|ledger)\b/', $lower)) {
            return 'memory';
        }
        if (preg_match('/\b(?:atlas:|command|comando|artisan|cli)\b/', $lower)) {
            return 'code_graph';
        }
        if (preg_match('/\b(?:evidence|evidência|receipt|hash)\b/', $lower)) {
            return 'evidence_ledger';
        }

        return 'reality_graph';
    }

    /**
     * @return array{purpose:string,source:string,query:string,record_usage:bool}
     */
    private function sourceQuery(string $purpose, string $source, string $text): array
    {
        $prefix = match ($purpose) {
            'counter_evidence' => 'counter evidence against claim: ',
            'unknown_resolution' => 'resolve unknown: ',
            default => 'verify claim: ',
        };

        return [
            'purpose' => $purpose,
            'source' => $source,
            'query' => mb_substr($prefix.$text, 0, 240),
            'record_usage' => false,
        ];
    }

    /**
     * @param  list<array{claim:string,expected_source:string,verb:string,kind:string,source_query:array<string,mixed>}>  $claims
     * @return list<array<string,mixed>>
     */
    private function counterEvidenceSlots(array $claims): array
    {
        $slots = [];
        foreach ($claims as $claim) {
            $claimText = (string) ($claim['claim'] ?? '');
            if ($claimText === '') {
                continue;
            }
            $source = (string) ($claim['expected_source'] ?? 'reality_graph');
            $slots[] = [
                'origin' => 'anti_confirmation',
                'claim' => $claimText,
                'source' => $source,
                'query' => $this->sourceQuery('counter_evidence', $source, $claimText)['query'],
                'source_query' => $this->sourceQuery('counter_evidence', $source, $claimText),
                'refs_against' => [],
                'refs_found' => 0,
                'sources_expected' => 1,
                'max_refs' => 1,
                'status' => 'empty_honest',
            ];
        }

        return $slots;
    }

    /**
     * @param  array<string,mixed>  $context
     * @return array<int,array<string,mixed>>
     */
    private function facetCoverage(array $context): array
    {
        if (is_array($context['facet_coverage'] ?? null)) {
            return $context['facet_coverage'];
        }
        if (is_array($context['sufficiency'] ?? null) && is_array($context['sufficiency']['facets'] ?? null)) {
            return $context['sufficiency']['facets'];
        }

        return [];
    }

    /**
     * @param  list<array{unknown:string,essential:bool,taxonomy:string,name:string,expected_source:string,source_query:array<string,mixed>}>  $unknowns
     * @param  array<int,array{type:string,value:string,essential?:bool}>  $facets
     * @param  array<int,array<string,mixed>>  $facetCoverage
     * @return list<array<string,mixed>>
     */
    private function essentialUnknownGaps(array $unknowns, array $facets, array $facetCoverage): array
    {
        // §2980: unknown essencial sem hit ⇒ not_enough_context=true with the unknown NAMED.
        // "hit" = at least one facet value appears in the unknown text (peek budget only).
        $gaps = [];
        foreach ($unknowns as $unknown) {
            if (! ($unknown['essential'] ?? false)) {
                continue;
            }
            if (! $this->unknownHasHit($unknown, $facets, $facetCoverage)) {
                $taxonomy = (string) ($unknown['taxonomy'] ?? 'unknown');
                $name = (string) ($unknown['name'] ?? $unknown['unknown'] ?? '');
                $gaps[] = [
                    'unknown' => (string) ($unknown['unknown'] ?? ''),
                    'essential' => true,
                    'taxonomy' => $taxonomy,
                    'name' => $name,
                    'expected_source' => (string) ($unknown['expected_source'] ?? 'reality_graph'),
                    'handle' => 'expand:unknown:'.$taxonomy.':'.$name,
                    'source_query' => (array) ($unknown['source_query'] ?? []),
                ];
            }
        }

        return $gaps;
    }

    /**
     * @param  array{unknown:string,essential:bool,taxonomy:string,name:string,expected_source:string,source_query:array<string,mixed>}  $unknown
     * @param  array<int,array{type:string,value:string,essential?:bool}>  $facets
     * @param  array<int,array<string,mixed>>  $facetCoverage
     */
    private function unknownHasHit(array $unknown, array $facets, array $facetCoverage): bool
    {
        $lower = mb_strtolower((string) ($unknown['unknown'] ?? ''));
        if ($facetCoverage !== []) {
            foreach ($facetCoverage as $facet) {
                $value = mb_strtolower((string) ($facet['value'] ?? ''));
                $refsDelivered = (int) ($facet['refs_delivered'] ?? 0);
                if ($value !== '' && $refsDelivered > 0 && str_contains($lower, $value)) {
                    return true;
                }
            }

            return false;
        }

        foreach ($facets as $facet) {
            $value = mb_strtolower((string) ($facet['value'] ?? ''));
            if ($value !== '' && str_contains($lower, $value)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string,mixed>
     */
    private function sourceMeta(bool $wiredIntoPackFor = false): array
    {
        return [
            'fabricates_claims' => false,
            'llm_used' => false,
            'db_touched' => false,
            'randomness' => false,
            'anti_confirmation_slot_always_present_when_claims' => true,
            'max_claims' => self::MAX_CLAIMS,
            'max_unknowns' => self::MAX_UNKNOWNS,
            'wired_into_packfor' => $wiredIntoPackFor,
            'flag' => 'atlas.aobg.facet_retrieval',
            'flag_default' => 'off',
        ];
    }
}
