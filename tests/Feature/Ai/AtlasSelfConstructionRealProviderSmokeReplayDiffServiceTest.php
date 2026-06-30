<?php

namespace Tests\Feature\Ai;

use App\Services\Ai\SelfConstruction\AtlasSelfConstructionRealProviderSmokeReplayDiffService;
use Tests\TestCase;

final class AtlasSelfConstructionRealProviderSmokeReplayDiffServiceTest extends TestCase
{
    private function service(): AtlasSelfConstructionRealProviderSmokeReplayDiffService
    {
        return new AtlasSelfConstructionRealProviderSmokeReplayDiffService;
    }

    /** @param array<string, mixed> $overrides */
    private function validSmoke(array $overrides = []): array
    {
        $payload = array_merge([
            'kind' => 'real_provider_packet_claim_to_completion',
            'status' => 'passed',
            'provider_run_id' => 'provider-run-001',
            'task_packet_id' => 'task-packet-001',
            'smoke_hash' => str_repeat('1', 64),
            'operator_approval_receipt_hash' => str_repeat('2', 64),
            'evidence_ledger_hash' => str_repeat('3', 64),
            'work_product_manifest_hash' => str_repeat('4', 64),
            'cost_event_hash' => str_repeat('5', 64),
            'continuation_summary_hash' => str_repeat('6', 64),
            'provider_response_hash' => str_repeat('7', 64),
            'provider_call_observed' => true,
            'token_spend_observed' => true,
            'claim_to_completion_observed' => true,
            'work_product_collected' => true,
            'provider_called_by_atlas' => false,
            'token_spent_by_atlas' => false,
            'dispatch_allowed' => false,
            'adapter_execution_allowed' => false,
            'self_programming_allowed' => false,
        ], $overrides);

        return $payload;
    }

    public function test_unchanged_valid_payload_is_green_with_no_blockers(): void
    {
        $smoke = $this->validSmoke();

        $result = $this->service()->compare($smoke, $smoke);

        $this->assertTrue($result['replay_diff_green']);
        $this->assertSame('passed', $result['status']);
        $this->assertSame(0, $result['mutation_count']);
        $this->assertSame([], $result['mutations']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) $result['replay_diff_hash']);
    }

    public function test_replay_diff_hash_is_stable_and_excludes_compared_at(): void
    {
        $smoke = $this->validSmoke();

        $first = $this->service()->compare($smoke, $smoke);
        $second = $this->service()->compare($smoke, $smoke);

        $this->assertSame($first['replay_diff_hash'], $second['replay_diff_hash']);
        $this->assertNotSame($first['compared_at'] ?? null, null);
    }

    public function test_evidence_field_map_covers_every_protected_field(): void
    {
        $smoke = $this->validSmoke();

        $result = $this->service()->compare($smoke, $smoke);
        $fields = array_column($result['evidence_field_map'], 'field');

        foreach ([
            'kind', 'status', 'provider_run_id', 'task_packet_id',
            'smoke_hash', 'operator_approval_receipt_hash', 'evidence_ledger_hash',
            'work_product_manifest_hash', 'cost_event_hash', 'continuation_summary_hash',
            'provider_response_hash', 'provider_call_observed', 'token_spend_observed',
            'claim_to_completion_observed', 'work_product_collected',
        ] as $expected) {
            $this->assertContains($expected, $fields, "missing evidence_field_map row: {$expected}");
        }
    }

    public function test_evidence_field_map_row_reports_presence_and_change(): void
    {
        $before = $this->validSmoke();
        $after = $before;
        $after['provider_run_id'] = 'provider-run-002';

        $result = $this->service()->compare($before, $after);
        $row = $this->rowFor($result, 'provider_run_id');

        $this->assertTrue($row['before_present']);
        $this->assertTrue($row['after_present']);
        $this->assertTrue($row['changed']);
        $this->assertSame('protected_real_provider_smoke_field_mutated', $row['blocker']);
        $this->assertSame('blocked', $result['status']);
    }

    public function test_missing_hash_field_after_replay_is_blocked(): void
    {
        $before = $this->validSmoke();
        unset($before['smoke_hash']);
        $after = $before;

        $result = $this->service()->compare($before, $after);
        $row = $this->rowFor($result, 'smoke_hash');

        $this->assertFalse($row['before_present']);
        $this->assertFalse($row['after_present']);
        $this->assertFalse($row['changed']);
        $this->assertSame('protected_real_provider_smoke_field_missing', $row['blocker']);
        $this->assertSame('blocked', $result['status']);
    }

    public function test_malformed_hash_field_is_blocked_even_when_before_and_after_match(): void
    {
        $before = $this->validSmoke(['smoke_hash' => 'not-a-real-hash']);
        $after = $before;

        $result = $this->service()->compare($before, $after);
        $row = $this->rowFor($result, 'smoke_hash');

        $this->assertFalse($row['changed']);
        $this->assertSame('protected_real_provider_smoke_field_malformed', $row['blocker']);
        $this->assertSame('blocked', $result['status']);
    }

    public function test_forbidden_runtime_flag_is_blocked(): void
    {
        $before = $this->validSmoke();
        $after = $before;
        $after['dispatch_allowed'] = true;

        $result = $this->service()->compare($before, $after);

        $this->assertSame('blocked', $result['status']);
        $this->assertContains('forbidden_runtime_flag_true_after_replay', array_column($result['mutations'], 'code'));
    }

    private function rowFor(array $result, string $field): array
    {
        foreach ($result['evidence_field_map'] as $row) {
            if ($row['field'] === $field) {
                return $row;
            }
        }

        $this->fail("evidence_field_map row not found for field: {$field}");
    }
}
