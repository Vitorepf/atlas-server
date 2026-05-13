<?php

namespace App\Services\Ai\Programming;

class ProgrammingStageReceiptValidator
{
    private const STAGE_ORDER = ['plan', 'review', 'patch', 'test', 'repair'];

    /**
     * @param  array<string,mixed>  $receipt
     * @return array<string,mixed>
     */
    public function validate(array $receipt): array
    {
        $errors = [];
        $stage = (string) ($receipt['stage'] ?? '');
        $planId = (string) ($receipt['plan_id'] ?? '');
        $attempt = (int) ($receipt['attempt'] ?? 0);
        $expectedId = $planId !== '' && $stage !== '' && $attempt > 0
            ? hash('sha256', $planId.'|'.$stage.'|'.$attempt)
            : null;

        if (($receipt['schema_version'] ?? null) !== 'atlas.programming.stage_receipt.v1') {
            $errors[] = 'invalid_schema_version';
        }
        if ($planId === '') {
            $errors[] = 'missing_plan_id';
        }
        if (! in_array($stage, self::STAGE_ORDER, true)) {
            $errors[] = 'invalid_stage';
        }
        if ($attempt < 1) {
            $errors[] = 'invalid_attempt';
        }
        if ($expectedId !== null && ($receipt['receipt_id'] ?? null) !== $expectedId) {
            $errors[] = 'receipt_id_hash_mismatch';
        }
        foreach (['input_hash', 'output_hash'] as $field) {
            if (! is_string($receipt[$field] ?? null) || ! preg_match('/\A[a-f0-9]{64}\z/', (string) $receipt[$field])) {
                $errors[] = 'invalid_'.$field;
            }
        }

        return [
            'schema_version' => 'atlas.programming.stage_receipt_validation.v1',
            'valid' => $errors === [],
            'errors' => $errors,
            'expected_receipt_id' => $expectedId,
        ];
    }

    /**
     * @param  array<int,array<string,mixed>>  $receipts
     * @return array<string,mixed>
     */
    public function validateTimeline(array $receipts): array
    {
        $errors = [];
        $lastIndex = -1;
        foreach ($receipts as $receipt) {
            $validation = $this->validate($receipt);
            if (! $validation['valid']) {
                $errors[] = 'invalid_receipt:'.($receipt['stage'] ?? 'unknown');
            }
            $index = array_search((string) ($receipt['stage'] ?? ''), self::STAGE_ORDER, true);
            if ($index !== false && $index < $lastIndex) {
                $errors[] = 'stage_order_regression';
            }
            if ($index !== false) {
                $lastIndex = max($lastIndex, $index);
            }
        }

        return [
            'schema_version' => 'atlas.programming.stage_receipt_timeline_validation.v1',
            'valid' => $errors === [],
            'errors' => array_values(array_unique($errors)),
        ];
    }
}
