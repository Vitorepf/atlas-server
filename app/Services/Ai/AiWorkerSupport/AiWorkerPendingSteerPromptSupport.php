<?php

declare(strict_types=1);

namespace App\Services\Ai\AiWorkerSupport;

/**
 * Pure pending-steer prompt injection (full-pass peel from PermissionSteerSection).
 */
final class AiWorkerPendingSteerPromptSupport
{
    public static function promptWithPendingSteer(string $prompt, string $steer): string
    {
        return rtrim($prompt)."\n\n# Pedido adicional do operador\n\n[STEER] {$steer}\n";
    }
}
