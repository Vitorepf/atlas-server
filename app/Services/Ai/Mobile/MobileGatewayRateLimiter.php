<?php

namespace App\Services\Ai\Mobile;

use App\Models\AiInboxItem;
use App\Models\AtlasMobileDevice;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

class MobileGatewayRateLimiter
{
    private const SENSITIVE_ACTIONS = [
        'approve_once',
        'approve_session',
        'approve_workspace_1h',
        'deny',
        'create_proposal',
        'ignore_30d',
        'discard',
        'commit',
        'open_pr',
        'merge',
        'apply_patch',
        'push_branch',
    ];

    public function assertPairingInitiateAllowed(Request $request, string $userId): void
    {
        $this->hit(
            'pairing_initiate',
            'ip:'.$this->ip($request).'|user:'.$userId,
            12,
            600,
        );
    }

    public function assertPairingConfirmAllowed(Request $request, ?string $pairingId, string $code): void
    {
        $identity = $pairingId ?: 'code:'.hash('sha256', $this->normalizeCode($code));

        $this->hit(
            'pairing_confirm',
            'ip:'.$this->ip($request).'|'.$identity,
            12,
            600,
        );
    }

    public function assertSensitiveActionAllowed(Request $request, AtlasMobileDevice $device, AiInboxItem $item, string $actionId): void
    {
        if (! in_array($actionId, self::SENSITIVE_ACTIONS, true)) {
            return;
        }

        $this->hit(
            'sensitive_action',
            'ip:'.$this->ip($request).'|device:'.$device->id.'|item:'.$item->id.'|action:'.$actionId,
            20,
            300,
        );
    }

    private function hit(string $bucket, string $identity, int $defaultMaxAttempts, int $defaultDecaySeconds): void
    {
        $maxAttempts = max(1, (int) config("atlas.mobile.rate_limits.{$bucket}_max_attempts", $defaultMaxAttempts));
        $decaySeconds = max(1, (int) config("atlas.mobile.rate_limits.{$bucket}_decay_seconds", $defaultDecaySeconds));
        $key = 'atlas-mobile:'.$bucket.':'.sha1($identity);

        if (RateLimiter::tooManyAttempts($key, $maxAttempts)) {
            $retryAfter = max(1, RateLimiter::availableIn($key));

            throw new HttpResponseException(response()->json([
                'message' => 'Muitas tentativas. Aguarde antes de tentar novamente.',
                'retry_after' => $retryAfter,
                'bucket' => $bucket,
            ], 429)->withHeaders([
                'Retry-After' => (string) $retryAfter,
            ]));
        }

        RateLimiter::hit($key, $decaySeconds);
    }

    private function ip(Request $request): string
    {
        return $request->ip() ?: 'unknown';
    }

    private function normalizeCode(string $code): string
    {
        return strtoupper(preg_replace('/[^A-Z0-9]/i', '', $code) ?? '');
    }
}
