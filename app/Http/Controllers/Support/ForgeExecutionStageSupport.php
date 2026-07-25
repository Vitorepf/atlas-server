<?php

declare(strict_types=1);

namespace App\Http\Controllers\Support;

/**
 * Pure forge execution stage classifiers (full-pass peel).
 */
final class ForgeExecutionStageSupport
{
    public static function phaseForStage(string $name): string
    {
        return match ($name) {
            'obra_binding', 'sandbox_provision', 'context_pack' => 'context',
            'patch_apply', 'action_manifest' => 'execute',
            'patch_verifier', 'test_run' => 'verify',
            'stage_receipts', 'evidence_ledger' => 'evidence',
            'repair_loop' => 'repair',
            'sandbox_rollback' => 'cleanup',
            default => 'runtime',
        };
    }

    public static function stageIsBlocking(string $status, mixed $blocker): bool
    {
        $hasBlocker = is_string($blocker) && $blocker !== '';

        return $hasBlocker || in_array($status, ['blocked', 'failed', 'degraded'], true);
    }
}
