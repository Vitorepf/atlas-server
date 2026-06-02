<?php

namespace App\Services\Ai\Hermes\Mesh;

use App\Services\Ai\Hermes\HermesAdapterReceipt;

/**
 * Pure, side-effect-free mesh fan-out advisor for the governed Hermes executive
 * runtime.
 *
 * Hermes is an executor / candidate-source only; ATLS (Atlas Decide) is the
 * sovereign authority. This advisor NEVER selects/calls a provider and NEVER
 * fans a mission out itself — it is the seam Atlas Decide consults to learn
 * whether a mission is even a legitimate candidate to be decomposed into a
 * Hermes mesh, and seals that judgement into an
 * `atlas.hermes.mesh_routing.v1` receipt so the Evidence Ledger can prove the
 * advice without trusting Hermes to narrate it.
 *
 * Fail-closed / default-off: `mesh_advised` is TRUE only when the operator
 * policy is the Atlas adapter (`providers.hermes_cli.mesh.policy === 'atlas_adapter'`)
 * AND a decomposition signal is present (a non-empty `payload.hermes.mesh.subtasks`
 * array OR `payload.hermes.mesh.requested === true`) AND the capture is NOT
 * privacy-blocked (sensitivity not in sensitive|secret). On any off / missing /
 * invalid input the receipt is disabled with an explicit `blocked_reason` and
 * `mesh_advised` is false. `authority` is always Atlas and
 * `hermes_mesh_can_decide` is always false. `mesh_advised` is the
 * `*_allowed_now`-equivalent: it may be true only on the fully-approved path.
 */
class HermesMeshRoutingAdvisor
{
    use HermesAdapterReceipt;

    /**
     * Advise Atlas Decide whether a mission should fan out as a Hermes mesh.
     * Side-effect free; returns a sealed receipt.
     *
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    public function advise(array $options): array
    {
        $policy = $this->policy();
        $subtaskCount = $this->subtaskCount($options);
        $hasSignal = $this->hasDecompositionSignal($options);
        $privacyBlocks = $this->privacyBlocks($options);

        $blockedReason = $this->blockedReason($policy, $hasSignal, $privacyBlocks);
        $meshAdvised = $blockedReason === null;

        $receipt = [
            'schema_version' => 'atlas.hermes.mesh_routing.v1',
            'advisor_component' => 'hermes_mesh_routing_advisor',
            'runtime_role' => 'executive_runtime',
            'mesh_policy' => $policy,
            'authority' => 'atlas',
            'hermes_mesh_can_decide' => false,
            'mesh_advised' => $meshAdvised,
            'subtask_count' => $subtaskCount,
            'blocked_reason' => $blockedReason,
            'reason' => $this->reason($meshAdvised, $blockedReason),
            'status' => $this->status($meshAdvised, $blockedReason),
        ];

        return $this->withReceiptHash($receipt);
    }

    private function blockedReason(string $policy, bool $hasSignal, bool $privacyBlocks): ?string
    {
        if ($policy !== 'atlas_adapter') {
            return 'mesh_policy_off';
        }

        if (! $hasSignal) {
            return 'no_decomposition_signal';
        }

        if ($privacyBlocks) {
            return 'privacy_blocks_external_runtime';
        }

        return null;
    }

    /**
     * @param  array<string,mixed>  $options
     */
    private function hasDecompositionSignal(array $options): bool
    {
        $subtasks = data_get($options, 'payload.hermes.mesh.subtasks');
        if (is_array($subtasks) && $subtasks !== []) {
            return true;
        }

        return data_get($options, 'payload.hermes.mesh.requested') === true;
    }

    /**
     * Count of declared subtasks. Hashes nothing — this is just a count, never
     * the subtask contents.
     *
     * @param  array<string,mixed>  $options
     */
    private function subtaskCount(array $options): int
    {
        $subtasks = data_get($options, 'payload.hermes.mesh.subtasks');

        return is_array($subtasks) ? count($subtasks) : 0;
    }

    /**
     * @param  array<string,mixed>  $options
     */
    private function privacyBlocks(array $options): bool
    {
        $sensitivity = data_get($options, 'payload.privacy.sensitivity');

        return in_array($sensitivity, ['sensitive', 'secret'], true);
    }

    private function policy(): string
    {
        $value = config('atlas.ai.providers.hermes_cli.mesh.policy', 'off');

        return is_string($value) && trim($value) !== '' ? strtolower(trim($value)) : 'off';
    }

    private function reason(bool $meshAdvised, ?string $blockedReason): string
    {
        if ($meshAdvised) {
            return 'Atlas Decide may fan this mission out as a Hermes mesh: mesh policy is the Atlas adapter, a decomposition signal is present and no privacy class blocks external executive runtime.';
        }

        return match ($blockedReason) {
            'mesh_policy_off' => 'Hermes mesh fan-out is not advised: providers.hermes_cli.mesh.policy is not the Atlas adapter, so Atlas Decide keeps the mission single-runtime.',
            'no_decomposition_signal' => 'Hermes mesh fan-out is not advised: no decomposition signal (no subtasks and mesh not requested), so Atlas Decide keeps the mission single-runtime.',
            'privacy_blocks_external_runtime' => 'Hermes mesh fan-out is blocked: sensitive or secret capture privacy forbids external executive runtime fan-out.',
            default => 'Hermes mesh fan-out is not advised under governance policy.',
        };
    }

    private function status(bool $meshAdvised, ?string $blockedReason): string
    {
        if ($meshAdvised) {
            return 'mesh_advised';
        }

        return match ($blockedReason) {
            'mesh_policy_off' => 'mesh_disabled_by_policy',
            'privacy_blocks_external_runtime' => 'mesh_blocked',
            default => 'mesh_not_advised',
        };
    }
}
