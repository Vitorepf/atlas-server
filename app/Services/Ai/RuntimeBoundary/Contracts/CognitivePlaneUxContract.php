<?php

declare(strict_types=1);

namespace App\Services\Ai\RuntimeBoundary\Contracts;

use App\Services\Ai\RuntimeBoundary\FutureRuntimeInvocationContract;

/**
 * Cognitive Plane UX hooks — PHP-side runtime invocation contract.
 *
 * AP-168/AP-169/AP-170 UX expansion to App/Mobile/Voice.
 */
final class CognitivePlaneUxContract extends FutureRuntimeInvocationContract
{
    protected function blockId(): string
    {
        return 'cognitive_plane_ux';
    }

    protected function targetRuntime(): string
    {
        return 'ux_app_mobile';
    }

    protected function blockValidation(array $payload): array
    {
        $errors = [];
        $ap = $payload['ap'] ?? null;
        if (! in_array($ap, ['AP-168', 'AP-169', 'AP-170'], true)) {
            $errors[] = 'ap must be one of [AP-168, AP-169, AP-170]';
        }
        $surface = $payload['surface'] ?? null;
        if (! in_array($surface, ['app', 'mobile', 'voice'], true)) {
            $errors[] = 'surface must be one of [app, mobile, voice]';
        }
        if (($payload['daily_plan_bootstrap_present'] ?? null) !== true) {
            $errors[] = 'daily_plan_bootstrap_present required';
        }
        if (($payload['sells_as_tutor_final'] ?? null) === true) {
            $errors[] = 'sells_as_tutor_final is forbidden — cognitive plane is runtime minimum, not tutor product';
        }

        return $errors;
    }
}
