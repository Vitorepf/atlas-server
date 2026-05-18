<?php

declare(strict_types=1);

namespace App\Services\Ai\Vox\Execution;

use App\Services\Ai\Vox\VoxActionOutcomeService;
use App\Services\Ai\Vox\VoxSchema;

/**
 * Resolves which VoxExecutor handles a given (executor_hint, provider_hint)
 * pair and dispatches. The router is itself stateless — it pulls the
 * already-instantiated executors from the constructor and matches by
 * `id()`. Adding a new executor = registering it in the service container
 * (see AppServiceProvider).
 *
 * The router does NOT validate the confirmation token; that is
 * VoxExecutionGate's job. By the time the router runs, the gate already
 * allowed dispatch and consumed the token (single-use).
 */
final class VoxExecutorRouter
{
    /** @var array<string, VoxExecutor> */
    private array $registry;

    /**
     * @param  iterable<VoxExecutor>  $executors
     */
    public function __construct(
        iterable $executors,
        private readonly VoxActionOutcomeService $outcomes,
    ) {
        $this->registry = [];
        foreach ($executors as $executor) {
            $this->registry[$executor->id()] = $executor;
        }
    }

    /**
     * @return array<string, array{available: bool, reason: ?string}>
     */
    public function healthSnapshot(): array
    {
        $snapshot = [];
        foreach ($this->registry as $id => $executor) {
            $snapshot[$id] = [
                'available' => $executor->isAvailable(),
                'reason' => $executor->unavailableReason(),
            ];
        }

        return $snapshot;
    }

    public function pick(string $executorHint, string $providerHint): string
    {
        // Provider CLI hints take precedence over `executor_hint=none`.
        if ($providerHint === 'codex_cli') {
            return VoxSchema::EXECUTOR_CODEX_CLI;
        }
        if ($providerHint === 'claude_cli') {
            return VoxSchema::EXECUTOR_CLAUDE_CLI;
        }

        return match ($executorHint) {
            'terminal_propose' => VoxSchema::EXECUTOR_TERMINAL_PROPOSE,
            'note' => VoxSchema::EXECUTOR_NOTE_CAPTURE,
            'edit' => VoxSchema::EXECUTOR_FILESYSTEM_EDIT,
            'shell' => VoxSchema::EXECUTOR_TERMINAL_PROPOSE, // V3 NEVER auto-runs shell
            default => VoxSchema::EXECUTOR_NOTE_CAPTURE,
        };
    }

    /**
     * @param  array<string,mixed>  $intentPacket
     * @param  array<string,mixed>  $receipt
     * @param  array<string,mixed>  $context  { request_id, decision, source }
     * @return array<string,mixed> VoxActionOutcome.v1
     */
    public function dispatch(array $intentPacket, array $receipt, array $context): array
    {
        $executorId = (string) ($context['executor'] ?? '');
        if ($executorId === '') {
            $executorId = $this->pick(
                executorHint: (string) ($intentPacket['executor_hint'] ?? 'none'),
                providerHint: (string) ($intentPacket['provider_hint'] ?? 'local'),
            );
        }

        $executor = $this->registry[$executorId] ?? null;
        if ($executor === null) {
            return $this->outcomes->executorBlocked(
                intentPacket: $intentPacket,
                receipt: $receipt,
                executor: $executorId,
                reasonCode: 'executor_unknown',
                message: "no Vox V3 executor registered for id '{$executorId}'",
            );
        }

        if (! $executor->isAvailable()) {
            return $this->outcomes->executorBlocked(
                intentPacket: $intentPacket,
                receipt: $receipt,
                executor: $executorId,
                reasonCode: $executor->unavailableReason() ?? 'executor_unavailable',
                message: "Vox V3 executor '{$executorId}' is not available in this deployment.",
            );
        }

        return $executor->dispatch($intentPacket, $receipt, $context);
    }
}
