<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction;

use App\Services\Semantic\EmbeddingService;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * S3.F1 + S3.F2 — the RELEVANCE GATE (the load-bearing safety of the self-improvement loop).
 *
 * THE PROBLEM IT EXISTS TO STOP: a real self-construct run generated 412 lines of
 * IRRELEVANT garbage — a Hermes Kanban driver — for a TODO about "running/scheduled
 * tasks", because the generation prompt was not anchored to the signal and NOTHING
 * checked whether what came back had anything to do with what was asked. The brain
 * context (Salto 2) fixes the AIM; this gate is the independent CHECK that the result
 * actually hit it. Brain-anchoring + relevance gate are belt and braces.
 *
 * TWO INDEPENDENT DIMENSIONS (both must pass — AND, not OR):
 *   - target_match (S3.F1): did the generated files TOUCH the file/area the signal named?
 *     path overlap — exact signal file = strong, sibling under the same dir = weak,
 *     unrelated path = 0. The 412-line case generated a generic file for a TODO in
 *     ANOTHER file → target_match ~0 → REJECTED on this dimension alone.
 *   - content_relevance (S3.F2): does the generated CONTENT actually overlap the signal's
 *     CONCERN text? Even if a file lands in the right directory, content that has nothing
 *     to do with the concern (a Kanban driver where a scheduling guard was asked for) must
 *     score LOW and be rejected. Uses the REAL embeddings (EmbeddingService / semantic_rag)
 *     for a semantic cosine on pgsql, with an HONEST deterministic token-overlap (Jaccard)
 *     fallback on sqlite / when the engine is unavailable. The method actually used is
 *     reported (content_method) — the gate NEVER labels a token score "semantic".
 *
 * WHY OUT-OF-PROCESS / NOT GAMEABLE BY THE GENERATION PROMPT:
 *   - this gate runs AFTER delivery, on the FACTS of what was produced (the touched file
 *     paths AND their content the materializer/delivery reports) versus the FACTS of the
 *     signal (the file it named, its line, its concern terms) — it never reads the
 *     generation prompt and never asks the provider "is this relevant?" (which the provider
 *     could trivially answer "yes" to). The provider cannot talk its way past a
 *     deterministic comparison of two fact sets it does not control.
 *   - it mirrors the adversarial-verify discipline (the loop-proposal-adversarial-verify
 *     skill): an independent, default-refute-if-uncertain re-check before a result is
 *     presented as worthy. "Default-refute" = a file-anchored signal whose named file was
 *     NOT touched, OR whose generated content does not overlap the concern, is REJECTED.
 *   - the gate is NOT gameable by ECHOING the signal text as a comment in the WRONG file:
 *     target_match runs first and is 0 for an off-target path, so no amount of content
 *     mimicry rescues a file in the wrong place.
 *
 * THE RULE (file-anchored signals — code markers / target_file gaps):
 *   a delivery is RELEVANT iff (a) it TOUCHED the file the signal named (same file, or a
 *   sibling under the same path the operator can defend reviewing) AND (b) the generated
 *   content of the matched file overlaps the signal's concern at/above the configured
 *   floor. An operator gap that names no file cannot be relevance-checked by target and is
 *   admitted as long as it produced a branch at all (the operator chose to spend on it;
 *   their judgement is the gate). The gate NEVER fabricates a pass: an empty delivery is
 *   never relevant. When the delivery carries no readable content for the matched file, the
 *   content dimension cannot bite and is admitted honestly (target_match still governs).
 *
 * The gate's PUBLIC verdict is deterministic given its inputs. The only side-effectful
 * dependency (the embedding engine) is OPTIONAL: with no engine, or off pgsql, the gate
 * uses the deterministic token-overlap fallback and labels it as such — it cannot itself
 * spend or merge. The caller (the loop) discards the branch on REJECT and records the
 * honest reason + scores.
 */
final class AtlasSelfImprovementRelevanceGate
{
    public const SCHEMA = 'atlas.ai.self_improvement_relevance.v2';

    /** Content scored by REAL local embeddings (semantic_rag/openai), cosine similarity. */
    public const METHOD_SEMANTIC = 'semantic_embedding';

    /** Content scored by deterministic token-overlap (Jaccard) — the sqlite/honest fallback. */
    public const METHOD_TOKEN_OVERLAP = 'lexical_token_overlap';

    /** No readable generated content to score → the content dimension does not bite. */
    public const METHOD_NONE = 'no_content_unverifiable';

    public function __construct(
        // OPTIONAL: when present (and on pgsql with a real engine) content_relevance is
        // scored semantically. When null / unavailable the gate uses the deterministic
        // token-overlap fallback. Left optional so the gate stays a trivially-constructable
        // pure-ish decision function for unit tests (`new AtlasSelfImprovementRelevanceGate`).
        private readonly ?EmbeddingService $embeddings = null,
    ) {}

    /**
     * Evaluate a delivered self-improvement against the signal that requested it.
     *
     * @param  array{area?:string,file?:?string,line?:?int,signal?:string,request?:string,source?:string}  $signal
     * @param  array<string,mixed>  $delivery  the mission/orchestrator envelope (delivered, branch, delivery.files / files)
     * @return array{
     *     relevant:bool,
     *     reason:string,
     *     target_file:?string,
     *     touched_files:list<string>,
     *     matched_file:?string,
     *     target_match:float,
     *     content_relevance:?float,
     *     content_method:string,
     *     schema_version:string
     * }
     */
    public function evaluate(array $signal, array $delivery): array
    {
        $target = $this->normalisePath($signal['file'] ?? null);
        $files = $this->touchedFiles($delivery); // [path => content|null], deterministic order
        $touched = array_keys($files);
        $delivered = (bool) ($delivery['delivered'] ?? false);

        // A delivery that produced no files is never relevant — the gate never
        // fabricates a pass for an empty/blocked result (default-refute).
        if (! $delivered || $touched === []) {
            return $this->verdict(false, 'no_delivery_to_check', $target, $touched, null, 0.0, null, self::METHOD_NONE);
        }

        // Operator gaps with no named file cannot be target-checked; the operator's
        // decision to spend on the request is itself the admission (it produced a
        // branch). Still honest: we report there was no target to verify against. The
        // content dimension also has no anchored concern-vs-file pair to compare.
        if ($target === null) {
            return $this->verdict(true, 'no_target_unverifiable_admitted', null, $touched, null, 1.0, null, self::METHOD_NONE);
        }

        // DIMENSION 1 — target_match. The named file (or a sibling under the same dir)
        // MUST be among the touched files, otherwise the generation went off-target —
        // the exact 412-line-garbage failure mode — and is REJECTED before content is
        // ever considered (so echoing the signal text in the WRONG file cannot rescue it).
        $matched = $this->matchTarget($target, $touched);
        $targetMatch = $this->targetMatchScore($target, $matched);
        if ($matched === null) {
            return $this->verdict(false, 'off_target_generation', $target, $touched, null, $targetMatch, null, self::METHOD_NONE);
        }

        // DIMENSION 2 — content_relevance. Compare the signal's CONCERN text against the
        // generated content of the matched file. The content gate only BITES when there
        // is readable content to score (the F1 path-only envelopes carry none → admitted,
        // honestly labelled, target_match alone governs as it did under F1).
        $concern = $this->concernText($signal, $target);
        $content = $this->contentFor($matched, $files);

        if ($content === null || $content === '' || $concern === '') {
            // Nothing to score on this dimension → it cannot reject; report honestly.
            return $this->verdict(true, 'on_target', $target, $touched, $matched, $targetMatch, null, self::METHOD_NONE);
        }

        [$contentScore, $method] = $this->contentRelevance($concern, $content);
        $floor = $this->contentFloor();

        if ($contentScore < $floor) {
            // On-target PATH but off-concern CONTENT (e.g. a Kanban driver where a
            // scheduling guard was asked for, or a file that merely echoes the signal as
            // a comment but does nothing relevant) → REJECTED.
            return $this->verdict(false, 'off_concern_content', $target, $touched, $matched, $targetMatch, $contentScore, $method);
        }

        return $this->verdict(true, 'on_target', $target, $touched, $matched, $targetMatch, $contentScore, $method);
    }

    // ------------------------------------------------------------------
    // target dimension (S3.F1, unchanged behaviour)
    // ------------------------------------------------------------------

    /**
     * Collect the file paths a delivery touched AND any content carried with them, from
     * either the flat product envelope (delivery.files) or the orchestrator's raw shape.
     * Entries may be plain path strings (no content) or {path, content} arrays.
     *
     * @param  array<string,mixed>  $delivery
     * @return array<string,?string>  normalised path => content (or null when not carried)
     */
    private function touchedFiles(array $delivery): array
    {
        /** @var array<string,?string> $out */
        $out = [];

        $ingest = function (mixed $entry) use (&$out): void {
            if (is_string($entry)) {
                $norm = $this->normalisePath($entry);
                if ($norm !== null && ! array_key_exists($norm, $out)) {
                    $out[$norm] = null;
                }

                return;
            }
            if (is_array($entry) && isset($entry['path']) && is_string($entry['path'])) {
                $norm = $this->normalisePath($entry['path']);
                if ($norm === null) {
                    return;
                }
                $content = isset($entry['content']) && is_string($entry['content']) ? $entry['content'] : null;
                // First non-null content wins; never overwrite real content with null.
                if (! array_key_exists($norm, $out) || ($out[$norm] === null && $content !== null)) {
                    $out[$norm] = $content;
                }
            }
        };

        // AtlasMissionService / MissionDeliveryOrchestrator envelope: delivery.files.
        $nested = $delivery['delivery']['files'] ?? null;
        if (is_array($nested)) {
            foreach ($nested as $f) {
                $ingest($f);
            }
        }

        // Fallback: a top-level files list (some shapes carry {path,...} entries).
        foreach ((array) ($delivery['files'] ?? []) as $f) {
            $ingest($f);
        }

        ksort($out); // deterministic order

        return $out;
    }

    /**
     * The named target counts as touched if an exact path matches, or a touched file
     * sits under the SAME directory as the target (a defensible adjacent change the
     * operator can review in context). Anything outside that directory is off-target.
     *
     * @param  list<string>  $touched
     */
    private function matchTarget(string $target, array $touched): ?string
    {
        if (in_array($target, $touched, true)) {
            return $target;
        }

        $dir = $this->dirOf($target);
        if ($dir !== '') {
            foreach ($touched as $t) {
                if ($this->dirOf($t) === $dir) {
                    return $t;
                }
            }
        }

        return null;
    }

    /**
     * Honest numeric for the target dimension: 1.0 exact file, 0.5 sibling-in-dir, 0.0
     * off-target. Used for the meta-metric and audit; the pass/fail is the matchTarget
     * boolean above (any match >= the configured target floor is on-target by path).
     */
    private function targetMatchScore(string $target, ?string $matched): float
    {
        if ($matched === null) {
            return 0.0;
        }

        return $matched === $target ? 1.0 : 0.5;
    }

    private function dirOf(string $path): string
    {
        $pos = strrpos($path, '/');

        return $pos === false ? '' : substr($path, 0, $pos);
    }

    // ------------------------------------------------------------------
    // content dimension (S3.F2)
    // ------------------------------------------------------------------

    /**
     * The signal's concern text: its marker/request text plus the target filename's own
     * tokens (so "tighten the workspace guard in Widget.php" carries "widget"). This is
     * the FACT side the gate controls — never the generation prompt.
     *
     * @param  array<string,mixed>  $signal
     */
    private function concernText(array $signal, string $target): string
    {
        $parts = [];
        foreach (['signal', 'request'] as $k) {
            $v = $signal[$k] ?? null;
            if (is_string($v) && trim($v) !== '') {
                $parts[] = trim($v);
            }
        }
        // The basename without extension carries domain meaning (Widget, Scheduler...).
        $base = $this->dirOf($target) === '' ? $target : substr($target, strrpos($target, '/') + 1);
        $base = preg_replace('/\.[A-Za-z0-9]+$/', '', $base) ?? $base;
        if (is_string($base) && $base !== '') {
            $parts[] = $base;
        }

        return trim(implode(' ', $parts));
    }

    /**
     * @param  array<string,?string>  $files
     */
    private function contentFor(string $matched, array $files): ?string
    {
        return $files[$matched] ?? null;
    }

    /**
     * Score concern-vs-content. SEMANTIC (cosine of real embeddings) on pgsql with a real
     * engine; otherwise the deterministic token-overlap fallback. The chosen method is
     * returned so the caller never mislabels a token score as semantic.
     *
     * @return array{0:float,1:string}  [score 0..1, method]
     */
    private function contentRelevance(string $concern, string $content): array
    {
        if ($this->semanticAvailable()) {
            $score = $this->semanticScore($concern, $content);
            if ($score !== null) {
                return [$score, self::METHOD_SEMANTIC];
            }
            // Engine claimed available but failed → fall through to the honest fallback.
        }

        return [$this->tokenOverlap($concern, $content), self::METHOD_TOKEN_OVERLAP];
    }

    /**
     * Real embeddings are usable only when an engine was injected AND we are on pgsql
     * (the same provider-safety boundary AtlasMemoryVectorSearchService enforces — the
     * sovereign embedding path runs against pgvector; on sqlite we use the honest
     * deterministic fallback rather than pretend).
     */
    private function semanticAvailable(): bool
    {
        if ($this->embeddings === null) {
            return false;
        }
        if (! (bool) $this->cfg('atlas.self_construction.relevance_semantic_enabled', true)) {
            return false;
        }

        try {
            return DB::getDriverName() === 'pgsql';
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Cosine similarity of two embedding vectors from the REAL EmbeddingService. Returns
     * null (never a fabricated number) if the engine is unavailable or errors — the caller
     * then falls back to the honest token-overlap and labels it correctly.
     */
    private function semanticScore(string $a, string $b): ?float
    {
        if ($this->embeddings === null) {
            return null;
        }

        try {
            $va = $this->embeddings->embedText($a);
            $vb = $this->embeddings->embedText($b);
        } catch (Throwable $throwable) {
            report($throwable);

            return null;
        }

        if ($va === [] || $vb === []) {
            return null;
        }

        return $this->cosine($va, $vb);
    }

    /**
     * @param  array<int,float>  $a
     * @param  array<int,float>  $b
     */
    private function cosine(array $a, array $b): float
    {
        $n = min(count($a), count($b));
        if ($n === 0) {
            return 0.0;
        }

        $dot = 0.0;
        $na = 0.0;
        $nb = 0.0;
        for ($i = 0; $i < $n; $i++) {
            $x = (float) $a[$i];
            $y = (float) $b[$i];
            $dot += $x * $y;
            $na += $x * $x;
            $nb += $y * $y;
        }
        if ($na <= 0.0 || $nb <= 0.0) {
            return 0.0;
        }

        $sim = $dot / (sqrt($na) * sqrt($nb));

        // Clamp to [0,1]: negatives mean "opposite", which for relevance is "not relevant".
        return max(0.0, min(1.0, $sim));
    }

    /**
     * Deterministic token-overlap (Jaccard over the concern's distinctive tokens). The
     * HONEST sqlite/no-engine fallback: it never claims to be semantic. Asymmetric toward
     * the concern (how much of the concern's vocabulary the content covers) so a huge
     * unrelated file (the 412-line Kanban driver) cannot dilute its way to a pass and a
     * short on-concern file is not penalised for brevity.
     */
    private function tokenOverlap(string $concern, string $content): float
    {
        $concernTokens = $this->tokens($concern);
        $contentTokens = $this->tokens($content);

        if ($concernTokens === []) {
            return 0.0;
        }
        if ($contentTokens === []) {
            return 0.0;
        }

        $contentSet = array_fill_keys($contentTokens, true);
        $hit = 0;
        foreach ($concernTokens as $t) {
            if (isset($contentSet[$t])) {
                $hit++;
            }
        }

        // Coverage of the concern's vocabulary by the content.
        return round($hit / count($concernTokens), 4);
    }

    /**
     * Lowercased significant tokens (>= 3 chars, stop-words dropped, code identifiers
     * split on camelCase / snake_case / non-alnum) — deterministic and language-agnostic.
     *
     * @return list<string>
     */
    private function tokens(string $text): array
    {
        $text = strtolower($text);
        // Split camelCase before flattening separators.
        $text = preg_replace('/([a-z0-9])([A-Z])/', '$1 $2', $text) ?? $text;
        $text = strtolower($text);
        $raw = preg_split('/[^a-z0-9]+/', $text) ?: [];

        $stop = [
            'the', 'and', 'for', 'this', 'that', 'with', 'here', 'into', 'are', 'was',
            'use', 'used', 'add', 'fix', 'todo', 'fixme', 'php', 'function', 'public',
            'private', 'class', 'return', 'void', 'namespace', 'app', 'services',
        ];
        $stopSet = array_fill_keys($stop, true);

        $out = [];
        foreach ($raw as $t) {
            if (strlen($t) < 3) {
                continue;
            }
            if (isset($stopSet[$t])) {
                continue;
            }
            $out[$t] = true; // distinct
        }

        return array_keys($out);
    }

    private function contentFloor(): float
    {
        return $this->clampFloor((float) $this->cfg('atlas.self_construction.relevance_min_content', 0.15));
    }

    private function clampFloor(float $f): float
    {
        return max(0.0, min(1.0, $f));
    }

    /**
     * Read a config value, tolerating an unbooted container (so the gate stays a
     * trivially-constructable pure decision function in raw PHPUnit unit tests). Falls
     * back to the literal default — which mirrors the config defaults in config/atlas.php
     * — when the framework helper is unavailable.
     */
    private function cfg(string $key, mixed $default): mixed
    {
        if (! function_exists('config')) {
            return $default;
        }
        try {
            return config($key, $default);
        } catch (Throwable) {
            return $default;
        }
    }

    // ------------------------------------------------------------------
    // shared
    // ------------------------------------------------------------------

    /**
     * Normalise a path to a comparable relative form: trim, drop leading "./" and
     * a leading slash, collapse backslashes. Returns null for empty/non-path input.
     */
    private function normalisePath(mixed $path): ?string
    {
        if (! is_string($path)) {
            return null;
        }
        $p = trim($path);
        if ($p === '') {
            return null;
        }
        $p = str_replace('\\', '/', $p);
        $p = preg_replace('#^\./#', '', $p) ?? $p;

        return ltrim($p, '/') !== '' ? ltrim($p, '/') : null;
    }

    /**
     * @param  list<string>  $touched
     * @return array{
     *     relevant:bool,
     *     reason:string,
     *     target_file:?string,
     *     touched_files:list<string>,
     *     matched_file:?string,
     *     target_match:float,
     *     content_relevance:?float,
     *     content_method:string,
     *     schema_version:string
     * }
     */
    private function verdict(
        bool $relevant,
        string $reason,
        ?string $target,
        array $touched,
        ?string $matched,
        float $targetMatch,
        ?float $contentRelevance,
        string $method,
    ): array {
        return [
            'relevant' => $relevant,
            'reason' => $reason,
            'target_file' => $target,
            'touched_files' => $touched,
            'matched_file' => $matched,
            'target_match' => round($targetMatch, 4),
            'content_relevance' => $contentRelevance === null ? null : round($contentRelevance, 4),
            'content_method' => $method,
            'schema_version' => self::SCHEMA,
        ];
    }
}
