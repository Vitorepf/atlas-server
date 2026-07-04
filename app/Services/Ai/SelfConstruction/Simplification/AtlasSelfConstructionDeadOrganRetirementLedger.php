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

    /** All four evidence gates confirmed — safe for autonomous deletion. */
    public const VERDICT_SAFE_TO_RETIRE = 'safe_to_retire';

    /** Retirement candidate, but at least one evidence gate is opt-in-supplied and unmet — blocked
     *  from autonomous deletion pending review. */
    public const VERDICT_REVIEW_NEEDED = 'review_needed';

    /** @var list<string> opt-in evidence-gate fact keys — when NONE is supplied, legacy behavior
     *  (bare retire_or_convert) is preserved exactly for existing callers. */
    private const EVIDENCE_GATE_FIELDS = [
        'consumer_impact_assessed',
        'behavior_parity_confirmed',
        'rollback_plan_present',
        'knowledge_sync_confirmed',
    ];

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

    /** @var list<string> Fields whose value must contain evidence-level depth — cannot be a single
     *  word, a short placeholder, or an array with no actionable structure. */
    private const PROOF_FIELDS_REQUIRING_BOUND_VALUE = [
        'parity_decision',
        'replay_gate_result',
        'rollback_receipt',
        'knowledge_sync_status',
    ];

    /** @var list<string> Substrings that make a proof value provider-unsafe — raw prompts, traces,
     *  conversation text, or secret-like payloads that must never leak into the retirement ledger. */
    private const PROVIDER_UNSAFE_PATTERNS = [
        'raw_prompt',
        'provider_trace',
        'conversation_text',
        'system:',
        'api_key',
        'secret=',
        'token=',
    ];

    /**
     * Records durable proof that a dead organ was safely retired. Every
     * required proof field must be present and non-empty or the retirement
     * is rejected with the exact missing fields named — a deletion without
     * full proof is unsafe to trust, and future agents must be able to see
     * exactly what evidence was (or wasn't) captured.
     *
     * AC2/AC3: parity, replay, rollback and knowledge_sync proof values must
     * be BOUND (not a generic placeholder) and PROVIDER-SAFE (no raw prompts,
     * traces, or secrets). Unbound or unsafe values are rejected with the
     * specific failing fields named.
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
     * @return array{schema:string, status:string, organ_id:string, missing_proof_fields:list<string>, unbound_proof_fields:list<string>, provider_unsafe_retirement_proof:bool, receipt_hash:?string}
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
                'schema'                          => self::SCHEMA,
                'status'                          => self::STATUS_REJECTED,
                'organ_id'                        => $organId,
                'missing_proof_fields'            => $missing,
                'unbound_proof_fields'            => [],
                'provider_unsafe_retirement_proof' => false,
                'receipt_hash'                    => null,
            ];
        }

        // AC2: check for unbound (generic/placeholder) proof values in required fields.
        $unbound = [];
        foreach (self::PROOF_FIELDS_REQUIRING_BOUND_VALUE as $field) {
            $value = $record[$field] ?? null;
            if ($value !== null && ! $this->isProofBound($value)) {
                $unbound[] = $field;
            }
        }

        if ($unbound !== []) {
            return [
                'schema'                          => self::SCHEMA,
                'status'                          => self::STATUS_REJECTED,
                'organ_id'                        => $organId,
                'missing_proof_fields'            => [],
                'unbound_proof_fields'            => $unbound,
                'provider_unsafe_retirement_proof' => false,
                'receipt_hash'                    => null,
            ];
        }

        // AC3: check for provider-unsafe payloads (raw prompts, traces, secrets).
        $providerUnsafe = $this->hasProviderUnsafePayload($record);

        if ($providerUnsafe) {
            return [
                'schema'                          => self::SCHEMA,
                'status'                          => self::STATUS_REJECTED,
                'organ_id'                        => $organId,
                'missing_proof_fields'            => [],
                'unbound_proof_fields'            => [],
                'provider_unsafe_retirement_proof' => true,
                'receipt_hash'                    => null,
            ];
        }

        return [
            'schema'                          => self::SCHEMA,
            'status'                          => self::STATUS_RECORDED,
            'organ_id'                        => $organId,
            'missing_proof_fields'            => [],
            'unbound_proof_fields'            => [],
            'provider_unsafe_retirement_proof' => false,
            'receipt_hash'                    => $this->retirementReceiptHash($organId, $record),
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
     * A bound proof value contains evidence-level depth — not a single short
     * placeholder word or an empty/generic array.
     */
    private function isProofBound(mixed $value): bool
    {
        if ($value === null) {
            return false;
        }

        if (is_array($value)) {
            // An array must have at least one meaningful key-value pair.
            if ($value === []) {
                return false;
            }
            // An array with a single numeric key and a very short string is unbound.
            $keys = array_keys($value);
            if ($keys === [0] && is_string($value[0]) && strlen(trim($value[0])) < 4) {
                return false;
            }
            return true;
        }

        $s = trim((string) $value);

        // A string fewer than 4 chars is too short to be evidence.
        if (strlen($s) < 4) {
            return false;
        }

        return true;
    }

    /**
     * Scan all proof fields for provider-unsafe content: raw prompts,
     * provider traces, conversation text, or secret-like payloads.
     */
    private function hasProviderUnsafePayload(array $record): bool
    {
        foreach (self::REQUIRED_RETIREMENT_PROOF_FIELDS as $field) {
            $value = $record[$field] ?? null;
            if ($value === null) {
                continue;
            }

            if (is_array($value)) {
                // Check array keys and values recursively for unsafe patterns.
                if ($this->arrayContainsUnsafeContent($value)) {
                    return true;
                }
            } elseif (is_string($value)) {
                if ($this->stringContainsUnsafePattern($value)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function arrayContainsUnsafeContent(array $data): bool
    {
        foreach ($data as $key => $val) {
            $keyStr = is_string($key) ? $key : '';
            if ($this->stringContainsUnsafePattern($keyStr)) {
                return true;
            }
            if (is_string($val) && $this->stringContainsUnsafePattern($val)) {
                return true;
            }
            if (is_array($val) && $this->arrayContainsUnsafeContent($val)) {
                return true;
            }
        }

        return false;
    }

    private function stringContainsUnsafePattern(string $s): bool
    {
        $lower = strtolower($s);
        foreach (self::PROVIDER_UNSAFE_PATTERNS as $pattern) {
            if (str_contains($lower, $pattern)) {
                return true;
            }
        }

        return false;
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

            $blockingReasons = [];
            $safeToRetire = false;

            if ($retireReasons !== []) {
                $evidenceKeysSupplied = array_filter(
                    self::EVIDENCE_GATE_FIELDS,
                    static fn (string $key): bool => array_key_exists($key, $raw),
                );

                if ($evidenceKeysSupplied === []) {
                    // Legacy path: no evidence gate ever supplied — preserve the bare verdict
                    // string exactly as before for existing callers.
                    $verdict = self::VERDICT_RETIRE_OR_CONVERT;
                } else {
                    $missingEvidence = [];
                    foreach (self::EVIDENCE_GATE_FIELDS as $field) {
                        if (! (bool) ($raw[$field] ?? false)) {
                            $missingEvidence[] = $field;
                        }
                    }

                    if ($missingEvidence === []) {
                        $verdict = self::VERDICT_SAFE_TO_RETIRE;
                        $safeToRetire = true;
                    } else {
                        $verdict = self::VERDICT_REVIEW_NEEDED;
                        $blockingReasons = $missingEvidence;
                    }
                }

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
                'safe_to_retire' => $safeToRetire,
                'blocking_reasons' => $blockingReasons,
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
