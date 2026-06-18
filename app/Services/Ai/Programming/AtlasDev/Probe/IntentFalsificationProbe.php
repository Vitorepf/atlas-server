<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\AtlasDev\Probe;

use App\Services\Ai\Programming\AtlasDev\Pipeline\IntentActionExtractor;
use App\Services\Ai\Programming\AtlasDev\Provider\DiffParseResult;
use App\Services\Ai\Programming\AtlasDev\Schemas\LightTaskContract;

/**
 * E1 — Deterministic intent-falsification probe.
 *
 * Runs AFTER the verification gate (post-gate, alongside the E2
 * IntentCoverageProbe in PipelineRunExecutor::execute) and asserts that at
 * least one ADDED line of the diff traceably implements the E2-established
 * intent (LightTaskContract::intentVerbs + intentText, populated by
 * IntentActionExtractor and SpecComposer). When none does, the probe appends
 * the `intent_likely_not_addressed` honesty flag (advisory channel) so the
 * CompletionStateGate auto-downgrades PASSED -> needs_review — even on a
 * GREEN gate (VAL-E1-003). In hard mode the executor routes the miss to
 * STATUS_FAILED (the sanctioned hard gate channel).
 *
 * The probe is DETERMINISTIC and model-irrelevant: it scans the synthetic
 * diff's added lines through a token-overlap heuristic reusing the
 * IntentActionExtractor recognized-verb vocabulary (one canonical source of
 * truth for the verb surface forms) plus the intent text's distinctive
 * subject tokens. It does NOT depend on a real LLM and does NOT re-detect
 * the verbs: it reads the persisted LightTaskContract basis (VAL-CROSS-005:
 * E1 checks against the E2-established intent, never something else).
 *
 * "Implements the intent verb" is evaluated structurally (model-irrelevant):
 *   - TIER 1 (verb surface form): an added line containing any surface form
 *     of a recognized intent verb (e.g. a `fix(...)`, `remove(...)`, `rename`
 *     call/comment/identifier) clearly implements the verb. This catches the
 *     direct case.
 *   - TIER 2 (intent subject overlap): an added line sharing a distinctive
 *     subject token with the intent text (identifiers, file names, domain
 *     nouns — stopwords and the verb words themselves are excluded) is
 *     traceably implementing the intent's object. A genuine code change to
 *     the subject of the intent (e.g. fixing `'helo atlas'` -> `'hello atlas'`
 *     for the intent "Fix ... greeting returns helo atlas ... hello atlas")
 *     clears the probe via this tier, while an unrelated diff
 *     (`+    return 42;`) shares no token with the intent and fires.
 *
 * Contract (validation assertions):
 *   - VAL-E1-003: green gate + diff missing the intent verb => flag present
 *     despite STATUS_PASSED.
 *   - VAL-E1-005: genuine-intent diff => no flag, green preserved.
 *   - VAL-E1-012: no config/flag combination yields a silent green for an
 *     intent-missing diff (the passed-forbids-flags ctor invariant is the
 *     mechanical floor).
 *   - VAL-E1-014: empty/no-patch write task (verbs present, no added lines)
 *     => flag fires, never silently green.
 *   - VAL-E1-015: scans ALL hunks of a many-file diff (position-independent:
 *     a verb-implementing hunk in a later file clears the flag; an
 *     equally-large diff with no implementing hunk fires it).
 *   - VAL-CROSS-005: checks against the E2-established intent basis (fires
 *     only when the E2 verb is unimplemented; stays silent otherwise).
 *
 * Off-equivalent behavior: a task with NO recognized intent verb
 * (intentVerbs empty — read-only / review / non-write paths) never trips the
 * probe regardless of the diff content (byte-identical to pre-E1 at the
 * probe level; the executor additionally gates the whole probe behind
 * atlas_dev.elevations.e1.mode).
 */
final class IntentFalsificationProbe
{
    /**
     * The honesty flag appended when the diff does not implement any of the
     * E2-established intent verbs carried on the contract (advisory channel:
     * drives the CompletionStateGate PASSED -> needs_review downgrade).
     */
    public const FLAG_INTENT_LIKELY_NOT_ADDRESSED = 'intent_likely_not_addressed';

    /**
     * Stopwords excluded from the intent's distinctive-token set so the
     * TIER 2 overlap check does not fire on common words ("the", "and",
     * "but", "test", "file", ...). Kept short on purpose: the goal is to
     * surface the SUBJECT of the intent (identifiers, domain nouns), not
     * generic task vocabulary. Lowercased, compared against lowercased tokens.
     */
    private const INTENT_STOPWORDS = [
        'a', 'an', 'the', 'and', 'or', 'but', 'in', 'on', 'at', 'to', 'of',
        'for', 'with', 'by', 'is', 'are', 'be', 'as', 'from', 'that', 'this',
        'it', 'its', 'into', 'only', 'run', 'change', 'do', 'not', 'no',
        'test', 'tests', 'file', 'files', 'command', 'commands', 'spec',
        'task', 'intent', 'code', 'src', 'app', 'fix', 'fixes', 'fixed',
        'corrigir', 'corrija', 'add', 'adicionar', 'adicione', 'remove',
        'remover', 'remova', 'rename', 'renomear', 'renomeie', 'refactor',
        'refatorar', 'extract', 'extrair', 'redirect', 'redirecionar',
        'gate', 'criar', 'create', 'ajustar', 'ajuste', 'expected', 'returns',
        'return', 'returns', 'failing', 'pass', 'passes', 'so', 'can',
        'we', 'you', 'i', 'they', 'he', 'she', 'make', 'makes', 'made',
        'when', 'where', 'which', 'who', 'how', 'what', 'why', 'if', 'else',
        'while', 'end', 'start', 'new', 'old', 'use', 'using', 'used',
    ];

    /**
     * Returns true when the diff does NOT traceably implement the
     * E2-established intent carried on the contract (VAL-E1-003). Returns
     * false when:
     *   - the task carries no recognized intent verb (intentVerbs empty =>
     *     nothing to probe; byte-identical to pre-E1 for non-write paths);
     *   - OR at least one added diff line implements the intent via TIER 1
     *     (verb surface form) or TIER 2 (intent subject overlap)
     *     (VAL-E1-005: the intent IS addressed).
     *
     * An empty/no-patch write task (verbs present, no added lines) returns
     * true (VAL-E1-014): an empty patch never silently clears a write intent.
     *
     * @param  ?DiffParseResult  $diffResult  the parsed diff to scan; null
     *                                        (e.g. parser unavailable) is
     *                                        conservatively treated as
     *                                        not-addressed for a write task
     *                                        (never silently green over an
     *                                        unevaluable intent).
     */
    public function isIntentLikelyNotAddressed(LightTaskContract $taskContract, ?DiffParseResult $diffResult): bool
    {
        // Only write tasks with a recognized intent verb can trip the probe.
        // A task with no recognized verb (intentVerbs empty) never fires,
        // regardless of the diff content (byte-identical to pre-E1).
        $intentVerbs = $taskContract->intentVerbs;
        if ($intentVerbs === []) {
            return false;
        }

        // Resolve the TIER 1 (verb surface forms) + TIER 2 (intent subject)
        // needle sets once. Both are sourced from the persisted contract
        // basis (VAL-CROSS-005): verb surface forms from
        // IntentActionExtractor's vocabulary, intent subject tokens from the
        // E2-populated intentText.
        $verbNeedles = $this->resolveVerbSurfaceForms($intentVerbs);
        $subjectNeedles = $this->resolveIntentSubjectTokens($taskContract->intentText, $intentVerbs);
        if ($verbNeedles === [] && $subjectNeedles === []) {
            // No basis to clear the flag AND no basis to assert it either:
            // conservatively do not fire (avoid false-positives). This path
            // is unreachable for verbs emitted by IntentActionExtractor.
            return false;
        }

        // No diff / no patch => write intent not addressed by any added line.
        // VAL-E1-014: an empty/no-patch write task fires the flag.
        if ($diffResult === null || ! $diffResult->hasPatch()) {
            return true;
        }

        $addedLines = $this->extractAddedLines((string) $diffResult->diff);
        if ($addedLines === []) {
            // MODE_PATCH with a body that contains no added lines (only
            // context/removals) => the write intent is not addressed.
            return true;
        }

        // VAL-E1-015: scan ALL added lines across ALL hunks (position-
        // independent). The diff addresses the intent if ANY added line
        // implements it via TIER 1 or TIER 2.
        foreach ($addedLines as $line) {
            if ($this->lineImplementsIntent($line, $verbNeedles, $subjectNeedles)) {
                return false;
            }
        }

        return true;
    }

    /**
     * E1 repair-loop feedback (VAL-E1-006, VAL-E1-013, VAL-CROSS-006):
     * produce a human-readable reason when the probe fires, and '' when it
     * does not. This reason is fed into the M2 repair loop as a SEPARATE
     * field (a live input the regenerated attempt can act on), NEVER by
     * mutating $failureExcerpt (which is hashed by
     * FailureSignatureHasher for same-signature-twice anti-spin).
     *
     * The reason references the unaddressed intent verbs and the intent
     * subject so the next repair iteration knows WHAT to implement. It is a
     * best-effort structural annotation sourced from the E2-established
     * basis (intentVerbs + intentText), model-irrelevant, and stable across
     * iterations for the same unaddressed intent (so it never destabilizes
     * the failure signature — it lives in its own prompt section, not in the
     * hashed excerpt).
     *
     * Returns '' when:
     *   - the probe does NOT fire (intent addressed, or no recognized verb);
     *   - OR no reason can be composed from the persisted basis (defensive:
     *     the executor treats '' as "no probe reason to feed forward", which
     *     is byte-identical to the pre-feedback baseline).
     */
    public function probeReason(LightTaskContract $taskContract, ?DiffParseResult $diffResult): string
    {
        // Mirror isIntentLikelyNotAddressed: only fire for a write task whose
        // intent is not traceably implemented by the diff. When the probe
        // does not fire, there is no reason to feed forward.
        if (! $this->isIntentLikelyNotAddressed($taskContract, $diffResult)) {
            return '';
        }

        $intentVerbs = $taskContract->intentVerbs;
        if ($intentVerbs === []) {
            // Defensive: isIntentLikelyNotAddressed already returns false for
            // an empty verb set, so this branch is unreachable. Kept for
            // total clarity.
            return '';
        }

        // Compose the reason from the E2-established basis: the verb set the
        // diff failed to implement + the intent text (the subject the next
        // iteration must touch). The reason is intentionally concise and
        // references the canonical verb labels so the regenerated attempt has
        // an actionable signal.
        $verbList = implode(', ', $intentVerbs);
        $intentText = trim($taskContract->intentText);

        if ($intentText !== '') {
            return sprintf(
                'The previous diff did not traceably implement the declared intent verbs (%s). '
                .'Next attempt must address the intent: "%s".',
                $verbList,
                $intentText,
            );
        }

        return sprintf(
            'The previous diff did not traceably implement the declared intent verbs (%s). '
            .'Next attempt must address at least one of these verbs.',
            $verbList,
        );
    }

    /**
     * Resolve the case-insensitive TIER 1 verb surface-form needles for the
     * given canonical verb labels, sourced from IntentActionExtractor's
     * recognized vocabulary. Includes the canonical labels themselves so a
     * diff line mentioning the label (e.g. a comment "implements corrigir")
     * also clears the probe. Returns the deduped, non-empty needle list.
     *
     * @param  list<string>  $intentVerbs
     * @return list<string>
     */
    private function resolveVerbSurfaceForms(array $intentVerbs): array
    {
        $needles = [];
        $seen = [];
        foreach ($intentVerbs as $label) {
            $lower = strtolower((string) $label);
            if ($lower !== '' && ! isset($seen[$lower])) {
                $needles[] = $lower;
                $seen[$lower] = true;
            }
        }
        foreach (IntentActionExtractor::RECOGNIZED_VERBS as $surfaceForm => $label) {
            if (! in_array($label, $intentVerbs, true)) {
                continue;
            }
            $lower = strtolower((string) $surfaceForm);
            if ($lower !== '' && ! isset($seen[$lower])) {
                $needles[] = $lower;
                $seen[$lower] = true;
            }
        }

        return $needles;
    }

    /**
     * Resolve the case-insensitive TIER 2 intent subject tokens: the
     * distinctive tokens (identifiers, file names, domain nouns) extracted
     * from the E2-populated intentText, excluding stopwords and the verb
     * words themselves. These represent the OBJECT of the intent — the
     * subject matter a genuine implementation must touch. An added line
     * sharing any of these tokens is traceably implementing the intent.
     *
     * Tokens are extracted by splitting on non-word characters and keeping
     * tokens of length >= 3 that are not stopwords. This is intentionally
     * permissive (a real fix to the subject of the intent clears the probe)
     * while still excluding generic task vocabulary.
     *
     * @param  list<string>  $intentVerbs  canonical verb labels (also excluded)
     * @return list<string>
     */
    private function resolveIntentSubjectTokens(string $intentText, array $intentVerbs): array
    {
        $trimmed = trim($intentText);
        if ($trimmed === '') {
            return [];
        }

        $excluded = array_merge(self::INTENT_STOPWORDS, array_map('strtolower', $intentVerbs));
        $excludedSet = array_flip($excluded);

        $tokens = [];
        $seen = [];
        $parts = preg_split('/[^\p{L}\p{N}_]+/u', strtolower($trimmed)) ?: [];
        foreach ($parts as $part) {
            if ($part === '' || isset($seen[$part])) {
                continue;
            }
            // Keep tokens of length >= 3 that are not stopwords / verb labels.
            // Length floor avoids firing on short fragments ('x', 'db').
            if (strlen($part) < 3) {
                continue;
            }
            if (isset($excludedSet[$part])) {
                continue;
            }
            $tokens[] = $part;
            $seen[$part] = true;
        }

        return $tokens;
    }

    /**
     * Extract the ADDED lines from a unified diff: lines starting with a
     * single '+' (the '+++' file headers are excluded). The leading '+' is
     * stripped so the heuristic matches the line's content, not the diff
     * marker. Scans every line (all hunks), preserving nothing about file
     * boundaries — the probe is position-independent (VAL-E1-015).
     *
     * @return list<string>
     */
    private function extractAddedLines(string $diff): array
    {
        if ($diff === '') {
            return [];
        }

        $lines = [];
        foreach (preg_split('/\r\n|\n|\r/', $diff) ?: [] as $raw) {
            if ($raw === '' || $raw[0] !== '+') {
                continue;
            }
            // Skip '+++' file headers (added-file markers, not content).
            if (strlen($raw) >= 3 && substr($raw, 0, 3) === '+++') {
                continue;
            }
            // Strip the leading '+' so the heuristic scans the line content.
            $lines[] = substr($raw, 1);
        }

        return $lines;
    }

    /**
     * Whether the (lowercased) added line implements the intent via TIER 1
     * (verb surface form) or TIER 2 (intent subject token overlap). Both
     * tiers use case-insensitive substring matching, mirroring the
     * established IntentActionExtractor / IntakeNormalizer heuristic. The
     * line "implements" the intent when it mentions the verb's surface form
     * OR shares a distinctive subject token with the intent text. This is a
     * best-effort structural heuristic, not a semantic proof — but it is the
     * model-irrelevant floor that prevents a silent green over an
     * intent-missing diff.
     *
     * @param  list<string>  $verbNeedles  lowercased verb surface forms + labels
     * @param  list<string>  $subjectNeedles  lowercased intent subject tokens
     */
    private function lineImplementsIntent(string $line, array $verbNeedles, array $subjectNeedles): bool
    {
        $haystack = strtolower($line);
        if ($haystack === '') {
            return false;
        }

        foreach ($verbNeedles as $needle) {
            if (str_contains($haystack, $needle)) {
                return true;
            }
        }

        foreach ($subjectNeedles as $needle) {
            if (str_contains($haystack, $needle)) {
                return true;
            }
        }

        return false;
    }
}
