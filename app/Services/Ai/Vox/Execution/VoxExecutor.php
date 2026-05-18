<?php

declare(strict_types=1);

namespace App\Services\Ai\Vox\Execution;

/**
 * Common shape every Vox V3 executor implements.
 *
 * Hard invariants:
 *   - Executors run AFTER VoxExecutionGate allowed dispatch — they never
 *     re-check the confirmation token, but they MUST never bypass safety:
 *     hard-veto strings inside intermediate state still abort the call.
 *   - Every executor returns a VoxActionOutcome.v1 payload via
 *     VoxActionOutcomeService.
 *   - Executors never log secrets; they never expose tokens; they never
 *     persist audio.
 */
interface VoxExecutor
{
    /**
     * Stable executor id (e.g. `terminal_propose`, `codex_cli`).
     * Matches VoxSchema::EXECUTOR_* constants.
     */
    public function id(): string;

    /**
     * Whether the executor can actually run right now. Returning false means
     * the controller serves a structured `blocked/unavailable` outcome
     * without invoking dispatch (e.g. provider CLI binary missing).
     */
    public function isAvailable(): bool;

    /**
     * One-line description for the health endpoint when not available.
     */
    public function unavailableReason(): ?string;

    /**
     * Runs the executor and returns a VoxActionOutcome.v1 payload. Must
     * not raise on operational failure — failure paths are encoded in the
     * outcome (status=failed/aborted with `error` populated).
     *
     * @param  array<string,mixed>  $intentPacket
     * @param  array<string,mixed>  $receipt
     * @param  array<string,mixed>  $context  Extra context the router passes
     *                              along (request_id, decision, source).
     * @return array<string,mixed> VoxActionOutcome.v1
     */
    public function dispatch(array $intentPacket, array $receipt, array $context): array;
}
