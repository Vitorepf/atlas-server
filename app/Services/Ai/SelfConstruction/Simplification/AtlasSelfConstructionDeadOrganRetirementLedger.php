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
