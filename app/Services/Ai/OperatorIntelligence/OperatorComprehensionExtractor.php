<?php

declare(strict_types=1);

namespace App\Services\Ai\OperatorIntelligence;

use App\Models\AiJob;
use App\Services\Ai\AiProviderManager;
use App\Services\Ai\AtlasDecide\AtlasStructuredOutputValidator;
use App\Services\Ai\Compounding\AtlasCaptureQualityGate;
use Illuminate\Support\Str;
use Throwable;

/**
 * The comprehension brain of operator-learning: reads an operator turn (or multi-turn
 * window) and extracts structured profile signals across the 170-item taxonomy —
 * INCLUDING inference — instead of the regex detector's ~20 trigger phrases.
 *
 * It is fail-closed against the central risk (inventing a preference the operator does
 * not hold). A signal must survive EVERY gate or it is dropped:
 *   1. provider real-or-blocked   — a failed/limited/empty model call yields NO signals
 *   2. structured-output schema   — closed-set taxonomy enum; an invented 171st id fails
 *   3. evidence-quote verbatim lock — the quote must be a contiguous substring of the
 *                                     real text (case+space fold only), ≥ MIN_QUOTE_CHARS
 *   4. taxonomy known-id          — unknown id is DROPPED (never falls through to OP-071)
 *   5. quality gate               — noise/dup rejected, identical content collapses
 *   6. EXPLICIT_ONLY + hedge      — registry explicit-only items honour the LLM's 'explicit'
 *                                   label ONLY over a hedge-free quote AND source; otherwise
 *                                   provenance is corrected to inference + forced deep review
 *   7. breadth-grounding          — a universal claim (sempre/todos/…) must be verbatim in the
 *                                   quote, and a global scope on a visibly scoped quote clamps
 *   8. privacy raise-only floor   — class = max(LLM, registry SENSITIVE_DEFAULT); sensitive+ is
 *                                   redacted at rest and never provider-external
 *   9. tier→confidence + high-stakes → ≤0.35 review (the classifier clamps implicit again)
 *
 * Phase 1 = LEARN/CAPTURE only. Output is detector-shaped signals fed to the unchanged
 * OperatorSignalCaptureService pipeline; nothing auto-applies (shadow_mode + the gates).
 */
final class OperatorComprehensionExtractor
{
    public const SCHEMA_VERSION = 'atlas.operator_comprehension_extractor.v1';

    private const MIN_QUOTE_CHARS = 20;

    /** tier → numeric confidence (the classifier clamps implicit/inferred again, ≤0.6). */
    private const TIER_CONFIDENCE = ['explicit' => 0.9, 'repeated' => 0.8, 'single_inference' => 0.55];

    /**
     * Uncertainty/hedging markers. Their presence means a statement is NOT an unambiguous
     * explicit declaration — used to gate the registry's EXPLICIT_ONLY items so the LLM's
     * bare 'explicit' label can never be honored over a hedged source. Folded + matched
     * at a word boundary (space-prefixed).
     */
    private const HEDGE_TOKENS = [
        'as vezes', 'acho que', 'eu acho', 'talvez', 'nao sei', 'sei la', 'pode ser',
        'quem sabe', 'imagino que', 'suponho', 'me parece', 'parece que', 'meio que',
        'nao tenho certeza', 'sem certeza', 'nao tenho bem certeza',
        'i think', 'i guess', 'maybe', 'perhaps', 'not sure', 'kind of', 'sort of',
        'i suppose', 'probably',
    ];

    /** Universal/permanent quantifiers — a claim using one must have it grounded in the quote. */
    private const UNIVERSAL_TOKENS = [
        'sempre', 'todos', 'todas', 'qualquer', 'nunca', 'jamais', 'para sempre', 'em todos',
        'always', 'every', 'everything', 'all', 'never', 'forever',
    ];

    /** Momentary/scoping markers — a quote carrying one is a poor anchor for a forever-rule. */
    private const MOMENTARY_MARKERS = [
        'queria testar', 'quero testar', 'vou testar', 'testar uma', 'um pouco', 'nesse caso',
        'neste caso', 'nesse momento', 'desta vez', 'dessa vez', 'so dessa vez', 'por agora',
        'so queria', 'so quero', 'this time', 'for now', 'just now', 'right now', 'in this case',
        'just wanted', 'wanted to test', 'a bit', 'one off', 'just this once',
    ];

    private ?string $cannedResponse = null;

    private ?bool $cannedRefute = null;

    public function __construct(
        private readonly AtlasStructuredOutputValidator $validator,
        private readonly AtlasCaptureQualityGate $quality,
        private readonly OperatorTaxonomyRegistry $registry,
        private readonly AiProviderManager $providers,
    ) {}

    /** Inject a canned model response to exercise the gate stack without a live provider. */
    public function setCannedResponseForTesting(?string $json): void
    {
        $this->cannedResponse = $json;
    }

    /** Inject the canned refute verdict (true=supported / false=refuted) for testing. */
    public function setCannedRefuteForTesting(?bool $supported): void
    {
        $this->cannedRefute = $supported;
    }

    /**
     * @param  array<string,mixed>  $context
     * @return list<array<string,mixed>>  detector-shaped signals for OperatorSignalCaptureService
     */
    public function extract(string $input, array $context = []): array
    {
        $text = trim($input);
        if (mb_strlen($text) < 8) {
            return [];
        }

        $raw = $this->callProvider($text);
        if ($raw === null) {
            return []; // real-or-blocked: never fabricate on a failed call
        }

        $result = $this->validator->validate($raw, $this->schema());
        if (($result['valid'] ?? false) !== true || ! is_array($result['value'] ?? null)) {
            return [];
        }

        $signals = [];
        $seen = [];
        foreach ((array) ($result['value']['signals'] ?? []) as $row) {
            $signal = $this->accept(is_array($row) ? $row : [], $text);
            if ($signal === null) {
                continue;
            }
            $key = $signal['taxonomy_item_id'].'|'.($signal['metadata']['content_hash'] ?? $signal['normalized_claim']);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $signals[] = $signal;
        }

        return $signals;
    }

    /**
     * Run one signal through every gate. Returns the detector-shaped array or null.
     *
     * @param  array<string,mixed>  $row
     * @return array<string,mixed>|null
     */
    private function accept(array $row, string $sourceText): ?array
    {
        $id = strtoupper(trim((string) ($row['taxonomy_item_id'] ?? '')));
        $item = $this->registry->get($id);
        // Gate 4: unknown id → DROP (LLM must cite a real menu id; never fall through).
        // Layer-1 system telemetry is never an extractor target either.
        if ($item === null || $item['layer'] === OperatorTaxonomyRegistry::LAYER_SYSTEM) {
            return null;
        }

        $claim = trim((string) ($row['claim'] ?? ''));
        $quote = trim((string) ($row['evidence_quote'] ?? ''));
        if ($claim === '' || ! $this->quoteIsGrounded($quote, $sourceText)) {
            return null; // Gate 3: a claim with no real verbatim anchor is invention.
        }

        $inferenceType = ($row['inference_type'] ?? 'implicit') === 'explicit' ? 'explicit' : 'implicit';

        // EXPLICIT_ONLY policy (registry inferability): these items may settle ONLY from a
        // genuine, unambiguous explicit declaration. The model is the SOLE, UNANCHORED judge
        // of "explicit", so its bare label is NOT trusted here — we independently require the
        // cited quote AND its surrounding source to be hedge-free ("as vezes acho que… mas
        // nao sei" never counts). An unverified explicit-only claim has its provenance
        // corrected to inference and is forced deep into review; it can never ride 0.9 to
        // auto-apply. This wires the previously dead registry floor structurally.
        $explicitOnly = (string) ($item['inferability'] ?? '') === 'explicit_only';
        $verifiedExplicit = $inferenceType === 'explicit'
            && ! $this->isHedged($quote)
            && ! $this->isHedged($sourceText);
        $explicitOnlyUnverified = $explicitOnly && ! $verifiedExplicit;
        if ($explicitOnlyUnverified) {
            $inferenceType = 'implicit'; // correct provenance — this is not a verified declaration
        }

        $tier = (string) ($row['confidence_tier'] ?? ($inferenceType === 'explicit' ? 'explicit' : 'single_inference'));
        $tier = isset(self::TIER_CONFIDENCE[$tier]) ? $tier : 'single_inference';
        if ($explicitOnlyUnverified) {
            $tier = 'single_inference'; // an unverified explicit-only item cannot keep an 'explicit' tier
        }

        $scopeType = in_array((string) ($row['scope_type'] ?? 'global'), ['global', 'project', 'session', 'thread'], true)
            ? (string) ($row['scope_type'] ?? 'global') : 'global';

        // Gate 5: quality + content-dedup (kills the "saved 50x" noise mode).
        $verdict = $this->quality->assess(['kind' => 'operator_'.$inferenceType, 'claim' => $claim, 'content' => ['quote' => $quote]]);
        if (($verdict['admit'] ?? false) !== true) {
            return null;
        }

        // Privacy RAISE-ONLY against the registry's declared floor — the SENSITIVE_DEFAULT
        // items (OP-131/132/… intrinsically sensitive) are protected STRUCTURALLY, not by
        // the keyword scan the LLM can dodge by labelling them 'normal'.
        $llmPrivacy = in_array((string) ($row['privacy_class'] ?? 'normal'), ['normal', 'private', 'sensitive', 'secret'], true)
            ? (string) ($row['privacy_class'] ?? 'normal') : 'normal';
        $privacy = $this->raisePrivacy($llmPrivacy, (string) ($item['privacy_default'] ?? 'normal'));

        $confidence = self::TIER_CONFIDENCE[$tier];
        // Force DEEP review (≤0.35) for the costliest cases regardless of the LLM's label:
        // a high-stakes id, or an explicit-only id reached WITHOUT a verified declaration.
        // Closes the hedged-source-labelled-explicit bypass for the whole protected set.
        if ($item['high_stakes'] || $explicitOnlyUnverified) {
            $confidence = min($confidence, 0.35);
        }
        // Over-generalization guard: a sweeping claim (sempre/todos/…) whose breadth is NOT
        // verbatim-grounded in the quote, or a global scope riding a visibly momentary/scoped
        // quote, is the classic "real fragment, fabricated rule" — the quote anchors the
        // WORDS, not the claimed breadth. Clamp to review.
        if ($this->overGeneralizes($claim, $quote)
            || ($scopeType === 'global' && ($inferenceType === 'implicit' || $this->quoteIsVisiblyScoped($quote)))) {
            $confidence = min($confidence, 0.55);
        }

        // Refute pass (the strongest claim-vs-quote defense): a 2nd model is asked to
        // DISPROVE the preference. Runs for the risky subset AND for ANYTHING that could
        // clear the auto-apply floor (≥0.85) — so no signal can ride an 'explicit' label
        // into automatic application without a second model confirming it. A claim the
        // 2nd model cannot confirm is DROPPED, not queued.
        $refuteEnabled = (bool) config('atlas_operator_intelligence.comprehension_refute_enabled', true);
        $needsRefute = $inferenceType === 'implicit' || $item['high_stakes'] || $confidence >= 0.85;
        if ($needsRefute && $refuteEnabled && ! $this->refuteSupports($claim, $quote)) {
            return null;
        }

        // Auto-apply provenance — the positive marker the gate REQUIRES before any signal
        // may auto-apply. Stamped ONLY when the refute safety is enabled; if the operator
        // disables refute, the marker is withheld and NOTHING auto-applies (fail-safe).
        $autoApplyProvenance = $refuteEnabled ? OperatorLearningGate::AUTO_APPLY_PROVENANCE : 'comprehension_unrefuted';

        $quoteForStore = $privacy === 'normal' ? $quote : '[redacted-quote]';

        return [
            'taxonomy_item_id' => $id,
            'claim' => $claim,
            'normalized_claim' => $claim,
            'signal_kind' => Str::startsWith($id, 'COL-') ? 'collaboration_preference'
                : ($inferenceType === 'implicit' ? 'operator_inference' : 'operator_preference'),
            'privacy_class' => $privacy,
            'confidence' => $confidence,
            'inference_type' => $inferenceType,
            'confidence_tier' => $tier,
            'scope_type' => $scopeType,
            'metadata' => [
                'extractor' => self::SCHEMA_VERSION,
                'auto_apply_provenance' => $autoApplyProvenance,
                'evidence_quote' => $quoteForStore,
                'inference_basis' => Str::limit((string) ($row['inference_basis'] ?? ''), 240, ''),
                'validity_hint' => (string) ($row['validity'] ?? $item['validity_default']),
                'high_stakes' => (bool) $item['high_stakes'],
                'requires_refutation' => $needsRefute,
                'content_hash' => $verdict['content_hash'] ?? null,
            ],
        ];
    }

    /**
     * Verbatim lock: the quote must appear as a CONTIGUOUS run in the source with only
     * case + whitespace folded (NOT punctuation-stripped — that would degrade the lock to
     * loose word-bag containment), and be long enough to be distinctive.
     */
    private function quoteIsGrounded(string $quote, string $source): bool
    {
        $q = $this->fold($quote);
        if (mb_strlen($q) < self::MIN_QUOTE_CHARS) {
            return false;
        }

        return str_contains($this->fold($source), $q);
    }

    private function fold(string $s): string
    {
        $s = Str::ascii(mb_strtolower($s));

        return trim(preg_replace('/\s+/', ' ', $s) ?? '');
    }

    /** Return the MORE restrictive of two privacy classes (escalate-only). */
    private function raisePrivacy(string $a, string $b): string
    {
        $rank = ['normal' => 0, 'private' => 1, 'sensitive' => 2, 'secret' => 3];

        return ($rank[$a] ?? 0) >= ($rank[$b] ?? 0) ? $a : $b;
    }

    /**
     * A claim over-generalizes when its breadth is not grounded: a universal quantifier
     * (sempre/todos/…) in the claim that does NOT appear verbatim in the quote is fabricated
     * reach (the quote anchors the WORDS, so it must anchor the breadth too), or a
     * universalizing claim whose quote is visibly momentary/scoped. Either → force review.
     * This replaces the gameable length-only heuristic (a long-but-scoped quote dodged it).
     */
    private function overGeneralizes(string $claim, string $quote): bool
    {
        $c = ' '.$this->fold($claim);
        $q = ' '.$this->fold($quote);
        $universalizes = false;
        foreach (self::UNIVERSAL_TOKENS as $t) {
            if (str_contains($c, ' '.$t)) {
                $universalizes = true;
                if (! str_contains($q, ' '.$t)) {
                    return true; // claimed breadth absent from the verbatim quote
                }
            }
        }

        return $universalizes && $this->quoteIsVisiblyScoped($quote);
    }

    /** Quote carries a momentary/scoping marker → a weak anchor for any durable rule. */
    private function quoteIsVisiblyScoped(string $quote): bool
    {
        $q = ' '.$this->fold($quote);
        foreach (self::MOMENTARY_MARKERS as $m) {
            if (str_contains($q, ' '.$m)) {
                return true;
            }
        }

        return false;
    }

    /** Text contains an uncertainty/hedging marker → not an unambiguous explicit declaration. */
    private function isHedged(string $text): bool
    {
        $t = ' '.$this->fold($text);
        foreach (self::HEDGE_TOKENS as $h) {
            if (str_contains($t, ' '.$h)) {
                return true;
            }
        }

        return false;
    }

    private function callProvider(string $text): ?string
    {
        if ($this->cannedResponse !== null) {
            return trim($this->cannedResponse) !== '' ? $this->cannedResponse : null;
        }
        try {
            $providerKey = config('atlas_operator_intelligence.comprehension_provider_key');
            $job = (new AiJob())->forceFill([
                'model' => config('atlas_operator_intelligence.comprehension_model'),
                'timeout_seconds' => (int) config('atlas_operator_intelligence.comprehension_timeout_seconds', 120),
                'metadata' => ['purpose' => 'operator_comprehension_extraction', 'proposal_only' => true],
            ]);
            $result = $this->providers->get(is_string($providerKey) && $providerKey !== '' ? $providerKey : null)
                ->run($job, $this->prompt($text));

            if (! is_object($result) || ($result->ok ?? false) !== true) {
                return null;
            }
            $out = (string) ($result->output ?? $result->stdout ?? '');

            return trim($out) !== '' ? $out : null;
        } catch (Throwable) {
            return null; // provider unavailable / limit / error → no signals
        }
    }

    /**
     * Adversarial refute pass — a SECOND model tries to disprove the inferred preference.
     * Fail-closed: any non-confirmation (error, limit, unparseable, supported!=true) DROPS
     * the signal. Skipped in test mode unless a canned verdict is set.
     */
    private function refuteSupports(string $claim, string $quote): bool
    {
        if ($this->cannedRefute !== null) {
            return $this->cannedRefute;
        }
        if ($this->cannedResponse !== null) {
            return true; // extraction test mode without an explicit refute verdict → supported
        }
        try {
            $providerKey = config('atlas_operator_intelligence.comprehension_provider_key');
            $job = (new AiJob())->forceFill([
                'model' => config('atlas_operator_intelligence.comprehension_model'),
                'timeout_seconds' => (int) config('atlas_operator_intelligence.comprehension_timeout_seconds', 120),
                'metadata' => ['purpose' => 'operator_preference_refutation', 'proposal_only' => true],
            ]);
            $result = $this->providers->get(is_string($providerKey) && $providerKey !== '' ? $providerKey : null)
                ->run($job, $this->refutePrompt($claim, $quote));
            if (! is_object($result) || ($result->ok ?? false) !== true) {
                return false;
            }
            $out = (string) ($result->output ?? $result->stdout ?? '');
            $decoded = json_decode($out, true);
            if (! is_array($decoded) && preg_match('/\{.*\}/s', $out, $m) === 1) {
                $decoded = json_decode($m[0], true);
            }

            return is_array($decoded) && ($decoded['supported'] ?? false) === true;
        } catch (Throwable) {
            return false;
        }
    }

    private function refutePrompt(string $claim, string $quote): string
    {
        return <<<PROMPT
        A preference was inferred about a user. Judge HONESTLY whether the quote actually
        establishes this as a DURABLE preference, or whether it is one-off / context-bound /
        an over-generalization.
        CLAIM: {$claim}
        EVIDENCE QUOTE (verbatim from the user): "{$quote}"
        Respond with ONLY a JSON object: {"supported": true|false, "reason": "one sentence"}.
        "supported" is true ONLY if the quote clearly establishes the claim as a lasting preference.
        PROMPT;
    }

    private function prompt(string $text): string
    {
        $menu = $this->registry->promptMenu();

        return <<<PROMPT
        You extract DURABLE operator-profile signals from a user's message for a personal AI.
        Map ONLY to this taxonomy menu (id — description):

        {$menu}

        RULES (obey exactly):
        - Respond with a SINGLE JSON object {"signals":[...]} and NOTHING else.
        - If nothing in the message grounds a real operator-profile signal, return {"signals":[]}.
        - NEVER invent a preference the operator did not state or strongly imply.
        - NEVER use a taxonomy_item_id that is not in the menu above.
        - Every "evidence_quote" MUST be copied VERBATIM from the operator message (a real substring).
        - Use inference_type "explicit" only for a direct declaration; otherwise "implicit".
        - For [HIGH-STAKES] items, emit ONLY on an explicit, unambiguous declaration.
        - Each signal: {taxonomy_item_id, claim (3rd person, concise), evidence_quote, inference_type, confidence_tier (explicit|repeated|single_inference), privacy_class (normal|private|sensitive|secret), validity (durable|momentary|scoped), scope_type (global|project|session|thread), inference_basis (one sentence)}.

        OPERATOR MESSAGE:
        ---
        {$text}
        ---
        PROMPT;
    }

    /**
     * @return array<string,mixed>
     */
    private function schema(): array
    {
        $ids = array_values(array_filter(
            $this->registry->ids(),
            fn (string $id): bool => ! Str::startsWith($id, 'SYS-'),
        ));

        return [
            'type' => 'object',
            'required' => ['signals'],
            'properties' => [
                'signals' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'required' => ['taxonomy_item_id', 'claim', 'evidence_quote', 'inference_type'],
                        'properties' => [
                            'taxonomy_item_id' => ['type' => 'string', 'enum' => $ids],
                            'claim' => ['type' => 'string'],
                            'evidence_quote' => ['type' => 'string'],
                            'inference_type' => ['type' => 'string', 'enum' => ['explicit', 'implicit']],
                            'confidence_tier' => ['type' => 'string', 'enum' => ['explicit', 'repeated', 'single_inference']],
                            'privacy_class' => ['type' => 'string', 'enum' => ['normal', 'private', 'sensitive', 'secret']],
                            'validity' => ['type' => 'string', 'enum' => ['durable', 'momentary', 'scoped']],
                            'scope_type' => ['type' => 'string', 'enum' => ['global', 'project', 'session', 'thread']],
                            'inference_basis' => ['type' => 'string'],
                        ],
                    ],
                ],
            ],
        ];
    }
}
