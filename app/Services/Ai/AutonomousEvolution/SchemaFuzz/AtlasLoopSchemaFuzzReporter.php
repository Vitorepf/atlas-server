<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\SchemaFuzz;

use InvalidArgumentException;

final class AtlasLoopSchemaFuzzReporter
{
    /**
     * @param  list<array{
     *     payload_id:string,
     *     payload:array<string,mixed>
     * }>  $payloads
     * @param  array<string, array{
     *     engine_id:string,
     *     version:string,
     *     validate:callable(array<string,mixed>): array{outcome:string,reject_reason_code:?string}
     * }> | null  $validators
     * @return list<array{
     *     payload_id:string,
     *     schema_name:string,
     *     outcome:string,
     *     reject_reason_code:?string,
     *     validator_engine_id:string,
     *     validator_version:string
     * }>
     */
    public function report(array $payloads, ?array $validators = null): array
    {
        $validators = $validators ?? $this->defaultValidators();
        ksort($validators, SORT_STRING);

        $rows = [];
        foreach ($payloads as $payloadEnvelope) {
            $payloadId = (string) ($payloadEnvelope['payload_id'] ?? '');
            $payload = $payloadEnvelope['payload'] ?? null;
            if ($payloadId === '' || ! is_array($payload)) {
                throw new InvalidArgumentException('Each payload envelope must contain payload_id and payload array.');
            }

            foreach ($validators as $schemaName => $validator) {
                $result = ($validator['validate'])($payload);
                $outcome = (string) ($result['outcome'] ?? '');
                $rejectReasonCode = $result['reject_reason_code'] ?? null;
                if (! in_array($outcome, ['accept', 'reject'], true)) {
                    throw new InvalidArgumentException(sprintf('Validator [%s] returned invalid outcome [%s].', $schemaName, $outcome));
                }

                $rows[] = [
                    'payload_id' => $payloadId,
                    'schema_name' => $schemaName,
                    'outcome' => $outcome,
                    'reject_reason_code' => $rejectReasonCode === null ? null : (string) $rejectReasonCode,
                    'validator_engine_id' => (string) $validator['engine_id'],
                    'validator_version' => (string) $validator['version'],
                ];
            }
        }

        return $rows;
    }

    /**
     * @return array<string, array{
     *     engine_id:string,
     *     version:string,
     *     validate:callable(array<string,mixed>): array{outcome:string,reject_reason_code:?string}
     * }>
     */
    private function defaultValidators(): array
    {
        return [
            'attempt_ledger_record' => [
                'engine_id' => 'atlas.array_shape',
                'version' => 'attempt_ledger_record.v1',
                'validate' => fn (array $payload): array => $this->validateAttemptLedgerRecord($payload),
            ],
            'decision_receipt' => [
                'engine_id' => 'atlas.array_shape',
                'version' => 'decision_receipt.v1',
                'validate' => fn (array $payload): array => $this->validateDecisionReceipt($payload),
            ],
            'task_envelope' => [
                'engine_id' => 'atlas.array_shape',
                'version' => 'task_envelope.v1',
                'validate' => fn (array $payload): array => $this->validateTaskEnvelope($payload),
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array{outcome:string,reject_reason_code:?string}
     */
    private function validateDecisionReceipt(array $payload): array
    {
        foreach (['receipt_id', 'decision_kind', 'occurred_at'] as $required) {
            if (! array_key_exists($required, $payload)) {
                return $this->reject('missing_required_'.$required);
            }
        }
        if (! is_string($payload['decision_kind']) || $payload['decision_kind'] === '') {
            return $this->reject('type_decision_kind');
        }
        if (! is_int($payload['attempt_count'] ?? null)) {
            return $this->reject('type_attempt_count');
        }
        if (! is_array($payload['artifacts'] ?? null)) {
            return $this->reject('type_artifacts');
        }

        return $this->accept();
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array{outcome:string,reject_reason_code:?string}
     */
    private function validateTaskEnvelope(array $payload): array
    {
        foreach (['task_id', 'objective', 'priority'] as $required) {
            if (! array_key_exists($required, $payload)) {
                return $this->reject('missing_required_'.$required);
            }
        }
        if (! is_string($payload['objective']) || $payload['objective'] === '') {
            return $this->reject('type_objective');
        }
        if (! is_int($payload['priority'])) {
            return $this->reject('type_priority');
        }
        if (! is_array($payload['allowed_files'] ?? null)) {
            return $this->reject('type_allowed_files');
        }

        return $this->accept();
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array{outcome:string,reject_reason_code:?string}
     */
    private function validateAttemptLedgerRecord(array $payload): array
    {
        foreach (['attempt_id', 'status', 'duration_ms'] as $required) {
            if (! array_key_exists($required, $payload)) {
                return $this->reject('missing_required_'.$required);
            }
        }
        if (! is_string($payload['status']) || $payload['status'] === '') {
            return $this->reject('type_status');
        }
        if (! is_int($payload['duration_ms'])) {
            return $this->reject('type_duration_ms');
        }
        if (! is_array($payload['receipts'] ?? null)) {
            return $this->reject('type_receipts');
        }

        return $this->accept();
    }

    /**
     * @return array{outcome:string,reject_reason_code:?string}
     */
    private function accept(): array
    {
        return [
            'outcome' => 'accept',
            'reject_reason_code' => null,
        ];
    }

    /**
     * @return array{outcome:string,reject_reason_code:?string}
     */
    private function reject(string $reasonCode): array
    {
        return [
            'outcome' => 'reject',
            'reject_reason_code' => $reasonCode,
        ];
    }
}
