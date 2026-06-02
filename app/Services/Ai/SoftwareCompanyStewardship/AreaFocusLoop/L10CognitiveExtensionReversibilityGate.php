<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

/**
 * S162 - L10 R4 cognitive-extension reversibility gate.
 *
 * Read-only gate. It evaluates a single cognitive-extension session against
 * the load-bearing R4 invariant: fusion amplifies, it NEVER replaces. A
 * session may run only when it is auditable, the operator gave explicit
 * opt-in, the extension can be reversibly detached, and the operator can
 * always override. Any silent coupling (an extension wired into the operator
 * without an audit trail) is rejected outright.
 *
 * This class is pure: it computes the decision from the session payload and
 * never couples to a runtime, never activates a session, never performs I/O,
 * and never reads a clock or randomness. Identical input yields identical
 * output.
 */
final class L10CognitiveExtensionReversibilityGate
{
    private const SCHEMA_VERSION = 'atlas.aaeos.l10.cognitive_extension_reversibility_gate.v1';

    /**
     * @param array<string, mixed> $session cognitive-extension session under evaluation
     *
     * @return array{
     *     schema_version: string,
     *     allowed: bool,
     *     opt_in_present: bool,
     *     detach_available: bool,
     *     override_available: bool,
     *     audit_ready: bool,
     *     blockers: list<string>
     * }
     */
    public function evaluate(array $session): array
    {
        $optInPresent = $this->hasExplicitOptIn($session);
        $auditReady = $this->isAuditReady($session);
        $detachAvailable = $this->hasReversibleDetach($session);
        $overrideAvailable = $this->hasOperatorOverride($session);
        $silentCoupling = $this->isSilentCoupling($session, $auditReady);

        $blockers = [];

        if (! $optInPresent) {
            $blockers[] = 'explicit_opt_in_missing';
        }

        if (! $auditReady) {
            $blockers[] = 'audit_trail_missing';
        }

        if (! $detachAvailable) {
            $blockers[] = 'reversible_detach_unavailable';
        }

        if (! $overrideAvailable) {
            $blockers[] = 'operator_override_unavailable';
        }

        if ($silentCoupling) {
            $blockers[] = 'silent_coupling_rejected';
        }

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'allowed' => $blockers === [],
            'opt_in_present' => $optInPresent,
            'detach_available' => $detachAvailable,
            'override_available' => $overrideAvailable,
            'audit_ready' => $auditReady,
            'blockers' => array_values($blockers),
        ];
    }

    /**
     * Explicit opt-in: the operator must have actively consented to this
     * cognitive-extension session. A defaulted/absent flag is NOT consent.
     *
     * @param array<string, mixed> $session
     */
    private function hasExplicitOptIn(array $session): bool
    {
        return ($session['operator_opt_in'] ?? false) === true
            || ($session['explicit_opt_in'] ?? false) === true;
    }

    /**
     * Auditability: the session must carry a real audit trail. Either an
     * explicit ready flag, or at least one declared audit channel.
     *
     * @param array<string, mixed> $session
     */
    private function isAuditReady(array $session): bool
    {
        if (($session['audit_ready'] ?? false) === true) {
            return true;
        }

        return $this->auditChannels($session) !== [];
    }

    /**
     * Reversible detach: the operator can always pull the extension out. A
     * session that pins/locks the coupling (detach not available) breaks the
     * R4 reversibility invariant.
     *
     * @param array<string, mixed> $session
     */
    private function hasReversibleDetach(array $session): bool
    {
        if (($session['detach_pinned'] ?? false) === true) {
            return false;
        }

        return ($session['reversible_detach'] ?? false) === true
            || ($session['detach_available'] ?? false) === true;
    }

    /**
     * Operator override: the operator can always supersede the extension's
     * suggestion. Without override the extension can capture the operator's
     * ends, which the R4 invariant forbids.
     *
     * @param array<string, mixed> $session
     */
    private function hasOperatorOverride(array $session): bool
    {
        if (($session['override_locked'] ?? false) === true) {
            return false;
        }

        return ($session['operator_override'] ?? false) === true
            || ($session['override_available'] ?? false) === true;
    }

    /**
     * Silent coupling: an extension that is actively coupled to the operator
     * while leaving no audit trail. This is the exact failure the R4 gate
     * exists to catch, so it is rejected independently of the audit blocker.
     *
     * @param array<string, mixed> $session
     */
    private function isSilentCoupling(array $session, bool $auditReady): bool
    {
        if (($session['silent_coupling'] ?? false) === true) {
            return true;
        }

        $coupled = ($session['coupling_active'] ?? false) === true
            || ($session['extension_attached'] ?? false) === true;

        return $coupled && ! $auditReady;
    }

    /**
     * Declared audit channels, de-duplicated and re-keyed as a clean
     * list<string> so no int-key coercion or empty value leaks through.
     *
     * @param array<string, mixed> $session
     *
     * @return list<string>
     */
    private function auditChannels(array $session): array
    {
        $channels = [];

        $declared = $session['audit_channels'] ?? [];
        if (is_array($declared)) {
            foreach ($declared as $channel) {
                if (! is_string($channel)) {
                    continue;
                }

                $channel = trim($channel);
                if ($channel !== '' && ! in_array($channel, $channels, true)) {
                    $channels[] = $channel;
                }
            }
        }

        return array_values($channels);
    }
}
