<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\AtlasDev\Gate;

use App\Services\Ai\Programming\AtlasDev\Schemas\Components\EvidenceRef;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\GateOutcome;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\TestRun;
use InvalidArgumentException;

/**
 * Outcome envelope from VerificationGate::run().
 *
 * aggregateStatus matches GateOutcome statuses: passed / failed / needs_review.
 * honestyFlags surface "we ran without coverage" / "we ran partial" so the
 * CompletionStateGate can downgrade passed → needs_review.
 */
final class VerificationGateResult
{
    public const STATUS_PASSED = 'passed';
    public const STATUS_FAILED = 'failed';
    public const STATUS_NEEDS_REVIEW = 'needs_review';

    public const ALLOWED_STATUSES = [
        self::STATUS_PASSED,
        self::STATUS_FAILED,
        self::STATUS_NEEDS_REVIEW,
    ];

    /**
     * @param  list<TestRun>  $tests
     * @param  list<GateOutcome>  $gates
     * @param  list<string>  $honestyFlags
     * @param  list<EvidenceRef>  $evidenceRefs
     */
    public function __construct(
        public readonly array $tests,
        public readonly array $gates,
        public readonly string $aggregateStatus,
        public readonly array $honestyFlags,
        public readonly array $evidenceRefs = [],
        public readonly string $profile = 'generic_no_test',
    ) {
        if (! in_array($this->aggregateStatus, self::ALLOWED_STATUSES, true)) {
            throw new InvalidArgumentException(
                'VerificationGateResult.aggregate_status must be one of ['
                .implode(',', self::ALLOWED_STATUSES)."], got '{$this->aggregateStatus}'."
            );
        }
        foreach ($this->tests as $i => $t) {
            if (! $t instanceof TestRun) {
                throw new InvalidArgumentException("VerificationGateResult.tests[{$i}] must be TestRun.");
            }
        }
        foreach ($this->gates as $i => $g) {
            if (! $g instanceof GateOutcome) {
                throw new InvalidArgumentException("VerificationGateResult.gates[{$i}] must be GateOutcome.");
            }
        }
        foreach ($this->honestyFlags as $i => $f) {
            if (! is_string($f) || $f === '') {
                throw new InvalidArgumentException("VerificationGateResult.honesty_flags[{$i}] must be non-empty string.");
            }
        }
        foreach ($this->evidenceRefs as $i => $r) {
            if (! $r instanceof EvidenceRef) {
                throw new InvalidArgumentException("VerificationGateResult.evidence_refs[{$i}] must be EvidenceRef.");
            }
        }
    }

    public function passed(): bool
    {
        return $this->aggregateStatus === self::STATUS_PASSED;
    }

    /**
     * Return a copy with the additional honesty flags appended (deduped,
     * order-preserving). Used by elevation probes (E2 intent_not_tested,
     * E1 intent_likely_not_addressed, E4 candidate_divergence, etc.) to
     * surface advisory signals through the sanctioned honesty-flag channel
     * so the CompletionStateGate auto-downgrades PASSED -> needs_review.
     *
     * The aggregate status and all other fields are preserved unchanged:
     * the honesty flag drives the downgrade, not a status mutation.
     *
     * @param  list<string>  $flags
     */
    public function withHonestyFlags(array $flags): self
    {
        $merged = array_values(array_unique(array_merge(
            $this->honestyFlags,
            array_values(array_filter($flags, static fn ($f): bool => is_string($f) && $f !== '')),
        )));

        return new self(
            tests: $this->tests,
            gates: $this->gates,
            aggregateStatus: $this->aggregateStatus,
            honestyFlags: $merged,
            evidenceRefs: $this->evidenceRefs,
            profile: $this->profile,
        );
    }
}
