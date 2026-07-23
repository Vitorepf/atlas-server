<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\NativeImplementation\AtlasSelfConstructionCompletionEvidenceHashService;
use App\Services\Ai\SelfConstruction\NativeImplementation\AtlasSelfConstructionFinalOperatorEvidenceClosureCorridorService;
use App\Services\Ai\SelfConstruction\NativeImplementation\AtlasSelfConstructionOperatorEvidenceDraftHashFinalizerService;
use App\Services\Ai\SelfConstruction\Readiness\AtlasSelfConstructionReadinessService;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class AtlasSelfConstructionFinalOperatorEvidenceClosureCorridorReadOnlyPublisherTest extends TestCase
{
    public function test_corridor_forces_the_publisher_into_preview_when_callers_request_a_write(): void
    {
        Storage::fake('local');
        $workspace = 'atlas/self-construction/operator-submissions/draft-workspaces/corridor-read-only-preview';
        $this->writeWorkspace($workspace, $this->readyDrafts());
        (new AtlasSelfConstructionOperatorEvidenceDraftHashFinalizerService)->finalize([
            'operator_draft_workspace_path' => $workspace,
            'write_computed_operator_draft_hashes' => true,
        ]);

        $corridor = new AtlasSelfConstructionFinalOperatorEvidenceClosureCorridorService(
            app(AtlasSelfConstructionReadinessService::class),
        );
        $preview = (function (array $options): array {
            return $this->draftWorkspacePublisherPreview($options);
        })->call($corridor, [
            'operator_draft_workspace_path' => $workspace,
            'publish_operator_draft_workspace' => true,
        ]);

        $this->assertSame('ready_to_publish_operator_draft_workspace', $preview['status']);
        $this->assertFalse($preview['publish_requested']);
        $this->assertSame(3, $preview['publishable_artifact_count']);
        $this->assertSame(0, $preview['published_artifact_count']);
        foreach ([
            'runtime-promotion.json',
            'real-provider-smoke.json',
            'completion-receipt.json',
        ] as $artifact) {
            Storage::disk('local')->assertMissing('atlas/self-construction/operator-submissions/'.$artifact);
        }
    }

    /** @param array<string, array<string, mixed>> $drafts */
    private function writeWorkspace(string $workspace, array $drafts): void
    {
        $paths = [
            'runtime_promotion_receipt' => [$workspace.'/runtime-promotion.json', 'storage/app/atlas/self-construction/operator-submissions/runtime-promotion.json'],
            'real_provider_smoke' => [$workspace.'/real-provider-smoke.json', 'storage/app/atlas/self-construction/operator-submissions/real-provider-smoke.json'],
            'human_completion_receipt' => [$workspace.'/completion-receipt.json', 'storage/app/atlas/self-construction/operator-submissions/completion-receipt.json'],
        ];
        foreach ($paths as $artifact => [$path]) {
            Storage::disk('local')->put($path, json_encode($drafts[$artifact], JSON_THROW_ON_ERROR));
        }
        Storage::disk('local')->put($workspace.'/manifest.json', json_encode([
            'schema_version' => 'atlas.self_construction.operator_evidence_draft_workspace.v1',
            'workspace_directory' => $workspace,
            'files' => array_map(
                static fn (string $artifact, array $paths): array => [
                    'artifact' => $artifact,
                    'draft_path' => $paths[0],
                    'recommended_file_path' => $paths[1],
                    'draft_is_evidence' => false,
                    'can_persist_draft_directly' => false,
                ],
                array_keys($paths),
                array_values($paths),
            ),
        ], JSON_THROW_ON_ERROR));
    }

    /** @return array<string, array<string, mixed>> */
    private function readyDrafts(): array
    {
        $hashes = new AtlasSelfConstructionCompletionEvidenceHashService;
        $runtime = [
            'receipt_id' => 'corridor-runtime-promotion',
            'signed_by' => 'vitorepf',
            'reason' => 'Operator reviewed runtime candidates and approves promotion without enabling execution.',
            'runtime_gap_matrix_hash' => str_repeat('1', 64),
            'runtime_promotion_basis_hash' => str_repeat('2', 64),
            'runtime_promotion_closure_basis_hash' => str_repeat('3', 64),
            'promoted_gap_ids' => ['adapter_execution_runtime'],
            'graduation_evidence_hashes' => ['adapter_execution_runtime' => str_repeat('4', 64)],
            'receipt_hash' => '<operator_generated_64_hex_receipt_hash>',
            'runtime_promotion_approved' => true,
            'operator_reviewed_runtime_graduations' => true,
            'no_runtime_autopromotion_acknowledged' => true,
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'provider_call_allowed' => false,
            'token_spend_allowed' => false,
            'adapter_execution_allowed' => false,
            'self_programming_allowed' => false,
        ];
        $smoke = [
            'kind' => 'real_provider_packet_claim_to_completion',
            'status' => 'passed',
            'provider_run_id' => 'corridor-provider-run',
            'task_packet_id' => 'corridor-task-packet',
            'observed_by' => 'operator',
            'approval_reason' => 'Operator approved and observed one real provider claim-to-completion smoke.',
            'smoke_hash' => '<64_hex_smoke_hash_from_operator_approved_real_provider_smoke>',
            'operator_approval_receipt_hash' => str_repeat('5', 64),
            'evidence_ledger_hash' => str_repeat('6', 64),
            'work_product_manifest_hash' => str_repeat('7', 64),
            'cost_event_hash' => str_repeat('8', 64),
            'continuation_summary_hash' => str_repeat('9', 64),
            'provider_response_hash' => str_repeat('a', 64),
            'provider_call_observed' => true,
            'token_spend_observed' => true,
            'claim_to_completion_observed' => true,
            'work_product_collected' => true,
            'operator_supplied_evidence' => true,
            'real_provider_run_observed_by_operator' => true,
            'provider_called_by_atlas' => false,
            'token_spent_by_atlas' => false,
            'dispatch_allowed' => false,
            'adapter_execution_allowed' => false,
            'self_programming_allowed' => false,
            'completion_claim_promoted_without_receipt' => false,
        ];
        $human = [
            'receipt_id' => 'corridor-os-complete',
            'signed_by' => 'vitorepf',
            'reason' => 'Operator reviewed the final completion evidence and approves OS completion.',
            'completion_audit_hash' => str_repeat('b', 64),
            'release_dossier_hash' => str_repeat('c', 64),
            'replay_diff_hash' => str_repeat('d', 64),
            'runtime_gap_matrix_hash' => str_repeat('e', 64),
            'runtime_promotion_receipt_hash' => $hashes->runtimePromotionReceiptHash($runtime),
            'real_provider_smoke_hash' => $hashes->realProviderSmokeHash($smoke),
            'certification_status_batch_hash' => str_repeat('f', 64),
            'receipt_hash' => '<operator_generated_64_hex_receipt_hash>',
            'os_complete_approved' => true,
            'operator_reviewed_completion_audit' => true,
            'no_autopromotion_acknowledged' => true,
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'provider_call_allowed' => false,
            'token_spend_allowed' => false,
            'adapter_execution_allowed' => false,
            'self_programming_allowed' => false,
            'completion_autopromoted' => false,
        ];

        return [
            'runtime_promotion_receipt' => $runtime,
            'real_provider_smoke' => $smoke,
            'human_completion_receipt' => $human,
        ];
    }
}
