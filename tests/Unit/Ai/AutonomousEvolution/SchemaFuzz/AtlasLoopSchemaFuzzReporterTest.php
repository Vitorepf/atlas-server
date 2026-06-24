<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution\SchemaFuzz;

use App\Services\Ai\AutonomousEvolution\SchemaFuzz\AtlasLoopSchemaFuzzReporter;
use PHPUnit\Framework\TestCase;

final class AtlasLoopSchemaFuzzReporterTest extends TestCase
{
    public function test_honest_report_preserves_accept_and_reject_reason_verbatim(): void
    {
        $reporter = new AtlasLoopSchemaFuzzReporter;
        $rows = $reporter->report([
            [
                'payload_id' => 'payload-accept',
                'payload' => [
                    'receipt_id' => 'dr-1',
                    'decision_kind' => 'accept',
                    'occurred_at' => '2026-06-24T00:00:00Z',
                    'attempt_count' => 1,
                    'artifacts' => [],
                ],
            ],
            [
                'payload_id' => 'payload-reject',
                'payload' => [
                    'receipt_id' => 'dr-2',
                    'decision_kind' => 99,
                    'occurred_at' => '2026-06-24T00:00:00Z',
                    'attempt_count' => 1,
                    'artifacts' => [],
                ],
            ],
        ], [
            'decision_receipt' => [
                'engine_id' => 'validator-a',
                'version' => 'v-test',
                'validate' => static function (array $payload): array {
                    if (is_string($payload['decision_kind'] ?? null)) {
                        return ['outcome' => 'accept', 'reject_reason_code' => null];
                    }

                    return ['outcome' => 'reject', 'reject_reason_code' => 'type_decision_kind'];
                },
            ],
        ]);

        $this->assertSame(
            [
                [
                    'payload_id' => 'payload-accept',
                    'schema_name' => 'decision_receipt',
                    'outcome' => 'accept',
                    'reject_reason_code' => null,
                    'validator_engine_id' => 'validator-a',
                    'validator_version' => 'v-test',
                ],
                [
                    'payload_id' => 'payload-reject',
                    'schema_name' => 'decision_receipt',
                    'outcome' => 'reject',
                    'reject_reason_code' => 'type_decision_kind',
                    'validator_engine_id' => 'validator-a',
                    'validator_version' => 'v-test',
                ],
            ],
            $rows,
        );
    }

    public function test_report_has_no_aggregate_fields_and_keeps_one_row_per_payload_schema_pair(): void
    {
        $reporter = new AtlasLoopSchemaFuzzReporter;
        $rows = $reporter->report([
            [
                'payload_id' => 'payload-1',
                'payload' => [
                    'task_id' => 'task-1',
                    'objective' => 'Reduce complexity',
                    'priority' => 100,
                    'allowed_files' => ['app/Services/Ai/Foo.php'],
                ],
            ],
        ], [
            'task_envelope' => [
                'engine_id' => 'validator-task',
                'version' => 'v1',
                'validate' => static fn (array $payload): array => ['outcome' => 'accept', 'reject_reason_code' => null],
            ],
            'task_envelope_alt' => [
                'engine_id' => 'validator-task-alt',
                'version' => 'v2',
                'validate' => static fn (array $payload): array => ['outcome' => 'reject', 'reject_reason_code' => 'shape_contract'],
            ],
        ]);

        $this->assertCount(2, $rows);
        $this->assertSame(
            [
                ['payload-1', 'task_envelope'],
                ['payload-1', 'task_envelope_alt'],
            ],
            array_map(
                static fn (array $row): array => [$row['payload_id'], $row['schema_name']],
                $rows,
            ),
        );

        foreach ($rows as $row) {
            $keys = array_keys($row);
            foreach ($keys as $key) {
                $this->assertSame(0, preg_match('/score|quality|grade|good|bad/i', $key));
            }
        }
    }

    public function test_same_payload_validated_by_two_schemas_emits_two_independent_rows(): void
    {
        $reporter = new AtlasLoopSchemaFuzzReporter;
        $rows = $reporter->report([
            [
                'payload_id' => 'payload-shared',
                'payload' => ['attempt_id' => 'a-1', 'status' => 'passed', 'duration_ms' => 10, 'receipts' => []],
            ],
        ], [
            'attempt_ledger_record' => [
                'engine_id' => 'validator-attempt',
                'version' => 'v1',
                'validate' => static fn (array $payload): array => ['outcome' => 'accept', 'reject_reason_code' => null],
            ],
            'decision_receipt' => [
                'engine_id' => 'validator-receipt',
                'version' => 'v3',
                'validate' => static fn (array $payload): array => ['outcome' => 'reject', 'reject_reason_code' => 'missing_required_receipt_id'],
            ],
        ]);

        $this->assertSame(
            [
                [
                    'payload_id' => 'payload-shared',
                    'schema_name' => 'attempt_ledger_record',
                    'outcome' => 'accept',
                    'reject_reason_code' => null,
                    'validator_engine_id' => 'validator-attempt',
                    'validator_version' => 'v1',
                ],
                [
                    'payload_id' => 'payload-shared',
                    'schema_name' => 'decision_receipt',
                    'outcome' => 'reject',
                    'reject_reason_code' => 'missing_required_receipt_id',
                    'validator_engine_id' => 'validator-receipt',
                    'validator_version' => 'v3',
                ],
            ],
            $rows,
        );
    }
}
