<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Proves the runtime-promotion operator runbook exporter is live at the operator surface and emits
 * deterministic facts: by default it renders + exports the runbook markdown to the local disk and reports the
 * path; with persist_export=false it renders in-memory only. The export is its only write — every execution
 * guarantee stays OFF.
 */
final class AtlasLoopRunbookExportCommandTest extends TestCase
{
    public function test_exports_runbook_and_reports_path(): void
    {
        Storage::fake('local');

        $exit = Artisan::call('atlas:loop:runbook-export', ['--json' => true]);
        $decoded = json_decode(trim(Artisan::output()), true);

        $this->assertSame(0, $exit);
        $this->assertSame('atlas.self_construction.runtime_promotion_operator_runbook_exporter.v1', $decoded['schema_version']);
        $this->assertSame('read_only_runtime_promotion_operator_runbook_exporter', $decoded['mode']);
        $this->assertSame('exported', $decoded['status']);
        $this->assertTrue($decoded['persist']);
        $this->assertNotSame('', $decoded['export_path']);
        $this->assertFalse($decoded['execution_allowed']);

        Storage::disk('local')->assertExists($decoded['export_path']);
    }

    public function test_in_memory_only_when_persist_disabled(): void
    {
        Storage::fake('local');

        $exit = Artisan::call('atlas:loop:runbook-export', [
            '--options' => json_encode(['persist_export' => false]),
            '--json' => true,
        ]);
        $decoded = json_decode(trim(Artisan::output()), true);

        $this->assertSame(0, $exit);
        $this->assertSame('available_in_memory_only', $decoded['status']);
        $this->assertFalse($decoded['persist']);
        $this->assertSame('', $decoded['export_path']);
        $this->assertNotSame('', $decoded['markdown']);
    }
}
