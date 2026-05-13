<?php

namespace Tests\Feature\ProgrammingGovernance;

use App\Models\AtlasProgrammingWorkItem;
use App\Services\Ai\Kernel\Architecture\AtlasFeaturePlacementService;
use App\Services\Ai\Programming\Governance\ProgrammingSpecCompiler;
use App\Services\Engineering\EngineeringCodeIntelligenceService;
use Illuminate\Support\Facades\Artisan;
use Tests\Concerns\CreatesAtlasProgrammingGovernanceTables;
use Tests\TestCase;

class SpecCompilerTest extends TestCase
{
    use CreatesAtlasProgrammingGovernanceTables;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createAtlasProgrammingGovernanceTables();
        $this->bindStubServices();
    }

    protected function tearDown(): void
    {
        $this->dropAtlasProgrammingGovernanceTables();
        parent::tearDown();
    }

    public function test_compiler_emits_canonical_field_set(): void
    {
        $item = $this->makeItem('Adicionar nova feature de export pdf');
        $compiled = app(ProgrammingSpecCompiler::class)->compile($item);

        $spec = $compiled['spec'];
        foreach ([
            'objective', 'context', 'expected_behavior', 'likely_files',
            'inputs_outputs', 'risks', 'tests', 'evidence_required',
            'rollback', 'completion_criteria',
        ] as $field) {
            $this->assertArrayHasKey($field, $spec, "missing field {$field}");
        }
        $this->assertSame('atlas.programming.spec_compiled.v1', $compiled['schema_version']);
    }

    public function test_critic_flags_short_objective_and_empty_lists(): void
    {
        $compiler = app(ProgrammingSpecCompiler::class);
        $report = $compiler->critique([
            'spec' => [
                'objective' => 'x',
                'context' => 'y',
                'expected_behavior' => 'z',
                'likely_files' => [],
                'risks' => [],
                'tests' => [],
                'evidence_required' => [],
                'rollback' => 'q',
                'completion_criteria' => [],
            ],
        ]);

        $this->assertSame('rejected', $report['status']);
        $this->assertGreaterThanOrEqual(7, count($report['blocking_issues']));
    }

    public function test_critic_passes_clean_spec(): void
    {
        $item = $this->makeItem('Refatorar runner para suportar cobertura completa de testes');
        $compiled = app(ProgrammingSpecCompiler::class)->compile($item);
        $report = app(ProgrammingSpecCompiler::class)->critique($compiled);

        $this->assertNotSame('rejected', $report['status']);
    }

    public function test_critic_flags_vague_word_in_objective(): void
    {
        $report = app(ProgrammingSpecCompiler::class)->critique([
            'spec' => [
                'objective' => 'Implementar talvez algo no runner',
                'context' => 'context with enough text to pass',
                'expected_behavior' => 'expected_behavior with enough text',
                'likely_files' => ['app/x.php'],
                'risks' => ['regression'],
                'tests' => ['phpunit'],
                'evidence_required' => ['phpunit_green'],
                'rollback' => 'revert with enough text',
                'completion_criteria' => ['tests pass'],
            ],
        ]);

        $vagueIssues = array_filter($report['issues'], fn ($i) => str_starts_with($i['reason'] ?? '', 'vague_word:'));
        $this->assertNotEmpty($vagueIssues);
    }

    public function test_command_can_attach_compiled_spec(): void
    {
        $item = $this->makeItem('Refatorar runner para suportar cobertura completa de testes');

        $exit = Artisan::call('atlas:programming:spec-compile', [
            'work_item' => $item->code,
            '--attach' => true,
            '--critique' => true,
            '--strict' => true,
            '--json' => true,
        ]);

        $this->assertSame(0, $exit);
        $payload = json_decode(Artisan::output(), true);
        $this->assertArrayHasKey('attach', $payload);
        $item->refresh();
        $this->assertNotNull($item->spec_hash);
    }

    public function test_command_strict_blocks_on_rejected_critic(): void
    {
        // A work item without intent will compile to a near-empty spec → critic rejects.
        $item = AtlasProgrammingWorkItem::query()->create([
            'code' => 'EMPTY-'.bin2hex(random_bytes(4)),
            'intent_text' => 'x',
            'intent_type' => 'other',
            'scope_mode' => 'structural',
            'risk_level' => 'medium',
            'status' => 'spec_required',
            'current_stage' => 'intake',
            'placement_json' => [],
            'code_intelligence_json' => [],
            'spec_json' => [],
            'plan_json' => [],
            'tasks_json' => [],
            'evidence_refs_json' => [],
            'gaps_json' => [],
            'metadata_json' => [],
        ]);

        $exit = Artisan::call('atlas:programming:spec-compile', [
            'work_item' => $item->code,
            '--strict' => true,
            '--json' => true,
        ]);

        $this->assertSame(1, $exit, 'strict mode must fail when the critic rejects.');
    }

    private function makeItem(string $intent): AtlasProgrammingWorkItem
    {
        Artisan::call('atlas:programming:intake', ['intent' => $intent, '--json' => true]);
        $code = (string) json_decode(Artisan::output(), true)['code'];

        return AtlasProgrammingWorkItem::query()->where('code', $code)->firstOrFail();
    }

    private function bindStubServices(): void
    {
        $this->app->bind(AtlasFeaturePlacementService::class, function () {
            return new class extends AtlasFeaturePlacementService
            {
                public function __construct() {}

                public function place(string $intent, array $hints = []): array
                {
                    return [
                        'status' => 'ok',
                        'placement' => ['layer' => 'kernel', 'domain' => 'programming'],
                        'owner_docs' => [
                            ['path' => 'docs/engineering-knowledge-base/atlas-programming-governance-system.md', 'exists' => true],
                        ],
                    ];
                }
            };
        });

        $this->app->bind(EngineeringCodeIntelligenceService::class, function () {
            return new class extends EngineeringCodeIntelligenceService
            {
                public function __construct() {}

                public function summary(): array
                {
                    return ['status' => 'ready', 'module_count' => 23, 'symbol_count' => 39419, 'doc_link_count' => 61791];
                }
            };
        });
    }
}
