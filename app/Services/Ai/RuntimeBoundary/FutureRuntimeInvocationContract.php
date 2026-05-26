<?php

declare(strict_types=1);

namespace App\Services\Ai\RuntimeBoundary;

/**
 * Future Runtime Invocation Contract — canonical PHP-side boundary.
 *
 * Implements the AP-201 pattern declared in
 * `atlas-ai-runtime-language-boundaries.md`. Each of the 11 blocks the
 * canon classifies as "runs in a non-Laravel runtime" (LiveKit Python,
 * Python ML, Swift native, Go edge, UX surface) has a contract emitter
 * on the PHP side that:
 *
 *   - declares the canonical schema_version
 *   - validates the invocation request shape
 *   - emits a provider-safe contract envelope (Decision Receipt-ready)
 *   - DOES NOT execute the external runtime — it produces the contract
 *
 * This is the seam that lets PHP/Laravel orchestrate cross-runtime
 * work without absorbing Python/Swift/LiveKit code into the Laravel
 * kernel (the explicit canon boundary).
 *
 * Each consumer block instantiates the base contract with its block-id
 * and target_runtime, then registers extra fields via the abstract
 * `additionalValidation()` hook.
 */
abstract class FutureRuntimeInvocationContract
{
    public const SCHEMA_VERSION = 'atlas.runtime_invocation_contract.v1';

    public const ALLOWED_TARGET_RUNTIMES = [
        'livekit_agents_python',
        'python_ai_data',
        'go_edge',
        'swift_native_mac',
        'ux_app_mobile',
        'ux_app_desktop',
        'external_crawler',
        'external_browser_automation',
    ];

    /**
     * The canonical block id this contract belongs to (e.g., "voice_realtime").
     */
    abstract protected function blockId(): string;

    /**
     * Which external runtime this contract targets.
     */
    abstract protected function targetRuntime(): string;

    /**
     * Block-specific validation. Returns a list of error strings; empty
     * means the block-specific shape is valid.
     *
     * @param  array<string,mixed>  $payload
     * @return list<string>
     */
    abstract protected function blockValidation(array $payload): array;

    /**
     * @param  array{
     *   invocation_id?: string,
     *   payload?: array<string,mixed>,
     *   decision_receipt_ref?: string,
     *   evidence_sink_ref?: string,
     *   rollback_plan_ref?: string,
     * }  $request
     * @return array{
     *   schema_version: string,
     *   block_id: string,
     *   target_runtime: string,
     *   invocation_id: ?string,
     *   contract_status: string,
     *   validation_errors: list<string>,
     *   declared_at: string,
     *   evidence_refs: array{decision_receipt: ?string, evidence_sink: ?string, rollback_plan: ?string},
     *   runtime_invocation_allowed: bool,
     *   detail: string
     * }
     */
    public function declare(array $request): array
    {
        if (! in_array($this->targetRuntime(), self::ALLOWED_TARGET_RUNTIMES, true)) {
            return $this->envelope(null, 'invalid_target_runtime',
                ["target_runtime '{$this->targetRuntime()}' not in allowed list"], $request);
        }

        $invocationId = isset($request['invocation_id']) && is_string($request['invocation_id'])
            ? $request['invocation_id']
            : null;

        $errors = [];
        if ($invocationId === null || $invocationId === '') {
            $errors[] = 'invocation_id required';
        }

        $payload = (array) ($request['payload'] ?? []);
        $errors = array_merge($errors, $this->blockValidation($payload));

        // Canonical invariants — AP-201 requires all 3 evidence refs.
        if (! is_string($request['decision_receipt_ref'] ?? null) || $request['decision_receipt_ref'] === '') {
            $errors[] = 'decision_receipt_ref required (AP-201 canonical invariant)';
        }
        if (! is_string($request['evidence_sink_ref'] ?? null) || $request['evidence_sink_ref'] === '') {
            $errors[] = 'evidence_sink_ref required (AP-201 canonical invariant)';
        }
        if (! is_string($request['rollback_plan_ref'] ?? null) || $request['rollback_plan_ref'] === '') {
            $errors[] = 'rollback_plan_ref required (AP-201 canonical invariant)';
        }

        $status = $errors === [] ? 'invocation_contract_ready' : 'blocked_invalid_invocation';

        return $this->envelope($invocationId, $status, $errors, $request);
    }

    /**
     * @param  list<string>  $errors
     * @param  array<string,mixed>  $request
     * @return array<string,mixed>
     */
    private function envelope(?string $invocationId, string $status, array $errors, array $request): array
    {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'block_id' => $this->blockId(),
            'target_runtime' => $this->targetRuntime(),
            'invocation_id' => $invocationId,
            'contract_status' => $status,
            'validation_errors' => $errors,
            'declared_at' => now()->toAtomString(),
            'evidence_refs' => [
                'decision_receipt' => isset($request['decision_receipt_ref']) && is_string($request['decision_receipt_ref'])
                    ? $request['decision_receipt_ref']
                    : null,
                'evidence_sink' => isset($request['evidence_sink_ref']) && is_string($request['evidence_sink_ref'])
                    ? $request['evidence_sink_ref']
                    : null,
                'rollback_plan' => isset($request['rollback_plan_ref']) && is_string($request['rollback_plan_ref'])
                    ? $request['rollback_plan_ref']
                    : null,
            ],
            'runtime_invocation_allowed' => $status === 'invocation_contract_ready',
            'detail' => $status === 'invocation_contract_ready'
                ? sprintf('Contract ready for runtime "%s" to consume block "%s".', $this->targetRuntime(), $this->blockId())
                : sprintf('Contract blocked: %d validation error(s).', count($errors)),
        ];
    }
}
