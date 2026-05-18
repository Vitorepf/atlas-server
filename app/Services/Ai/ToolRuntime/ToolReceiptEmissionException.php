<?php

namespace App\Services\Ai\ToolRuntime;

use Throwable;

/**
 * Raised when Tool Runtime cannot guarantee that an Evidence Runtime receipt
 * was persisted for an executed tool invocation.
 *
 * Strict mode guarantee: a tool execution MUST NOT close `succeeded` if we
 * cannot prove that an `ai_receipts` row exists. The bridge throws so the
 * caller (ToolInvocationService) marks the invocation `blocked` with the
 * receipt failure reason in `error_summary`.
 *
 * Lenient mode keeps the legacy local-only receipt path but ALSO logs +
 * audits the degradation — it never returns silently.
 */
class ToolReceiptEmissionException extends ToolRuntimeException
{
    public readonly string $invocationId;

    public readonly string $reason;

    /**
     * @param  array<string,mixed>  $context
     */
    public function __construct(
        string $invocationId,
        string $reason,
        string $message,
        public readonly array $context = [],
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
        $this->invocationId = $invocationId;
        $this->reason = $reason;
    }

    public static function bridgeUnavailable(string $invocationId): self
    {
        return new self(
            $invocationId,
            'evidence_runtime_unavailable',
            "Tool invocation [{$invocationId}] receipt cannot be emitted: Evidence Runtime tables are absent. ".
            'Strict mode refuses local-only receipts for unauthenticated evidence state.',
            ['mode' => 'strict']
        );
    }

    /**
     * @param  array<string,mixed>  $context
     */
    public static function emissionFailed(string $invocationId, string $detail, ?Throwable $previous = null, array $context = []): self
    {
        return new self(
            $invocationId,
            'evidence_runtime_exception',
            "Tool invocation [{$invocationId}] receipt emission failed: {$detail}. Strict mode refuses local-only receipts for runtime exceptions.",
            $context + ['mode' => 'strict'],
            $previous,
        );
    }
}
