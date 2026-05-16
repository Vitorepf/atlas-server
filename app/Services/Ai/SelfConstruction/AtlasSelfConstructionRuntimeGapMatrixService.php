<?php

namespace App\Services\Ai\SelfConstruction;

use Carbon\CarbonImmutable;

final class AtlasSelfConstructionRuntimeGapMatrixService
{
    public const SCHEMA_VERSION = 'atlas.self_construction.runtime_gap_matrix.v1';

    public const MODE = 'read_only_runtime_gap_matrix';

    public function __construct(
        private readonly AtlasSelfConstructionReadinessService $readiness,
    ) {}

    /** @return array<string, mixed> */
    public function matrix(array $options = []): array
    {
        $controlPlane = $this->readiness->agentControlPlane();
        $notYet = (array) data_get($controlPlane, 'control_plane.not_yet_runtime_capable', []);
        $executionAllowed = (bool) data_get($controlPlane, 'execution_allowed', false);
        $nextRequiredSlice = (string) data_get($controlPlane, 'control_plane.persistent_runtime.next_required_slice', '');
        $promotionReceiptInput = (array) ($options['runtime_promotion_receipt'] ?? []);
        $persistPromotionReceipt = (bool) ($options['persist_runtime_promotion_receipt'] ?? false);
        $graduations = [
            'adapter_execution_runtime' => (new AtlasSelfConstructionAdapterExecutionRuntimeGraduationService)->certify(),
            'automatic_cost_import_runtime' => (new AtlasSelfConstructionAutomaticCostImportRuntimeGraduationService)->certify(),
            'automatic_work_product_collection_runtime' => (new AtlasSelfConstructionAutomaticWorkProductCollectionRuntimeGraduationService)->certify(),
            'automatic_dispatch_scheduler_codex_real_invoker_post_start_receipt_contract_runtime' => (new AtlasSelfConstructionDispatchSchedulerReceiptRuntimeReentryClosureService($this->readiness))->certify(),
        ];

        $rows = [
            $this->row(
                id: 'adapter_execution_runtime',
                listedAsGap: in_array('adapter_execution_runtime', $notYet, true),
                statusPayload: $this->safeStatus('agentControlPlaneAdapterExecutionRuntimeBoundaryStatus'),
                requiredPromotion: 'signed_provider_execution_gate_and_adapter_execution_receipt',
                graduationPayload: $graduations['adapter_execution_runtime'],
            ),
            $this->row(
                id: 'automatic_cost_import_runtime',
                listedAsGap: in_array('automatic_cost_import_runtime', $notYet, true),
                statusPayload: $this->safeStatus('agentControlPlaneAutomaticCostImportRuntimeStatus'),
                requiredPromotion: 'signed_cost_import_execution_gate_and_cost_event_write_receipt',
                graduationPayload: $graduations['automatic_cost_import_runtime'],
            ),
            $this->row(
                id: 'automatic_work_product_collection_runtime',
                listedAsGap: in_array('automatic_work_product_collection_runtime', $notYet, true),
                statusPayload: $this->safeStatus('agentControlPlaneAutomaticWorkProductCollectionRuntimeStatus'),
                requiredPromotion: 'signed_work_product_collection_execution_gate_and_manifest_receipt',
                graduationPayload: $graduations['automatic_work_product_collection_runtime'],
            ),
            $this->row(
                id: 'automatic_dispatch_scheduler_codex_real_invoker_post_start_receipt_contract_runtime',
                listedAsGap: in_array('automatic_dispatch_scheduler_codex_real_invoker_post_start_receipt_contract_runtime', $notYet, true),
                statusPayload: ['status' => $nextRequiredSlice === 'activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_receipt_contract' ? 'next_slice_pending' : 'not_current_pointer'],
                requiredPromotion: 'complete_post_start_receipt_contract_to_real_provider_smoke_chain',
                graduationPayload: $graduations['automatic_dispatch_scheduler_codex_real_invoker_post_start_receipt_contract_runtime'],
                additionalBlockers: [
                    'post_start_receipt_contract_runtime_not_promoted',
                    'real_provider_smoke_missing',
                    'human_os_complete_receipt_missing',
                ],
            ),
        ];

        $runtimePromotionBasisHash = $this->runtimePromotionBasisHash($rows);
        $promotionReceiptService = new AtlasSelfConstructionRuntimePromotionReceiptService;
        $expectedRuntimeGapMatrixHash = $this->expectedRuntimeGapMatrixHash(
            rows: $rows,
            runtimePromotionBasisHash: $runtimePromotionBasisHash,
            notYet: $notYet,
            executionAllowed: $executionAllowed,
            nextRequiredSlice: $nextRequiredSlice,
        );
        $runtimePromotionClosureBasisHash = $this->runtimePromotionClosureBasisHash($rows, $runtimePromotionBasisHash, $expectedRuntimeGapMatrixHash);
        $runtimePromotionReceipt = $persistPromotionReceipt && $promotionReceiptInput !== []
            ? $promotionReceiptService->persist($promotionReceiptInput, $rows, $runtimePromotionBasisHash, $expectedRuntimeGapMatrixHash, $runtimePromotionClosureBasisHash)
            : $promotionReceiptService->verify($promotionReceiptInput, $rows, $runtimePromotionBasisHash, $expectedRuntimeGapMatrixHash, $runtimePromotionClosureBasisHash);
        $rows = $this->applyRuntimePromotionReceipt($rows, $runtimePromotionReceipt);

        $runtimeRows = array_values(array_filter($rows, static fn (array $row): bool => ! (bool) ($row['runtime_y'] ?? false)));
        $graduationRows = array_values(array_filter($rows, static fn (array $row): bool => (bool) ($row['runtime_y_candidate'] ?? false)));
        $allRuntimeY = $runtimeRows === [];

        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'mode' => self::MODE,
            'status' => $allRuntimeY ? 'passed' : 'blocked',
            'assessed_at' => CarbonImmutable::now()->toIso8601String(),
            'all_runtime_y' => $allRuntimeY,
            'execution_allowed' => $executionAllowed,
            'not_yet_runtime_capable' => $notYet,
            'next_required_slice' => $nextRequiredSlice,
            'rows' => $rows,
            'runtime_promotion_basis_hash' => $runtimePromotionBasisHash,
            'runtime_promotion_closure_basis_hash' => $runtimePromotionClosureBasisHash,
            'expected_runtime_gap_matrix_hash_for_promotion_receipt' => $expectedRuntimeGapMatrixHash,
            'runtime_promotion_receipt' => $runtimePromotionReceipt,
            'runtime_gap_count' => count($runtimeRows),
            'runtime_y_count' => count(array_filter($rows, static fn (array $row): bool => (bool) ($row['runtime_y'] ?? false))),
            'runtime_y_candidate_count' => count($graduationRows),
            'blocked_gap_ids' => array_values(array_map(static fn (array $row): string => (string) $row['gap_id'], $runtimeRows)),
            'graduation_candidate_gap_ids' => array_values(array_map(static fn (array $row): string => (string) $row['gap_id'], $graduationRows)),
            'non_execution_guarantees' => [
                'runtime_gap_matrix_does_not_start_codex',
                'runtime_gap_matrix_does_not_call_provider',
                'runtime_gap_matrix_does_not_dispatch_work',
                'runtime_gap_matrix_does_not_spend_tokens',
                'runtime_gap_matrix_does_not_enable_self_programming',
            ],
        ];
        $payload['runtime_gap_matrix_hash'] = $this->stableHash($payload);

        return $payload;
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @param  array<int, mixed>  $notYet
     */
    private function expectedRuntimeGapMatrixHash(
        array $rows,
        string $runtimePromotionBasisHash,
        array $notYet,
        bool $executionAllowed,
        string $nextRequiredSlice,
    ): string {
        $runtimeRows = array_values(array_filter($rows, static fn (array $row): bool => ! (bool) ($row['runtime_y'] ?? false)));
        $graduationRows = array_values(array_filter($rows, static fn (array $row): bool => (bool) ($row['runtime_y_candidate'] ?? false)));

        return $this->stableHash([
            'schema_version' => self::SCHEMA_VERSION,
            'mode' => self::MODE,
            'status' => $runtimeRows === [] ? 'passed' : 'blocked',
            'all_runtime_y' => $runtimeRows === [],
            'execution_allowed' => $executionAllowed,
            'not_yet_runtime_capable' => $notYet,
            'next_required_slice' => $nextRequiredSlice,
            'rows' => $rows,
            'runtime_promotion_basis_hash' => $runtimePromotionBasisHash,
            'runtime_gap_count' => count($runtimeRows),
            'runtime_y_count' => count(array_filter($rows, static fn (array $row): bool => (bool) ($row['runtime_y'] ?? false))),
            'runtime_y_candidate_count' => count($graduationRows),
            'blocked_gap_ids' => array_values(array_map(static fn (array $row): string => (string) $row['gap_id'], $runtimeRows)),
            'graduation_candidate_gap_ids' => array_values(array_map(static fn (array $row): string => (string) $row['gap_id'], $graduationRows)),
            'non_execution_guarantees' => [
                'runtime_gap_matrix_does_not_start_codex',
                'runtime_gap_matrix_does_not_call_provider',
                'runtime_gap_matrix_does_not_dispatch_work',
                'runtime_gap_matrix_does_not_spend_tokens',
                'runtime_gap_matrix_does_not_enable_self_programming',
            ],
        ]);
    }

    /** @param array<int, array<string, mixed>> $rows */
    private function runtimePromotionBasisHash(array $rows): string
    {
        $basis = array_map(static fn (array $row): array => [
            'gap_id' => (string) ($row['gap_id'] ?? ''),
            'graduation_schema' => (string) ($row['graduation_schema'] ?? ''),
            'graduation_evidence_hash' => (string) ($row['graduation_evidence_hash'] ?? ''),
            'runtime_y_candidate' => (bool) ($row['runtime_y_candidate'] ?? false),
            'required_promotion' => (string) ($row['required_promotion'] ?? ''),
        ], $rows);

        return $this->stableHash(['runtime_promotion_basis' => $basis]);
    }

    /** @param array<int, array<string, mixed>> $rows */
    private function runtimePromotionClosureBasisHash(array $rows, string $runtimePromotionBasisHash, string $expectedRuntimeGapMatrixHash): string
    {
        $candidateRows = array_values(array_filter(
            $rows,
            static fn (array $row): bool => (bool) ($row['runtime_y_candidate'] ?? false) === true
                && (bool) ($row['runtime_y'] ?? false) === false,
        ));
        $graduationHashes = [];
        foreach ($candidateRows as $row) {
            $gapId = (string) ($row['gap_id'] ?? '');
            if ($gapId !== '') {
                $graduationHashes[$gapId] = (string) ($row['graduation_evidence_hash'] ?? '');
            }
        }

        return $this->stableHash([
            'runtime_promotion_closure_basis' => [
                'runtime_promotion_basis_hash' => $runtimePromotionBasisHash,
                'expected_runtime_gap_matrix_hash' => $expectedRuntimeGapMatrixHash,
                'promoted_gap_ids' => array_values(array_keys($graduationHashes)),
                'graduation_evidence_hashes' => $graduationHashes,
                'runtime_gap_count' => count(array_filter($rows, static fn (array $row): bool => ! (bool) ($row['runtime_y'] ?? false))),
                'runtime_y_candidate_count' => count($candidateRows),
                'runtime_enabled_count' => count(array_filter($rows, static fn (array $row): bool => (bool) ($row['runtime_enabled'] ?? false))),
            ],
        ]);
    }

    /** @return array<string, mixed> */
    private function row(string $id, bool $listedAsGap, array $statusPayload, string $requiredPromotion, array $graduationPayload, array $additionalBlockers = []): array
    {
        $status = (string) data_get($statusPayload, 'status');
        $certificationHash = (string) data_get($statusPayload, $this->statusKey($id).'.certification_hash', data_get($statusPayload, $this->statusKey($id).'_status.certification_hash', ''));
        $graduationHash = (string) data_get($graduationPayload, 'certification_hash', '');
        $runtimeYCandidate = (bool) data_get($graduationPayload, 'runtime_y_candidate', false);
        $blockers = $listedAsGap
            ? ['runtime_not_promoted_even_though_graduation_candidate_may_exist']
            : [];
        if (! $runtimeYCandidate) {
            $blockers[] = 'runtime_graduation_candidate_not_available';
        }
        $blockers = array_values(array_unique(array_merge($blockers, $additionalBlockers)));

        return [
            'gap_id' => $id,
            'listed_as_gap' => $listedAsGap,
            'runtime_y' => ! $listedAsGap,
            'runtime_y_candidate' => $runtimeYCandidate,
            'runtime_enabled' => false,
            'certification_status' => $status,
            'evidence_hash' => $certificationHash,
            'graduation_schema' => (string) data_get($graduationPayload, 'schema_version', ''),
            'graduation_status' => (string) data_get($graduationPayload, 'status', 'missing'),
            'graduation_evidence_hash' => $graduationHash,
            'promoted_by_runtime_receipt' => false,
            'required_promotion' => $requiredPromotion,
            'blockers' => $blockers,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @param  array<string, mixed>  $receipt
     * @return array<int, array<string, mixed>>
     */
    private function applyRuntimePromotionReceipt(array $rows, array $receipt): array
    {
        if ((string) data_get($receipt, 'status') !== 'passed') {
            return $rows;
        }

        $promotedGapIds = (array) data_get($receipt, 'promoted_gap_ids', []);
        $graduationHashes = (array) data_get($receipt, 'graduation_evidence_hashes', []);

        return array_values(array_map(static function (array $row) use ($promotedGapIds, $graduationHashes): array {
            $gapId = (string) ($row['gap_id'] ?? '');
            $hash = (string) ($row['graduation_evidence_hash'] ?? '');
            $promoted = in_array($gapId, $promotedGapIds, true)
                && (string) ($graduationHashes[$gapId] ?? '') === $hash
                && $hash !== '';

            if ($promoted) {
                $row['runtime_y'] = true;
                $row['promoted_by_runtime_receipt'] = true;
                $row['blockers'] = [];
            }

            return $row;
        }, $rows));
    }

    /** @return array<string, mixed> */
    private function safeStatus(string $method): array
    {
        try {
            return method_exists($this->readiness, $method)
                ? $this->readiness->{$method}()
                : ['status' => 'method_missing'];
        } catch (\Throwable $e) {
            return ['status' => 'exception', 'error' => $e->getMessage()];
        }
    }

    private function statusKey(string $id): string
    {
        return match ($id) {
            'adapter_execution_runtime' => 'agent_control_plane_adapter_execution_runtime_boundary',
            'automatic_cost_import_runtime' => 'agent_control_plane_automatic_cost_import_runtime',
            'automatic_work_product_collection_runtime' => 'agent_control_plane_automatic_work_product_collection_runtime',
            default => $id,
        };
    }

    private function stableHash(array $payload): string
    {
        unset($payload['assessed_at'], $payload['runtime_gap_matrix_hash']);
        unset($payload['runtime_promotion_receipt']['verified_at'], $payload['runtime_promotion_receipt']['receipt_verification_hash']);
        unset($payload['runtime_promotion_receipt']['expected_runtime_gap_matrix_hash'], $payload['runtime_promotion_receipt']['expected_runtime_promotion_closure_basis_hash']);

        return hash('sha256', (string) json_encode($this->ksortRecursive($payload), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

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
}
