<?php

namespace App\Services\Ai\Programming\BenchmarkReadiness;

use RuntimeException;

/**
 * Thrown whenever something tries to actually RUN the benchmark suite.
 *
 * Two layers of refusal:
 *  1. Missing authorization — caller did not provide the required canon fields;
 *  2. Even-with-authorization — the runtime is not wired in this readiness-only
 *     slice, so even a valid-shaped authorization cannot execute.
 *
 * The two reasons are distinct so tests + operators can tell the difference.
 */
class BenchmarkReadinessAuthorizationException extends RuntimeException
{
    public const REASON_MISSING_AUTHORIZATION = 'missing_authorization';

    public const REASON_RUNTIME_NOT_WIRED = 'not_run_runtime_disabled_in_readiness_only_slice';

    /**
     * @param  array<int,string>  $missingFields
     */
    public static function missingAuthorization(array $missingFields): self
    {
        $detail = $missingFields === [] ? '<none-supplied>' : implode(',', $missingFields);

        return new self(
            sprintf(
                '[%s] human authorization required; missing fields: %s',
                self::REASON_MISSING_AUTHORIZATION,
                $detail,
            ),
        );
    }

    public static function runtimeNotWired(): self
    {
        return new self(sprintf(
            '[%s] benchmark runtime is intentionally NOT wired in this slice; suite remains benchmark_not_run.',
            self::REASON_RUNTIME_NOT_WIRED,
        ));
    }
}
