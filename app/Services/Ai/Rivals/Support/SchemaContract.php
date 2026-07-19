<?php

namespace App\Services\Ai\Rivals\Support;

use App\Services\Ai\Rivals\Core\ClaimTier;
use App\Services\Ai\Rivals\Core\FailureClass;

/**
 * Validação fail-closed dos payloads Rivals 2.0: validate() devolve a lista de
 * violações (vazia = válido). Campo ausente nunca é inventado — ausência é
 * violação ou {present:false, reason_missing} explícito no evidence pack.
 */
class SchemaContract
{
    public const RUN_PLAN_V1 = 'atlas.rivals2.run_plan.v1';

    public const RUN_PLAN = 'atlas.rivals2.run_plan.v2';

    public const RUN_RECEIPT_V1 = 'atlas.rivals2.run_receipt.v1';

    public const RUN_RECEIPT_V2 = 'atlas.rivals2.run_receipt.v2';

    public const RUN_RECEIPT = 'atlas.rivals2.run_receipt.v3';

    public const EVIDENCE_PACK_V1 = 'atlas.rivals2.evidence_pack.v1';

    public const EVIDENCE_PACK = 'atlas.rivals2.evidence_pack.v2';

    public const LEDGER_ENTRY_V1 = 'atlas.rivals2.ledger_entry.v1';

    public const LEDGER_ENTRY = 'atlas.rivals2.ledger_entry.v2';

    public const ADJUDICATION = 'atlas.rivals2.adjudication.v2';

    public const REPORT_V1 = 'atlas.rivals2.report.v1';

    public const REPORT = 'atlas.rivals2.report.v2';

    public const ENTERPRISE_REPORT = 'atlas.rivals2.enterprise_report.v1';

    private const REQUIRED = [
        self::RUN_PLAN_V1 => [
            'schema_version', 'run_id', 'suite_id', 'case_ids', 'arms',
            'repetitions', 'budget', 'seed', 'environment', 'created_at',
        ],
        self::RUN_PLAN => [
            'schema_version', 'run_id', 'suite_id', 'case_ids', 'arms',
            'repetitions', 'budget', 'seed', 'environment', 'claim_tier',
            'preregistration_hash', 'created_at',
        ],
        self::RUN_RECEIPT_V1 => [
            'schema_version', 'run_id', 'case_id', 'task_type', 'arm_id', 'repetition',
            'status', 'wall_ms', 'tokens_in', 'tokens_out', 'cost_usd',
            'artifacts', 'started_at', 'finished_at',
        ],
        self::RUN_RECEIPT_V2 => [
            'schema_version', 'run_id', 'case_id', 'task_type', 'arm_id', 'repetition',
            'status', 'wall_ms', 'tokens_in', 'tokens_out', 'cost_usd',
            'artifacts', 'started_at', 'finished_at', 'claim_tier', 'harness_only',
            'failure_class', 'field_presence',
        ],
        self::RUN_RECEIPT => [
            'schema_version', 'run_id', 'case_id', 'task_type', 'arm_id', 'repetition',
            'status', 'wall_ms', 'tokens_in', 'tokens_out', 'cost_usd',
            'artifacts', 'started_at', 'finished_at', 'claim_tier', 'harness_only',
            'failure_class', 'failure_reason', 'field_presence',
        ],
        self::EVIDENCE_PACK_V1 => [
            'schema_version', 'run_id', 'plan_hash', 'receipts_hash',
            'artifacts', 'workspace_fingerprint', 'built_at',
        ],
        self::EVIDENCE_PACK => [
            'schema_version', 'run_id', 'plan_hash', 'preregistration_hash', 'manifest_hash',
            'native_receipts', 'raw_results', 'receipts_hash', 'state_hash',
            'events_hash', 'smoke_receipt_hash', 'adapter', 'artifacts',
            'orphan_artifacts', 'workspace_fingerprint', 'evidence_hash', 'built_at',
        ],
        self::LEDGER_ENTRY_V1 => [
            'schema_version', 'entry_id', 'prev_hash', 'run_id', 'verdict', 'appended_at',
        ],
        self::LEDGER_ENTRY => [
            'schema_version', 'entry_id', 'logical_id', 'entry_type', 'prev_hash',
            'run_id', 'revision', 'verdict', 'verdict_hash', 'evidence_pack_hash',
            'adjudication_hash', 'report_hash', 'supersedes_entry_id', 'appended_at',
        ],
        self::ADJUDICATION => [
            'schema_version', 'run_id', 'verdict', 'pipeline_valid', 'pipeline_blockers',
            'claim_tier', 'internal_claim_allowed', 'internal_claim_blockers',
            'public_claim_allowed', 'public_claim_blockers', 'not_ready_reasons',
            'claim_allowed', 'claim_blockers', 'claim_scope', 'adjudicated_at',
        ],
        self::REPORT_V1 => [
            'schema_version', 'run_id', 'rows', 'claim_allowed', 'claim_blockers', 'claim_scope', 'built_at',
        ],
        self::REPORT => [
            'schema_version', 'run_id', 'rows', 'pipeline_valid', 'claim_tier',
            'internal_claim_allowed', 'public_claim_allowed', 'not_ready_reasons',
            'claim_allowed', 'claim_blockers', 'claim_scope', 'statistical_analysis',
            'missing_data_policy', 'report_hash', 'built_at',
        ],
        self::ENTERPRISE_REPORT => [
            'schema_version', 'built_at', 'report_hash', 'claim_allowed', 'claim_blockers',
            'executive_summary', 'delivery_inventory', 'suite_rows', 'model_dissections',
            'model_matrix', 'atlas_uplift', 'gaps',
            'included_run_ids', 'excluded_run_ids',
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

        if (in_array($schemaId, [self::RUN_RECEIPT_V1, self::RUN_RECEIPT_V2, self::RUN_RECEIPT], true)
            && array_key_exists('status', $payload)
            && ! in_array($payload['status'], self::RECEIPT_STATUSES, true)) {
            $violations[] = 'invalid_status:'.$payload['status'];
        }
        if (in_array($schemaId, [self::RUN_PLAN_V1, self::RUN_PLAN], true)
            && array_key_exists('repetitions', $payload)
            && (! is_int($payload['repetitions']) || $payload['repetitions'] < 1)) {
            $violations[] = 'invalid_repetitions';
        }
        if (in_array($schemaId, [self::RUN_PLAN_V1, self::RUN_PLAN], true)) {
            $violations = array_merge($violations, self::validatePlan($payload, $schemaId));
        }
        if (in_array($schemaId, [self::RUN_RECEIPT_V1, self::RUN_RECEIPT_V2, self::RUN_RECEIPT], true)) {
            $violations = array_merge($violations, self::validateReceipt($payload, $schemaId));
        }
        if ($schemaId === self::ADJUDICATION) {
            foreach (['pipeline_valid', 'internal_claim_allowed', 'public_claim_allowed', 'claim_allowed'] as $field) {
                if (array_key_exists($field, $payload) && ! is_bool($payload[$field])) {
                    $violations[] = "invalid_boolean:{$field}";
                }
            }
        }
        if ($schemaId === self::ENTERPRISE_REPORT) {
            $violations = array_merge($violations, self::validateEnterpriseReport($payload));
        }

        return array_values(array_unique($violations));
    }

    /** @return list<string> */
    private static function validateEnterpriseReport(array $payload): array
    {
        $violations = [];
        if (array_key_exists('claim_allowed', $payload) && $payload['claim_allowed'] !== false) {
            $violations[] = 'enterprise_claim_allowed_must_be_false';
        }
        if (array_key_exists('suite_rows', $payload)) {
            if (! is_array($payload['suite_rows'])) {
                $violations[] = 'invalid_array:suite_rows';
            } else {
                $count = count($payload['suite_rows']);
                $expected = count((array) config('atlas_rivals.benchmarks.repos', []));
                if ($expected <= 0) {
                    $expected = 10;
                }
                if ($count !== $expected) {
                    $violations[] = "enterprise_suite_rows_count:{$count}";
                }
            }
        }
        foreach (['executive_summary', 'model_matrix', 'atlas_uplift', 'gaps', 'claim_blockers', 'included_run_ids', 'excluded_run_ids', 'delivery_inventory', 'model_dissections'] as $field) {
            if (array_key_exists($field, $payload) && ! is_array($payload[$field])) {
                $violations[] = "invalid_array:{$field}";
            }
        }
        if (array_key_exists('delivery_inventory', $payload) && is_array($payload['delivery_inventory'])) {
            $expected = count((array) config('atlas_rivals.benchmarks.repos', []));
            if ($expected <= 0) {
                $expected = 10;
            }
            if (count($payload['delivery_inventory']) !== $expected) {
                $violations[] = 'enterprise_delivery_inventory_count:'.count($payload['delivery_inventory']);
            }
        }

        return $violations;
    }

    /** @return list<string> */
    private static function validatePlan(array $payload, string $schemaId): array
    {
        $violations = [];
        foreach (['run_id', 'suite_id', 'created_at'] as $field) {
            if (array_key_exists($field, $payload) && ! is_string($payload[$field])) {
                $violations[] = "invalid_string:{$field}";
            }
        }
        foreach (['case_ids', 'arms', 'budget', 'environment'] as $field) {
            if (array_key_exists($field, $payload) && ! is_array($payload[$field])) {
                $violations[] = "invalid_array:{$field}";
            }
        }
        if (array_key_exists('seed', $payload) && ! is_int($payload['seed'])) {
            $violations[] = 'invalid_integer:seed';
        }
        foreach ((array) ($payload['case_ids'] ?? []) as $caseId) {
            if (! is_string($caseId)
                || preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,127}$/', $caseId) !== 1) {
                $violations[] = 'invalid_case_id:'.(string) $caseId;
            }
        }
        if ($schemaId === self::RUN_PLAN
            && array_key_exists('claim_tier', $payload)
            && ! in_array($payload['claim_tier'], ClaimTier::all(), true)) {
            $violations[] = 'invalid_claim_tier:'.(string) $payload['claim_tier'];
        }

        return $violations;
    }

    /** @return list<string> */
    private static function validateReceipt(array $payload, string $schemaId): array
    {
        $violations = [];
        foreach (['run_id', 'case_id', 'task_type', 'arm_id'] as $field) {
            if (array_key_exists($field, $payload) && ! is_string($payload[$field])) {
                $violations[] = "invalid_string:{$field}";
            }
        }
        if (array_key_exists('repetition', $payload)
            && (! is_int($payload['repetition']) || $payload['repetition'] < 1)) {
            $violations[] = 'invalid_repetition';
        }
        foreach (['wall_ms', 'tokens_in', 'tokens_out', 'cost_usd'] as $field) {
            if (array_key_exists($field, $payload) && ! is_numeric($payload[$field])) {
                $violations[] = "invalid_numeric:{$field}";
            }
        }
        if (array_key_exists('artifacts', $payload) && is_array($payload['artifacts'])) {
            foreach ($payload['artifacts'] as $index => $artifact) {
                if (! is_array($artifact)
                    || ! is_string($artifact['path'] ?? null)
                    || ! preg_match('/^[a-f0-9]{64}$/', (string) ($artifact['sha256'] ?? ''))) {
                    $violations[] = "invalid_artifact:{$index}";
                } elseif (str_starts_with($artifact['path'], '/')
                    || in_array('..', explode('/', str_replace('\\', '/', $artifact['path'])), true)) {
                    $violations[] = "unsafe_artifact_path:{$index}";
                }
            }
        } elseif (array_key_exists('artifacts', $payload)) {
            $violations[] = 'invalid_array:artifacts';
        }
        foreach (['started_at', 'finished_at'] as $field) {
            if (array_key_exists($field, $payload)
                && $payload[$field] !== null
                && ! is_string($payload[$field])) {
                $violations[] = "invalid_timestamp:{$field}";
            }
        }
        if (is_string($payload['started_at'] ?? null) && is_string($payload['finished_at'] ?? null)) {
            $started = strtotime($payload['started_at']);
            $finished = strtotime($payload['finished_at']);
            if ($started !== false && $finished !== false && $finished < $started) {
                $violations[] = 'invalid_timestamp_order';
            }
        }
        if (in_array($schemaId, [self::RUN_RECEIPT_V2, self::RUN_RECEIPT], true)) {
            if (array_key_exists('claim_tier', $payload)
                && ! in_array($payload['claim_tier'], ClaimTier::all(), true)) {
                $violations[] = 'invalid_claim_tier:'.(string) $payload['claim_tier'];
            }
            if (array_key_exists('harness_only', $payload) && ! is_bool($payload['harness_only'])) {
                $violations[] = 'invalid_boolean:harness_only';
            }
            if (array_key_exists('failure_class', $payload)
                && $payload['failure_class'] !== null
                && ! is_string($payload['failure_class'])) {
                $violations[] = 'invalid_failure_class';
            } elseif (($payload['failure_class'] ?? null) !== null
                && ! in_array($payload['failure_class'], FailureClass::all(), true)) {
                $violations[] = 'unknown_failure_class:'.$payload['failure_class'];
            }
            if (array_key_exists('field_presence', $payload) && ! is_array($payload['field_presence'])) {
                $violations[] = 'invalid_array:field_presence';
            }
        }
        if ($schemaId === self::RUN_RECEIPT && array_key_exists('failure_reason', $payload)) {
            $reason = $payload['failure_reason'];
            $isSuccess = ($payload['status'] ?? null) === 'success';
            if ($isSuccess && $reason !== null) {
                $violations[] = 'success_failure_reason_must_be_null';
            } elseif (! $isSuccess && (! is_string($reason) || trim($reason) === '')) {
                $violations[] = 'invalid_failure_reason';
            }
        }

        return $violations;
    }
}
