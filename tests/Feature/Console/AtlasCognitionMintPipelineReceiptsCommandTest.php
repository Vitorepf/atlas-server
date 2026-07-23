<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\AtlasAaeosTestRunReceipt;
use App\Models\AtlasEngineeringCodeSymbol;
use App\Services\Ai\AgenticEngineeringOs\Maturity\AtlasImplementationTruthService;
use App\Services\Ai\AgenticEngineeringOs\Maturity\AtlasCapabilityTestExecutionService;
use App\Services\Ai\Cognition\AtlasCognitionEvidenceResolver;
use App\Services\Ai\Cognition\AtlasCognitionScoreCardService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
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
    /** @var list<string> */
    private array $tempFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            @unlink($file);
        }
        parent::tearDown();
    }

    public function test_orchestration_runs_only_resolved_refs_respects_limit_and_counts_green(): void
    {
        // Fake truth: 3 capabilities, uma com ref não-resolvida (deve ser pulada).
        $truth = new class extends AtlasImplementationTruthService
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
        $execution = new class extends AtlasCapabilityTestExecutionService
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

            public function verifyGreenMintSeal(string $capabilityId, string $testRef, array $evidenceRefs): array
            {
                return [
                    'sealed' => $testRef === 'AlphaTest',
                    'born_stale' => $testRef !== 'AlphaTest',
                    'fresh_hashes' => ['test_file_hash' => 'h', 'impl_files_hash' => 'i'],
                    'explain' => null,
                    'git_porcelain' => null,
                ];
            }
        };

        $this->app->instance(AtlasImplementationTruthService::class, $truth);
        $this->app->instance(AtlasCapabilityTestExecutionService::class, $execution);

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
        $truth = new class extends AtlasImplementationTruthService
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
        $execution = new class extends AtlasCapabilityTestExecutionService
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

            public function verifyGreenMintSeal(string $capabilityId, string $testRef, array $evidenceRefs): array
            {
                return [
                    'sealed' => true,
                    'born_stale' => false,
                    'fresh_hashes' => ['test_file_hash' => null, 'impl_files_hash' => null],
                    'explain' => null,
                    'git_porcelain' => null,
                ];
            }
        };
        $this->app->instance(AtlasImplementationTruthService::class, $truth);
        $this->app->instance(AtlasCapabilityTestExecutionService::class, $execution);

        Artisan::call('atlas:cognition:mint-pipeline-receipts', ['--limit' => 3]);

        $this->assertSame(3, $execution->calls, '--limit=3 verifica exatamente 3, não as 10');
    }

    public function test_born_stale_seal_rejects_green_in_summary_and_returns_non_zero_exit(): void
    {
        $truth = new class extends AtlasImplementationTruthService
        {
            public function __construct()
            {
            }

            public function capabilityTestRefs(?string $capability = null): array
            {
                return [
                    ['capability_id' => 'cap-stale', 'owner_doc' => 'stale.md', 'evidence_refs' => [['kind' => 'symbol', 'ref' => 'StaleImpl']], 'test_refs' => [['ref' => 'StaleTest', 'index_resolved' => true, 'matched' => 'StaleTest']]],
                ];
            }

            public function freshnessHashes(array $evidenceRefs, string $testRef): array
            {
                return ['test_file_hash' => 'mint-test', 'impl_files_hash' => 'mint-impl'];
            }
        };

        $execution = new class extends AtlasCapabilityTestExecutionService
        {
            public function __construct()
            {
            }

            public function runAndRecord(string $capabilityId, string $testRef, ?string $explicitPath = null, ?string $testFileHash = null, ?string $implFilesHash = null): array
            {
                return ['passed' => true, 'tests_run' => 2];
            }

            public function verifyGreenMintSeal(string $capabilityId, string $testRef, array $evidenceRefs): array
            {
                return [
                    'sealed' => false,
                    'born_stale' => true,
                    'fresh_hashes' => ['test_file_hash' => 'fresh-test', 'impl_files_hash' => 'fresh-impl'],
                    'explain' => ['format' => 'atlas.aaeos.impl_files_hash.v2', 'paths' => ['app/Foo.php' => 'deadbeef']],
                    'git_porcelain' => ' M app/Foo.php',
                ];
            }
        };

        $this->app->instance(AtlasImplementationTruthService::class, $truth);
        $this->app->instance(AtlasCapabilityTestExecutionService::class, $execution);

        $out = new BufferedOutput;
        $exit = Artisan::call('atlas:cognition:mint-pipeline-receipts', ['--limit' => 1, '--json' => true], $out);
        $report = json_decode($out->fetch(), true);

        $this->assertSame(1, $exit);
        $this->assertSame(0, $report['green_receipts_minted']);
        $this->assertSame(1, $report['born_stale_count']);
        $this->assertTrue($report['minted'][0]['born_stale']);
        $this->assertSame(' M app/Foo.php', $report['minted'][0]['seal']['git_porcelain']);
    }

    public function test_dry_run_lists_nine_v4_supplemental_partial_targets_without_running_or_persisting(): void
    {
        $services = [];
        $caps = [];
        for ($i = 1; $i <= 9; $i++) {
            $short = 'Pip05SupplementalService'.$i;
            $service = 'App\\Services\\Ai\\Cognition\\'.$short;
            $testRef = $short.'Test';
            $testRel = 'tests/Fixtures/Pip05/'.$testRef.'.php';
            @mkdir(dirname(base_path($testRel)), 0775, true);
            file_put_contents(base_path($testRel), "<?php\nfinal class {$testRef} { public function test_binding(): void { {$short}::class; } }\n");
            $this->tempFiles[] = base_path($testRel);

            $services[] = $service;
            $caps[$service] = [
                'capability_id' => 'pip05.cap-'.$i,
                'owner_doc' => 'docs/engineering-knowledge-base/pip05-'.$i.'.md',
                'evidence_refs' => [['kind' => 'symbol', 'ref' => $short]],
                'test_refs' => [['ref' => $testRef, 'index_resolved' => true, 'matched' => $testRef, 'file_path' => $testRel]],
            ];
        }

        $truth = new class($caps) extends AtlasImplementationTruthService
        {
            public function __construct(private readonly array $caps) {}

            public function capabilityTestRefs(?string $capability = null): array
            {
                return array_values($this->caps);
            }

            public function freshnessHashes(array $evidenceRefs, string $testRef): array
            {
                return ['test_file_hash' => 'dry-test', 'impl_files_hash' => 'dry-impl'];
            }
        };
        $resolver = new class($caps) extends AtlasCognitionEvidenceResolver
        {
            public function __construct(private readonly array $caps) {}

            public function ownerCapabilityIdsForFqn(?string $serviceClass): array
            {
                return isset($this->caps[$serviceClass]) ? [$this->caps[$serviceClass]['capability_id']] : [];
            }
        };
        $execution = new class extends AtlasCapabilityTestExecutionService
        {
            public int $calls = 0;

            public function __construct() {}

            public function runAndRecord(string $capabilityId, string $testRef, ?string $explicitPath = null, ?string $testFileHash = null, ?string $implFilesHash = null): array
            {
                $this->calls++;

                return ['passed' => true, 'tests_run' => 1];
            }
        };
        $scorecard = new class($services) extends AtlasCognitionScoreCardService
        {
            public function __construct(private readonly array $services) {}

            public function build(): array
            {
                return [
                    'score' => ['dimensions' => ['pipeline' => ['score_out_of_10' => 0.0]]],
                    'subsystems' => [],
                    'v4' => [
                        'modules' => array_map(
                            static fn (string $service): array => [
                                'acronym' => class_basename($service),
                                'pipeline_status' => AtlasCognitionScoreCardService::STATUS_PARTIAL,
                                'supplemental_count' => 1,
                                'service_classes' => [$service],
                                'members' => [class_basename($service)],
                            ],
                            $this->services,
                        ),
                    ],
                ];
            }
        };

        $this->app->instance(AtlasImplementationTruthService::class, $truth);
        $this->app->instance(AtlasCapabilityTestExecutionService::class, $execution);
        $this->app->instance(AtlasCognitionScoreCardService::class, $scorecard);
        $this->app->instance(AtlasCognitionEvidenceResolver::class, $resolver);

        $out = new BufferedOutput;
        $exit = Artisan::call('atlas:cognition:mint-pipeline-receipts', ['--limit' => 20, '--dry-run' => true, '--json' => true], $out);
        $report = json_decode($out->fetch(), true);

        $this->assertSame(0, $exit);
        $this->assertTrue($report['dry_run']);
        $this->assertSame(9, $report['dry_run_target_count']);
        $this->assertCount(9, $report['dry_run_targets']);
        $this->assertSame(0, $execution->calls, 'dry-run lists targets without running tests or writing receipts');
    }

    public function test_v4_supplemental_target_rejects_test_ref_that_does_not_bind_to_service_class(): void
    {
        $service = 'App\\Services\\Ai\\Cognition\\Pip05BoundService';
        $testRel = 'tests/Fixtures/Pip05/Pip05BoundServiceTest.php';
        @mkdir(dirname(base_path($testRel)), 0775, true);
        file_put_contents(base_path($testRel), "<?php\nfinal class Pip05BoundServiceTest { public function test_unbound(): void { self::assertTrue(true); } }\n");
        $this->tempFiles[] = base_path($testRel);

        $cap = [
            'capability_id' => 'pip05.unbound',
            'owner_doc' => 'docs/engineering-knowledge-base/pip05-unbound.md',
            'evidence_refs' => [['kind' => 'symbol', 'ref' => 'Pip05BoundService']],
            'test_refs' => [['ref' => 'Pip05BoundServiceTest', 'index_resolved' => true, 'matched' => 'Pip05BoundServiceTest', 'file_path' => $testRel]],
        ];
        $truth = new class($cap) extends AtlasImplementationTruthService
        {
            public function __construct(private readonly array $cap) {}

            public function capabilityTestRefs(?string $capability = null): array
            {
                return [$this->cap];
            }

            public function freshnessHashes(array $evidenceRefs, string $testRef): array
            {
                return ['test_file_hash' => 'unbound-test', 'impl_files_hash' => 'unbound-impl'];
            }
        };
        $resolver = new class($cap, $service) extends AtlasCognitionEvidenceResolver
        {
            public function __construct(private readonly array $cap, private readonly string $service) {}

            public function ownerCapabilityIdsForFqn(?string $serviceClass): array
            {
                return $serviceClass === $this->service ? [$this->cap['capability_id']] : [];
            }
        };
        $execution = new class extends AtlasCapabilityTestExecutionService
        {
            public int $calls = 0;

            public function __construct() {}

            public function runAndRecord(string $capabilityId, string $testRef, ?string $explicitPath = null, ?string $testFileHash = null, ?string $implFilesHash = null): array
            {
                $this->calls++;

                return ['passed' => true, 'tests_run' => 1];
            }
        };
        $scorecard = new class($service) extends AtlasCognitionScoreCardService
        {
            public function __construct(private readonly string $service) {}

            public function build(): array
            {
                return [
                    'score' => ['dimensions' => ['pipeline' => ['score_out_of_10' => 0.0]]],
                    'subsystems' => [],
                    'v4' => [
                        'modules' => [[
                            'acronym' => 'PIP05-UNBOUND',
                            'pipeline_status' => AtlasCognitionScoreCardService::STATUS_PARTIAL,
                            'supplemental_count' => 1,
                            'service_classes' => [$this->service],
                            'members' => ['PIP05-UNBOUND'],
                        ]],
                    ],
                ];
            }
        };

        $this->app->instance(AtlasImplementationTruthService::class, $truth);
        $this->app->instance(AtlasCapabilityTestExecutionService::class, $execution);
        $this->app->instance(AtlasCognitionScoreCardService::class, $scorecard);
        $this->app->instance(AtlasCognitionEvidenceResolver::class, $resolver);

        $out = new BufferedOutput;
        $exit = Artisan::call('atlas:cognition:mint-pipeline-receipts', ['--limit' => 1, '--dry-run' => true, '--json' => true], $out);
        $report = json_decode($out->fetch(), true);

        $this->assertSame(0, $exit);
        $this->assertSame(0, $report['dry_run_target_count']);
        $this->assertSame(1, $report['binding_rejection_count']);
        $this->assertSame('test_ref_not_bound_to_service_class', $report['binding_rejections'][0]['reason']);
        $this->assertSame(0, $execution->calls, 'unbound test refs are rejected before any mint attempt');
    }

    public function test_verify_green_mint_seal_detects_index_drift_after_receipt(): void
    {
        $this->bootSealTables();
        $implRel = 'tests/Fixtures/Pip02Seal/PIP02SealProof.php';
        $testRel = 'tests/Fixtures/Pip02Seal/PIP02SealProofTest.php';
        @mkdir(dirname(base_path($implRel)), 0775, true);
        file_put_contents(base_path($implRel), "<?php\nclass PIP02SealProof {}\n");
        file_put_contents(base_path($testRel), "<?php\nclass PIP02SealProofTest {}\n");
        $this->tempFiles[] = base_path($implRel);
        $this->tempFiles[] = base_path($testRel);

        AtlasEngineeringCodeSymbol::query()->create([
            'symbol_type' => 'class',
            'symbol_name' => 'App\\Services\\Ai\\Aaeos\\PIP02SealProof',
            'file_path' => $implRel,
            'language' => 'php',
            'status' => 'active',
            'docs_status' => 'documented',
            'source_hash' => 'pip02-seal-impl',
        ]);
        AtlasEngineeringCodeSymbol::query()->create([
            'symbol_type' => 'test_method',
            'symbol_name' => 'Tests\\Unit\\Ai\\Aaeos\\PIP02SealProofTest::test_green',
            'file_path' => $testRel,
            'language' => 'php',
            'status' => 'active',
            'docs_status' => 'documented',
            'source_hash' => 'pip02-seal-test',
        ]);

        $evidenceRefs = [['kind' => 'symbol', 'ref' => 'PIP02SealProof']];
        $truth = new AtlasImplementationTruthService(
            new \App\Services\Ai\AgenticEngineeringOs\Maturity\AtlasImplementationEvidenceResolver,
            new \App\Services\Semantic\CanonicalDocsFrontmatterParser,
            new AtlasCapabilityTestExecutionService,
        );
        $hashes = $truth->freshnessHashes($evidenceRefs, 'PIP02SealProofTest');
        $execution = new AtlasCapabilityTestExecutionService;

        AtlasAaeosTestRunReceipt::query()->create([
            'capability_id' => 'pip02.seal-proof',
            'test_ref' => 'PIP02SealProofTest',
            'filter' => 'PIP02SealProofTest',
            'passed' => true,
            'tests_run' => 1,
            'exit_code' => 0,
            'test_file_hash' => $hashes['test_file_hash'],
            'impl_files_hash' => $hashes['impl_files_hash'],
            'output_tail' => 'OK (1 test)',
            'runner' => 'phpunit',
            'ran_at' => now(),
        ]);

        $fresh = $execution->verifyGreenMintSeal('pip02.seal-proof', 'PIP02SealProofTest', $evidenceRefs);
        $this->assertTrue($fresh['sealed']);
        $this->assertFalse($fresh['born_stale']);

        file_put_contents(base_path($implRel), "<?php\nclass PIP02SealProof { public int \$drift = 1; }\n");

        $stale = $execution->verifyGreenMintSeal('pip02.seal-proof', 'PIP02SealProofTest', $evidenceRefs);
        $this->assertFalse($stale['sealed']);
        $this->assertTrue($stale['born_stale']);
        $this->assertNotNull($stale['explain']);
    }

    private function bootSealTables(): void
    {
        if (! Schema::hasTable('atlas_aaeos_test_run_receipts')) {
            (require base_path('database/migrations/2026_06_02_090000_create_atlas_aaeos_test_run_receipts_table.php'))->up();
        }
        if (! Schema::hasTable('atlas_engineering_code_symbols')) {
            Schema::create('atlas_engineering_code_symbols', function ($table): void {
                $table->uuid('id')->primary();
                $table->string('symbol_type', 40);
                $table->string('symbol_name', 512);
                $table->string('file_path', 512)->nullable();
                $table->string('language', 16)->default('php');
                $table->string('status', 24)->default('active');
                $table->string('docs_status', 24)->default('undocumented');
                $table->string('source_hash', 128)->nullable();
                $table->timestamps();
            });
        }
    }
}
