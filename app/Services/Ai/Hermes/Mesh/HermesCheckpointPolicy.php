<?php

namespace App\Services\Ai\Hermes\Mesh;

use App\Services\Ai\Hermes\HermesAdapterReceipt;

/**
 * Governed execution-safety policy for code-mutating Hermes missions.
 *
 * Hermes ships a `--checkpoints` flag that snapshots the workspace before a
 * mission mutates code and can roll it back when a verifier fails. Atlas — not
 * Hermes — decides IF that safety net is engaged for a given mission. This is a
 * PURE planner: it never shells out to Hermes and never flips the CLI flag
 * itself; it only emits the sealed decision the Executive Runtime consults when
 * it builds the (already-governed) `--checkpoints` invocation.
 *
 * Fail-closed: `checkpoint_before`, `rollback_on_failed_verifier` and `enabled`
 * are TRUE only when the operator policy is the Atlas adapter AND the permission
 * mode is `write` or `danger` (NEVER `read`). When the policy is off, missing or
 * the mode is `read`, every flag is false and a `reason` records why. The
 * permission mode is normalized to read|write|danger (anything else => `read`,
 * the safest mode). `authority` is always Atlas and
 * `hermes_checkpoint_can_decide` is always false.
 */
class HermesCheckpointPolicy
{
    use HermesAdapterReceipt;

    /**
     * Decide whether checkpoint-before and rollback-on-failed-verifier should be
     * engaged for this mission. Side-effect free; returns a sealed receipt.
     *
     * @param  array<string,mixed>  $mission  An `atlas.hermes.executive_mission.v1` array.
     * @return array<string,mixed>
     */
    public function decide(array $mission, string $permissionMode): array
    {
        $policy = $this->policy();
        $permissionMode = $this->permissionMode($permissionMode);
        $missionId = $this->string($mission['mission_id'] ?? null, 190);
        $missionHash = $this->string($mission['mission_hash'] ?? null, 190);

        $receipt = [
            'schema_version' => 'atlas.hermes.checkpoint_policy.v1',
            'policy_component' => 'hermes_checkpoint_policy',
            'checkpoint_policy' => $policy,
            'authority' => 'atlas',
            'hermes_checkpoint_can_decide' => false,
            'enabled' => false,
            'checkpoint_before' => false,
            'rollback_on_failed_verifier' => false,
            'checkpoint_allowed_now' => false,
            'permission_mode' => $permissionMode,
            'mission_id' => $missionId,
            'mission_hash' => $missionHash,
            'reason' => null,
            'status' => 'checkpoint_disabled_by_policy',
        ];

        if ($policy !== 'atlas_adapter') {
            $receipt['reason'] = 'checkpoint_policy_not_atlas_adapter';

            return $this->withReceiptHash($receipt);
        }

        if ($permissionMode === 'read') {
            $receipt['reason'] = 'permission_mode_read';
            $receipt['status'] = 'checkpoint_blocked';

            return $this->withReceiptHash($receipt);
        }

        if (! in_array($permissionMode, ['write', 'danger'], true)) {
            $receipt['reason'] = 'permission_mode_not_write_or_danger';
            $receipt['status'] = 'checkpoint_blocked';

            return $this->withReceiptHash($receipt);
        }

        // Fully-approved path: a code-mutating (write/danger) mission under the
        // Atlas adapter policy engages both the pre-mutation snapshot and the
        // verifier-failure rollback.
        $receipt['enabled'] = true;
        $receipt['checkpoint_before'] = true;
        $receipt['rollback_on_failed_verifier'] = true;
        $receipt['checkpoint_allowed_now'] = true;
        $receipt['status'] = 'checkpoint_engaged';

        return $this->withReceiptHash($receipt);
    }

    private function policy(): string
    {
        $value = config('atlas.ai.providers.hermes_cli.mesh.checkpoint_policy', 'off');

        return is_string($value) && trim($value) !== '' ? strtolower(trim($value)) : 'off';
    }

    private function permissionMode(string $permissionMode): string
    {
        $mode = strtolower(trim($permissionMode));

        return in_array($mode, ['read', 'write', 'danger'], true) ? $mode : 'read';
    }

    private function string(mixed $value, int $limit): ?string
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }

        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        return mb_strlen($value) > $limit ? mb_substr($value, 0, $limit) : $value;
    }
}
