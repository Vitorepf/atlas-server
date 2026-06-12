<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Services\Ai\Aaeos\AtlasAaeosImplementationTruthService;
use App\Services\Ai\Aaeos\AtlasAaeosTestExecutionService;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

/**
 * L2-9: o orquestrador que sobe a dimensão pipeline do scorecard ACOS por evidência REAL
 * (green-run receipts). Congelado com fakes (sem spawn de PHPUnit no teste): a
 * orquestração respeita --limit, só conta receipts VERDES, pula refs não-resolvidas, e
 * reporta o lift medido antes/depois. O minting real já foi provado live (2 verdes,
 * tests_run 6+12).
 */
final class AtlasCognitionMintPipelineReceiptsCommandTest extends TestCase
{
    public function test_orchestration_runs_only_resolved_refs_respects_limit_and_counts_green(): void
    {
        // Fake truth: 3 capabilities, uma com ref não-resolvida (deve ser pulada).
        $truth = new class extends AtlasAaeosImplementationTruthService
        {
            public function __construct()
            {
            }

            public function capabilityTestRefs(?string $capability = null): array
            {
                return [
                    ['capability_id' => 'cap-a', 'owner_doc' => 'a.md', 'evidence_refs' => [], 'test_refs' => [['ref' => 'AlphaTest', 'index_resolved' => true, 'matched' => 'AlphaTest']]],
                    ['capability_id' => 'cap-b', 'owner_doc' => 'b.md', 'evidence_refs' => [], 'test_refs' => [['ref' => 'BetaTest', 'index_resolved' => false, 'matched' => null]]],
                    ['capability_id' => 'cap-c', 'owner_doc' => 'c.md', 'evidence_refs' => [], 'test_refs' => [['ref' => 'GammaTest', 'index_resolved' => true, 'matched' => 'GammaTest']]],
                ];
            }

            public function freshnessHashes(array $evidenceRefs, string $testRef): array
            {
                return ['test_file_hash' => 'h', 'impl_files_hash' => 'i'];
            }
        };

        // Fake execution: AlphaTest verde, GammaTest vermelho — sem PHPUnit real.
        $execution = new class extends AtlasAaeosTestExecutionService
        {
            public int $calls = 0;

            public function __construct()
            {
            }

            public function runAndRecord(string $capabilityId, string $testRef, ?string $explicitPath = null, ?string $testFileHash = null, ?string $implFilesHash = null): array
            {
                $this->calls++;

                return [
                    'passed' => $testRef === 'AlphaTest',
                    'tests_run' => $testRef === 'AlphaTest' ? 4 : 0,
                ];
            }
        };

        $this->app->instance(AtlasAaeosImplementationTruthService::class, $truth);
        $this->app->instance(AtlasAaeosTestExecutionService::class, $execution);

        $out = new BufferedOutput;
        $exit = Artisan::call('atlas:cognition:mint-pipeline-receipts', ['--limit' => 5, '--json' => true], $out);
        $report = json_decode($out->fetch(), true);

        $this->assertSame(0, $exit);
        // Só as 2 capabilities com ref RESOLVIDA foram verificadas (cap-b pulada).
        $this->assertSame(2, $execution->calls, 'ref não-resolvida nunca vira receipt');
        $this->assertSame(2, $report['capabilities_processed']);
        $this->assertSame(1, $report['green_receipts_minted'], 'só o verde conta (Alpha); Gamma vermelho não infla');
        $this->assertArrayHasKey('pipeline_lift', $report);
    }

    public function test_limit_bounds_the_pass(): void
    {
        $truth = new class extends AtlasAaeosImplementationTruthService
        {
            public function __construct()
            {
            }

            public function capabilityTestRefs(?string $capability = null): array
            {
                $caps = [];
                for ($i = 0; $i < 10; $i++) {
                    $caps[] = ['capability_id' => 'cap-'.$i, 'owner_doc' => $i.'.md', 'evidence_refs' => [], 'test_refs' => [['ref' => 'T'.$i.'Test', 'index_resolved' => true, 'matched' => 'T'.$i.'Test']]];
                }

                return $caps;
            }

            public function freshnessHashes(array $evidenceRefs, string $testRef): array
            {
                return ['test_file_hash' => null, 'impl_files_hash' => null];
            }
        };
        $execution = new class extends AtlasAaeosTestExecutionService
        {
            public int $calls = 0;

            public function __construct()
            {
            }

            public function runAndRecord(string $capabilityId, string $testRef, ?string $explicitPath = null, ?string $testFileHash = null, ?string $implFilesHash = null): array
            {
                $this->calls++;

                return ['passed' => true, 'tests_run' => 1];
            }
        };
        $this->app->instance(AtlasAaeosImplementationTruthService::class, $truth);
        $this->app->instance(AtlasAaeosTestExecutionService::class, $execution);

        Artisan::call('atlas:cognition:mint-pipeline-receipts', ['--limit' => 3]);

        $this->assertSame(3, $execution->calls, '--limit=3 verifica exatamente 3, não as 10');
    }
}
