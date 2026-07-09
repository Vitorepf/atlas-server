<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming;

use RuntimeException;

/**
 * Obra 2 / RAG-01…04 — thrown when agentic RAG context_sufficiency_gate blocks execution.
 */
final class ProgrammingRagGateException extends RuntimeException
{
    /**
     * @param  list<string>  $reasons
     */
    public function __construct(
        public readonly array $reasons = [],
        string $message = 'programming_rag_gate_blocked',
    ) {
        parent::__construct($message.($reasons !== [] ? ': '.implode(',', $reasons) : ''));
    }
}
