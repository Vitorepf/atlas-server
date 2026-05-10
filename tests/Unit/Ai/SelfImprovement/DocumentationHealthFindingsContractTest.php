<?php

namespace Tests\Unit\Ai\SelfImprovement;

use App\Services\Ai\Cognitive\ProductiveFailure\ProductiveFailureSessionRepository;
use App\Services\Ai\Context\LocalRagBenchmarkService;
use App\Services\Ai\Kernel\Architecture\AtlasAiArchitectureValidationService;
use App\Services\Ai\Kernel\Architecture\AtlasArchitectureOperationsCatalog;
use App\Services\Ai\Kernel\Architecture\AtlasRivalsStrategyReadModel;
use App\Services\Ai\Kernel\Decision\DynamicComputeMarketAdvisor;
use App\Services\Ai\Kernel\Domain\AtlasAiDomainCatalogService;
use App\Services\Ai\Kernel\Evidence\AtlasEvidenceLedger;
use App\Services\Ai\Kernel\Evidence\AtlasLedgerReplayService;
use App\Services\Ai\Kernel\Evidence\ProviderPerformanceProjection;
use App\Services\Ai\Mobile\ProposalInboxEmitter;
use App\Services\Ai\SelfImprovement\AtlasSelfImprovementInput;
use App\Services\Ai\SelfImprovement\AtlasSelfImprovementRuntime;
use App\Services\Ai\SelfImprovement\AtlasSelfImprovementScheduleService;
use App\Services\Ai\Voice\AtlasVoiceRivalsRunner;
use Mockery;
use ReflectionMethod;
use Tests\TestCase;

class DocumentationHealthFindingsContractTest extends TestCase
{
    public function test_documentation_health_finding_uses_only_active_split_required_docs(): void
    {
        $validation = Mockery::mock(AtlasAiArchitectureValidationService::class);
        $validation->shouldReceive('payload')->once()->andReturn([
            'documentation' => [
                'status' => 'ok',
                'summary' => [
                    'doc_count' => 80,
                    'oversized_count' => 14,
                ],
                'oversized_docs' => [
                    [
                        'path' => 'docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md',
                        'line_count' => 2000,
                        'limit' => 300,
                        'status' => 'split_required_grandfathered',
                        'recommended_action' => 'keep as grandfathered source',
                    ],
                    ...collect(range(1, 13))
                        ->map(fn (int $index): array => [
                            'path' => "docs/engineering-knowledge-base/active-{$index}.md",
                            'line_count' => 300 + $index,
                            'limit' => 300,
                            'status' => 'split_required',
                            'recommended_action' => 'split this active doc into focused specs',
                        ])
                        ->all(),
                ],
            ],
        ]);

        $findings = $this->documentationHealthFindings($this->runtime($validation));

        $this->assertCount(1, $findings);
        $finding = $findings[0];

        $this->assertSame('atlas.self_improvement.documentation_health_gap.v1', data_get($finding, 'metadata.schema_version'));
        $this->assertSame('split_oversized_active_docs', data_get($finding, 'payload.recommended_action'));
        $this->assertSame(14, data_get($finding, 'payload.oversized_count'));
        $this->assertSame(13, data_get($finding, 'payload.split_required_count'));
        $this->assertSame(1, data_get($finding, 'payload.grandfathered_count'));
        $this->assertSame('docs/engineering-knowledge-base/active-13.md', data_get($finding, 'payload.largest_doc.path'));
        $this->assertCount(12, data_get($finding, 'source_refs'));
        $this->assertSame('docs/engineering-knowledge-base/active-1.md', data_get($finding, 'source_refs.0.id'));
        $this->assertSame('documentation_health', data_get($finding, 'source_refs.0.type'));
        $this->assertStringStartsWith('self-improvement:documentation-health:', $finding['dedupe_key']);
        $this->assertSame([
            'docs/engineering-knowledge-base/active-1.md',
            'docs/engineering-knowledge-base/active-2.md',
            'docs/engineering-knowledge-base/active-3.md',
            'docs/engineering-knowledge-base/active-4.md',
            'docs/engineering-knowledge-base/active-5.md',
            'docs/engineering-knowledge-base/active-6.md',
            'docs/engineering-knowledge-base/active-7.md',
            'docs/engineering-knowledge-base/active-8.md',
            'docs/engineering-knowledge-base/active-9.md',
            'docs/engineering-knowledge-base/active-10.md',
            'docs/engineering-knowledge-base/active-11.md',
            'docs/engineering-knowledge-base/active-12.md',
        ], collect(data_get($finding, 'source_refs'))->pluck('id')->all());
    }

    public function test_documentation_health_finding_stays_empty_for_grandfathered_only_docs(): void
    {
        $validation = Mockery::mock(AtlasAiArchitectureValidationService::class);
        $validation->shouldReceive('payload')->once()->andReturn([
            'documentation' => [
                'status' => 'ok',
                'summary' => [
                    'doc_count' => 4,
                    'oversized_count' => 1,
                ],
                'oversized_docs' => [[
                    'path' => 'docs/engineering-knowledge-base/START_HERE.md',
                    'line_count' => 600,
                    'limit' => 300,
                    'status' => 'split_required_grandfathered',
                    'recommended_action' => 'keep as full reading order',
                ]],
            ],
        ]);

        $this->assertSame([], $this->documentationHealthFindings($this->runtime($validation)));
    }

    public function test_documentation_health_finding_preserves_normalized_architecture_filters(): void
    {
        $validation = Mockery::mock(AtlasAiArchitectureValidationService::class);
        $validation->shouldReceive('payload')->once()->andReturn([
            'documentation' => [
                'status' => 'warning',
                'summary' => [
                    'doc_count' => 8,
                    'oversized_count' => 1,
                ],
                'oversized_docs' => [[
                    'path' => 'docs/engineering-knowledge-base/kernel/static-scans.md',
                    'line_count' => 401,
                    'limit' => 300,
                    'status' => 'split_required',
                    'recommended_action' => 'split this active doc into focused specs',
                ]],
            ],
        ]);

        $findings = $this->documentationHealthFindings($this->runtime($validation), [
            'flow' => 'docs_drift_review',
            'status' => ' warning ',
            'surface_id' => ' atlas_cli_dev ',
            'provider' => ' codex_cli ',
            'ignored' => 'drop',
        ]);

        $this->assertCount(1, $findings);
        $this->assertSame([
            'status' => 'warning',
            'surface_id' => 'atlas_cli_dev',
            'provider' => 'codex_cli',
            'flow' => 'docs_drift_review',
        ], data_get($findings, '0.metadata.filters'));
    }

    public function test_documentation_health_finding_drops_empty_and_non_scalar_filters(): void
    {
        $validation = Mockery::mock(AtlasAiArchitectureValidationService::class);
        $validation->shouldReceive('payload')->once()->andReturn([
            'documentation' => [
                'status' => 'warning',
                'summary' => [
                    'doc_count' => 3,
                    'oversized_count' => 1,
                ],
                'oversized_docs' => [[
                    'path' => 'docs/engineering-knowledge-base/kernel/runtime-boundary.md',
                    'line_count' => 333,
                    'limit' => 300,
                    'status' => 'split_required',
                    'recommended_action' => 'split this active doc into focused specs',
                ]],
            ],
        ]);

        $findings = $this->documentationHealthFindings($this->runtime($validation), [
            'domain' => ' backend ',
            'flow' => ' docs_drift_review ',
            'status' => '   ',
            'provider' => null,
            'surface_id' => ['atlas_cli_dev'],
            'unknown' => 'drop',
        ]);

        $this->assertCount(1, $findings);
        $this->assertSame([
            'domain' => 'backend',
            'flow' => 'docs_drift_review',
        ], data_get($findings, '0.metadata.filters'));
    }

    /**
     * @param  array<string,mixed>  $filters
     * @return array<int,array<string,mixed>>
     */
    private function documentationHealthFindings(AtlasSelfImprovementRuntime $runtime, array $filters = ['flow' => 'docs_drift_review']): array
    {
        $method = new ReflectionMethod($runtime, 'documentationHealthFindings');
        $method->setAccessible(true);

        return $method->invoke($runtime, $filters);
    }

    private function runtime(AtlasAiArchitectureValidationService $validation): AtlasSelfImprovementRuntime
    {
        return new AtlasSelfImprovementRuntime(
            Mockery::mock(AtlasEvidenceLedger::class),
            Mockery::mock(AtlasLedgerReplayService::class),
            Mockery::mock(ProposalInboxEmitter::class),
            Mockery::mock(AtlasAiDomainCatalogService::class),
            $validation,
            app(AtlasArchitectureOperationsCatalog::class),
            Mockery::mock(AtlasSelfImprovementScheduleService::class),
            new AtlasSelfImprovementInput,
            Mockery::mock(ProviderPerformanceProjection::class),
            Mockery::mock(DynamicComputeMarketAdvisor::class),
            Mockery::mock(AtlasRivalsStrategyReadModel::class),
            app(AtlasVoiceRivalsRunner::class),
            app(LocalRagBenchmarkService::class),
            Mockery::mock(ProductiveFailureSessionRepository::class),
        );
    }
}
