<?php

declare(strict_types=1);

namespace App\Services\Ai\Vox\Execution;

use App\Services\Ai\Vox\VoxActionOutcomeService;
use App\Services\Ai\Vox\VoxSchema;

/**
 * V3 note_capture · saves the compiled prompt / human input as a Vox
 * inbox note.
 *
 * Wave 6 honest stance: no canonical Inbox writer is wired through Vox yet
 * (the Atlas Inbox surface lives at app/Services/Inbox/ but accepts only
 * surface-authored events, not Vox calls). Instead of inventing a write
 * path that could drift from the real Inbox contract, this executor
 * returns an `escalated` outcome with `desktop_action.save_as_note` so the
 * operator can persist it themselves (the Desktop already has a Capturas
 * surface). When a real Vox→Inbox bridge ships, change `inboxAvailable()`
 * to inspect that backend and the rest stays the same.
 */
final class VoxNoteCaptureExecutor implements VoxExecutor
{
    public function __construct(
        private readonly VoxActionOutcomeService $outcomes,
    ) {}

    public function id(): string
    {
        return VoxSchema::EXECUTOR_NOTE_CAPTURE;
    }

    public function isAvailable(): bool
    {
        // The executor IS reachable even without a Kernel-side Inbox writer:
        // it falls back to a desktop_action so the operator can still save
        // the text locally. That is an honest path, not a fake.
        return true;
    }

    public function unavailableReason(): ?string
    {
        return null;
    }

    public function dispatch(array $intentPacket, array $receipt, array $context): array
    {
        $noteText = (string) ($intentPacket['compiled_prompt'] ?? '');
        if ($noteText === '') {
            $noteText = (string) ($intentPacket['human_input_text'] ?? '');
        }

        $noteRef = $this->inboxAvailable()
            ? $this->persistToInbox($intentPacket, $noteText)
            : null;

        return $this->outcomes->noteCaptured(
            intentPacket: $intentPacket,
            receipt: $receipt,
            noteText: $noteText,
            noteRef: $noteRef,
        );
    }

    /**
     * Hook for the future Vox→Inbox bridge. Wave 6 leaves this returning
     * false on purpose so the escalated path is always exercised.
     */
    private function inboxAvailable(): bool
    {
        return false;
    }

    /**
     * @param  array<string,mixed>  $intentPacket
     */
    private function persistToInbox(array $intentPacket, string $noteText): ?string
    {
        // Placeholder for the future Vox→Inbox writer. Return null to keep
        // the outcome honest if anything goes wrong.
        unset($intentPacket, $noteText);

        return null;
    }
}
