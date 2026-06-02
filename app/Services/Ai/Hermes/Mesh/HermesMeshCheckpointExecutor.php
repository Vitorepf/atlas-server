<?php

declare(strict_types=1);

namespace App\Services\Ai\Hermes\Mesh;

use App\Services\Ai\Hermes\HermesAdapterReceipt;

/**
 * Governed rollback-on-failed-verifier DECISION loop for code-mutating missions.
 *
 * {@see HermesCheckpointPolicy} decides IF Hermes' `--checkpoints` safety net is
 * engaged for a mission. This executor closes the loop: it pairs that sealed
 * policy decision with the verifier outcome and emits the sealed decision of
 * WHETHER a rollback should fire now. It is PURE and side-effect-bounded — it
 * NEVER shells out to Hermes, NEVER restores a checkpoint and NEVER decides
 * policy. It only emits the advisory `atlas.hermes.checkpoint_action.v1` receipt
 * the Executive Runtime consults before building the (already-governed) rollback
 * invocation.
 *
 * Fail-closed: `rollback_now` is TRUE only when the policy decision is
 * `enabled === true` AND `rollback_on_failed_verifier === true` AND the verifier
 * explicitly reported `passed === false`. Any missing/invalid policy key, or a
 * verifier missing its `passed` flag, yields `rollback_now=false` and records a
 * `reason`. `authority` is always Atlas and `hermes_checkpoint_can_decide` is
 * always false.
 */
class HermesMeshCheckpointExecutor
{
    use HermesAdapterReceipt;

    /**
     * Advisory rollback strategy label. Informational only; this class never
     * executes a rollback.
     */
    private const ROLLBACK_STRATEGY = 'hermes_checkpoints_restore';

    /**
     * Decide whether a checkpoint rollback should fire now given the sealed
     * checkpoint policy decision and the verifier outcome. Side-effect free;
     * returns a sealed receipt.
     *
     * @param  array<string,mixed>  $checkpointDecision  Output of {@see HermesCheckpointPolicy::decide()}.
     * @param  array<string,mixed>  $verifierResult  ['passed' => bool, 'detail'? => string]
     * @return array<string,mixed>
     */
    public function evaluate(array $checkpointDecision, array $verifierResult): array
    {
        $permissionMode = $this->permissionMode($checkpointDecision['permission_mode'] ?? null);

        $receipt = [
            'schema_version' => 'atlas.hermes.checkpoint_action.v1',
            'action_component' => 'hermes_mesh_checkpoint_executor',
            'authority' => 'atlas',
            'hermes_checkpoint_can_decide' => false,
            'rollback_now' => false,
            'rollback_strategy' => self::ROLLBACK_STRATEGY,
            'rollback_args' => $this->rollbackArgs(),
            'permission_mode' => $permissionMode,
            'verifier_passed' => false,
            'action_allowed_now' => false,
            'reason' => null,
            'status' => 'rollback_not_required',
        ];

        $enabled = $this->bool($checkpointDecision['enabled'] ?? null);
        $rollbackOnFail = $this->bool($checkpointDecision['rollback_on_failed_verifier'] ?? null);

        // Fail-closed: the policy must be fully engaged before any rollback is
        // even considered.
        if ($enabled !== true) {
            $receipt['reason'] = 'checkpoint_policy_not_enabled';
            $receipt['status'] = 'rollback_blocked';

            return $this->withReceiptHash($receipt);
        }

        if ($rollbackOnFail !== true) {
            $receipt['reason'] = 'rollback_on_failed_verifier_not_engaged';
            $receipt['status'] = 'rollback_blocked';

            return $this->withReceiptHash($receipt);
        }

        // Fail-closed: the verifier must explicitly report a boolean outcome.
        if (! array_key_exists('passed', $verifierResult) || ! is_bool($verifierResult['passed'])) {
            $receipt['reason'] = 'verifier_result_missing_passed';
            $receipt['status'] = 'rollback_blocked';

            return $this->withReceiptHash($receipt);
        }

        $passed = $verifierResult['passed'];
        $receipt['verifier_passed'] = $passed;

        if ($passed === true) {
            $receipt['reason'] = 'verifier_passed';
            $receipt['status'] = 'rollback_not_required';

            return $this->withReceiptHash($receipt);
        }

        // Fully-approved path: policy engaged + rollback armed + verifier failed.
        $receipt['rollback_now'] = true;
        $receipt['action_allowed_now'] = true;
        $receipt['reason'] = null;
        $receipt['status'] = 'rollback_required';

        return $this->withReceiptHash($receipt);
    }

    /**
     * Pure advisory hermes argv for a checkpoint rollback. Informational only —
     * this class never executes it.
     *
     * @return array<int,string>
     */
    public function rollbackArgs(): array
    {
        return ['checkpoints'];
    }

    private function bool(mixed $value): ?bool
    {
        return is_bool($value) ? $value : null;
    }

    private function permissionMode(mixed $value): string
    {
        if (! is_string($value)) {
            return 'read';
        }

        $mode = strtolower(trim($value));

        return in_array($mode, ['read', 'write', 'danger'], true) ? $mode : 'read';
    }
}
