<?php

namespace App\Services\Ai\ToolRuntime;

use Throwable;

/**
 * Raised when Tool Runtime policy enforcement cannot prove that a Policy
 * decision was emitted by the canonical Permission Gate (Meta 3).
 *
 * Strict mode guarantee: a tool MUST NOT execute if we cannot record an
 * auditable policy decision. The bridge throws this exception so the
 * invocation surface (ToolInvocationService, ProgrammingToolBridge, …) can
 * mark the invocation as `blocked` with an explicit error_summary instead of
 * silently falling back to `allow`.
 *
 * Lenient mode keeps the legacy fallback path but ALSO logs + audits — it
 * never returns silently.
 */
class ToolPolicyEnforcementException extends ToolRuntimeException
{
    public readonly string $toolId;

    public readonly string $reason;

    /**
     * @param  array<string,mixed>  $context
     */
    public function __construct(
        string $toolId,
        string $reason,
        string $message,
        public readonly array $context = [],
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
        $this->toolId = $toolId;
        $this->reason = $reason;
    }

    public static function bridgeUnavailable(string $toolId): self
    {
        return new self(
            $toolId,
            'policy_runtime_unavailable',
            "Tool [{$toolId}] cannot be evaluated: Policy/Permission tables are absent. ".
            'Strict mode refuses fallback decisions for unauthenticated policy state.',
            ['mode' => 'strict']
        );
    }

    /**
     * @param  array<string,mixed>  $context
     */
    public static function evaluationFailed(string $toolId, string $detail, ?Throwable $previous = null, array $context = []): self
    {
        return new self(
            $toolId,
            'policy_runtime_exception',
            "Tool [{$toolId}] policy evaluation failed: {$detail}. Strict mode refuses fallback decisions for runtime exceptions.",
            $context + ['mode' => 'strict'],
            $previous,
        );
    }
}
