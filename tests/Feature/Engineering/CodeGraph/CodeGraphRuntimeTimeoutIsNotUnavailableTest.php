<?php

declare(strict_types=1);

namespace Tests\Feature\Engineering\CodeGraph;

use App\Services\Engineering\CodeGraph\CodeGraphRuntimeInvoker;
use App\Services\Engineering\CodeGraph\CodeGraphSymbolBuilder;
use ReflectionMethod;
use Tests\TestCase;

/**
 * "Estourou o tempo" e "o runtime não está aí" são fatos diferentes, e por
 * meses chegaram ao operador como a mesma palavra.
 *
 * O callgraph tipado precisa de ~43s sobre os ~13k arquivos deste repo e morria
 * nos 30s do default do invocador. O build reportava `runtime_unavailable`, que
 * se lê como "o runtime ainda não está pronto" — então ninguém foi olhar. O
 * grafo de chamadas ficou em ZERO por 13 segundos de diferença.
 *
 * Um diagnóstico que aponta a causa errada é pior que nenhum: ele encerra a
 * investigação.
 */
final class CodeGraphRuntimeTimeoutIsNotUnavailableTest extends TestCase
{
    /**
     * @param  array<string,mixed>  $runtimeResult
     * @return array<string,mixed>
     */
    private function statsFor(array $runtimeResult): array
    {
        config()->set('atlas.code_graph.call_edges', true);

        $this->app->bind(CodeGraphRuntimeInvoker::class, fn (): object => new class($runtimeResult) extends CodeGraphRuntimeInvoker
        {
            /** @param array<string,mixed> $result */
            public function __construct(private readonly array $result) {}

            public function invoke(string $op, array $input, array $limits = [], string $decisionReceiptHash = ''): array
            {
                return $this->result;
            }
        });

        $builder = app(CodeGraphSymbolBuilder::class);
        $method = new ReflectionMethod($builder, 'computeCallEdges');

        return $method->invoke($builder, [
            ['name' => 'Tests\\Fixture', 'type' => 'class', 'file_path' => 'app/Services/Engineering/CodeGraph/CodeGraphSymbolBuilder.php'],
        ])['stats'];
    }

    public function test_a_timeout_and_a_missing_runtime_never_report_the_same_thing(): void
    {
        $timedOut = $this->statsFor([
            'status' => CodeGraphRuntimeInvoker::STATUS_FAILED,
            'findings' => [['kind' => 'runtime_error', 'error' => 'runtime_timeout_after_300s']],
        ]);
        $blocked = $this->statsFor([
            'status' => CodeGraphRuntimeInvoker::STATUS_BLOCKED,
            'findings' => [['kind' => 'runtime_blocked', 'reason' => 'python3_unavailable']],
        ]);

        self::assertSame('runtime_timeout', $timedOut['status']);
        self::assertSame('runtime_unavailable', $blocked['status']);
        self::assertNotSame($timedOut['status'], $blocked['status']);
    }

    public function test_the_reason_travels_so_the_operator_sees_the_cause_not_a_label(): void
    {
        $timedOut = $this->statsFor([
            'status' => CodeGraphRuntimeInvoker::STATUS_FAILED,
            'findings' => [['kind' => 'runtime_error', 'error' => 'runtime_timeout_after_300s']],
        ]);
        $blocked = $this->statsFor([
            'status' => CodeGraphRuntimeInvoker::STATUS_BLOCKED,
            'findings' => [['kind' => 'runtime_blocked', 'reason' => 'code_graph_real_edges_flag_disabled']],
        ]);

        // Sem o motivo, "300s não bastaram" e "a flag está desligada" viram a
        // mesma linha de saída — e a linha sugere a causa errada.
        self::assertSame('runtime_timeout_after_300s', $timedOut['reason']);
        self::assertSame('code_graph_real_edges_flag_disabled', $blocked['reason']);
    }

    public function test_the_call_site_asks_for_the_time_the_work_actually_needs(): void
    {
        // 43,1s medidos sobre 13.290 arquivos. Sem limite explícito, o invocador
        // cai nos 30s do DEFAULT e o trabalho nunca termina — por 13 segundos.
        config()->set('atlas.code_graph.call_edges', true);
        // instance(), não bind(): com bind o container devolve um objeto NOVO a
        // cada resolve, e o que o builder usou não seria o que o teste inspeciona.
        $invoker = new class extends CodeGraphRuntimeInvoker
        {
            /** @var array<string,mixed> */
            public array $seen = [];

            public function invoke(string $op, array $input, array $limits = [], string $decisionReceiptHash = ''): array
            {
                $this->seen = $limits;

                return ['status' => self::STATUS_BLOCKED, 'findings' => []];
            }
        };
        $this->app->instance(CodeGraphRuntimeInvoker::class, $invoker);
        $builder = app(CodeGraphSymbolBuilder::class);
        (new ReflectionMethod($builder, 'computeCallEdges'))->invoke($builder, [
            ['name' => 'Tests\\Fixture', 'type' => 'class', 'file_path' => 'app/Services/Engineering/CodeGraph/CodeGraphSymbolBuilder.php'],
        ]);

        self::assertSame(300, $invoker->seen['timeout_seconds'] ?? null);
    }
}
