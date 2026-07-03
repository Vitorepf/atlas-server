<?php

namespace App\Services\Ai\Rivals\Support;

/**
 * Validação fail-closed dos payloads Rivals 2.0: validate() devolve a lista de
 * violações (vazia = válido). Campo ausente nunca é inventado — ausência é
 * violação ou {present:false, reason_missing} explícito no evidence pack.
 */
class SchemaContract
{
    public const RUN_PLAN = 'atlas.rivals2.run_plan.v1';
    public const RUN_RECEIPT = 'atlas.rivals2.run_receipt.v1';
    public const EVIDENCE_PACK = 'atlas.rivals2.evidence_pack.v1';
    public const LEDGER_ENTRY = 'atlas.rivals2.ledger_entry.v1';
    public const REPORT = 'atlas.rivals2.report.v1';

    private const REQUIRED = [
        self::RUN_PLAN => [
            'schema_version', 'run_id', 'suite_id', 'case_ids', 'arms',
            'repetitions', 'budget', 'seed', 'environment', 'created_at',
        ],
        self::RUN_RECEIPT => [
            'schema_version', 'run_id', 'case_id', 'task_type', 'arm_id', 'repetition',
            'status', 'wall_ms', 'tokens_in', 'tokens_out', 'cost_usd',
            'artifacts', 'started_at', 'finished_at',
        ],
        self::EVIDENCE_PACK => [
            'schema_version', 'run_id', 'plan_hash', 'receipts_hash',
            'artifacts', 'workspace_fingerprint', 'built_at',
        ],
        self::LEDGER_ENTRY => [
            'schema_version', 'entry_id', 'prev_hash', 'run_id', 'verdict', 'appended_at',
        ],
        self::REPORT => [
            'schema_version', 'run_id', 'rows', 'claim_allowed', 'claim_blockers', 'claim_scope', 'built_at',
        ],
    ];

    private const RECEIPT_STATUSES = ['success', 'failure', 'error', 'timeout'];

    /** @return list<string> violations; empty = valid */
    public static function validate(array $payload, string $schemaId): array
    {
        $required = self::REQUIRED[$schemaId] ?? null;
        if ($required === null) {
            return ["unknown_schema:{$schemaId}"];
        }

        $violations = [];
        if (($payload['schema_version'] ?? null) !== $schemaId) {
            $violations[] = "schema_version_mismatch:expected={$schemaId}";
        }
        foreach ($required as $field) {
            if (! array_key_exists($field, $payload)) {
                $violations[] = "missing_field:{$field}";
            }
        }

        if ($schemaId === self::RUN_RECEIPT
            && array_key_exists('status', $payload)
            && ! in_array($payload['status'], self::RECEIPT_STATUSES, true)) {
            $violations[] = 'invalid_status:'.$payload['status'];
        }
        if ($schemaId === self::RUN_PLAN && array_key_exists('repetitions', $payload)
            && (! is_int($payload['repetitions']) || $payload['repetitions'] < 1)) {
            $violations[] = 'invalid_repetitions';
        }

        return $violations;
    }
}
