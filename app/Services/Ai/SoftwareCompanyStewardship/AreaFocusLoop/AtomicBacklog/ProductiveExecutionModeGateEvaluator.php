<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AtomicBacklog;

/**
 * Pure gate for the Forge productive-execution path (Claude 2 S50).
 *
 * Decides whether a cycle may run a real provider (`execute`), must stay in the
 * safe fixture lane (`fixture_only`), or is `blocked` — all WITHOUT invoking any
 * provider. Real execution is allowed only behind the six confirm-gates plus an
 * explicit provider authorization; the fixture lane is reserved for explicit
 * smoke work and never requires provider authorization.
 */
final class ProductiveExecutionModeGateEvaluator
{
    private const SCHEMA_VERSION = 'atlas.productive_execution.mode_gate.v1';

    private const MODE_EXECUTE = 'execute';

    private const MODE_FIXTURE_ONLY = 'fixture_only';

    private const MODE_BLOCKED = 'blocked';

    /**
     * The six confirm-gates that govern real provider execution, in evaluation
     * order. Every gate must report a passing signal before `execute` is allowed.
     *
     * @var list<string>
     */
    private const CONFIRM_GATES = [
        'slice_plan_locked',
        'branch_worktree_ready',
        'validation_commands_resolved',
        'receipt_before_provider',
        'budget_available',
        'kill_switch_clear',
    ];

    /**
     * @param  array<string,mixed>  $confirmGates       Named confirm-gate signals.
     * @param  array<string,mixed>  $providerAuthorization  Provider execute authorization.
     * @param  array<string,mixed>  $work               Work descriptor (mode, scope).
     * @return array<string,mixed>
     */
    public function evaluate(array $confirmGates, array $providerAuthorization, array $work): array
    {
        $failedGates = $this->failedConfirmGates($confirmGates);
        $smokeOnly = $this->isSmokeOnly($work);
        $providerAuthorized = $this->isProviderAuthorized($providerAuthorization);

        $gateSummary = $this->buildGateSummary($confirmGates, $failedGates, $smokeOnly, $providerAuthorized);

        // Governance first: a failed confirm-gate blocks every lane, including fixture.
        if ($failedGates !== []) {
            $reasons = [];

            foreach ($failedGates as $gate) {
                $reasons[] = 'confirm_gate_failed:'.$gate;
            }

            return $this->result(self::MODE_BLOCKED, false, $reasons, $gateSummary);
        }

        // Explicit smoke work stays in the fixture lane and never touches a provider.
        if ($smokeOnly) {
            return $this->result(self::MODE_FIXTURE_ONLY, false, [], $gateSummary);
        }

        // Real execution additionally requires an explicit provider authorization.
        if (! $providerAuthorized) {
            return $this->result(
                self::MODE_BLOCKED,
                false,
                ['provider_authorization_missing'],
                $gateSummary,
            );
        }

        return $this->result(self::MODE_EXECUTE, true, [], $gateSummary);
    }

    /**
     * @param  array<string,mixed>  $confirmGates
     * @return list<string>
     */
    private function failedConfirmGates(array $confirmGates): array
    {
        $failed = [];

        foreach (self::CONFIRM_GATES as $gate) {
            if (! $this->gatePasses($confirmGates, $gate)) {
                $failed[] = $gate;
            }
        }

        return $failed;
    }

    /**
     * A gate passes only on an explicit positive signal; absence is a failure so
     * an incomplete confirm-gate set can never be mistaken for a green run.
     *
     * @param  array<string,mixed>  $confirmGates
     */
    private function gatePasses(array $confirmGates, string $gate): bool
    {
        if (! array_key_exists($gate, $confirmGates)) {
            return false;
        }

        $value = $confirmGates[$gate];

        if (is_bool($value)) {
            return $value;
        }

        if (is_string($value)) {
            return in_array(strtolower($value), ['pass', 'passed', 'ok', 'green', 'true'], true);
        }

        if (is_int($value)) {
            return $value === 1;
        }

        return false;
    }

    /**
     * @param  array<string,mixed>  $work
     */
    private function isSmokeOnly(array $work): bool
    {
        if (($work['smoke_only'] ?? false) === true) {
            return true;
        }

        $mode = $work['mode'] ?? null;

        return is_string($mode) && strtolower($mode) === 'smoke_only';
    }

    /**
     * @param  array<string,mixed>  $providerAuthorization
     */
    private function isProviderAuthorized(array $providerAuthorization): bool
    {
        if (($providerAuthorization['authorized'] ?? false) !== true) {
            return false;
        }

        // An authorization that is present but explicitly revoked never counts.
        return ($providerAuthorization['revoked'] ?? false) !== true;
    }

    /**
     * @param  array<string,mixed>  $confirmGates
     * @param  list<string>  $failedGates
     * @return array<string,mixed>
     */
    private function buildGateSummary(
        array $confirmGates,
        array $failedGates,
        bool $smokeOnly,
        bool $providerAuthorized,
    ): array {
        $passedCount = count(self::CONFIRM_GATES) - count($failedGates);

        return [
            'required_gates' => count(self::CONFIRM_GATES),
            'passed_gates' => $passedCount,
            'failed_gates' => $failedGates,
            'all_gates_passed' => $failedGates === [],
            'smoke_only' => $smokeOnly,
            'provider_authorized' => $providerAuthorized,
        ];
    }

    /**
     * @param  list<string>  $blockingReasons
     * @param  array<string,mixed>  $gateSummary
     * @return array<string,mixed>
     */
    private function result(string $mode, bool $executeAllowed, array $blockingReasons, array $gateSummary): array
    {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'mode' => $mode,
            'execute_allowed' => $executeAllowed,
            'blocking_reasons' => $blockingReasons,
            'gate_summary' => $gateSummary,
        ];
    }
}
