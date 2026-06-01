<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Generated;

/**
 * Self-Construction Builder Persona And Handoff — pure, deterministic certifier.
 *
 * Turns the documented operating posture for "any AI building Atlas" into a
 * machine-checkable gate. Two related obligations are decided here WITHOUT
 * trusting free-form prose:
 *
 *   1. Required Opening Move: before self-construction edits, the builder must
 *      have identified eight things (goal, target capability, authoritative
 *      docs, hot files, git delta, risk, smallest safe slice, required
 *      validation). Any missing field => the builder is NOT cleared to edit.
 *
 *   2. Handoff Packet: every paused/completed task must leave a 13-key packet.
 *      A packet missing any documented key, or claiming a maturity jump with
 *      no evidence, or asserting all gates passed while listing failed gates,
 *      is NOT a valid handoff.
 *
 * It also enforces Long Session Continuity (the six resume questions) and the
 * Tone Of Work contract (avoid grand claims / vague "enterprise" language;
 * prefer exact claims with concrete paths).
 *
 * Contract (from the doc Builder Persona / Required Opening Move / Handoff
 * Packet / Long Session Continuity / Tone Of Work sections):
 *   Entrada (certifyHandoff): objective, target_capability, maturity_before,
 *            maturity_after, docs_changed[], code_changed[], hot_files[],
 *            commands_run[], gates_passed[], gates_failed[], evidence[],
 *            residual_risk, next_safe_step, do_not_touch[], plus the six
 *            continuity answers and free-form summary text for tone scan.
 *   Saida:   persona ('governed_architect' always — never generic coder),
 *            opening_move {complete, missing[]}, handoff {complete, missing[],
 *            violations[]}, continuity {answerable, unanswered[]},
 *            tone {clean, banned_phrases[]}, edits_allowed (bool),
 *            handoff_valid (bool), required_next_action, status.
 *
 * Documented rules this code genuinely enforces:
 *   - Persona is fixed: the builder "acts as governed architect, not generic
 *     coder" => persona is always 'governed_architect'; the negative roles
 *     (generic_code_generator, product_decorator, provider_wrapper,
 *     memory_free_session, impressive_diff_optimizer) are emitted as the
 *     explicit not-this list.
 *   - Required Opening Move: all EIGHT fields must be present and non-empty,
 *     else edits_allowed=false and required_next_action=complete_opening_move.
 *   - Handoff Packet: all THIRTEEN documented keys must be present; a missing
 *     key makes handoff_valid=false.
 *   - "Handoff must preserve state, constraints, files, gates, risks and next
 *     action." => a maturity_after strictly greater than maturity_before with
 *     EMPTY evidence is a violation (claim without evidence); gates_passed and
 *     gates_failed sharing a gate id is a contradiction violation; an empty
 *     do_not_touch on a high residual_risk handoff is a violation (constraints
 *     not preserved).
 *   - Long Session Continuity: if any of the six resume questions is
 *     unanswered, "context reconstruction must happen before edits" =>
 *     edits_allowed=false and required_next_action=reconstruct_context.
 *   - Tone Of Work: summary text containing banned vague-claim phrases (e.g.
 *     "enterprise-grade", "fully production ready", "massive rewrite") makes
 *     tone.clean=false and is a handoff violation — the doc says avoid grand
 *     claims without evidence and vague "enterprise" language without gates.
 *
 * Non-goals honoured (read-only): it does NOT perform edits, does NOT write
 * any handoff to disk, does NOT mutate memory, does NOT promote maturity and
 * does NOT mark a task complete. It only decides whether the persona posture
 * and handoff packet satisfy the documented contract.
 *
 * @see docs/engineering-knowledge-base/self-construction/builder-persona-and-handoff.md
 */
final class AtlasBuilderPersonaAndHandoffService
{
    /** Stable schema id this certifier emits. */
    public const SCHEMA = 'atlas.self_construction_builder_persona_and_handoff.v1';

    /** The builder persona is fixed by the doc; it is never a generic coder. */
    public const PERSONA = 'governed_architect';

    /** Output statuses (closed set). */
    public const STATUS_READY = 'ready_to_build';
    public const STATUS_OPENING_MOVE_INCOMPLETE = 'opening_move_incomplete';
    public const STATUS_CONTEXT_RECONSTRUCTION_REQUIRED = 'context_reconstruction_required';
    public const STATUS_HANDOFF_INVALID = 'handoff_invalid';

    /** Required-next-action verbs (closed set). */
    public const NEXT_COMPLETE_OPENING_MOVE = 'complete_opening_move';
    public const NEXT_RECONSTRUCT_CONTEXT = 'reconstruct_context_before_edits';
    public const NEXT_REPAIR_HANDOFF = 'repair_handoff_packet';
    public const NEXT_PROCEED_SMALL_SLICE = 'proceed_with_smallest_safe_slice';

    /**
     * The eight fields the builder MUST identify before self-construction work
     * (Required Opening Move section, in document order).
     *
     * @var list<string>
     */
    public const OPENING_MOVE_FIELDS = [
        'current_goal',
        'target_capability',
        'authoritative_docs',
        'hot_files',
        'current_git_delta',
        'risk',
        'smallest_safe_slice',
        'required_validation',
    ];

    /**
     * The thirteen keys every Handoff Packet must carry (Handoff Packet YAML,
     * in document order).
     *
     * @var list<string>
     */
    public const HANDOFF_KEYS = [
        'objective',
        'target_capability',
        'maturity_before',
        'maturity_after',
        'docs_changed',
        'code_changed',
        'hot_files',
        'commands_run',
        'gates_passed',
        'gates_failed',
        'evidence',
        'residual_risk',
        'next_safe_step',
        'do_not_touch',
    ];

    /**
     * The six questions the next AI must be able to answer after compaction or
     * resume (Long Session Continuity section).
     *
     * @var list<string>
     */
    public const CONTINUITY_QUESTIONS = [
        'what_is_being_built',
        'why_this_priority',
        'which_docs_are_law',
        'what_changed',
        'what_remains_unsafe',
        'next_smallest_step',
    ];

    /**
     * The negative roles the builder is explicitly NOT (Builder Persona
     * "is not" list).
     *
     * @var list<string>
     */
    private const NOT_THIS = [
        'generic_code_generator',
        'product_decorator',
        'provider_wrapper',
        'memory_free_session',
        'impressive_diff_optimizer',
    ];

    /**
     * Vague / grand-claim phrases the Tone Of Work section says to avoid. Kept
     * lowercase; matching is case-insensitive substring on the summary text.
     *
     * @var list<string>
     */
    private const BANNED_TONE_PHRASES = [
        'enterprise-grade',
        'enterprise grade',
        'fully production ready',
        'production-ready everywhere',
        'massive rewrite',
        'broad rewrite',
        'world-class',
        'revolutionary',
        'game-changing',
    ];

    /** Residual-risk levels that demand an explicit do_not_touch boundary. */
    private const HIGH_RISK = 'high';

    /**
     * Certify a builder posture + handoff packet against the documented
     * contract. Pure: no filesystem, no git, no DB, no clock.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function certifyHandoff(array $input = []): array
    {
        // ---- Required Opening Move (eight fields) ---------------------------
        $openingMissing = [];
        foreach (self::OPENING_MOVE_FIELDS as $field) {
            if (! $this->present($input[$field] ?? null)) {
                $openingMissing[] = $field;
            }
        }
        $openingComplete = $openingMissing === [];

        // ---- Long Session Continuity (six questions) ------------------------
        $continuityAnswers = $this->mapOrEmpty($input['continuity_answers'] ?? null);
        $unanswered = [];
        foreach (self::CONTINUITY_QUESTIONS as $question) {
            if (! $this->present($continuityAnswers[$question] ?? null)) {
                $unanswered[] = $question;
            }
        }
        $continuityAnswerable = $unanswered === [];

        // ---- Handoff Packet completeness (thirteen keys) --------------------
        // A key counts as present when it is supplied with a non-null value.
        // An empty list (e.g. gates_failed: []) is a LEGITIMATE value — "no
        // failed gates" — so completeness checks key existence, not non-empty.
        // The semantic checks below catch lists that must NOT be empty.
        $handoff = $this->mapOrEmpty($input['handoff'] ?? null);
        $handoffMissing = [];
        foreach (self::HANDOFF_KEYS as $key) {
            if (! array_key_exists($key, $handoff) || $handoff[$key] === null) {
                $handoffMissing[] = $key;
            }
        }

        // ---- Handoff Packet semantic violations -----------------------------
        $violations = $this->handoffViolations($handoff);

        // ---- Tone Of Work scan ----------------------------------------------
        $summaryText = $this->stringOrNull($input['summary'] ?? null) ?? '';
        $bannedPhrases = $this->scanTone($summaryText);
        $toneClean = $bannedPhrases === [];
        if (! $toneClean) {
            $violations[] = 'tone_violation_vague_or_grand_claim';
        }

        $violations = array_values(array_unique($violations));

        $handoffComplete = $handoffMissing === [];
        $handoffValid = $handoffComplete && $violations === [];

        // ---- Decision composition -------------------------------------------
        // Edits are only cleared when the opening move is complete AND the
        // resume context is answerable. The handoff packet is a separate
        // obligation for the paused/completed state.
        $editsAllowed = $openingComplete && $continuityAnswerable;

        [$status, $next] = $this->decide(
            $openingComplete,
            $continuityAnswerable,
            $handoffValid,
        );

        $decision = [
            'schema_version' => self::SCHEMA,
            'persona' => self::PERSONA,
            'persona_not_this' => self::NOT_THIS,
            'status' => $status,
            'edits_allowed' => $editsAllowed,
            'handoff_valid' => $handoffValid,
            'opening_move' => [
                'complete' => $openingComplete,
                'required' => self::OPENING_MOVE_FIELDS,
                'missing' => $openingMissing,
            ],
            'continuity' => [
                'answerable' => $continuityAnswerable,
                'required' => self::CONTINUITY_QUESTIONS,
                'unanswered' => $unanswered,
            ],
            'handoff' => [
                'complete' => $handoffComplete,
                'required_keys' => self::HANDOFF_KEYS,
                'missing_keys' => $handoffMissing,
                'violations' => $violations,
            ],
            'tone' => [
                'clean' => $toneClean,
                'banned_phrases' => $bannedPhrases,
            ],
            'required_next_action' => $next,
        ];

        return [
            'schema_version' => self::SCHEMA,
            'status' => $status,
            'mode' => 'read_only_builder_persona_and_handoff_certification',
            'persona' => self::PERSONA,
            'edits_allowed' => $editsAllowed,
            'handoff_valid' => $handoffValid,
            'decision' => $decision,
            'decision_hash' => $this->stableHash($decision),
            'non_execution_guarantees' => [
                'certifier_does_not_perform_edits',
                'certifier_does_not_write_handoff_to_disk',
                'certifier_does_not_mutate_memory',
                'certifier_does_not_promote_maturity',
                'certifier_does_not_mark_task_complete',
            ],
            'human_summary' => $this->humanSummary($status),
        ];
    }

    /**
     * Semantic checks that a packet preserves "state, constraints, files,
     * gates, risks and next action" — beyond mere key presence.
     *
     * @param  array<string, mixed>  $handoff
     * @return list<string>
     */
    private function handoffViolations(array $handoff): array
    {
        $violations = [];

        // Claim without evidence: maturity advanced but no evidence listed.
        $before = $this->numeric($handoff['maturity_before'] ?? null);
        $after = $this->numeric($handoff['maturity_after'] ?? null);
        $evidence = $this->listOfStrings($handoff['evidence'] ?? []);
        if ($before !== null && $after !== null && $after > $before && $evidence === []) {
            $violations[] = 'maturity_claim_without_evidence';
        }

        // Contradiction: the same gate id appears in both passed and failed.
        $passed = $this->listOfStrings($handoff['gates_passed'] ?? []);
        $failed = $this->listOfStrings($handoff['gates_failed'] ?? []);
        $overlap = array_values(array_intersect($passed, $failed));
        if ($overlap !== []) {
            $violations[] = 'gate_reported_passed_and_failed:'.implode(',', $overlap);
        }

        // Constraints not preserved: high residual risk with no do_not_touch.
        $risk = $this->stringOrNull($handoff['residual_risk'] ?? null);
        $doNotTouch = $this->listOfStrings($handoff['do_not_touch'] ?? []);
        if ($risk === self::HIGH_RISK && $doNotTouch === []) {
            $violations[] = 'high_risk_without_do_not_touch_boundary';
        }

        return $violations;
    }

    /**
     * @return array{0: string, 1: string} [status, required_next_action]
     */
    private function decide(bool $openingComplete, bool $continuityAnswerable, bool $handoffValid): array
    {
        // Opening move is the first obligation before any edit.
        if (! $openingComplete) {
            return [self::STATUS_OPENING_MOVE_INCOMPLETE, self::NEXT_COMPLETE_OPENING_MOVE];
        }

        // Then resume context must be reconstructable.
        if (! $continuityAnswerable) {
            return [self::STATUS_CONTEXT_RECONSTRUCTION_REQUIRED, self::NEXT_RECONSTRUCT_CONTEXT];
        }

        // A packet that exists but is malformed must be repaired.
        if (! $handoffValid) {
            return [self::STATUS_HANDOFF_INVALID, self::NEXT_REPAIR_HANDOFF];
        }

        return [self::STATUS_READY, self::NEXT_PROCEED_SMALL_SLICE];
    }

    /**
     * Case-insensitive substring scan for banned tone phrases.
     *
     * @return list<string>
     */
    private function scanTone(string $summary): array
    {
        if (trim($summary) === '') {
            return [];
        }

        $haystack = mb_strtolower($summary);
        $hits = [];
        foreach (self::BANNED_TONE_PHRASES as $phrase) {
            if (str_contains($haystack, $phrase)) {
                $hits[] = $phrase;
            }
        }

        return array_values(array_unique($hits));
    }

    private function humanSummary(string $status): string
    {
        return match ($status) {
            self::STATUS_READY => 'Builder persona is governed architect; opening move complete, resume context answerable, handoff packet valid — proceed with the smallest safe slice.',
            self::STATUS_OPENING_MOVE_INCOMPLETE => 'Required Opening Move is incomplete; identify the missing fields before any self-construction edit.',
            self::STATUS_CONTEXT_RECONSTRUCTION_REQUIRED => 'Long Session Continuity questions are unanswered; reconstruct context before edits.',
            self::STATUS_HANDOFF_INVALID => 'Handoff packet is missing keys or contains contradictions/unsupported claims; repair it before pausing or claiming completion.',
            default => 'Builder persona certification produced an unknown status.',
        };
    }

    private function present(mixed $value): bool
    {
        if ($value === null) {
            return false;
        }
        if (is_string($value)) {
            return trim($value) !== '';
        }
        if (is_array($value)) {
            return $value !== [];
        }
        if (is_bool($value)) {
            return $value;
        }

        return true;
    }

    private function stringOrNull(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    private function numeric(mixed $value): ?float
    {
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }
        if (is_string($value) && is_numeric(trim($value))) {
            return (float) trim($value);
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function mapOrEmpty(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }

    /**
     * @return list<string>
     */
    private function listOfStrings(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $out = [];
        foreach ($value as $item) {
            $str = $this->stringOrNull($item);
            if ($str !== null) {
                $out[] = $str;
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * Stable, order-independent hash of a decision payload for evidence.
     *
     * @param  array<string, mixed>  $payload
     */
    private function stableHash(array $payload): string
    {
        $normalized = $this->normalizeForHash($payload);

        return 'sha256:'.hash('sha256', (string) json_encode($normalized, JSON_UNESCAPED_SLASHES));
    }

    private function normalizeForHash(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $isList = array_is_list($value);
        $out = [];
        foreach ($value as $key => $item) {
            $out[$key] = $this->normalizeForHash($item);
        }

        if (! $isList) {
            ksort($out);
        }

        return $out;
    }
}
