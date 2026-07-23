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

        return [
            'ok' => $violations === [],
            'violations' => $violations,
            'contract' => $contract,
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
