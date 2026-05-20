<?php

namespace Tests\Feature\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\AtlasSelfConstructionOperatorEvidenceDraftWorkspaceInspectorService;
use App\Services\Ai\SelfConstruction\AtlasSelfConstructionReadinessService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class AtlasSelfConstructionOperatorEvidenceDraftWorkspaceInspectorTest extends TestCase
{
    public function test_inspector_reports_no_workspace_without_writes(): void
    {
        Storage::fake('local');

        $payload = (new AtlasSelfConstructionOperatorEvidenceDraftWorkspaceInspectorService)->inspect();

        $this->assertSame('atlas.self_construction.operator_evidence_draft_workspace_inspector.v1', $payload['schema_version']);
        $this->assertSame('no_workspace', $payload['status']);
        $this->assertContains('operator_draft_workspace_manifest_not_found', $payload['violations']);
        $this->assertFalse($payload['completion_allowed']);
        $this->assertFalse($payload['dispatch_allowed']);
        $this->assertFalse($payload['provider_call_allowed']);
        $this->assertFalse($payload['token_spend_allowed']);
        $this->assertFalse($payload['self_programming_allowed']);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_inspector_accepts_placeholder_workspace_as_safe_for_operator_editing(): void
    {
        Storage::fake('local');
        $workspace = $this->writeWorkspace();

        $payload = (new AtlasSelfConstructionOperatorEvidenceDraftWorkspaceInspectorService)->inspect([
            'operator_draft_workspace_path' => $workspace,
        ]);

        $this->assertSame('workspace_safe_for_operator_editing', $payload['status']);
        $this->assertSame(0, $payload['violation_count']);
        $this->assertSame(3, $payload['artifact_count']);
        $this->assertTrue($payload['workspace_safe_for_operator_editing']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $payload['inspector_hash']);

        foreach ($payload['files'] as $file) {
            $this->assertTrue($file['exists']);
            $this->assertFalse($file['draft_is_evidence']);
            $this->assertFalse($file['can_persist_draft_directly']);
            $this->assertFalse($file['verify_command_has_persist_flag']);
            $this->assertGreaterThan(0, $file['placeholder_count']);
        }
    }

    public function test_inspector_accepts_private_storage_prefixed_workspace_path(): void
    {
        Storage::fake('local');
        $workspace = $this->writeWorkspace();

        $payload = (new AtlasSelfConstructionOperatorEvidenceDraftWorkspaceInspectorService)->inspect([
            'operator_draft_workspace_path' => 'storage/app/private/'.$workspace,
        ]);

        $this->assertSame('workspace_safe_for_operator_editing', $payload['status']);
        $this->assertSame($workspace.'/manifest.json', $payload['manifest_path']);
        $this->assertSame($workspace, $payload['workspace_directory']);
        $this->assertSame(3, $payload['artifact_count']);
        $this->assertSame(0, $payload['violation_count']);
        $this->assertTrue($payload['workspace_safe_for_operator_editing']);
    }

    public function test_inspector_detects_forbidden_flags_and_persist_verify_command(): void
    {
        Storage::fake('local');
        $workspace = $this->writeWorkspace();
        $manifestPath = $workspace.'/manifest.json';
        $manifest = json_decode(Storage::disk('local')->get($manifestPath), true, flags: JSON_THROW_ON_ERROR);
        $manifest['files'][0]['command_to_verify_draft_file'] .= ' --persist-runtime-promotion-receipt';
        Storage::disk('local')->put($manifestPath, $this->draftJson($manifest));

        $runtimePath = $workspace.'/runtime-promotion.json';
        $runtime = json_decode(Storage::disk('local')->get($runtimePath), true, flags: JSON_THROW_ON_ERROR);
        $runtime['dispatch_allowed'] = true;
        Storage::disk('local')->put($runtimePath, $this->draftJson($runtime));

        $payload = (new AtlasSelfConstructionOperatorEvidenceDraftWorkspaceInspectorService)->inspect([
            'operator_draft_workspace_path' => $manifestPath,
        ]);

        $this->assertSame('workspace_attention_required', $payload['status']);
        $this->assertFalse($payload['workspace_safe_for_operator_editing']);
        $this->assertContains('runtime_promotion_receipt:forbidden_flags_true', $payload['violations']);
        $this->assertContains('runtime_promotion_receipt:verify_command_contains_persist_flag', $payload['violations']);
    }

    public function test_inspector_reports_portuguese_operator_placeholders_in_draft_workspace(): void
    {
        Storage::fake('local');
        $workspace = $this->writeWorkspace();

        $runtimePath = $workspace.'/runtime-promotion.json';
        Storage::disk('local')->put($runtimePath, $this->draftJson([
            'receipt_id' => 'runtime-test',
            'signed_by' => 'SEU_NOME',
            'reason' => 'MOTIVO REAL COM PELO MENOS 32 CARACTERES',
            'receipt_hash' => '<hash>',
        ]));

        $smokePath = $workspace.'/real-provider-smoke.json';
        Storage::disk('local')->put($smokePath, $this->draftJson([
            'provider_run_id' => 'substitua pelo provider run real',
            'task_packet_id' => 'substitua pelo task packet real',
            'observed_by' => 'SEU_NOME',
            'approval_reason' => 'MOTIVO REAL COM PELO MENOS 32 CARACTERES',
            'smoke_hash' => '<hash>',
        ]));

        $completionPath = $workspace.'/completion-receipt.json';
        Storage::disk('local')->put($completionPath, $this->draftJson([
            'receipt_id' => 'completion-test',
            'signed_by' => 'SEU_NOME',
            'reason' => 'MOTIVO REAL COM PELO MENOS 32 CARACTERES',
            'receipt_hash' => '<hash>',
        ]));

        $payload = (new AtlasSelfConstructionOperatorEvidenceDraftWorkspaceInspectorService)->inspect([
            'operator_draft_workspace_path' => $workspace,
        ]);

        $this->assertSame('workspace_safe_for_operator_editing', $payload['status']);
        $this->assertSame(0, $payload['violation_count']);
        $runtime = $this->fileReport($payload, 'runtime_promotion_receipt');
        $smoke = $this->fileReport($payload, 'real_provider_smoke');
        $completion = $this->fileReport($payload, 'human_completion_receipt');
        $this->assertContains('signed_by', $runtime['placeholders']);
        $this->assertContains('reason', $runtime['placeholders']);
        $this->assertContains('provider_run_id', $smoke['placeholders']);
        $this->assertContains('task_packet_id', $smoke['placeholders']);
        $this->assertContains('observed_by', $smoke['placeholders']);
        $this->assertContains('approval_reason', $smoke['placeholders']);
        $this->assertContains('signed_by', $completion['placeholders']);
        $this->assertContains('reason', $completion['placeholders']);
        $this->assertFalse((bool) $payload['completion_claim_allowed']);
        $this->assertFalse((bool) $payload['runtime_write_allowed']);
    }

    /** @param array<string, mixed> $payload */
    private function fileReport(array $payload, string $artifact): array
    {
        foreach ((array) $payload['files'] as $file) {
            if ((string) ($file['artifact'] ?? '') === $artifact) {
                return (array) $file;
            }
        }

        $this->fail('Missing file report for '.$artifact);
    }

    public function test_readiness_and_cli_quartet_are_registered(): void
    {
        Storage::fake('local');

        $status = app(AtlasSelfConstructionReadinessService::class)->atlasSelfConstructionOperatorEvidenceDraftWorkspaceInspectorStatus();
        $this->assertSame('atlas.self_construction_agent_control_plane_atlas_self_construction_operator_evidence_draft_workspace_inspector_status.v1', $status['schema_version']);
        $this->assertSame('no_workspace', $status['status']);
        $this->assertFalse($status['dispatch_allowed']);

        foreach ([
            'atlas-self-construction-operator-evidence-draft-workspace-inspector-contract',
            'atlas-self-construction-operator-evidence-draft-workspace-inspector-preflight',
            'atlas-self-construction-operator-evidence-draft-workspace-inspector-implementation-packet',
            'atlas-self-construction-operator-evidence-draft-workspace-inspector-status',
        ] as $option) {
            $exit = Artisan::call('atlas:ai:self-construction', [
                '--'.$option => true,
                '--json' => true,
            ]);
            $this->assertSame(0, $exit);
            $decoded = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
            $this->assertFalse($decoded['dispatch_allowed']);
        }

        $capabilities = (array) data_get(app(AtlasSelfConstructionReadinessService::class)->agentControlPlane(), 'control_plane.current_capability', []);
        foreach ([
            'atlas_self_construction_operator_evidence_draft_workspace_inspector_contract',
            'atlas_self_construction_operator_evidence_draft_workspace_inspector_preflight',
            'atlas_self_construction_operator_evidence_draft_workspace_inspector_implementation_packet',
            'atlas_self_construction_operator_evidence_draft_workspace_inspector_service',
            'atlas_self_construction_operator_evidence_draft_workspace_inspector_status_projection',
        ] as $capability) {
            $this->assertContains($capability, $capabilities);
        }
    }

    private function writeWorkspace(): string
    {
        $workspace = 'atlas/self-construction/operator-submissions/draft-workspaces/20260515-000000-test';
        $artifacts = [
            'runtime_promotion_receipt' => ['receipt_id' => 'runtime-test', 'signed_by' => '<operator>', 'receipt_hash' => '<hash>'],
            'real_provider_smoke' => ['provider_run_id' => '<provider_run_id>', 'smoke_hash' => '<hash>'],
            'human_completion_receipt' => ['receipt_id' => 'completion-test', 'signed_by' => '<operator>', 'receipt_hash' => '<hash>'],
        ];
        $files = [];

        foreach ($artifacts as $artifact => $payload) {
            $filename = match ($artifact) {
                'runtime_promotion_receipt' => 'runtime-promotion.json',
                'real_provider_smoke' => 'real-provider-smoke.json',
                default => 'completion-receipt.json',
            };
            $path = $workspace.'/'.$filename;
            $json = $this->draftJson($payload);
            Storage::disk('local')->put($path, $json);
            $files[] = [
                'artifact' => $artifact,
                'draft_path' => $path,
                'payload_template_json_sha256' => hash('sha256', $json),
                'draft_file_sha256' => hash('sha256', $json),
                'command_to_verify_draft_file' => 'php artisan atlas:ai:self-construction --atlas-self-construction-os-completion-evidence-status --json',
                'draft_is_evidence' => false,
                'can_persist_draft_directly' => false,
            ];
        }

        Storage::disk('local')->put($workspace.'/manifest.json', $this->draftJson([
            'schema_version' => 'atlas.self_construction.operator_evidence_draft_workspace.v1',
            'workspace_directory' => $workspace,
            'files' => $files,
        ]));

        return $workspace;
    }

    /** @param array<string, mixed> $payload */
    private function draftJson(array $payload): string
    {
        ksort($payload);

        return (string) json_encode($payload, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
