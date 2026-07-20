<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\EngineeringKernel;

use App\Models\AiJob;
use App\Services\Ai\AiProvider;
use App\Services\Ai\AiProviderManager;
use App\Services\Ai\AiProviderResult;
use App\Services\Ai\EngineeringKernel\Adapters\AgentExecutionProviderPortAdapter;
use App\Services\Ai\EngineeringKernel\Adapters\MaestroCostBudgetMeterAdapter;
use App\Services\Ai\EngineeringKernel\BudgetMeter;
use App\Services\Ai\EngineeringKernel\ProviderPort;
use App\Services\Ai\SelfConstruction\Maestro\Cost\AtlasMaestroCostAggregator;
use App\Services\Ai\SelfConstruction\Maestro\Cost\AtlasMaestroCostLedger;
use App\Services\Ai\SoftwareCompanyStewardship\AgentExecution\AgentExecutionProviderPortService;
use Tests\TestCase;

final class KernelProviderPortBudgetMeterAdaptersTest extends TestCase
{
    private string $ledgerPath = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->ledgerPath = sys_get_temp_dir().'/atlas-ek-cost-ledger-'.bin2hex(random_bytes(6)).'.jsonl';
    }

    protected function tearDown(): void
    {
        @unlink($this->ledgerPath);
        parent::tearDown();
    }

    // ── ProviderPort adapter ────────────────────────────────────────────────────

    public function test_provider_port_adapter_implements_interface(): void
    {
        $adapter = new AgentExecutionProviderPortAdapter(new AgentExecutionProviderPortService);

        $this->assertInstanceOf(ProviderPort::class, $adapter);
    }

    public function test_provider_port_normalize_only_is_truthfully_marked_not_invoked(): void
    {
        $service = new AgentExecutionProviderPortService;
        $adapter = new AgentExecutionProviderPortAdapter($service);

        $payload = [
            'provider_id' => 'claude_code',
            'model_family' => 'sonnet',
            'provider_invoked' => true,
        ];

        $expected = array_replace($service->normalize($payload), [
            'provider_invoked' => false,
            'executes_provider' => false,
        ]);
        $this->assertSame($expected, $adapter->invoke($payload));
    }

    public function test_provider_port_executes_real_invoker_once_and_parses_patch_contract(): void
    {
        $calls = 0;
        $adapter = new AgentExecutionProviderPortAdapter(
            new AgentExecutionProviderPortService,
            providerInvoker: function (string $provider, string $model, string $prompt) use (&$calls): array {
                $calls++;
                self::assertSame('test-model', $model);

                return [
                    'ok' => true,
                    'output' => json_encode([
                        'patch_plan' => ['allowed_files' => ['app/X.php'], 'patches' => []],
                        'command_plan' => [],
                    ]),
                    'provider' => $provider,
                    'model' => $model,
                ];
            },
        );

        $receipt = $adapter->invoke([
            'execute_provider' => true,
            'provider' => 'codex_cli',
            'model' => 'test-model',
            'prompt' => 'Produce a governed patch plan',
            'claim' => ['allowed_files' => ['app/X.php']],
        ]);

        self::assertSame(1, $calls);
        self::assertSame('ok', $receipt['status']);
        self::assertArrayHasKey('patch_plan', $receipt);
        self::assertArrayNotHasKey('command_plan', $receipt);
        self::assertSame('test-model', $receipt['model']);
        self::assertTrue($receipt['provider_invoked']);
    }

    public function test_free_form_answer_with_single_claim_file_is_salvaged_into_patch_plan(): void
    {
        // Camada 4 (ordem do operador, 20/07): kimi resolve mas responde em
        // formato livre; com alvo inequívoco (claim de 1 arquivo) o Atlas monta
        // o patch_plan em vez de jogar a resposta fora.
        $adapter = new AgentExecutionProviderPortAdapter(
            new AgentExecutionProviderPortService,
            providerInvoker: fn (string $provider, string $model, string $prompt): array => [
                'ok' => true,
                'output' => "Aqui está a correção completa:\n\n```php\n<?php\n\nfunction fixed() { return 42; }\n```\n\nIsso resolve o bug.",
                'provider' => $provider,
                'model' => $model,
            ],
        );

        $receipt = $adapter->invoke([
            'execute_provider' => true,
            'provider' => 'codex_cli',
            'model' => 'test-model',
            'prompt' => 'Fix the bug',
            'claim' => ['allowed_files' => ['app/X.php']],
        ]);

        self::assertSame('ok', $receipt['status']);
        self::assertTrue($receipt['contract_salvaged']);
        self::assertSame(['app/X.php'], $receipt['patch_plan']['allowed_files']);
        self::assertSame('modify', $receipt['patch_plan']['patches'][0]['mode']);
        self::assertStringContainsString('function fixed()', $receipt['patch_plan']['patches'][0]['next']);
    }

    public function test_free_form_answer_picks_largest_fence_and_names_target_from_prompt_on_creation(): void
    {
        // Criação (claim vazio, contexto rivals isolado): alvo vem NOMEADO no
        // prompt; o maior fence é a solução (o menor é exemplo).
        putenv('ATLAS_RIVALS_RUNTIME_EXECUTION=true');
        try {
            $adapter = new AgentExecutionProviderPortAdapter(
                new AgentExecutionProviderPortService,
                providerInvoker: fn (string $provider, string $model, string $prompt): array => [
                    'ok' => true,
                    'output' => "Example usage:\n```\nsort([3,1])\n```\nFull solution:\n```python\ndef solve():\n    data = sorted(map(int, input().split()))\n    print(*data)\n\nsolve()\n```",
                    'provider' => $provider,
                    'model' => $model,
                ],
            );

            $receipt = $adapter->invoke([
                'execute_provider' => true,
                'provider' => 'codex_cli',
                'model' => 'test-model',
                'prompt' => 'Write the complete solution into the file solution.py (create or overwrite it).',
                'claim' => ['allowed_files' => []],
            ]);

            self::assertSame('ok', $receipt['status']);
            self::assertTrue($receipt['contract_salvaged']);
            self::assertSame(['solution.py'], $receipt['patch_plan']['allowed_files']);
            self::assertSame('create', $receipt['patch_plan']['patches'][0]['mode']);
            self::assertStringContainsString('def solve()', $receipt['patch_plan']['patches'][0]['next']);
            self::assertStringNotContainsString('sort([3,1])', $receipt['patch_plan']['patches'][0]['next']);
        } finally {
            putenv('ATLAS_RIVALS_RUNTIME_EXECUTION');
        }
    }

    public function test_almost_valid_json_with_swapped_closers_is_repaired(): void
    {
        // Payload EXATO da falha ao vivo (20/07, bridge → atlas:cli:dev → kimi):
        // contrato quase perfeito com "...}}]}" onde ia "...}]}". O modelo
        // OBEDECEU o contrato e errou um fechador — jogar fora era crime.
        putenv('ATLAS_RIVALS_RUNTIME_EXECUTION=true');
        try {
            $adapter = new AgentExecutionProviderPortAdapter(
                new AgentExecutionProviderPortService,
                providerInvoker: fn (string $provider, string $model, string $prompt): array => [
                    'ok' => true,
                    'output' => '{"patch_plan":{"allowed_files":["solution.py"],"patches":[{"path":"solution.py","mode":"create","next":"a = int(input())\nb = int(input())\nprint(a + b)\n"}}]}',
                    'provider' => $provider,
                    'model' => $model,
                ],
            );

            $receipt = $adapter->invoke([
                'execute_provider' => true,
                'provider' => 'codex_cli',
                'model' => 'test-model',
                'prompt' => 'Write the solution into the file solution.py',
                'claim' => ['allowed_files' => []],
            ]);

            self::assertSame('ok', $receipt['status']);
            self::assertSame(['solution.py'], $receipt['patch_plan']['allowed_files']);
            self::assertSame('create', $receipt['patch_plan']['patches'][0]['mode']);
            self::assertStringContainsString('print(a + b)', $receipt['patch_plan']['patches'][0]['next']);
        } finally {
            putenv('ATLAS_RIVALS_RUNTIME_EXECUTION');
        }
    }

    public function test_free_form_answer_with_ambiguous_target_stays_invalid_contract(): void
    {
        // Ambiguidade (claim com 2 arquivos) NÃO autoriza adivinhação — o
        // salvage é conservador; engenharia séria mantém o contrato estrito.
        $adapter = new AgentExecutionProviderPortAdapter(
            new AgentExecutionProviderPortService,
            providerInvoker: fn (string $provider, string $model, string $prompt): array => [
                'ok' => true,
                'output' => "```php\n<?php echo 1;\n```",
                'provider' => $provider,
                'model' => $model,
            ],
        );

        $receipt = $adapter->invoke([
            'execute_provider' => true,
            'provider' => 'codex_cli',
            'model' => 'test-model',
            'prompt' => 'Fix the bug',
            'claim' => ['allowed_files' => ['app/A.php', 'app/B.php']],
        ]);

        self::assertSame('invalid_provider_contract', $receipt['status']);
    }

    public function test_free_form_answer_without_fence_stays_invalid_contract(): void
    {
        $adapter = new AgentExecutionProviderPortAdapter(
            new AgentExecutionProviderPortService,
            providerInvoker: fn (string $provider, string $model, string $prompt): array => [
                'ok' => true,
                'output' => 'A resposta é 42, basta somar os valores.',
                'provider' => $provider,
                'model' => $model,
            ],
        );

        $receipt = $adapter->invoke([
            'execute_provider' => true,
            'provider' => 'codex_cli',
            'model' => 'test-model',
            'prompt' => 'Fix the bug',
            'claim' => ['allowed_files' => ['app/X.php']],
        ]);

        self::assertSame('invalid_provider_contract', $receipt['status']);
    }

    public function test_provider_port_fails_closed_when_provider_receipt_model_differs(): void
    {
        $adapter = new AgentExecutionProviderPortAdapter(
            new AgentExecutionProviderPortService,
            providerInvoker: fn (string $provider, string $model, string $prompt): array => [
                'ok' => true, 'provider' => $provider, 'model' => 'other-model',
                'output' => json_encode(['patch_plan' => ['allowed_files' => ['app/X.php'], 'patches' => []]]),
            ],
        );

        $receipt = $adapter->invoke(['execute_provider' => true, 'provider' => 'codex_cli', 'model' => 'required-model',
            'prompt' => 'plan', 'claim' => ['allowed_files' => ['app/X.php']]]);

        self::assertSame('provider_route_mismatch', $receipt['status']);
    }

    public function test_real_provider_receives_explicit_model_on_job_and_receipt_uses_actual_result_route(): void
    {
        $provider = \Mockery::mock(AiProvider::class);
        $provider->shouldReceive('key')->andReturn('codex_cli');
        $provider->shouldReceive('run')->once()->withArgs(function (AiJob $job, string $prompt): bool {
            self::assertSame('alternate-model', $job->model);
            self::assertSame('codex_cli', $job->provider);
            self::assertSame('plan', $prompt);

            return true;
        })->andReturn(new AiProviderResult(
            ok: true,
            output: json_encode(['patch_plan' => ['allowed_files' => ['app/X.php'], 'patches' => []]], JSON_THROW_ON_ERROR),
            command: [], exitCode: 0, durationMs: 1, stdout: '', stderr: '',
            metadata: ['provider' => 'codex_cli', 'model' => 'alternate-model'],
        ));
        $manager = \Mockery::mock(AiProviderManager::class);
        $manager->shouldReceive('get')->once()->with('codex_cli')->andReturn($provider);

        $receipt = (new AgentExecutionProviderPortAdapter(new AgentExecutionProviderPortService, $manager))->invoke([
            'execute_provider' => true, 'provider' => 'codex_cli', 'model' => 'alternate-model',
            'prompt' => 'plan', 'claim' => ['allowed_files' => ['app/X.php']],
        ]);

        self::assertSame('ok', $receipt['status']);
        self::assertSame('alternate-model', $receipt['model']);
        self::assertSame('codex_cli', $receipt['provider']);
    }

    public function test_real_provider_failure_preserves_exact_error_code_and_receipt_details(): void
    {
        $provider = \Mockery::mock(AiProvider::class);
        $provider->shouldReceive('key')->andReturn('hermes_cli');
        $provider->shouldReceive('run')->once()->andReturn(new AiProviderResult(
            ok: false,
            output: '',
            command: ['hermes', '[redacted]'],
            exitCode: 29,
            durationMs: 421,
            stdout: '',
            stderr: 'provider quota resets in 15 seconds',
            errorCode: 'rate_limited',
            errorMessage: 'provider quota resets in 15 seconds',
        ));
        $manager = \Mockery::mock(AiProviderManager::class);
        $manager->shouldReceive('get')->once()->with('hermes_cli')->andReturn($provider);

        $receipt = (new AgentExecutionProviderPortAdapter(
            new AgentExecutionProviderPortService,
            $manager,
        ))->invoke([
            'execute_provider' => true,
            'provider' => 'hermes_cli',
            'model' => 'kimi-k2.7',
            'prompt' => 'plan',
            'claim' => ['allowed_files' => ['app/X.php']],
        ]);

        self::assertSame('unavailable', $receipt['status']);
        self::assertSame('provider_failure:rate_limited', $receipt['failure_reason']);
        self::assertSame('rate_limited', $receipt['error_code']);
        self::assertSame('provider quota resets in 15 seconds', $receipt['error_message']);
        self::assertSame(29, $receipt['exit_code']);
        self::assertSame(421, $receipt['duration_ms']);
    }

    public function test_normalize_only_receipt_is_explicitly_not_a_provider_execution(): void
    {
        $adapter = new AgentExecutionProviderPortAdapter(new AgentExecutionProviderPortService);
        $receipt = $adapter->invoke(['provider_id' => 'codex_cli']);

        self::assertFalse($receipt['provider_invoked']);
        self::assertFalse($receipt['executes_provider']);
    }

    // ── BudgetMeter adapter ─────────────────────────────────────────────────────

    private function costFact(array $overrides = []): array
    {
        return array_merge([
            'task_packet_id' => 'pkt-1',
            'task_class' => 'engineering',
            'provider' => 'claude_code',
            'model' => 'sonnet',
            'cycle_id' => 'cycle-1',
            'tokens_in' => 100,
            'tokens_out' => 50,
            'cost_cents' => 10,
            'recorded_at' => '2026-07-01T00:00:00+00:00',
        ], $overrides);
    }

    public function test_budget_meter_adapter_implements_interface(): void
    {
        $adapter = new MaestroCostBudgetMeterAdapter(
            new AtlasMaestroCostLedger($this->ledgerPath),
            new AtlasMaestroCostAggregator,
        );

        $this->assertInstanceOf(BudgetMeter::class, $adapter);
    }

    public function test_measure_records_through_the_cost_ledger(): void
    {
        $ledger = new AtlasMaestroCostLedger($this->ledgerPath);
        $adapter = new MaestroCostBudgetMeterAdapter($ledger, new AtlasMaestroCostAggregator($ledger));

        $fact = $this->costFact();
        $result = $adapter->measure($fact);

        $this->assertSame('pkt-1', $result['task_packet_id']);
        $this->assertArrayHasKey('cost_hash', $result);

        $ledgerRows = $ledger->all();
        $this->assertCount(1, $ledgerRows);
        $this->assertSame($result, $ledgerRows[0]);
    }

    public function test_measure_returns_empty_array_for_malformed_fact_instead_of_throwing(): void
    {
        $ledger = new AtlasMaestroCostLedger($this->ledgerPath);
        $adapter = new MaestroCostBudgetMeterAdapter($ledger, new AtlasMaestroCostAggregator($ledger));

        $result = $adapter->measure(['task_packet_id' => 'pkt-1']);

        $this->assertSame([], $result);
        $this->assertSame([], $ledger->all());
    }

    public function test_summarize_aggregates_through_the_aggregator_by_task_class(): void
    {
        $ledger = new AtlasMaestroCostLedger($this->ledgerPath);
        $adapter = new MaestroCostBudgetMeterAdapter($ledger, new AtlasMaestroCostAggregator($ledger));

        $adapter->measure($this->costFact());
        $adapter->measure($this->costFact(['task_packet_id' => 'pkt-2', 'cost_cents' => 5]));

        $summary = $adapter->summarize(['by' => 'task_class']);
        $direct = (new AtlasMaestroCostAggregator($ledger))->aggregateByTaskClass();

        $this->assertSame($direct, $summary);
        $this->assertSame(15, $summary['engineering']['sum_cost_cents']);
    }

    public function test_summarize_aggregates_by_provider_when_requested(): void
    {
        $ledger = new AtlasMaestroCostLedger($this->ledgerPath);
        $adapter = new MaestroCostBudgetMeterAdapter($ledger, new AtlasMaestroCostAggregator($ledger));

        $adapter->measure($this->costFact());

        $summary = $adapter->summarize(['by' => 'provider']);
        $direct = (new AtlasMaestroCostAggregator($ledger))->aggregateByProvider();

        $this->assertSame($direct, $summary);
    }

    public function test_summarize_filters_by_cycle_id(): void
    {
        $ledger = new AtlasMaestroCostLedger($this->ledgerPath);
        $adapter = new MaestroCostBudgetMeterAdapter($ledger, new AtlasMaestroCostAggregator($ledger));

        $adapter->measure($this->costFact(['cycle_id' => 'cycle-a']));
        $adapter->measure($this->costFact(['task_packet_id' => 'pkt-2', 'cycle_id' => 'cycle-b']));

        $summary = $adapter->summarize(['by' => 'task_class', 'cycle_id' => 'cycle-a']);

        $this->assertSame(1, $summary['engineering']['count_records']);
    }
}
