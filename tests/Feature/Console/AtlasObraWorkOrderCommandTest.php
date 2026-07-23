<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Services\Ai\SelfConstruction\AtlasTaskPacketQualityInspector;
use Tests\TestCase;

/**
 * K2 (Obra #18) — the scaffolder generates a Kit-conformant work-order, refuses
 * phantom references, and its output passes the K3 linter (end-to-end proof).
 */
class AtlasObraWorkOrderCommandTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/atlas-wo-'.uniqid('', true);
        mkdir($this->dir, 0775, true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->dir));
        parent::tearDown();
    }

    /**
     * @param  array<string,mixed>  $descriptor
     */
    private function descriptor(array $descriptor): string
    {
        $path = $this->dir.'/slice.json';
        file_put_contents($path, (string) json_encode($descriptor));

        return $path;
    }

    public function test_generates_valid_work_order_with_auto_filled_frozen_callers(): void
    {
        $slice = $this->descriptor([
            'objective' => 'query-aware retrieval',
            'allowed_files' => ['app/Services/Ai/Memory/AtlasMemoryRegistryService.php'],
            'forbidden_files' => ['app/Services/Ai/Memory/AtlasMemoryVectorSearchService.php'],
            'acceptance_criteria' => ['php artisan test'],
            'acceptance_test_ref' => ['path' => 'tests/Feature/Ai/Brain/EvolutionDiaryTest.php'],
            'glossary' => ['REG' => 'app/Services/Ai/Memory/AtlasMemoryRegistryService.php'],
            'freeze_callers_of' => ['AtlasMemoryRegistryService'],
        ]);
        $out = $this->dir.'/wo.json';

        $this->artisan('atlas:obra:work-order', ['slice' => $slice, '--out' => $out])->assertExitCode(0);

        $wo = json_decode((string) file_get_contents($out), true);
        $this->assertNotSame('', $wo['acceptance_test_ref']['hash'], 'the acceptance test must be hashed for K4.');
        $this->assertNotEmpty($wo['frozen_callers'], 'callers of a real symbol must be auto-filled.');
        $this->assertSame(['REG' => 'app/Services/Ai/Memory/AtlasMemoryRegistryService.php'], $wo['glossary']);
    }

    public function test_refuses_a_phantom_path(): void
    {
        $slice = $this->descriptor([
            'objective' => 'x',
            'glossary' => ['GHOST' => 'app/Services/Ai/DoesNotExistPhantom.php'],
        ]);

        $this->artisan('atlas:obra:work-order', ['slice' => $slice])->assertExitCode(1);
    }

    public function test_refuses_a_phantom_symbol(): void
    {
        $slice = $this->descriptor([
            'objective' => 'x',
            'freeze_callers_of' => ['ZzzPhantomSymbolXyzzyNeverDefined'],
        ]);

        $this->artisan('atlas:obra:work-order', ['slice' => $slice])->assertExitCode(1);
    }

    public function test_generated_work_order_passes_the_k3_linter(): void
    {
        $slice = $this->descriptor([
            'objective' => 'query-aware retrieval',
            'allowed_files' => ['app/Services/Ai/NewThing.php'],
            'forbidden_files' => ['app/Services/Ai/Memory/AtlasMemoryVectorSearchService.php'],
            'acceptance_criteria' => ['php artisan test'],
            'required_evidence' => ['tests_or_gates_result'],
            'acceptance_test_ref' => ['path' => 'tests/Feature/Ai/Brain/EvolutionDiaryTest.php'],
            'glossary' => ['REG' => 'app/Services/Ai/Memory/AtlasMemoryRegistryService.php'],
        ]);
        $out = $this->dir.'/wo.json';
        $this->artisan('atlas:obra:work-order', ['slice' => $slice, '--out' => $out])->assertExitCode(0);

        $wo = json_decode((string) file_get_contents($out), true);
        // Feed the generated WO to the linter with the evidence a cold worker needs.
        $wo['required_evidence'] = ['tests_or_gates_result'];
        $result = (new AtlasTaskPacketQualityInspector)->inspect($wo);

        foreach (['glossary_sigla_without_path', 'cited_path_missing', 'kit_order_missing_pre_written_test', 'acceptance_cites_unlisted_file'] as $code) {
            $this->assertNotContains($code, $result['deficiencies'], "K2 output must satisfy K3: {$code}");
        }
    }
}
