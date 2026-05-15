<?php

namespace Tests\Feature\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\AtlasSelfConstructionRealProviderSmokeOperatorRunbookExporterService;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class AtlasSelfConstructionRealProviderSmokeOperatorRunbookExporterTest extends TestCase
{
    public function test_export_returns_available_status_with_all_phases(): void
    {
        $result = $this->exporter()->build();

        $this->assertSame(AtlasSelfConstructionRealProviderSmokeOperatorRunbookExporterService::SCHEMA_VERSION, $result['schema_version']);
        $this->assertSame('available', $result['status']);
        $this->assertSame([
            'before_execution',
            'during_execution',
            'after_execution',
            'payload_submission',
            'persistence',
            'audit_rerun',
        ], $result['phase_ids']);
    }

    public function test_markdown_is_rendered_with_phase_headers(): void
    {
        $result = $this->exporter()->build();
        $markdown = (string) $result['markdown'];

        $this->assertStringContainsString('# Atlas Self-Construction OS', $markdown);
        $this->assertStringContainsString('## Before execution', $markdown);
        $this->assertStringContainsString('## Audit rerun', $markdown);
        $this->assertStringContainsString('## Stop conditions', $markdown);
    }

    public function test_machine_json_contains_phases_and_stop_conditions(): void
    {
        $result = $this->exporter()->build();
        $machine = (array) $result['machine_json'];

        $this->assertArrayHasKey('phases', $machine);
        $this->assertArrayHasKey('stop_conditions', $machine);
        $this->assertNotEmpty($machine['phases']);
        $this->assertNotEmpty($machine['stop_conditions']);
    }

    public function test_default_export_does_not_persist_anything(): void
    {
        Storage::fake('local');
        $result = $this->exporter()->build();

        $this->assertFalse((bool) $result['persist_export_requested']);
        $this->assertFalse((bool) $result['export_persisted']);
        $this->assertSame('', (string) $result['export_path']);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_persist_export_writes_runbook_artifact_only(): void
    {
        Storage::fake('local');
        $result = $this->exporter()->build(['persist_export' => true]);

        $this->assertTrue((bool) $result['persist_export_requested']);
        $this->assertTrue((bool) $result['export_persisted']);
        $this->assertNotSame('', (string) $result['export_path']);
        $this->assertTrue(Storage::disk('local')->exists((string) $result['export_path']));

        // ensure no real provider smoke evidence file was created
        $files = Storage::disk('local')->allFiles();
        $smokeArtifacts = array_filter(
            $files,
            static fn (string $path): bool => str_contains($path, 'os-completion/real-provider-smokes'),
        );
        $this->assertSame([], array_values($smokeArtifacts));
    }

    public function test_exporter_hash_is_64_hex(): void
    {
        $result = $this->exporter()->build();

        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) $result['exporter_hash']);
        $this->assertFalse((bool) $result['evidence_persistence_allowed_here']);
    }

    public function test_stop_conditions_include_persistence_invariants(): void
    {
        $result = $this->exporter()->build();
        $stop = (array) $result['stop_conditions'];

        $this->assertContains('never_persist_without_explicit_flag', $stop);
        $this->assertContains('stop_if_any_endgame_service_persists_evidence', $stop);
        $this->assertContains('stop_if_atlas_kernel_is_calling_provider', $stop);
    }

    private function exporter(): AtlasSelfConstructionRealProviderSmokeOperatorRunbookExporterService
    {
        return new AtlasSelfConstructionRealProviderSmokeOperatorRunbookExporterService;
    }
}
