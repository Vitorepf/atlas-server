<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Simplification;

/**
 * Pure retirement ledger. Classifies Autonomous OS organs as retain or
 * retire_or_convert so proxy-only or unused organs are never silently
 * counted as real capability.
 *
 * VERDICT (first match wins, evaluated per organ):
 *   retire_or_convert — proxy_only=true, OR superseded_by is non-empty,
 *                        OR (consumer_count <= 0 AND has_proof_receipt=false)
 *   retain             — otherwise
 *
 * INPUT:
 *   organs: list<{
 *     organ_id:            string
 *     consumer_count?:     int    (default 0)
 *     proxy_only?:         bool   (default false)
 *     has_proof_receipt?:  bool   (default false)
 *     superseded_by?:      string (default '')
 *   }>
 *
 * OUTPUT:
 *   { schema, organs: list<{organ_id, verdict, retire_reasons, retain_reasons}>,
 *     retire_count, retain_count }
 *
 * Pure: no I/O, no side effects.
 */
final class AtlasSelfConstructionDeadOrganRetirementLedger
{
    public const SCHEMA = 'atlas.self_construction.dead_organ_retirement_ledger.v1';

    public const VERDICT_RETAIN = 'retain';

    public const VERDICT_RETIRE_OR_CONVERT = 'retire_or_convert';

    public const STATUS_RECORDED = 'recorded';

    public const STATUS_REJECTED = 'rejected';

    private const REQUIRED_RETIREMENT_PROOF_FIELDS = [
        'evidence_refs',
        'consumer_scan_result',
        'parity_decision',
        'deletion_plan_hash',
        'replay_gate_result',
        'rollback_receipt',
        'knowledge_sync_status',
    ];

    /**
     * Records durable proof that a dead organ was safely retired. Every
     * required proof field must be present and non-empty or the retirement
     * is rejected with the exact missing fields named — a deletion without
     * full proof is unsafe to trust, and future agents must be able to see
     * exactly what evidence was (or wasn't) captured.
     *
     * @param  array{
     *   organ_id?: string,
     *   evidence_refs?: list<string>,
     *   consumer_scan_result?: array<string,mixed>|string,
     *   parity_decision?: array<string,mixed>|string,
     *   deletion_plan_hash?: string,
     *   replay_gate_result?: array<string,mixed>|string,
     *   rollback_receipt?: array<string,mixed>|string,
     *   knowledge_sync_status?: array<string,mixed>|string,
     * }  $record
     * @return array{schema:string, status:string, organ_id:string, missing_proof_fields:list<string>, receipt_hash:?string}
     */
    public function recordRetirement(array $record): array
    {
        $organId = (string) ($record['organ_id'] ?? '');

        $missing = [];
        if ($organId === '') {
            $missing[] = 'organ_id';
        }

        foreach (self::REQUIRED_RETIREMENT_PROOF_FIELDS as $field) {
            if (! $this->isProofPresent($record[$field] ?? null)) {
                $missing[] = $field;
            }
        }

        if ($missing !== []) {
            return [
                'schema' => self::SCHEMA,
                'status' => self::STATUS_REJECTED,
                'organ_id' => $organId,
                'missing_proof_fields' => $missing,
                'receipt_hash' => null,
            ];
        }

        return [
            'schema' => self::SCHEMA,
            'status' => self::STATUS_RECORDED,
            'organ_id' => $organId,
            'missing_proof_fields' => [],
            'receipt_hash' => $this->retirementReceiptHash($organId, $record),
        ];
    }

    private function isProofPresent(mixed $value): bool
    {
        if ($value === null) {
            return false;
        }

        if (is_array($value)) {
            return $value !== [];
        }

        return trim((string) $value) !== '';
    }

    /**
     * @param  array<string,mixed>  $record
     */
    private function retirementReceiptHash(string $organId, array $record): string
    {
        $proof = ['organ_id' => $organId];
        foreach (self::REQUIRED_RETIREMENT_PROOF_FIELDS as $field) {
            $proof[$field] = $record[$field];
        }
        ksort($proof);

        return hash('sha256', (string) json_encode($proof, JSON_THROW_ON_ERROR));
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function classify(array $input): array
    {
        $rawOrgans = is_array($input['organs'] ?? null) ? $input['organs'] : [];

        $rows = [];
        $retireCount = 0;
        $retainCount = 0;

        foreach ($rawOrgans as $raw) {
            $organId = (string) ($raw['organ_id'] ?? '');
            if ($organId === '') {
                continue;
            }

            $consumerCount = max(0, (int) ($raw['consumer_count'] ?? 0));
            $proxyOnly = (bool) ($raw['proxy_only'] ?? false);
            $hasProofReceipt = (bool) ($raw['has_proof_receipt'] ?? false);
            $supersededBy = trim((string) ($raw['superseded_by'] ?? ''));

            $retireReasons = [];
            if ($proxyOnly) {
                $retireReasons[] = 'proxy_only_no_real_capability';
            }
            if ($supersededBy !== '') {
                $retireReasons[] = 'superseded_by:'.$supersededBy;
            }
            if ($consumerCount === 0 && ! $hasProofReceipt) {
                $retireReasons[] = 'unused_no_consumers_no_proof';
            }

            if ($retireReasons !== []) {
                $verdict = self::VERDICT_RETIRE_OR_CONVERT;
                $retireCount++;
                $retainReasons = [];
            } else {
                $verdict = self::VERDICT_RETAIN;
                $retainCount++;
                $retainReasons = [];
                if ($consumerCount > 0) {
                    $retainReasons[] = 'has_consumers:'.$consumerCount;
                }
                if ($hasProofReceipt) {
                    $retainReasons[] = 'has_proof_receipt';
                }
            }

            $rows[] = [
                'organ_id' => $organId,
                'verdict' => $verdict,
                'retire_reasons' => $retireReasons,
                'retain_reasons' => $retainReasons,
            ];
        }

        return [
            'schema' => self::SCHEMA,
            'organs' => $rows,
            'retire_count' => $retireCount,
            'retain_count' => $retainCount,
        ];
    }
}
