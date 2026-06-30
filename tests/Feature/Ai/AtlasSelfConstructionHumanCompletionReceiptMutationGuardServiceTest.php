<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Services\Ai\SelfConstruction\AtlasSelfConstructionCompletionEvidenceHashService;
use App\Services\Ai\SelfConstruction\AtlasSelfConstructionHumanCompletionReceiptMutationGuardService;
use Tests\TestCase;

final class AtlasSelfConstructionHumanCompletionReceiptMutationGuardServiceTest extends TestCase
{
    public function test_unchanged_receipt_reports_passed_with_stable_hash(): void
    {
        $receipt = $this->validReceipt();
        $guard = new AtlasSelfConstructionHumanCompletionReceiptMutationGuardService;

        $first = $guard->compare($receipt, $receipt);
        $second = $guard->compare($receipt, $receipt);

        $this->assertTrue($first['guard_passed']);
        $this->assertTrue($first['receipt_reuse_allowed']);
        $this->assertSame(0, $first['mutation_count']);
        $this->assertSame(0, $first['mutated_field_count']);
        $this->assertSame([], $first['mutated_fields']);
        $this->assertSame($first['protected_field_count'], count($first['unchanged_protected_fields']));
        $this->assertSame($first['mutation_guard_hash'], $second['mutation_guard_hash']);
    }

    public function test_mutated_field_changes_reuse_allowed_and_lists_mutation(): void
    {
        $before = $this->validReceipt();
        $after = $before;
        $after['signed_by'] = 'Other Operator';

        $result = (new AtlasSelfConstructionHumanCompletionReceiptMutationGuardService)->compare($before, $after);

        $this->assertFalse($result['guard_passed']);
        $this->assertFalse($result['receipt_reuse_allowed']);
        $this->assertSame(1, $result['mutated_field_count']);
        $this->assertSame('signed_by', $result['mutated_fields'][0]['field']);
        $this->assertTrue($result['mutated_fields'][0]['present_before']);
        $this->assertTrue($result['mutated_fields'][0]['present_after']);
        $this->assertNotContains('signed_by', $result['unchanged_protected_fields']);
    }

    public function test_missing_after_protected_field_is_treated_as_mutation_with_presence_diagnostics(): void
    {
        $before = $this->validReceipt();
        $after = $before;
        unset($after['runtime_gap_matrix_hash']);

        $result = (new AtlasSelfConstructionHumanCompletionReceiptMutationGuardService)->compare($before, $after);

        $this->assertFalse($result['receipt_reuse_allowed']);
        $row = collect($result['mutated_fields'])->firstWhere('field', 'runtime_gap_matrix_hash');
        $this->assertNotNull($row);
        $this->assertTrue($row['present_before']);
        $this->assertFalse($row['present_after']);
    }

    public function test_tamper_map_does_not_leak_unrelated_payload_fields(): void
    {
        $before = $this->validReceipt();
        $after = $before;
        $after['unrelated_decoration_field'] = 'noise';

        $result = (new AtlasSelfConstructionHumanCompletionReceiptMutationGuardService)->compare($before, $after);

        $this->assertTrue($result['guard_passed']);
        $this->assertSame([], $result['mutated_fields']);
        $this->assertSame(0, $result['mutated_field_count']);
        foreach ($result['unchanged_protected_fields'] as $field) {
            $this->assertNotSame('unrelated_decoration_field', $field);
        }
    }

    /** @param array<string, mixed> $overrides
     * @return array<string, mixed> */
    private function validReceipt(array $overrides = []): array
    {
        $receipt = array_merge([
            'receipt_id' => 'operator-os-complete-test',
            'signed_by' => 'Vitore Operator',
            'reason' => 'Reviewed final completion evidence.',
            'completion_audit_hash' => str_repeat('a', 64),
            'release_dossier_hash' => str_repeat('a', 64),
            'replay_diff_hash' => str_repeat('a', 64),
            'runtime_gap_matrix_hash' => str_repeat('a', 64),
            'runtime_promotion_receipt_hash' => str_repeat('a', 64),
            'real_provider_smoke_hash' => str_repeat('a', 64),
            'certification_status_batch_hash' => str_repeat('a', 64),
            'receipt_hash' => '',
            'os_complete_approved' => true,
            'operator_reviewed_completion_audit' => true,
            'no_autopromotion_acknowledged' => true,
        ], $overrides);
        $receipt['receipt_hash'] = (new AtlasSelfConstructionCompletionEvidenceHashService)->humanCompletionReceiptHash($receipt);

        return $receipt;
    }
}
