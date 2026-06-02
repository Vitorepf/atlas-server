<?php

namespace App\Services\Ai\Hermes;

use App\Models\HermesCapabilityCandidate;

/**
 * Operator-gated enablement of a quarantined Hermes capability candidate.
 *
 * The {@see HermesCapabilityRegistry} auto-detects everything Hermes newly
 * exposes and lands each one as a quarantined HermesCapabilityCandidate
 * (`gate_status = quarantined_for_atlas_capability_review`, `enabled = false`);
 * nothing is ever usable automatically. This gate is the single Atlas-sovereign
 * promotion path that closes the keystone loop — auto-detect -> Atlas approves
 * -> usable — by flipping a candidate to approved on explicit operator
 * confirmation. It is fail-closed: every path returns a sealed
 * `atlas.hermes.capability_enablement_receipt.v1`, `enablement_allowed_now` is
 * true ONLY on a successful approved enablement, ALWAYS_QUARANTINE classes
 * additionally require explicit operator authority, and the row is mutated only
 * AFTER the success receipt_hash is computed so the persisted
 * `gate_json.enablement_receipt_hash` proves the enablement event. The flip is
 * reversible: a later call can re-quarantine the candidate. The capability-aware
 * invocation builder then honors only approved candidates.
 */
class HermesCapabilityEnablementGate
{
    use HermesAdapterReceipt;

    /**
     * Capability classes that can never be enabled on operator confirmation
     * alone — promoting them requires explicit operator authority.
     */
    private const ALWAYS_QUARANTINE = ['mcp_server', 'hook', 'delegation', 'code_exec', 'gateway'];

    /**
     * @param  array<string,mixed>  $approval  operator_confirmed:bool, approved_by:?string, reason:?string, operator_authority:?bool
     * @return array<string,mixed>
     */
    public function approve(HermesCapabilityCandidate $candidate, array $approval = []): array
    {
        $class = (string) $candidate->capability_class;
        $key = (string) $candidate->capability_key;
        $riskLevel = (string) ($candidate->risk_level ?? '');
        $gateStatusBefore = (string) ($candidate->gate_status ?? '');

        $operatorConfirmed = ($approval['operator_confirmed'] ?? false) === true;
        $operatorAuthority = ($approval['operator_authority'] ?? false) === true;

        $receipt = [
            'schema_version' => 'atlas.hermes.capability_enablement_receipt.v1',
            'gate' => 'hermes_capability_enablement_gate',
            'capability_authority' => 'atlas',
            'enablement_allowed_now' => false,
            'capability_id' => $class.':'.$key,
            'capability_class' => $class,
            'risk_level' => $riskLevel,
            'operator_confirmed' => $operatorConfirmed,
            'operator_authority' => $operatorAuthority,
            'status' => 'rejected_not_a_candidate',
            'candidate_id' => $candidate->id,
            'gate_status_before' => $gateStatusBefore,
            'gate_status_after' => $gateStatusBefore,
        ];

        // (1) Only a still-quarantined candidate is reviewable. An already
        // enabled capability is not a candidate for enablement.
        if (! $this->reviewable($candidate)) {
            return $this->reject($receipt, 'rejected_not_a_candidate');
        }

        // (2) Enablement is an explicit operator act — never implicit.
        if (! $operatorConfirmed) {
            return $this->reject($receipt, 'rejected_enablement_not_confirmed');
        }

        // (3) High-risk always-quarantine classes additionally require explicit
        // operator authority on top of confirmation.
        if (in_array($class, self::ALWAYS_QUARANTINE, true) && ! $operatorAuthority) {
            return $this->reject($receipt, 'rejected_high_risk_requires_operator_authority');
        }

        // (4) Success. Seal the receipt BEFORE mutating the row so the persisted
        // gate_json.enablement_receipt_hash equals this receipt's receipt_hash.
        $gateStatusAfter = 'approved_for_atlas_capability_use';
        $receipt['enablement_allowed_now'] = true;
        $receipt['status'] = 'approved';
        $receipt['gate_status_after'] = $gateStatusAfter;

        $sealed = $this->withReceiptHash($receipt);

        $approvedBy = $this->string($approval['approved_by'] ?? null) ?? 'operator';
        $approvedAt = now()->toJSON();

        $candidate->update([
            'enabled' => true,
            'gate_status' => $gateStatusAfter,
            'review_required' => false,
            'reviewed_at' => now(),
            'gate_json' => array_merge(
                is_array($candidate->gate_json) ? $candidate->gate_json : [],
                [
                    'enablement_receipt_hash' => $sealed['receipt_hash'],
                    'approved_by' => $approvedBy,
                    'approved_at' => $approvedAt,
                ],
            ),
        ]);

        return $sealed;
    }

    /**
     * @param  array<string,mixed>  $receipt
     * @return array<string,mixed>
     */
    private function reject(array $receipt, string $status): array
    {
        $receipt['enablement_allowed_now'] = false;
        $receipt['status'] = $status;
        $receipt['gate_status_after'] = $receipt['gate_status_before'];

        return $this->withReceiptHash($receipt);
    }

    /**
     * A candidate is reviewable only while it is still quarantined and not yet
     * enabled. Once enabled it is no longer a candidate for enablement.
     */
    private function reviewable(HermesCapabilityCandidate $candidate): bool
    {
        return $candidate->enabled !== true;
    }

    private function string(mixed $value): ?string
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
