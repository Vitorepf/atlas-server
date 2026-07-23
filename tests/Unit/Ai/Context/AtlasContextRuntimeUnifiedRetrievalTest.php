<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Context;

use App\Services\Ai\Context\AiContextPackBuilder;
use App\Services\Ai\AtlasOpenBrainContextInjectionService;
use App\Services\Ai\AtlasOpenBrainContextPackService;
use App\Services\Ai\Context\AtlasAucriRuntimeEnforcementService;
use App\Services\Ai\Context\AtlasContextQualityCertificationService;
use App\Services\Ai\Context\AtlasContextRuntime;
use App\Services\Ai\ValueObjects\AiContextPack;
use App\Services\Ai\ValueObjects\AiTaskRequest;
use ReflectionClass;
use Tests\TestCase;

final class AtlasContextRuntimeUnifiedRetrievalTest extends TestCase
{
    public function test_compose_builds_fused_retrieval_once_and_hands_it_to_injection(): void
    {
        config()->set('atlas.context_runtime.unified_retrieval_enabled', true);
        $task = AiTaskRequest::fromInput(
            'implement unified retrieval',
            ['payload' => ['workspace' => base_path(), 'task_type' => 'dev']],
            ['agent' => 'developer', 'intent' => 'test'],
        );
        $internalPack = new AiContextPack([
            'task' => ['type' => 'dev', 'objective' => 'implement unified retrieval'],
            'surface' => ['workspace' => base_path()],
            'memory' => ['recall' => []],
        ], []);
        $fusedPack = [
            'schema' => 'atlas.aobg.context_pack.v1',
            'workspace' => 'atlas-server',
            'provider_bound' => true,
            'code_graph' => [],
            'reality_graph_paths' => [],
            'memory' => [],
            'provenance' => ['memory' => ['status' => 'empty']],
        ];

        $builder = $this->createMock(AiContextPackBuilder::class);
        $builder->expects($this->once())
            ->method('build')
            ->willReturn($internalPack);
        $fused = $this->createMock(AtlasOpenBrainContextPackService::class);
        $fused->expects($this->once())
            ->method('packFor')
            ->with(
                'implement unified retrieval',
                $this->callback(static fn (array $options): bool => $options['include_runtime_compose'] === false),
            )
            ->willReturn($fusedPack);
        $injection = $this->createMock(AtlasOpenBrainContextInjectionService::class);
        $injection->expects($this->once())
            ->method('inject')
            ->with(
                'implement unified retrieval',
                $task,
                $internalPack,
                $this->callback(static fn (array $options): bool => $options['precomputed_aobg_pack'] === $fusedPack),
            )
            ->willReturn(['status' => 'injected']);

        $runtime = new AtlasContextRuntime(
            $builder,
            $injection,
            (new ReflectionClass(AtlasAucriRuntimeEnforcementService::class))->newInstanceWithoutConstructor(),
            (new ReflectionClass(AtlasContextQualityCertificationService::class))->newInstanceWithoutConstructor(),
            $fused,
        );
        $contract = $runtime->compose('implement unified retrieval', $task, [
            'workspace' => base_path(),
            'flow_id' => 'programming.dev',
        ]);

        $this->assertSame('unified', data_get($contract->pack, 'retrieval_core_status'));
        $this->assertSame($fusedPack, data_get($contract->pack, 'retrieval_core'));
        $this->assertSame('injected', data_get($contract->pack, 'open_brain_injection.status'));
    }

    public function test_shadow_mode_builds_fused_pack_but_keeps_legacy_injection(): void
    {
        config()->set('atlas.context_runtime.unified_retrieval_enabled', true);
        config()->set('atlas.context_runtime.unified_retrieval_mode', 'shadow');
        $task = AiTaskRequest::fromInput(
            'shadow unified retrieval',
            ['payload' => ['workspace' => base_path(), 'task_type' => 'dev']],
            ['agent' => 'developer', 'intent' => 'test'],
        );
        $internalPack = new AiContextPack([
            'task' => ['type' => 'dev', 'objective' => 'shadow unified retrieval'],
            'surface' => ['workspace' => base_path()],
            'memory' => ['recall' => []],
        ], []);
        $fusedPack = [
            'schema' => 'atlas.aobg.context_pack.v1',
            'workspace' => 'atlas-server',
            'provider_bound' => true,
            'code_graph' => [],
            'reality_graph_paths' => [],
            'memory' => [],
        ];

        $builder = $this->createMock(AiContextPackBuilder::class);
        $builder->expects($this->once())->method('build')->willReturn($internalPack);
        $fused = $this->createMock(AtlasOpenBrainContextPackService::class);
        $fused->expects($this->once())->method('packFor')->willReturn($fusedPack);
        $injection = $this->createMock(AtlasOpenBrainContextInjectionService::class);
        $injection->expects($this->once())
            ->method('inject')
            ->with(
                'shadow unified retrieval',
                $task,
                $internalPack,
                $this->callback(static fn (array $options): bool => ! array_key_exists('precomputed_aobg_pack', $options)),
            )
            ->willReturn(['status' => 'injected']);

        $runtime = new AtlasContextRuntime(
            $builder,
            $injection,
            (new ReflectionClass(AtlasAucriRuntimeEnforcementService::class))->newInstanceWithoutConstructor(),
            (new ReflectionClass(AtlasContextQualityCertificationService::class))->newInstanceWithoutConstructor(),
            $fused,
        );
        $contract = $runtime->compose('shadow unified retrieval', $task, [
            'workspace' => base_path(),
            'flow_id' => 'programming.dev',
        ]);

        $this->assertSame('shadow', data_get($contract->pack, 'retrieval_core_status'));
        $this->assertSame($fusedPack, data_get($contract->pack, 'retrieval_core'));
        $this->assertSame('shadow', data_get($contract->pack, 'retrieval_rollout.mode'));
        $this->assertFalse((bool) data_get($contract->pack, 'retrieval_rollout.live'));
    }
}
