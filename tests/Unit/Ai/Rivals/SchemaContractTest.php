<?php

namespace Tests\Unit\Ai\Rivals;

use App\Services\Ai\Rivals\Support\SchemaContract;
use PHPUnit\Framework\TestCase;

class SchemaContractTest extends TestCase
{
    public function test_unknown_schema_is_a_violation(): void
    {
        $this->assertSame(['unknown_schema:nope'], SchemaContract::validate([], 'nope'));
    }

    public function test_missing_fields_and_version_mismatch_are_reported(): void
    {
        $violations = SchemaContract::validate(['schema_version' => 'wrong'], SchemaContract::RUN_PLAN);
        $this->assertContains('schema_version_mismatch:expected='.SchemaContract::RUN_PLAN, $violations);
        $this->assertContains('missing_field:run_id', $violations);
    }

    public function test_receipt_status_is_constrained(): void
    {
        $payload = array_fill_keys([
            'run_id', 'case_id', 'task_type', 'arm_id', 'repetition', 'wall_ms',
            'tokens_in', 'tokens_out', 'cost_usd', 'artifacts', 'started_at', 'finished_at',
        ], 'x');
        $payload['schema_version'] = SchemaContract::RUN_RECEIPT;
        $payload['status'] = 'won_bigly'; // score sintético não passa

        $this->assertContains('invalid_status:won_bigly', SchemaContract::validate($payload, SchemaContract::RUN_RECEIPT));
    }

    public function test_failed_receipt_requires_a_readable_failure_reason(): void
    {
        $payload = array_fill_keys([
            'run_id', 'case_id', 'task_type', 'arm_id', 'repetition', 'wall_ms',
            'tokens_in', 'tokens_out', 'cost_usd', 'artifacts', 'started_at', 'finished_at',
            'claim_tier', 'harness_only', 'failure_class', 'field_presence',
        ], 'x');
        $payload['schema_version'] = SchemaContract::RUN_RECEIPT;
        $payload['status'] = 'error';
        $payload['repetition'] = 1;
        $payload['wall_ms'] = 0;
        $payload['tokens_in'] = 0;
        $payload['tokens_out'] = 0;
        $payload['cost_usd'] = 0.0;
        $payload['artifacts'] = [];
        $payload['claim_tier'] = 'production';
        $payload['harness_only'] = false;
        $payload['failure_class'] = 'environment_failure';
        $payload['field_presence'] = [];

        $this->assertContains(
            'missing_field:failure_reason',
            SchemaContract::validate($payload, SchemaContract::RUN_RECEIPT),
        );

        $payload['failure_reason'] = '   ';
        $this->assertContains(
            'invalid_failure_reason',
            SchemaContract::validate($payload, SchemaContract::RUN_RECEIPT),
        );
    }
}
