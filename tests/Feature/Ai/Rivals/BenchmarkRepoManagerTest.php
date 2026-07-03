<?php

namespace Tests\Feature\Ai\Rivals;

use App\Services\Ai\Rivals\Benchmarks\BenchmarkRepoManager;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Hermético: repo git LOCAL como origem (file://), install/smoke são comandos
 * shell reais mas triviais. Nenhuma rede, nenhum provider, nenhum storage vivo.
 */
class BenchmarkRepoManagerTest extends TestCase
{
    private string $storage;

    private string $benchRoot;

    private string $sourceRepo;

    protected function setUp(): void
    {
        parent::setUp();
        $base = sys_get_temp_dir().'/rivals_benchmarks_test_'.uniqid();
        $this->storage = $base.'/storage';
        $this->benchRoot = $base.'/benchmarks';
        $this->sourceRepo = $base.'/source_repo';
        mkdir($this->sourceRepo, 0755, true);
        foreach ([
            'git init -q',
            'git -c user.email=t@t -c user.name=t commit -q --allow-empty -m seed',
        ] as $cmd) {
            exec('cd '.escapeshellarg($this->sourceRepo)." && {$cmd}", $out, $code);
            $this->assertSame(0, $code, "fixture repo setup failed: {$cmd}");
        }

        config()->set('atlas_rivals.storage_root', $this->storage);
        config()->set('atlas_rivals.benchmarks.root', $this->benchRoot);
        config()->set('atlas_rivals.benchmarks.repos', [
            'ok_repo' => [
                'url' => 'file://'.$this->sourceRepo,
                'adapter' => 'tau2_bfcl',
                'install' => ['mkdir -p .atlas-venv/bin'],
                'smoke' => 'echo smoke-ok',
            ],
            'broken_repo' => [
                'url' => 'file://'.$this->sourceRepo,
                'adapter' => 'hal_harness',
                'install' => ['mkdir -p .atlas-venv/bin'],
                'smoke' => 'echo boom >&2; exit 3',
            ],
        ]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(dirname($this->storage));
        parent::tearDown();
    }

    public function test_smoke_real_clona_instala_executa_e_grava_receipt(): void
    {
        $result = (new BenchmarkRepoManager)->smoke('ok_repo');

        $this->assertSame('running', $result['status']);
        $this->assertNull($result['error']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{40}$/', $result['commit']);
        $this->assertSame(['clone', 'install', 'smoke'], array_column($result['steps'], 'step'));
        $this->assertStringContainsString('smoke-ok', $result['stdout_tail']);
        $this->assertFileExists($result['receipt_path']);
        $this->assertFileExists($result['log']['path']);
        $this->assertSame(hash_file('sha256', $result['log']['path']), $result['log']['sha256']);
        // install marker torna o próximo smoke idempotente (sem re-install)
        $this->assertFileExists($this->benchRoot.'/ok_repo/.atlas-venv/.atlas-install-ok');
    }

    public function test_smoke_que_falha_vira_blocked_com_erro_exato_nunca_done(): void
    {
        (new BenchmarkRepoManager)->smoke('ok_repo');
        $result = (new BenchmarkRepoManager)->smoke('broken_repo');

        $this->assertSame('blocked', $result['status']);
        $this->assertStringContainsString('smoke_failed', $result['error']);
        $this->assertStringContainsString('boom', $result['error']);

        // status() deriva do receipt gravado — blocked persiste
        $status = (new BenchmarkRepoManager)->status();
        $broken = collect($status['repos'])->firstWhere('repo_id', 'broken_repo');
        $this->assertSame('blocked', $broken['status']);
        $this->assertSame(1, $status['running']);
        $this->assertSame(1, $status['blocked']);
    }

    public function test_repo_nunca_smokado_e_blocked_honesto(): void
    {
        $status = (new BenchmarkRepoManager)->status();
        $ok = collect($status['repos'])->firstWhere('repo_id', 'ok_repo');

        $this->assertSame('blocked', $ok['status']);
        $this->assertSame('smoke_never_ran', $ok['error']);
        $this->assertFalse($ok['cloned']);
    }

    public function test_comando_benchmark_smoke_repo_desconhecido_e_erro(): void
    {
        $this->artisan('atlas:rivals benchmark-smoke --repo=nope --json')
            ->assertExitCode(1);
    }

    public function test_comando_benchmarks_lista_registry_com_status(): void
    {
        $this->artisan('atlas:rivals benchmarks --json')->assertExitCode(0);
    }
}
