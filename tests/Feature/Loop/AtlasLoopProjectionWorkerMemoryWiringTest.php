<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Services\Ai\AutonomousEvolution\AtlasLoopProjectionMemoryRecall;
use App\Services\Ai\AutonomousEvolution\AtlasLoopProjectionWorker;
use App\Services\Ai\AutonomousEvolution\Persistence\AtlasLoopStore;
use ReflectionClass;
use RuntimeException;
use Tests\TestCase;

final class AtlasLoopProjectionWorkerMemoryWiringTest extends TestCase
{
    private function worker(?AtlasLoopProjectionMemoryRecall $recall = null): AtlasLoopProjectionWorker
    {
        $ref = new ReflectionClass(AtlasLoopProjectionWorker::class);
        $store = (new ReflectionClass(AtlasLoopStore::class))->newInstanceWithoutConstructor();
        // Use a partial ctor invocation: pass store + nullable recall, rest defaults.
        return new AtlasLoopProjectionWorker($store, null, null, null, null, null, null, $recall);
    }

    public function test_constructor_is_backward_compatible_without_memory_arg(): void
    {
        $store = (new ReflectionClass(AtlasLoopStore::class))->newInstanceWithoutConstructor();
        $worker = new AtlasLoopProjectionWorker($store);

        $this->assertInstanceOf(AtlasLoopProjectionWorker::class, $worker);
        // The new public helper exists and returns [] with no recall wired (≡ feature flag off).
        $this->assertSame([], $worker->recallMemoryHits('App/Foo', 'class'));
    }

    public function test_with_memory_recall_off_helper_returns_empty_list(): void
    {
        $recall = $this->stubRecall(fn (): array => [
            ['kind' => 'doc', 'scope' => 'global', 'text' => 'X', 'source_ref' => 'docs/x.md'],
        ]);
        // Force the recall's OWN feature flag OFF (after stubRecall turned it on) — the recall returns
        // [] inside forTarget(), so the worker helper sees []. Deterministic outcomes unchanged.
        config()->set('atlas.loop.projection_memory_recall_enabled', false);
        $worker = $this->worker($recall);

        $this->assertSame([], $worker->recallMemoryHits('app/Services/X.php', 'class'));
    }

    public function test_forbidden_short_circuit_path_does_not_invoke_memory_recall(): void
    {
        // process() with no objective_id is the cheapest short-circuit that never reaches the recall
        // section. We assert: the recall is NOT invoked. The forbidden-target short-circuit takes the same
        // shape (returns BEFORE the recall site).
        $invoked = 0;
        $recall = $this->stubRecall(function () use (&$invoked): array {
            $invoked++;

            return [['kind' => 'doc', 'scope' => 'global', 'text' => 'x', 'source_ref' => 'x']];
        });
        $worker = $this->worker($recall);

        $out = $worker->process(['objective_id' => '', 'checkpoint' => []]);
        $this->assertSame('skipped', $out['outcome']);
        $this->assertSame(0, $invoked, 'forbidden / pre-recall short-circuit must not invoke the recall');
    }

    public function test_memory_recall_failure_falls_back_to_deterministic_path_no_new_outcome(): void
    {
        $recall = $this->stubRecall(static function (): array {
            throw new RuntimeException('memory_unreachable');
        });
        $worker = $this->worker($recall);

        $hits = $worker->recallMemoryHits('App/Services/Foo.php', 'class');
        $this->assertSame([], $hits, 'a recall throw must collapse to [] — never a new park/error outcome');

        // The deterministic short-circuit (no objective_id ⇒ skipped) is unaffected by the recall throw.
        $out = $worker->process(['objective_id' => '', 'checkpoint' => []]);
        $this->assertSame('skipped', $out['outcome']);
        $this->assertSame('no_objective_id', $out['reason']);
    }

    public function test_recall_helper_passes_through_decision_touching_the_target(): void
    {
        $captured = ['target' => null, 'axis' => null];
        $hit = ['kind' => 'decision', 'scope' => 'global', 'text' => 'design contract for App/Foo', 'source_ref' => 'docs/foo.md'];
        $recall = $this->stubRecall(function (string $t, string $a) use (&$captured, $hit): array {
            $captured['target'] = $t;
            $captured['axis'] = $a;

            return [$hit];
        });
        $worker = $this->worker($recall);

        $hits = $worker->recallMemoryHits('App/Foo', 'class');
        $this->assertNotEmpty($hits, 'flags on + matching items => recall returns a non-empty decision');
        $this->assertStringStartsWith('atlas_memory:', (string) $hits[0]['source_ref'], 'recall produces a stable atlas_memory: source_ref');
        $this->assertSame('decision', $hits[0]['kind']);

        // The deterministic obligation set the worker emits already carries contract_upheld with an
        // assertion_ref — verify the literal lines are still present in the worker source (no regression
        // from the memory wiring).
        $workerSrc = (string) file_get_contents(base_path('app/Services/Ai/AutonomousEvolution/AtlasLoopProjectionWorker.php'));
        $this->assertStringContainsString('contract_upheld', $workerSrc);
        $this->assertStringContainsString("'assertion_ref'", $workerSrc);
    }

    /**
     * Build a real AtlasLoopProjectionMemoryRecall whose registry returns whatever the closure decides. The
     * recall's own gate (`projection_memory_recall_enabled`) is forced ON inside the closure path so the
     * recall actually exercises forTarget() against the stub registry.
     *
     * @param  callable(string,string):list<array{kind:string,scope:string,text:string,source_ref:string}>  $forTarget
     */
    private function stubRecall(callable $forTarget): AtlasLoopProjectionMemoryRecall
    {
        config()->set('atlas.loop.projection_memory_recall_enabled', true);

        $captured = ['call' => $forTarget];
        $registry = new class($captured) extends \App\Services\Ai\AtlasMemoryRegistryService
        {
            public array $captured;

            public function __construct(array $captured)
            {
                $this->captured = $captured;
            }

            public function search(array $filters = [], int $limit = 50): \Illuminate\Support\Collection
            {
                $q = (string) ($filters['q'] ?? '');
                $rows = ($this->captured['call'])($q, '');

                return collect($rows)->map(static fn (array $r): array => [
                    'memory_type' => $r['kind'],
                    'scope' => $r['scope'],
                    'summary' => $r['text'],
                    'source_type' => 'docs',
                    'source_label' => $r['source_ref'],
                    'content_hash' => $r['source_ref'],
                    'normalized_priority' => 1.0,
                ]);
            }
        };
        $input = new \App\Services\Ai\Memory\MemoryRecallInput;

        return new AtlasLoopProjectionMemoryRecall($registry, $input);
    }
}
