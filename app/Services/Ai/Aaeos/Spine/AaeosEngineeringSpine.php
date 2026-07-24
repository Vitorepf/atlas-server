<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Spine;

/**
 * Shared Engineering Spine contract for Dev · Forge · Autonomos.
 *
 * Forces a single vocabulary for N9 Delivery + N11 Evidence so executors
 * do not invent parallel ledgers or delivery stacks. This façade is the
 * **seam**; concrete delivery/evidence still live in RealExecution / Kernel.
 *
 * @see docs/evidence/2026-07-22-atlas-server-god-debulk/ATLAS-NUCLEUS-GOD-SOTA.md
 */
final class AaeosEngineeringSpine
{
    public const SCHEMA = 'atlas.aaeos.engineering_spine.v1';

    public const DELIVERY_OWNER = 'N9';

    public const EVIDENCE_OWNER = 'N11';

    /** Canonical class anchors (navigation — not optional alternate stacks). */
    public const DELIVERY_RUNTIME_CLASS = 'App\\Services\\Ai\\RealExecution\\AtlasRealEngineeringExecutionKernelService';

    public const EVIDENCE_LEDGER_CLASS = 'App\\Services\\Ai\\Kernel\\Evidence\\AtlasEvidenceLedger';

    /**
     * @param  'dev'|'forge'|'autonomos'|string  $mode
     * @return array<string,mixed>
     */
    public function contractForMode(string $mode): array
    {
        $mode = strtolower(trim($mode));

        return [
            'schema' => self::SCHEMA,
            'mode' => $mode,
            'elite_same_bar' => true,
            'delivery' => [
                'owner' => self::DELIVERY_OWNER,
                'runtime_class' => self::DELIVERY_RUNTIME_CLASS,
                'required_artifacts' => $this->deliveryArtifacts($mode),
            ],
            'evidence' => [
                'owner' => self::EVIDENCE_OWNER,
                'ledger_class' => self::EVIDENCE_LEDGER_CLASS,
                'required_slots' => $this->evidenceSlots($mode),
                'parallel_ledger_forbidden' => true,
            ],
            'forbidden' => [
                'second_provider_registry',
                'executor_private_evidence_truth',
                'verified_true_without_ledger_api',
            ],
        ];
    }

    /**
     * Validate that a caller-declared stack points at the shared spine.
     *
     * @param  array<string,mixed>  $declared
     * @return array{ok:bool,violations:list<string>,contract:array<string,mixed>}
     */
    public function assertShared(string $mode, array $declared = []): array
    {
        $contract = $this->contractForMode($mode);
        $violations = [];

        $deliveryClass = (string) ($declared['delivery_runtime_class'] ?? self::DELIVERY_RUNTIME_CLASS);
        $evidenceClass = (string) ($declared['evidence_ledger_class'] ?? self::EVIDENCE_LEDGER_CLASS);

        if ($deliveryClass !== self::DELIVERY_RUNTIME_CLASS) {
            $violations[] = 'delivery_runtime_must_be_shared_n9';
        }
        if ($evidenceClass !== self::EVIDENCE_LEDGER_CLASS) {
            $violations[] = 'evidence_ledger_must_be_shared_n11';
        }
        if (array_key_exists('parallel_ledger', $declared) && $declared['parallel_ledger']) {
            $violations[] = 'parallel_ledger_forbidden';
        }

        // P2d / R66: critical N11 only via settlement-emitted effect receipt (ledger readback).
        $settlement = $this->assertSettlementEffectEvidence($declared);
        $violations = array_values(array_unique(array_merge($violations, $settlement['violations'])));
        $contract['settlement_evidence'] = $settlement;

        return [
            'ok' => $violations === [],
            'violations' => $violations,
            'contract' => $contract,
        ];
    }

    /**
     * R66: applicable N11 site is satisfied only by settlement-emitted effect receipt
     * (observer identity + changed-files hash + landed SHA) via ledger readback.
     * Caller-declared/empty refs fail closed.
     *
     * @param  array<string,mixed>  $declared
     * @return array{applicable:bool,ok:bool,violations:list<string>,refs_checked:int}
     */
    public function assertSettlementEffectEvidence(array $declared = []): array
    {
        $applicable = (bool) ($declared['n11_applicable'] ?? false)
            || (bool) ($declared['requires_settlement_effect'] ?? false)
            || array_key_exists('settlement_effect_refs', $declared)
            || array_key_exists('effect_receipt_refs', $declared);

        if (! $applicable) {
            return [
                'applicable' => false,
                'ok' => true,
                'violations' => [],
                'refs_checked' => 0,
                'rule' => 'R66',
            ];
        }

        $violations = [];
        $refs = $declared['settlement_effect_refs'] ?? $declared['effect_receipt_refs'] ?? null;

        if (! is_array($refs) || $refs === []) {
            $violations[] = 'n11_settlement_effect_refs_required';

            return [
                'applicable' => true,
                'ok' => false,
                'violations' => $violations,
                'refs_checked' => 0,
                'rule' => 'R66',
            ];
        }

        $checked = 0;
        foreach ($refs as $index => $ref) {
            $checked++;
            if (! is_array($ref)) {
                $violations[] = 'n11_settlement_ref_invalid:'.$index;

                continue;
            }
            if ((bool) ($ref['empty'] ?? false)
                || (bool) ($ref['caller_declared_only'] ?? false)
                || (bool) ($ref['declared_without_ledger'] ?? false)) {
                $violations[] = 'n11_empty_or_declared_only_ref_forbidden';
            }
            foreach (['observer_identity', 'changed_files_hash', 'landed_sha', 'ledger_event_id'] as $field) {
                if (trim((string) ($ref[$field] ?? '')) === '') {
                    $violations[] = 'n11_settlement_ref_missing_'.$field;
                }
            }
            $hash = (string) ($ref['changed_files_hash'] ?? '');
            if ($hash !== '' && preg_match('/^[a-f0-9]{64}$/', $hash) !== 1) {
                $violations[] = 'n11_changed_files_hash_invalid';
            }
            $sha = (string) ($ref['landed_sha'] ?? '');
            if ($sha !== '' && preg_match('/^[a-f0-9]{40,64}$/', $sha) !== 1) {
                $violations[] = 'n11_landed_sha_invalid';
            }
            // Ledger readback: must be verified (caller reloaded event from shared N11 ledger).
            if (! (bool) ($ref['ledger_readback_verified'] ?? false)) {
                $violations[] = 'n11_settlement_ledger_readback_required';
            }
            // Self-green from empty payload is forbidden.
            if (array_key_exists('payload', $ref) && $ref['payload'] === []) {
                $violations[] = 'n11_empty_payload_self_green_forbidden';
            }
        }

        $violations = array_values(array_unique($violations));

        return [
            'applicable' => true,
            'ok' => $violations === [],
            'violations' => $violations,
            'refs_checked' => $checked,
            'rule' => 'R66',
        ];
    }

    /**
     * @return list<string>
     */
    private function deliveryArtifacts(string $mode): array
    {
        return match ($mode) {
            'forge' => ['sdd_or_plan', 'work_packets', 'patch_or_diff', 'verification', 'delivery_pack'],
            'autonomos' => ['task_contract', 'scoped_diff', 'verification', 'scoped_commit_receipt'],
            default => ['plan_or_mini_spec', 'patch_or_diff', 'verification_receipt'],
        };
    }

    /**
     * @return list<string>
     */
    private function evidenceSlots(string $mode): array
    {
        $base = ['verification_receipt', 'evidence_refs'];
        if ($mode === 'forge') {
            $base[] = 'evidence_pack';
            $base[] = 'release_decision_slot';
        }
        if ($mode === 'autonomos') {
            $base[] = 'seed_gate_receipt';
            $base[] = 'task_lease_binding';
        }

        return $base;
    }
}
