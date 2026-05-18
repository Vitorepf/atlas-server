<?php

declare(strict_types=1);

namespace App\Services\Ai\Vox\Execution;

use App\Services\Ai\Vox\VoxActionOutcomeService;
use App\Services\Ai\Vox\VoxSchema;

/**
 * V3 filesystem_edit · NOT WIRED in Wave 6.
 *
 * Wave 6 doesn't ship a governed patch/diff applicator. The codebase has
 * an Atlas Forge edit pipeline but it is gated by its own preview /
 * receipt / review flow, not by a voice trigger. Wiring Vox directly to
 * the filesystem without that flow would violate Lei 0.75 (we'd be
 * promising what we can't audit).
 *
 * The executor therefore always returns a structured `blocked` outcome
 * with a stable reason_code, so the Desktop can show "filesystem_edit
 * ainda não disponível" instead of pretending the file was patched.
 */
final class VoxFilesystemEditExecutor implements VoxExecutor
{
    public function __construct(
        private readonly VoxActionOutcomeService $outcomes,
    ) {}

    public function id(): string
    {
        return VoxSchema::EXECUTOR_FILESYSTEM_EDIT;
    }

    public function isAvailable(): bool
    {
        return false;
    }

    public function unavailableReason(): ?string
    {
        return 'filesystem_edit_executor_not_ready';
    }

    public function dispatch(array $intentPacket, array $receipt, array $context): array
    {
        return $this->outcomes->executorBlocked(
            intentPacket: $intentPacket,
            receipt: $receipt,
            executor: $this->id(),
            reasonCode: 'filesystem_edit_executor_not_ready',
            message: 'filesystem_edit via Vox V3 ainda não tem infraestrutura governada — use Atlas Forge.',
        );
    }
}
