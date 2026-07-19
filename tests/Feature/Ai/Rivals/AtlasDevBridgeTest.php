<?php

namespace Tests\Feature\Ai\Rivals;

use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class AtlasDevBridgeTest extends TestCase
{
    private string $workspace;

    protected function setUp(): void
    {
        parent::setUp();
        $this->workspace = sys_get_temp_dir().'/rivals_atlas_dev_bridge_'.uniqid();
        File::ensureDirectoryExists($this->workspace);
        file_put_contents($this->workspace.'/.rivals_task.md', 'Fix the scoped test.');
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->workspace);
        parent::tearDown();
    }

    public function test_bridge_plan_locks_model_and_disables_decide_and_fallback(): void
    {
        $process = new Process([
            PHP_BINARY,
            base_path('scripts/rivals-atlas-dev-bridge.php'),
            '--workspace='.$this->workspace,
            '--prompt-file='.$this->workspace.'/.rivals_task.md',
            '--model=claude-sonnet-5',
            '--dry-run',
        ], base_path());
        $process->run();
        $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
        $payload = json_decode($process->getOutput(), true);

        $this->assertTrue($payload['fair_mode']['single_provider']);
        $this->assertTrue($payload['fair_mode']['decide_disabled']);
        $this->assertTrue($payload['fair_mode']['fallback_disabled']);
        $this->assertTrue($payload['fair_mode']['deterministic_fast_path_disabled']);
        $this->assertContains('--single-provider', $payload['argv']);
        $this->assertContains('--no-decide', $payload['argv']);
        $this->assertContains('--fallback-disabled', $payload['argv']);
        $this->assertContains('--provider=claude_cli', $payload['argv']);
    }

    public function test_bridge_runs_atlas_dev_for_kimi_never_bare_hermes(): void
    {
        // ⚠️ Este teste AFIRMAVA O BUG. Ele se chamava
        // "routes_verboo_kimi_through_hermes_without_provider_fallback" e fixava
        // `execution === 'hermes_cli_oneshot'` + `argv[0] === 'hermes'` como
        // contrato — era ele que mantinha o bypass vivo e verde.
        //
        // O que ele queria era legítimo: impedir que kimi caísse em claude_cli.
        // A solução escolhida foi pular o Atlas INTEIRO — e aí o braço "com
        // Atlas" virou `hermes -z`, Hermes CLI puro, sem artisan no laço. 107
        // recibos com execution=hermes_cli_oneshot, ZERO com
        // atlas_cli_dev_efficient: a coluna "com Atlas" do relatório nunca mediu
        // o Atlas, e o delta comparava harness de agente.
        //
        // Rodar COM ATLAS = rodar o Atlas Dev. `hermes -z` é o músculo sozinho;
        // `atlas:cli:dev --ai=hermes` é o Atlas orquestrando o mesmo músculo,
        // com contexto, scope guard e verificação na frente. Só o segundo
        // responde "o que o Atlas ADICIONA a este modelo".
        //
        // O anti-fallback continua garantido abaixo, agora sem pagar o preço de
        // não medir nada.
        $process = new Process([
            PHP_BINARY,
            base_path('scripts/rivals-atlas-dev-bridge.php'),
            '--workspace='.$this->workspace,
            '--prompt-file='.$this->workspace.'/.rivals_task.md',
            '--model=kimi-k2.7',
            '--timeout=1801',
            '--dry-run',
        ], base_path());
        $process->run();
        $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
        $payload = json_decode($process->getOutput(), true);

        $this->assertSame('atlas_cli_dev_efficient', $payload['execution'], 'o braço Atlas tem de rodar o Atlas');
        $this->assertSame('hermes', $payload['ai'], 'kimi segue no músculo hermes — mas agora sob o Atlas');
        $this->assertNotSame('hermes', $payload['argv'][0] ?? null, 'nunca mais chamar o hermes cru como se fosse Atlas');
        $this->assertContains('atlas:cli:dev', $payload['argv']);
        $this->assertNotContains('-z', $payload['argv'], 'o one-shot cru do hermes não é o braço Atlas');
        // O anti-fallback original, preservado: kimi jamais vira claude.
        $this->assertNotContains('--provider=claude_cli', $payload['argv']);
        $this->assertContains('--single-provider', $payload['argv']);
        $this->assertContains('--no-decide', $payload['argv']);
        $this->assertContains('--fallback-disabled', $payload['argv']);
        $this->assertSame(1801, $payload['provider_timeout_seconds']);
        $this->assertSame(
            '1801',
            data_get($payload, 'runtime_env.ATLAS_AI_HERMES_TIMEOUT_SECONDS'),
        );
    }

    public function test_hermes_oneshot_stays_reachable_only_as_explicit_operator_escape(): void
    {
        // O one-shot cru continua alcançável, mas só por escolha DECLARADA do
        // operador — nunca por dedução do provider, que era como a medição
        // trocava sozinha e em silêncio. Quem liga isto sabe que não está
        // medindo o Atlas: o recibo sai com atlas_runtime=false e o uplift
        // recusa o par.
        $process = new Process([
            PHP_BINARY,
            base_path('scripts/rivals-atlas-dev-bridge.php'),
            '--workspace='.$this->workspace,
            '--prompt-file='.$this->workspace.'/.rivals_task.md',
            '--model=kimi-k2.7',
            '--dry-run',
        ], base_path(), ['ATLAS_RIVALS_BRIDGE_HERMES_ONESHOT' => 'true']);
        $process->run();
        $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
        $payload = json_decode($process->getOutput(), true);

        $this->assertSame('hermes', $payload['ai']);
        $this->assertSame('hermes_cli_oneshot', $payload['execution']);
        $this->assertNull($payload['provider']);
        $this->assertSame('hermes', $payload['argv'][0] ?? null);
        $this->assertContains('-z', $payload['argv']);
        $this->assertContains('--provider', $payload['argv']);
        $this->assertContains('verboo', $payload['argv']);
        $this->assertNotContains('--provider=claude_cli', $payload['argv']);
        $this->assertNotContains('--provider=codex_cli', $payload['argv']);
    }

    public function test_bare_bridge_locks_verboo_provider_and_same_kimi_model(): void
    {
        $process = new Process([
            PHP_BINARY,
            base_path('scripts/rivals-hermes-bare.php'),
            '--workspace='.$this->workspace,
            '--prompt-file='.$this->workspace.'/.rivals_task.md',
            '--model=kimi-k2.7',
            '--dry-run',
        ], base_path());
        $process->run();
        $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
        $payload = json_decode($process->getOutput(), true);

        $this->assertSame('verboo', $payload['provider']);
        $this->assertSame('kimi-k2.7', $payload['model']);
        $this->assertContains('--provider', $payload['argv']);
        $this->assertContains('verboo', $payload['argv']);
        $this->assertContains('kimi-k2.7', $payload['argv']);
    }
}
