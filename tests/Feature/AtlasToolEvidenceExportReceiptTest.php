<?php

namespace Tests\Feature;

use App\Services\Tools\AtlasToolEvidenceQueryService;
use App\Services\Tools\AtlasToolEvidenceStore;
use Illuminate\Support\Facades\File;
use Tests\Concerns\CreatesAtlasToolRuntimeTables;
use Tests\TestCase;

class AtlasToolEvidenceExportReceiptTest extends TestCase
{
    use CreatesAtlasToolRuntimeTables;

    private string $workspace;

    protected function setUp(): void
    {
        parent::setUp();

        $this->workspace = sys_get_temp_dir().'/atlas-tool-evidence-export-'.bin2hex(random_bytes(4));
        File::ensureDirectoryExists($this->workspace);
        $this->createAtlasToolRuntimeTables();
    }

    protected function tearDown(): void
    {
        $this->dropAtlasToolRuntimeTables();
        File::deleteDirectory($this->workspace);

        parent::tearDown();
    }

    public function test_exported_tool_evidence_exposes_receipt_hashes_without_raw_runtime_payload(): void
    {
        $run = app(AtlasToolEvidenceStore::class)->recordExternalToolResult('semgrep', $this->workspace, [
            'status' => 'passed',
            'required' => true,
            'duration_ms' => 42,
            'command' => ['semgrep', '--config', 'auto', $this->workspace],
            'summary' => ['ok' => true],
            'findings' => [],
        ], [
            'run_context_type' => 'engineering_run',
            'run_context_id' => 'run-export-1',
            'envelope_id' => 'engineering_run:run-export-1',
            'tenant_id' => 'tenant_tools',
            'operator_id' => 'operator_tools',
        ]);

        $this->assertNotNull($run);

        $export = app(AtlasToolEvidenceQueryService::class)->exportRun($run->id, [
            'workspace' => $this->workspace,
        ]);

        $this->assertSame('atlas.tool_evidence.v1', data_get($export, 'schema'));
        $this->assertSame('atlas.tool_evidence_receipt.v1', data_get($export, 'receipt.schema_version'));
        $this->assertSame($run->id, data_get($export, 'receipt.tool_run_id'));
        $this->assertSame($run->workspace_hash, data_get($export, 'receipt.workspace_hash'));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) data_get($export, 'receipt.summary_hash'));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) data_get($export, 'receipt.normalized_result_hash'));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) data_get($export, 'receipt.evidence_receipt_hash'));
        $this->assertFalse(data_get($export, 'receipt.raw_command_exposed'));
        $this->assertFalse(data_get($export, 'receipt.raw_output_exposed'));
        $this->assertFalse(data_get($export, 'receipt.workspace_path_exposed'));
        $this->assertFalse(data_get($export, 'receipt.provider_dispatch_allowed'));
        $this->assertFalse(data_get($export, 'receipt.runtime_policy_mutation_allowed'));

        $this->assertSame(data_get($export, 'receipt.evidence_receipt_hash'), data_get($export, 'integrity.evidence_receipt_hash'));
        $this->assertSame(data_get($export, 'receipt.summary_hash'), data_get($export, 'run.metadata.summary_hash'));
        $this->assertArrayNotHasKey('workspace', data_get($export, 'run'));
        $this->assertStringNotContainsString($this->workspace, json_encode($export, JSON_THROW_ON_ERROR));
    }
}
