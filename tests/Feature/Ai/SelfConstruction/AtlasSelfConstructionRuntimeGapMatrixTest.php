<?php

namespace Tests\Feature\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\AtlasSelfConstructionCompletionEvidenceHashService;
use App\Services\Ai\SelfConstruction\AtlasSelfConstructionReadinessService;
use App\Services\Ai\SelfConstruction\AtlasSelfConstructionRuntimeGapMatrixService;
use App\Services\Ai\SelfConstruction\AtlasSelfConstructionRuntimePromotionReceiptService;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class AtlasSelfConstructionRuntimeGapMatrixTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    public function test_runtime_gap_matrix_exposes_blocked_gaps_with_evidence(): void
    {
        $matrix = (new AtlasSelfConstructionRuntimeGapMatrixService(app(AtlasSelfConstructionReadinessService::class)))->matrix();

        $this->assertSame('atlas.self_construction.runtime_gap_matrix.v1', $matrix['schema_version']);
        $this->assertSame($matrix['all_runtime_y'] ? 'passed' : 'blocked', $matrix['status']);
        $this->assertFalse($matrix['execution_allowed']);
        $this->assertSame(count($matrix['blocked_gap_ids']), $matrix['runtime_gap_count']);
        $this->assertIsInt($matrix['runtime_y_candidate_count']);
        $this->assertIsArray($matrix['graduation_candidate_gap_ids']);
        $this->assertIsArray($matrix['not_yet_runtime_capable']);
        $this->assertIsString($matrix['next_required_slice']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $matrix['runtime_promotion_basis_hash']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $matrix['runtime_promotion_closure_basis_hash']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $matrix['expected_runtime_gap_matrix_hash_for_promotion_receipt']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $matrix['runtime_gap_matrix_hash']);
    }

    public function test_runtime_gap_matrix_hashes_are_stable_across_read_only_evaluations(): void
    {
        $service = new AtlasSelfConstructionRuntimeGapMatrixService(app(AtlasSelfConstructionReadinessService::class));

        $first = $service->matrix();
        $second = $service->matrix();

        $this->assertSame($first['runtime_promotion_basis_hash'], $second['runtime_promotion_basis_hash']);
        $this->assertSame($first['runtime_promotion_closure_basis_hash'], $second['runtime_promotion_closure_basis_hash']);
        $this->assertSame($first['expected_runtime_gap_matrix_hash_for_promotion_receipt'], $second['expected_runtime_gap_matrix_hash_for_promotion_receipt']);
        $this->assertSame($first['runtime_gap_matrix_hash'], $second['runtime_gap_matrix_hash']);
    }

    public function test_expected_runtime_gap_matrix_hash_uses_stable_signable_base(): void
    {
        $matrix = (new AtlasSelfConstructionRuntimeGapMatrixService(app(AtlasSelfConstructionReadinessService::class)))->matrix();
        $rows = (array) $matrix['rows'];
        $runtimeRows = array_values(array_filter($rows, static fn (array $row): bool => ! (bool) ($row['runtime_y'] ?? false)));
        $graduationRows = array_values(array_filter($rows, static fn (array $row): bool => (bool) ($row['runtime_y_candidate'] ?? false)));
        $base = [
            'schema_version' => $matrix['schema_version'],
            'mode' => $matrix['mode'],
            'status' => $matrix['status'],
            'assessed_at' => $matrix['assessed_at'],
            'all_runtime_y' => $matrix['all_runtime_y'],
            'execution_allowed' => $matrix['execution_allowed'],
            'not_yet_runtime_capable' => $matrix['not_yet_runtime_capable'],
            'next_required_slice' => $matrix['next_required_slice'],
            'rows' => $rows,
            'runtime_promotion_basis_hash' => $matrix['runtime_promotion_basis_hash'],
            'runtime_gap_count' => count($runtimeRows),
            'runtime_y_count' => count(array_filter($rows, static fn (array $row): bool => (bool) ($row['runtime_y'] ?? false))),
            'runtime_y_candidate_count' => count($graduationRows),
            'blocked_gap_ids' => array_values(array_map(static fn (array $row): string => (string) $row['gap_id'], $runtimeRows)),
            'graduation_candidate_gap_ids' => array_values(array_map(static fn (array $row): string => (string) $row['gap_id'], $graduationRows)),
            'non_execution_guarantees' => $matrix['non_execution_guarantees'],
        ];
        unset($base['assessed_at']);

        $expected = hash('sha256', (string) json_encode(
            $this->ksortRecursive($base),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        ));

        $this->assertSame($expected, $matrix['expected_runtime_gap_matrix_hash_for_promotion_receipt']);
    }

    public function test_runtime_gap_matrix_includes_certification_status_for_runtime_boundaries(): void
    {
        $matrix = (new AtlasSelfConstructionRuntimeGapMatrixService(app(AtlasSelfConstructionReadinessService::class)))->matrix();
        $rows = collect($matrix['rows'])->keyBy('gap_id');

        foreach ([
            'adapter_execution_runtime',
            'automatic_cost_import_runtime',
            'automatic_work_product_collection_runtime',
        ] as $gapId) {
            $this->assertSame('available', data_get($rows[$gapId], 'certification_status'));
            $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) data_get($rows[$gapId], 'evidence_hash'));
            $this->assertSame('available', data_get($rows[$gapId], 'graduation_status'));
            $this->assertTrue((bool) data_get($rows[$gapId], 'runtime_y_candidate'));
            $this->assertFalse((bool) data_get($rows[$gapId], 'runtime_enabled'));
            $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) data_get($rows[$gapId], 'graduation_evidence_hash'));
            $this->assertSame(! (bool) data_get($rows[$gapId], 'listed_as_gap'), (bool) data_get($rows[$gapId], 'runtime_y'));
        }

        $this->assertContains(
            data_get($rows['automatic_dispatch_scheduler_codex_real_invoker_post_start_receipt_contract_runtime'], 'certification_status'),
            ['next_slice_pending', 'not_current_pointer'],
        );
        $this->assertSame('atlas.self_construction.dispatch_scheduler_receipt_runtime_reentry_closure.v1', data_get($rows['automatic_dispatch_scheduler_codex_real_invoker_post_start_receipt_contract_runtime'], 'graduation_schema'));
    }

    public function test_runtime_promotion_receipt_does_not_promote_non_candidate_current_gap(): void
    {
        $service = new AtlasSelfConstructionRuntimeGapMatrixService(app(AtlasSelfConstructionReadinessService::class));
        $matrix = $service->matrix();
        $rows = (array) $matrix['rows'];
        $receipt = $this->runtimePromotionReceipt($matrix);
        $verification = (new AtlasSelfConstructionRuntimePromotionReceiptService)->verify($receipt, $rows);
        $promoted = $service->matrix(['runtime_promotion_receipt' => $receipt]);

        $this->assertSame('blocked_missing_runtime_promotion_receipt', $verification['status']);
        $this->assertContains('promoted_gap_ids_do_not_match_runtime_gap_matrix', array_column($verification['violations'], 'code'));
        $this->assertFalse($promoted['execution_allowed']);
        $this->assertSame('blocked_missing_runtime_promotion_receipt', data_get($promoted, 'runtime_promotion_receipt.status'));
        foreach ($promoted['rows'] as $row) {
            $this->assertFalse($row['runtime_enabled']);
        }
    }

    public function test_runtime_promotion_receipt_verifier_accepts_candidate_rows_without_enabling_runtime(): void
    {
        $rows = $this->candidateRows();
        $receipt = $this->runtimePromotionReceiptFromRows($rows);

        $verification = (new AtlasSelfConstructionRuntimePromotionReceiptService)->verify(
            $receipt,
            $rows,
            $receipt['runtime_promotion_basis_hash'],
        );

        $this->assertSame('passed', $verification['status'], json_encode($verification['violations'], JSON_THROW_ON_ERROR));
        $this->assertTrue($verification['runtime_promotion_allowed']);
        $this->assertFalse($verification['execution_allowed']);
        $this->assertFalse($verification['adapter_execution_allowed']);
        $this->assertSame(array_column($rows, 'gap_id'), $verification['promoted_gap_ids']);
    }

    public function test_runtime_promotion_receipt_persistence_stays_blocked_for_non_candidate_current_gap(): void
    {
        $service = new AtlasSelfConstructionRuntimeGapMatrixService(app(AtlasSelfConstructionReadinessService::class));
        $matrix = $service->matrix();
        $receipt = $this->runtimePromotionReceipt($matrix);

        $persisted = $service->matrix([
            'runtime_promotion_receipt' => $receipt,
            'persist_runtime_promotion_receipt' => true,
        ]);
        $latest = $service->matrix();

        $this->assertFalse(data_get($persisted, 'runtime_promotion_receipt.persisted'));
        $this->assertSame('blocked_missing_runtime_promotion_receipt', data_get($latest, 'runtime_promotion_receipt.status'));
    }

    public function test_runtime_promotion_receipt_rejects_hash_that_does_not_match_payload(): void
    {
        $service = new AtlasSelfConstructionRuntimeGapMatrixService(app(AtlasSelfConstructionReadinessService::class));
        $matrix = $service->matrix();
        $rows = (array) $matrix['rows'];
        $receipt = $this->runtimePromotionReceipt($matrix);
        $receipt['receipt_hash'] = str_repeat('8', 64);

        $verification = (new AtlasSelfConstructionRuntimePromotionReceiptService)->verify($receipt, $rows);

        $this->assertSame('blocked_missing_runtime_promotion_receipt', $verification['status']);
        $this->assertFalse($verification['receipt_hash_matches_payload']);
        $this->assertContains('receipt_hash_mismatch', array_column($verification['violations'], 'code'));
    }

    public function test_runtime_promotion_receipt_rejects_stale_runtime_gap_matrix_hash(): void
    {
        $rows = $this->candidateRows();
        $receipt = $this->runtimePromotionReceiptFromRows($rows);
        $receipt['runtime_gap_matrix_hash'] = str_repeat('d', 64);
        $receipt['receipt_hash'] = (new AtlasSelfConstructionCompletionEvidenceHashService)->runtimePromotionReceiptHash($receipt);

        $verification = (new AtlasSelfConstructionRuntimePromotionReceiptService)->verify(
            receipt: $receipt,
            rows: $rows,
            expectedRuntimePromotionBasisHash: $receipt['runtime_promotion_basis_hash'],
            expectedRuntimeGapMatrixHash: str_repeat('c', 64),
        );

        $this->assertSame('blocked_missing_runtime_promotion_receipt', $verification['status']);
        $this->assertSame(str_repeat('c', 64), $verification['expected_runtime_gap_matrix_hash']);
        $this->assertContains('runtime_gap_matrix_hash_mismatch', array_column($verification['violations'], 'code'));
    }

    public function test_runtime_promotion_receipt_rejects_closure_basis_hash_mismatch(): void
    {
        $rows = $this->candidateRows();
        $receipt = $this->runtimePromotionReceiptFromRows($rows);
        $receipt['runtime_promotion_closure_basis_hash'] = str_repeat('e', 64);
        $receipt['receipt_hash'] = (new AtlasSelfConstructionCompletionEvidenceHashService)->runtimePromotionReceiptHash($receipt);

        $verification = (new AtlasSelfConstructionRuntimePromotionReceiptService)->verify(
            receipt: $receipt,
            rows: $rows,
            expectedRuntimePromotionBasisHash: $receipt['runtime_promotion_basis_hash'],
            expectedRuntimeGapMatrixHash: $receipt['runtime_gap_matrix_hash'],
            expectedRuntimePromotionClosureBasisHash: $this->runtimePromotionClosureBasisHash($rows, $receipt['runtime_promotion_basis_hash'], $receipt['runtime_gap_matrix_hash']),
        );

        $this->assertSame('blocked_missing_runtime_promotion_receipt', $verification['status']);
        $this->assertContains('runtime_promotion_closure_basis_hash_mismatch', array_column($verification['violations'], 'code'));
    }

    public function test_runtime_promotion_receipt_rejects_placeholder_signer(): void
    {
        $service = new AtlasSelfConstructionRuntimeGapMatrixService(app(AtlasSelfConstructionReadinessService::class));
        $matrix = $service->matrix();
        $receipt = $this->runtimePromotionReceipt($matrix, ['signed_by' => 'operator']);

        $verification = (new AtlasSelfConstructionRuntimePromotionReceiptService)->verify($receipt, (array) $matrix['rows']);

        $this->assertSame('blocked_missing_runtime_promotion_receipt', $verification['status']);
        $this->assertContains('runtime_promotion_receipt_signer_must_be_real_operator', array_column($verification['violations'], 'code'));
    }

    public function test_runtime_promotion_receipt_rejects_short_reason(): void
    {
        $service = new AtlasSelfConstructionRuntimeGapMatrixService(app(AtlasSelfConstructionReadinessService::class));
        $matrix = $service->matrix();
        $receipt = $this->runtimePromotionReceipt($matrix, ['reason' => 'ok']);

        $verification = (new AtlasSelfConstructionRuntimePromotionReceiptService)->verify($receipt, (array) $matrix['rows']);

        $this->assertSame('blocked_missing_runtime_promotion_receipt', $verification['status']);
        $this->assertContains('runtime_promotion_receipt_reason_too_short', array_column($verification['violations'], 'code'));
    }

    public function test_runtime_promotion_receipt_rejects_duplicate_promoted_gap_ids(): void
    {
        $rows = $this->candidateRows();
        $receipt = $this->runtimePromotionReceiptFromRows($rows);
        $receipt['promoted_gap_ids'][] = (string) $receipt['promoted_gap_ids'][0];
        $receipt['receipt_hash'] = (new AtlasSelfConstructionCompletionEvidenceHashService)->runtimePromotionReceiptHash($receipt);

        $verification = (new AtlasSelfConstructionRuntimePromotionReceiptService)->verify($receipt, $rows);

        $this->assertSame('blocked_missing_runtime_promotion_receipt', $verification['status']);
        $this->assertContains('promoted_gap_ids_contain_duplicates', array_column($verification['violations'], 'code'));
    }

    public function test_runtime_promotion_receipt_rejects_unknown_graduation_hash_key(): void
    {
        $service = new AtlasSelfConstructionRuntimeGapMatrixService(app(AtlasSelfConstructionReadinessService::class));
        $matrix = $service->matrix();
        $receipt = $this->runtimePromotionReceipt($matrix);
        $receipt['graduation_evidence_hashes']['unknown_gap'] = str_repeat('7', 64);
        $receipt['receipt_hash'] = (new AtlasSelfConstructionCompletionEvidenceHashService)->runtimePromotionReceiptHash($receipt);

        $verification = (new AtlasSelfConstructionRuntimePromotionReceiptService)->verify($receipt, (array) $matrix['rows']);

        $this->assertSame('blocked_missing_runtime_promotion_receipt', $verification['status']);
        $this->assertContains('graduation_hash_for_unknown_gap_id', array_column($verification['violations'], 'code'));
    }

    public function test_runtime_promotion_receipt_rejects_runtime_enabled_row(): void
    {
        $service = new AtlasSelfConstructionRuntimeGapMatrixService(app(AtlasSelfConstructionReadinessService::class));
        $matrix = $service->matrix();
        $rows = (array) $matrix['rows'];
        $rows = $this->candidateRows();
        $rows[0]['runtime_enabled'] = true;
        $receipt = $this->runtimePromotionReceiptFromRows($rows);

        $verification = (new AtlasSelfConstructionRuntimePromotionReceiptService)->verify($receipt, $rows);

        $this->assertSame('blocked_missing_runtime_promotion_receipt', $verification['status']);
        $this->assertContains('runtime_enabled_before_runtime_promotion_receipt', array_column($verification['violations'], 'code'));
    }

    /**
     * @param  array<string, mixed>  $matrix
     * @param  array<string, mixed>  $overrides
     */
    private function runtimePromotionReceipt(array $matrix, array $overrides = []): array
    {
        $rows = (array) $matrix['rows'];
        $graduationHashes = [];
        foreach ($rows as $row) {
            if (! (bool) ($row['runtime_y_candidate'] ?? false) || (bool) ($row['runtime_y'] ?? false)) {
                continue;
            }
            $graduationHashes[(string) $row['gap_id']] = (string) $row['graduation_evidence_hash'];
        }

        $receipt = [
            'receipt_id' => 'runtime-promotion-receipt-001',
            'signed_by' => 'Vitorepf Runtime Operator',
            'reason' => 'Reviewed runtime graduation candidates and approved runtime gap promotion.',
            'runtime_gap_matrix_hash' => (string) $matrix['runtime_gap_matrix_hash'],
            'runtime_promotion_basis_hash' => (string) $matrix['runtime_promotion_basis_hash'],
            'runtime_promotion_closure_basis_hash' => (string) $matrix['runtime_promotion_closure_basis_hash'],
            'promoted_gap_ids' => array_values(array_map(
                static fn (array $row): string => (string) $row['gap_id'],
                array_values(array_filter($rows, static fn (array $row): bool => (bool) ($row['runtime_y_candidate'] ?? false) && ! (bool) ($row['runtime_y'] ?? false))),
            )),
            'graduation_evidence_hashes' => $graduationHashes,
            'receipt_hash' => str_repeat('9', 64),
            'runtime_promotion_approved' => true,
            'operator_reviewed_runtime_graduations' => true,
            'no_runtime_autopromotion_acknowledged' => true,
        ];
        $receipt = array_merge($receipt, $overrides);
        $receipt['receipt_hash'] = (new AtlasSelfConstructionCompletionEvidenceHashService)->runtimePromotionReceiptHash($receipt);

        return $receipt;
    }

    /** @return list<array<string, mixed>> */
    private function candidateRows(): array
    {
        return [
            [
                'gap_id' => 'adapter_execution_runtime',
                'runtime_y' => false,
                'runtime_y_candidate' => true,
                'runtime_enabled' => false,
                'graduation_schema' => 'atlas.self_construction.adapter_execution_runtime_graduation.v1',
                'graduation_evidence_hash' => str_repeat('a', 64),
                'required_promotion' => 'signed_provider_execution_gate_and_adapter_execution_receipt',
            ],
            [
                'gap_id' => 'automatic_cost_import_runtime',
                'runtime_y' => false,
                'runtime_y_candidate' => true,
                'runtime_enabled' => false,
                'graduation_schema' => 'atlas.self_construction.automatic_cost_import_runtime_graduation.v1',
                'graduation_evidence_hash' => str_repeat('b', 64),
                'required_promotion' => 'signed_cost_import_execution_gate_and_cost_event_write_receipt',
            ],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  array<string, mixed>  $overrides
     */
    private function runtimePromotionReceiptFromRows(array $rows, array $overrides = []): array
    {
        $basis = array_map(static fn (array $row): array => [
            'gap_id' => (string) ($row['gap_id'] ?? ''),
            'graduation_schema' => (string) ($row['graduation_schema'] ?? ''),
            'graduation_evidence_hash' => (string) ($row['graduation_evidence_hash'] ?? ''),
            'runtime_y_candidate' => (bool) ($row['runtime_y_candidate'] ?? false),
            'required_promotion' => (string) ($row['required_promotion'] ?? ''),
        ], $rows);
        $basisHash = hash('sha256', (string) json_encode(['runtime_promotion_basis' => $this->ksortRecursive($basis)], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $graduationHashes = [];
        foreach ($rows as $row) {
            $graduationHashes[(string) $row['gap_id']] = (string) $row['graduation_evidence_hash'];
        }

        $receipt = array_merge([
            'receipt_id' => 'runtime-promotion-receipt-synthetic-001',
            'signed_by' => 'Vitorepf Runtime Operator',
            'reason' => 'Reviewed synthetic runtime graduation candidates and approved runtime gap promotion.',
            'runtime_gap_matrix_hash' => str_repeat('c', 64),
            'runtime_promotion_basis_hash' => $basisHash,
            'runtime_promotion_closure_basis_hash' => $this->runtimePromotionClosureBasisHash($rows, $basisHash, str_repeat('c', 64)),
            'promoted_gap_ids' => array_values(array_map(static fn (array $row): string => (string) $row['gap_id'], $rows)),
            'graduation_evidence_hashes' => $graduationHashes,
            'receipt_hash' => str_repeat('9', 64),
            'runtime_promotion_approved' => true,
            'operator_reviewed_runtime_graduations' => true,
            'no_runtime_autopromotion_acknowledged' => true,
        ], $overrides);
        $receipt['receipt_hash'] = (new AtlasSelfConstructionCompletionEvidenceHashService)->runtimePromotionReceiptHash($receipt);

        return $receipt;
    }

    /** @param array<string, mixed> $value */
    private function ksortRecursive(array $value): array
    {
        foreach ($value as $key => $entry) {
            if (is_array($entry)) {
                $value[$key] = $this->ksortRecursive($entry);
            }
        }
        if ($value !== [] && array_keys($value) !== range(0, count($value) - 1)) {
            ksort($value);
        }

        return $value;
    }

    /** @param list<array<string, mixed>> $rows */
    private function runtimePromotionClosureBasisHash(array $rows, string $basisHash, string $expectedRuntimeGapMatrixHash): string
    {
        $graduationHashes = [];
        foreach ($rows as $row) {
            $graduationHashes[(string) $row['gap_id']] = (string) $row['graduation_evidence_hash'];
        }

        return hash('sha256', (string) json_encode($this->ksortRecursive([
            'runtime_promotion_closure_basis' => [
                'runtime_promotion_basis_hash' => $basisHash,
                'expected_runtime_gap_matrix_hash' => $expectedRuntimeGapMatrixHash,
                'promoted_gap_ids' => array_values(array_keys($graduationHashes)),
                'graduation_evidence_hashes' => $graduationHashes,
                'runtime_gap_count' => count($rows),
                'runtime_y_candidate_count' => count($rows),
                'runtime_enabled_count' => count(array_filter($rows, static fn (array $row): bool => (bool) ($row['runtime_enabled'] ?? false))),
            ],
        ]), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
}
