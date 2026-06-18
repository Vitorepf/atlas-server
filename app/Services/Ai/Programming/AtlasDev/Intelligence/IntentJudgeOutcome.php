<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\AtlasDev\Intelligence;

/**
 * E1 — Outcome of the optional LLM-as-judge adversarial sub-layer for the
 * intent-falsification detector.
 *
 * The judge is an OPT-IN sub-layer behind the `atlas_dev.elevations.e1.
 * llm_judge` sub-flag (default OFF). It runs AFTER the deterministic
 * IntentFalsificationProbe verdict and may ONLY add doubt or escalate:
 *
 *   - DOUBT     => the judge suspects the diff also misses the intent (or
 *                  surfaces an additional concern). The detector records an
 *                  extra judge-attributed finding and/or escalates severity.
 *   - ESCALATE  => the judge escalates the finding to critical/blocker.
 *   - APPROVE   => the judge asserts the intent is addressed. This outcome
 *                  is IGNORED: the judge has no power to clear the
 *                  deterministic probe's flag or downgrade severity.
 *                  VAL-E1-011: a fake judge screaming APPROVE cannot remove
 *                  `intent_likely_not_addressed`.
 *   - DOWNGRADE => the judge attempts to lower severity. ALSO IGNORED (the
 *                  deterministic verdict is the floor; the judge is strictly
 *                  doubt-additive).
 *
 * This is a value object (not an enum) so the judge callable can carry a
 * free-form `note` (evidence/doubt rationale) without forcing the enum
 * variant to encode it. The detector interprets the outcome defensively:
 * only DOUBT/ESCALATE mutate the receipt, and only ever by ADDING a finding
 * or raising severity — never by removing or lowering.
 */
final class IntentJudgeOutcome
{
    public const DOUBT = 'doubt';

    public const ESCALATE = 'escalate';

    public const APPROVE = 'approve';

    public const DOWNGRADE = 'downgrade';

    public const SILENT = 'silent';

    /** @var list<string> */
    public const ALLOWED_KINDS = [
        self::DOUBT,
        self::ESCALATE,
        self::APPROVE,
        self::DOWNGRADE,
        self::SILENT,
    ];

    /**
     * Whether this outcome is "doubt-additive" (DOUBT or ESCALATE) — the only
     * outcomes the detector honors. APPROVE / DOWNGRADE / SILENT never mutate
     * the receipt (the deterministic probe verdict is the immovable floor).
     */
    public function isDoubtAdditive(): bool
    {
        return $this->kind === self::DOUBT || $this->kind === self::ESCALATE;
    }

    public function __construct(
        public readonly string $kind,
        public readonly ?string $note = null,
        public readonly ?string $escalateSeverity = null,
    ) {
        if (! in_array($kind, self::ALLOWED_KINDS, true)) {
            throw new \InvalidArgumentException(
                'IntentJudgeOutcome.kind must be one of ['.implode(',', self::ALLOWED_KINDS)."], got '{$kind}'."
            );
        }
    }

    /**
     * Judge suspects the diff also misses the intent (or surfaces an
     * additional concern). The detector records an extra judge-attributed
     * finding.
     */
    public static function doubt(?string $note = null): self
    {
        return new self(self::DOUBT, $note);
    }

    /**
     * Judge escalates the finding severity. The optional severity must be a
     * valid ReviewFinding severity (defaults to critical when omitted).
     */
    public static function escalate(?string $note = null, ?string $severity = null): self
    {
        return new self(self::ESCALATE, $note, $severity);
    }

    /**
     * Judge asserts the intent is addressed. IGNORED by the detector: the
     * judge has no power to clear the deterministic probe's flag.
     */
    public static function approve(?string $note = null): self
    {
        return new self(self::APPROVE, $note);
    }

    /**
     * Judge attempts to downgrade severity. IGNORED by the detector: the
     * deterministic verdict is the floor and cannot be lowered by the judge.
     */
    public static function downgrade(?string $note = null): self
    {
        return new self(self::DOWNGRADE, $note);
    }

    /**
     * Judge stays silent (no opinion). Equivalent to APPROVE for the receipt
     * (the deterministic verdict stands unchanged).
     */
    public static function silent(): self
    {
        return new self(self::SILENT);
    }
}
