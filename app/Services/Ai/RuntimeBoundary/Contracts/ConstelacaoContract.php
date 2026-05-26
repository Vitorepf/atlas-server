<?php

declare(strict_types=1);

namespace App\Services\Ai\RuntimeBoundary\Contracts;

use App\Services\Ai\RuntimeBoundary\FutureRuntimeInvocationContract;

/**
 * Constelacao — PHP-side runtime invocation contract.
 *
 * Block: constelacao · Runtime: python_ai_data
 * Per `atlas-constelacao-surface.md` lens maturity gate.
 */
final class ConstelacaoContract extends FutureRuntimeInvocationContract
{
    protected function blockId(): string
    {
        return 'constelacao';
    }

    protected function targetRuntime(): string
    {
        return 'python_ai_data';
    }

    protected function blockValidation(array $payload): array
    {
        $errors = [];
        $lens = $payload['lens'] ?? null;
        if (! in_array($lens, ['lens_1', 'lens_2', 'lens_3'], true)) {
            $errors[] = 'lens must be one of [lens_1, lens_2, lens_3]';
        }
        if ($lens === 'lens_2' && ($payload['lens_2_maturity_gate_passed'] ?? null) !== true) {
            $errors[] = 'lens_2 requires lens_2_maturity_gate_passed=true (30d usage review canon)';
        }
        if (($payload['command_sky_promotion_allowed'] ?? false) === true) {
            $errors[] = 'command_sky_promotion is blocked at every invocation per canon';
        }
        if (($payload['proposal_only'] ?? null) !== true) {
            $errors[] = 'constelacao runtime is proposal_only (vector engine never auto-promotes)';
        }

        return $errors;
    }
}
