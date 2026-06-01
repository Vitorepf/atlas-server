<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AtomicBacklog;

final class ProviderFallbackHonestyClassifier
{
    private const SCHEMA_VERSION = 'atlas.provider.fallback_honesty.v1';

    private const MODE_SEMANTIC_PROVIDER = 'semantic_provider';

    private const MODE_EXPLICIT_LOCAL_FALLBACK = 'explicit_local_fallback';

    private const MODE_BLOCKED_SILENT_FALLBACK = 'blocked_silent_fallback';

    /**
     * Failure markers that represent a transient provider failure. A timeout or a
     * rate limit is NOT a successful semantic invocation: the provider produced no
     * result, so it must never be reported as semantic_provider success.
     */
    private const TRANSIENT_FAILURE_REASONS = [
        'timeout',
        'rate_limit',
        'rate_limited',
    ];

    /**
     * @param array<string, mixed> $providerState
     * @param array<string, mixed> $request
     * @param array<string, mixed> $fallback
     * @return array{
     *     schema_version: string,
     *     mode: string,
     *     provider_invoked: bool,
     *     fallback_honest: bool,
     *     blockers: list<string>,
     *     audit_reason: string
     * }
     */
    public function classify(array $providerState, array $request, array $fallback): array
    {
        $privacyBlocker = $this->checkPrivacy($providerState, $request);
        if ($privacyBlocker !== null) {
            return $this->buildBlocked(
                [$privacyBlocker],
                'provider_blocked_on_privacy_class_mismatch',
            );
        }

        $transientReason = $this->transientFailureReason($providerState);

        if ($transientReason === null && $this->providerSucceeded($providerState)) {
            return $this->buildSemanticProvider();
        }

        if (! $this->fallbackDeclared($fallback)) {
            $blocker = $transientReason !== null
                ? 'provider_'.$transientReason.'_without_declared_fallback'
                : 'provider_unavailable_without_declared_fallback';

            return $this->buildBlocked(
                [$blocker],
                $transientReason !== null
                    ? 'transient_'.$transientReason.'_has_no_declared_fallback'
                    : 'no_declared_fallback_for_unavailable_provider',
            );
        }

        if (! $this->fallbackDisclosed($fallback)) {
            return $this->buildBlocked(
                ['silent_fallback_not_disclosed'],
                'fallback_present_but_silent_and_undisclosed',
            );
        }

        return $this->buildExplicitLocalFallback($transientReason);
    }

    /**
     * @param array<string, mixed> $providerState
     * @param array<string, mixed> $request
     */
    private function checkPrivacy(array $providerState, array $request): ?string
    {
        $requestClass = $request['privacy_class'] ?? null;
        if (! is_string($requestClass) || $requestClass === '') {
            return null;
        }

        $allowed = $providerState['allowed_privacy_classes'] ?? null;
        if (! is_array($allowed) || $allowed === []) {
            return null;
        }

        return in_array($requestClass, $allowed, true)
            ? null
            : 'privacy_class_mismatch';
    }

    /**
     * @param array<string, mixed> $providerState
     */
    private function transientFailureReason(array $providerState): ?string
    {
        $failure = $providerState['failure_reason'] ?? null;
        if (is_string($failure) && in_array($failure, self::TRANSIENT_FAILURE_REASONS, true)) {
            return $this->normalizeTransientReason($failure);
        }

        if (($providerState['timed_out'] ?? false) === true) {
            return 'timeout';
        }

        if (($providerState['rate_limited'] ?? false) === true) {
            return 'rate_limit';
        }

        return null;
    }

    private function normalizeTransientReason(string $reason): string
    {
        return $reason === 'rate_limited' ? 'rate_limit' : $reason;
    }

    /**
     * @param array<string, mixed> $providerState
     */
    private function providerSucceeded(array $providerState): bool
    {
        if (($providerState['available'] ?? false) !== true) {
            return false;
        }

        if (($providerState['compatible'] ?? true) !== true) {
            return false;
        }

        return ($providerState['invoked'] ?? false) === true
            && ($providerState['succeeded'] ?? true) === true;
    }

    /**
     * @param array<string, mixed> $fallback
     */
    private function fallbackDeclared(array $fallback): bool
    {
        if (($fallback['declared'] ?? false) !== true) {
            return false;
        }

        $target = $fallback['target'] ?? null;

        return is_string($target) && $target !== '';
    }

    /**
     * @param array<string, mixed> $fallback
     */
    private function fallbackDisclosed(array $fallback): bool
    {
        if (($fallback['silent'] ?? false) === true) {
            return false;
        }

        return ($fallback['disclosed'] ?? false) === true;
    }

    /**
     * @return array{
     *     schema_version: string,
     *     mode: string,
     *     provider_invoked: bool,
     *     fallback_honest: bool,
     *     blockers: list<string>,
     *     audit_reason: string
     * }
     */
    private function buildSemanticProvider(): array
    {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'mode' => self::MODE_SEMANTIC_PROVIDER,
            'provider_invoked' => true,
            'fallback_honest' => true,
            'blockers' => [],
            'audit_reason' => 'configured_compatible_provider_served_semantic_result',
        ];
    }

    /**
     * @return array{
     *     schema_version: string,
     *     mode: string,
     *     provider_invoked: bool,
     *     fallback_honest: bool,
     *     blockers: list<string>,
     *     audit_reason: string
     * }
     */
    private function buildExplicitLocalFallback(?string $transientReason): array
    {
        $reason = $transientReason !== null
            ? 'declared_fallback_after_transient_'.$transientReason
            : 'declared_local_fallback_for_unavailable_provider';

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'mode' => self::MODE_EXPLICIT_LOCAL_FALLBACK,
            'provider_invoked' => false,
            'fallback_honest' => true,
            'blockers' => [],
            'audit_reason' => $reason,
        ];
    }

    /**
     * @param list<string> $blockers
     * @return array{
     *     schema_version: string,
     *     mode: string,
     *     provider_invoked: bool,
     *     fallback_honest: bool,
     *     blockers: list<string>,
     *     audit_reason: string
     * }
     */
    private function buildBlocked(array $blockers, string $auditReason): array
    {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'mode' => self::MODE_BLOCKED_SILENT_FALLBACK,
            'provider_invoked' => false,
            'fallback_honest' => false,
            'blockers' => $blockers,
            'audit_reason' => $auditReason,
        ];
    }
}
