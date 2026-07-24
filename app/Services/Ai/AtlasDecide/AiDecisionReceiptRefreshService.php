<?php

namespace App\Services\Ai\AtlasDecide;

use App\Models\AiJob;

/**
 * Compatibility surface for historical expired-receipt handling.
 *
 * Renewal is deliberately fail-closed. Legacy V2 does not bind the complete
 * mutable job request, and issuing a replacement writes authority evidence on
 * a connection that cannot atomically commit with the job transport. A future
 * renewal protocol must introduce that durable, independently verified binding
 * before this surface can ever permit a write again.
 */
class AiDecisionReceiptRefreshService
{
    public function canRefreshExpiredBeforeProviderCall(AiJob $job): bool
    {
        return false;
    }

    public function refreshExpiredBeforeProviderCall(AiJob $job, string $reason = 'expired_before_provider_call'): ?AiJob
    {
        return null;
    }
}
