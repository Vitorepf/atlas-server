<?php

declare(strict_types=1);

namespace App\Services\Ai\Compounding;

/**
 * The content-quality gate that stops Atlas from "learning" noise. Given a learning
 * candidate it returns admit/reject + a CONTENT hash (computed from the meaningful
 * state only, stripping volatile metadata + per-row hashes), so identical-content
 * candidates collapse to one instead of multiplying.
 *
 * It rejects the four noise families observed in real capture:
 *   - fixture_echo : smoke-test / readiness-simulation artifacts (e.g. ATLAS_COMPOUND_OK)
 *   - meta_stub    : "a learning signal was emitted" with no actual content
 *   - contentless  : empty / all-default-template payloads (no real proposed change)
 *   - low_substance: too little meaningful information to be worth saving
 *
 * Pure + deterministic (no I/O, no clock). Duplicate detection is done by the caller
 * grouping on the content_hash this returns — the gate stays a pure assessor.
 */
final class AtlasCaptureQualityGate
{
    public const SCHEMA = 'atlas.ai.capture_quality_gate.v1';

    public const REASON_OK = 'ok';

    public const REASON_CONTENTLESS = 'contentless';

    public const REASON_META_STUB = 'meta_stub';

    public const REASON_FIXTURE_ECHO = 'fixture_echo';

    public const REASON_LOW_SUBSTANCE = 'low_substance';

    /** Generic "a signal happened" phrasing carrying no actual learned content. */
    private const META_STUB_PATTERNS = [
        'emitted a learning signal contract',
        'for future routing, retrieval and execution evaluation',
        'produced passed outcome and should inform future routing',
        'should inform future routing, retrieval or execution when matching evidence recurs',
    ];

    /**
     * Smoke-test / readiness-simulation markers — Atlas testing itself, not learning.
     * STRONG = artefatos estruturais que só existem em fixture (1 hit rejeita).
     * WEAK = vocabulário normal de engenharia ("smoke test" aparece em learnings REAIS
     * de falha de teste); 1 hit sozinho NÃO pode rejeitar — exige 2+ marcadores
     * distintos co-ocorrendo (o perfil de um fixture echo de verdade).
     */
    private const STRONG_FIXTURE_PATTERNS = [
        'atlas_compound_ok',
        'reply with exactly',
    ];

    private const WEAK_FIXTURE_PATTERNS = [
        'deterministic smoke',
        'smoke simulation',
        'smoke test',
        'readiness simulation',
    ];

    /** Keys that are per-row volatile metadata, excluded from the content hash. */
    private const VOLATILE_KEYS = [
        'candidate_hash', 'candidate_receipt_hash', 'receipt_hash', 'memory_hash',
        'proposal_hash', 'content_hash', 'recorded_at', 'created_at', 'updated_at',
        'id', 'learning_candidate_id', 'run_outcome_id', 'rag_feedback_id', 'flow_id',
        'trace_id', 'session_id', 'decided_at',
    ];

    /**
     * @param  array<string,mixed>  $candidate  {kind?, claim?/summary?, content?/proposed_state?, scope?}
     * @return array{admit:bool,reason:string,content_hash:string,quality_score:int}
     */
    public function assess(array $candidate): array
    {
        $kind = (string) ($candidate['kind'] ?? '');
        $claim = trim((string) ($candidate['claim'] ?? ($candidate['summary'] ?? '')));
        $content = $candidate['content'] ?? ($candidate['proposed_state'] ?? null);
        $identity = $candidate['identity'] ?? $content; // dedup/hash on the proposed change only

        $hash = $this->contentHash($kind, $claim, $identity);
        $haystack = $this->normalizeText($claim.' '.$this->flatten($content));

        foreach (self::STRONG_FIXTURE_PATTERNS as $p) {
            if ($p !== '' && str_contains($haystack, $p)) {
                return $this->verdict(false, self::REASON_FIXTURE_ECHO, $hash, 0);
            }
        }
        $weakHits = 0;
        foreach (self::WEAK_FIXTURE_PATTERNS as $p) {
            if ($p !== '' && str_contains($haystack, $p)) {
                $weakHits++;
            }
        }
        if ($weakHits >= 2) {
            return $this->verdict(false, self::REASON_FIXTURE_ECHO, $hash, 0);
        }
        foreach (self::META_STUB_PATTERNS as $p) {
            if (str_contains($haystack, $this->normalizeText($p))) {
                return $this->verdict(false, self::REASON_META_STUB, $hash, 5);
            }
        }
        // Contentless ONLY when the claim is ALSO trivial — a substantive claim with
        // empty structured content (the normal shape for memory / retrieval_hint
        // learnings, and for any language) must NEVER be dropped as "contentless".
        $claimSubstantive = mb_strlen($this->normalizeText($claim)) >= 8;
        if (! $claimSubstantive && $this->isContentless($content)) {
            return $this->verdict(false, self::REASON_CONTENTLESS, $hash, 0);
        }
        $score = $this->substanceScore($claim, $content);
        if ($score < $this->minScore()) {
            return $this->verdict(false, self::REASON_LOW_SUBSTANCE, $hash, $score);
        }

        return $this->verdict(true, self::REASON_OK, $hash, $score);
    }

    /**
     * Content hash over the MEANINGFUL state only (volatile metadata stripped), so two
     * candidates that propose the same change collapse to one regardless of surrounding
     * summary text / evidence refs / per-row hashes.
     */
    public function contentHash(string $kind, string $claim, mixed $content): string
    {
        // Identity is the meaningful CONTENT when present (so a generator that varies
        // only the summary still collapses); fall back to the claim when content is
        // empty (so two distinct sentence-learnings stay distinct).
        // CANONICALIZED antes do hash: sem isto, whitespace/caixa/ordem de chaves
        // aninhadas — exatamente a família de variação que um gerador LLM produz a
        // cada run — quebram o dedup e o padrão "137→3" volta com outro nome.
        $stripped = $this->canonicalize($this->stripVolatile($content));
        $identity = $this->isContentless($stripped)
            ? ['kind' => strtolower(trim($kind)), 'claim' => $this->normalizeText($claim)]
            : ['kind' => strtolower(trim($kind)), 'content' => $stripped];

        return 'sha256:'.hash('sha256', (string) json_encode($identity, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /**
     * Forma canônica para identidade de dedup: strings normalizadas (caixa+espaços),
     * mapas com chaves ordenadas recursivamente, listas de escalares ordenadas
     * (evidências reordenadas são o mesmo conteúdo); listas de estruturas mantêm a
     * ordem (sequências de passos têm ordem semântica).
     */
    private function canonicalize(mixed $content): mixed
    {
        if (is_string($content)) {
            return $this->normalizeText($content);
        }
        if (! is_array($content)) {
            return $content;
        }
        $out = [];
        foreach ($content as $k => $v) {
            $out[$k] = $this->canonicalize($v);
        }
        if (array_is_list($out)) {
            $allScalar = array_reduce($out, static fn (bool $c, mixed $v): bool => $c && ! is_array($v), true);
            if ($allScalar) {
                sort($out);
            }

            return $out;
        }
        ksort($out);

        return $out;
    }

    private function minScore(): int
    {
        try {
            if (function_exists('config')) {
                $v = config('atlas.ai.capture_quality_gate.min_score', 20);

                return is_numeric($v) ? (int) $v : 20;
            }
        } catch (\Throwable) {
            // no booted app — fall through to the safe default
        }

        return 20;
    }

    /**
     * Contentless = no meaningful content beyond empty collections, zeros, booleans and
     * bare config thresholds. The all-default template `{should_repromote_sources:[],
     * should_demote_noise_count:0, target_context_sufficiency_min:70}` is contentless.
     */
    private function isContentless(mixed $content): bool
    {
        if ($content === null || $content === '' || $content === []) {
            return true;
        }
        if (is_string($content)) {
            return mb_strlen(trim($content)) < 8;
        }
        if (is_array($content)) {
            foreach ($content as $v) {
                if (! $this->isContentless($v) && ! is_int($v) && ! is_float($v) && ! is_bool($v)) {
                    return false; // found a real string/non-empty collection ⇒ has content
                }
                if ((is_int($v) || is_float($v)) && ! $this->isContentless($v)) {
                    // a number alone is a threshold/count, not "content" — keep scanning
                    continue;
                }
            }

            return true; // only empties / zeros / booleans / bare numbers
        }

        return is_int($content) || is_float($content) || is_bool($content);
    }

    private function substanceScore(string $claim, mixed $content): int
    {
        $text = $this->normalizeText($claim.' '.$this->flatten($content));
        foreach (array_merge(self::META_STUB_PATTERNS, self::STRONG_FIXTURE_PATTERNS, self::WEAK_FIXTURE_PATTERNS) as $p) {
            $text = str_replace($this->normalizeText($p), ' ', $text);
        }
        // Unicode-aware: letters/digits in ANY script count (pt-BR, CJK, …). Keep 2-3
        // char tokens so technical acronyms/metrics (SQL, p99, GC, N+1) survive.
        $tokens = array_filter(
            preg_split('/[^\p{L}\p{N}]+/u', $text) ?: [],
            static fn (string $t): bool => mb_strlen($t) >= 2,
        );
        $distinct = count(array_unique($tokens));
        // Scripts without word spaces (CJK / Hangul): count characters as morphemes,
        // else a whole Chinese sentence reads as a single token.
        $cjk = (int) preg_match_all('/[\p{Han}\p{Hiragana}\p{Katakana}\p{Hangul}]/u', $text);

        return (int) min(100, $distinct * 6 + $cjk * 4);
    }

    private function flatten(mixed $content): string
    {
        if ($content === null) {
            return '';
        }
        if (is_string($content)) {
            return $content;
        }

        return (string) json_encode($this->stripVolatile($content), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private function stripVolatile(mixed $content): mixed
    {
        if (! is_array($content)) {
            return $content;
        }
        $out = [];
        foreach ($content as $k => $v) {
            if (is_string($k) && in_array(strtolower($k), self::VOLATILE_KEYS, true)) {
                continue;
            }
            $out[$k] = is_array($v) ? $this->stripVolatile($v) : $v;
        }

        return $out;
    }

    private function normalizeText(string $s): string
    {
        return trim(preg_replace('/\s+/u', ' ', mb_strtolower($s)) ?? '');
    }

    /**
     * @return array{admit:bool,reason:string,content_hash:string,quality_score:int}
     */
    private function verdict(bool $admit, string $reason, string $hash, int $score): array
    {
        return ['admit' => $admit, 'reason' => $reason, 'content_hash' => $hash, 'quality_score' => $score];
    }
}
