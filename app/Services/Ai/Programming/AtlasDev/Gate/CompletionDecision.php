<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\AtlasDev\Gate;

use App\Services\Ai\Programming\AtlasDev\Schemas\Components\CompletionSummary;
use InvalidArgumentException;

/**
 * Final decision produced by CompletionStateGate before ReceiptComposer
 * folds it into a VerificationReceipt.
 */
final class CompletionDecision
{
    /**
     * @param  list<string>  $honestyFlags
     * @param  list<string>  $residualRisks
     * @param  list<string>  $reasons
     */
    public function __construct(
        public readonly string $status,
        public readonly array $honestyFlags,
        public readonly array $residualRisks,
        public readonly array $reasons = [],
    ) {
        if (! in_array($this->status, CompletionSummary::ALLOWED_STATUSES, true)) {
            throw new InvalidArgumentException(
                'CompletionDecision.status must be one of ['
                .implode(',', CompletionSummary::ALLOWED_STATUSES)."], got '{$this->status}'."
            );
        }
        if ($this->status === CompletionSummary::STATUS_PASSED && $this->honestyFlags !== []) {
            throw new InvalidArgumentException(
                'CompletionDecision invariant: status=passed forbids honesty_flags.'
            );
        }
        foreach ($this->honestyFlags as $i => $f) {
            if (! is_string($f) || $f === '') {
                throw new InvalidArgumentException("CompletionDecision.honesty_flags[{$i}] must be non-empty string.");
            }
        }
        foreach ($this->residualRisks as $i => $r) {
            if (! is_string($r) || $r === '') {
                throw new InvalidArgumentException("CompletionDecision.residual_risks[{$i}] must be non-empty string.");
            }
        }
    }

    public function toCompletionSummary(): CompletionSummary
    {
        return new CompletionSummary(
            status: $this->status,
            honestyFlags: $this->honestyFlags,
            residualRisks: $this->residualRisks,
        );
    }
}
