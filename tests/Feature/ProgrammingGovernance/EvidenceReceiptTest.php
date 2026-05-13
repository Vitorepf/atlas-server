<?php

namespace Tests\Feature\ProgrammingGovernance;

use App\Models\AtlasEngineeringEvidence;
use App\Models\AtlasProgrammingWorkItem;
use Illuminate\Support\Facades\Artisan;
use Tests\Concerns\CreatesAtlasProgrammingGovernanceTables;
use Tests\TestCase;

class EvidenceReceiptTest extends TestCase
{
    use CreatesAtlasProgrammingGovernanceTables;

    private string $workspace = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->createAtlasProgrammingGovernanceTables();
        $this->workspace = $this->makeProgrammingWorkspace([
            'app/Services/Foo.php',
            'app/X.php',
            'app/Y.php',
        ]);
    }

    protected function tearDown(): void
    {
        $this->cleanupProgrammingWorkspaces();
        $this->dropAtlasProgrammingGovernanceTables();
        parent::tearDown();
    }

    public function test_receipt_without_mechanical_proof_is_rejected(): void
    {
        $code = $this->intake('Conserte typo');

        $exit = Artisan::call('atlas:programming:receipt', [
            'work_item' => $code,
            '--summary' => 'Tudo certo!',
            '--json' => true,
        ]);

        $this->assertSame(1, $exit, 'narrative-only summary must not count as evidence');
        $this->assertDatabaseCount('atlas_engineering_evidence', 0);
    }

    public function test_receipt_with_command_and_output_is_accepted(): void
    {
        $code = $this->intake('Conserte typo');

        $exit = Artisan::call('atlas:programming:receipt', [
            'work_item' => $code,
            '--command' => 'vendor/bin/phpunit tests/Unit',
            '--output' => 'OK (29 tests, 41 assertions)',
            '--file' => ['app/Services/Foo.php'],
            '--test' => ['Tests\\Unit\\FooTest'],
            '--json' => true,
        ]);

        $this->assertSame(0, $exit);

        $payload = json_decode(Artisan::output(), true);
        $this->assertSame('passed', $payload['receipt']['status']);
        $this->assertNotEmpty($payload['receipt']['receipt_id']);
        $this->assertSame(1, AtlasEngineeringEvidence::query()->count());

        $item = AtlasProgrammingWorkItem::query()->firstOrFail();
        $this->assertCount(1, $item->evidence_refs_json);
        $this->assertSame('verifying', $item->status);
    }

    public function test_receipt_persists_to_engineering_evidence_with_metadata(): void
    {
        $code = $this->intake('Refatorar runner');

        Artisan::call('atlas:programming:receipt', [
            'work_item' => $code,
            '--command' => 'phpunit tests/Feature',
            '--output' => 'green',
            '--file' => ['app/X.php', 'app/Y.php'],
            '--gap' => ['frontend_smoke_pending'],
            '--json' => true,
        ]);

        $row = AtlasEngineeringEvidence::query()->firstOrFail();
        $this->assertSame('atlas_programming_governance', $row->source);
        $this->assertSame(['app/X.php', 'app/Y.php'], $row->files);
        $this->assertSame('phpunit tests/Feature', $row->command);
        $this->assertContains('frontend_smoke_pending', $row->metadata['gaps']);
    }

    private function intake(string $intent): string
    {
        Artisan::call('atlas:programming:intake', [
            'intent' => $intent,
            '--workspace' => $this->workspace,
            '--json' => true,
        ]);

        return (string) json_decode(Artisan::output(), true)['code'];
    }
}
