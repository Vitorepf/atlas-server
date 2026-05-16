<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\AtlasDev\Provider;

use RuntimeException;

/**
 * Raised when the gateway reports a different provider/model than the lock
 * declared in LightTaskContract.provider_lock, or when fallback is attempted
 * while fallback_allowed=false. Atlas Dev never masks fallback (contracts
 * doc 6.2 invariant 5) — adapter callers must surface this to the operator.
 */
final class ProviderLockViolationException extends RuntimeException {}
