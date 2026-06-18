<?php

declare(strict_types=1);

namespace Tests\Feature\Loop\Constitution;

use App\Services\Ai\AutonomousEvolution\Constitution\AtlasLoopMergeActuator;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * LOOP-OS · Fase 1 · Slice 1 — prova COMPORTAMENTAL do atuador de merge.
 *
 * O gate canônico do Slice 1: DOIS processos OS reais correndo pela main → o lock serializa
 * (zero interleaving), cada commit vira um objeto git real, history linear, fsck limpo. É a
 * prova de que a corrida sem-lock que corrompia a main (e escondia os edits sibling-ride no
 * juiz) está morta — não por grep no fonte, mas por execução concorrente real.
 */
final class AtlasLoopMergeActuatorTest extends TestCase
{
    /** @var list<string> */
    private array $dirs = [];

    protected function tearDown(): void
    {
        foreach ($this->dirs as $d) {
            File::deleteDirectory($d);
        }
        parent::tearDown();
    }

    private function freshRepo(): string
    {
        $repo = sys_get_temp_dir().'/atlas-actuator-'.bin2hex(random_bytes(5));
        $this->dirs[] = $repo;
        File::ensureDirectoryExists($repo);
        $this->git($repo, ['init', '-q']);
        $this->git($repo, ['config', 'user.email', 'seed@atlas']);
        $this->git($repo, ['config', 'user.name', 'seed']);
        File::put($repo.'/seed.txt', "seed\n");
        $this->git($repo, ['add', '-A']);
        $this->git($repo, ['-c', 'user.email=seed@atlas', '-c', 'user.name=seed', 'commit', '-q', '-m', 'seed', '--no-gpg-sign']);

        return $repo;
    }

    /** @param list<string> $args */
    private function git(string $repo, array $args): Process
    {
        $p = new Process(array_merge(['git'], $args), $repo, null, null, 30.0);
        $p->run();

        return $p;
    }

    private function writeWorker(string $repo): string
    {
        $worker = $repo.'/_worker.php';
        File::put($worker, <<<'PHP'
            <?php
            require $argv[1]; // vendor/autoload.php

            use App\Services\Ai\AutonomousEvolution\Constitution\AtlasLoopMergeActuator;
            use Symfony\Component\Process\Process;

            [$self, $autoload, $repo, $id, $log] = $argv;

            $res = (new AtlasLoopMergeActuator())->withMainMergeLock($repo, function () use ($repo, $id, $log) {
                // Marca a fronteira da seção crítica. Com o lock correto, o START de um worker
                // NUNCA aparece antes do END do outro (aninhamento perfeito).
                file_put_contents($log, "START $id\n", FILE_APPEND | LOCK_EX);
                usleep(250_000); // alarga a janela de corrida
                file_put_contents($log, "END $id\n", FILE_APPEND | LOCK_EX);

                file_put_contents("$repo/w_$id.txt", "work $id\n");
                (new Process(['git', 'add', '-A'], $repo, null, null, 30.0))->run();
                (new Process(['git', '-c', 'user.email=t@t', '-c', 'user.name=t', 'commit', '-q', '-m', "w$id", '--no-gpg-sign'], $repo, null, null, 30.0))->run();
            }, 15.0);

            fwrite(STDOUT, json_encode(['id' => $id, 'acquired' => $res['acquired'], 'reason' => $res['reason'] ?? null]));
            PHP);

        return $worker;
    }

    public function test_two_racing_processes_serialize_no_interleave_one_commit_each(): void
    {
        $repo = $this->freshRepo();
        $worker = $this->writeWorker($repo);
        $log = $repo.'/race.log';
        $autoload = base_path('vendor/autoload.php');

        // Dois processos OS REAIS, lançados concorrentemente, brigando pela MESMA fechadura.
        $a = new Process([PHP_BINARY, $worker, $autoload, $repo, 'A', $log], $repo, null, null, 60.0);
        $b = new Process([PHP_BINARY, $worker, $autoload, $repo, 'B', $log], $repo, null, null, 60.0);
        $a->start();
        $b->start();
        $a->wait();
        $b->wait();

        // Ambos conseguiram (timeout 15s >> seção ~250ms: o perdedor ESPERA e então adquire).
        $ra = json_decode($a->getOutput(), true);
        $rb = json_decode($b->getOutput(), true);
        $this->assertSame(true, $ra['acquired'] ?? null, 'A adquiriu: '.$a->getOutput().$a->getErrorOutput());
        $this->assertSame(true, $rb['acquired'] ?? null, 'B adquiriu: '.$b->getOutput().$b->getErrorOutput());

        // SERIALIZAÇÃO: o log tem que ser perfeitamente aninhado (START x, END x, START y, END y).
        $lines = array_values(array_filter(explode("\n", trim((string) File::get($log)))));
        $this->assertCount(4, $lines, 'exatamente 2 seções (START+END cada): '.implode(' | ', $lines));
        $inside = null;
        foreach ($lines as $ln) {
            [$kind, $who] = explode(' ', $ln);
            if ($kind === 'START') {
                $this->assertNull($inside, "INTERLEAVING: START $who enquanto $inside ainda dentro → lock falhou");
                $inside = $who;
            } else {
                $this->assertSame($inside, $who, "END $who sem o START correspondente");
                $inside = null;
            }
        }
        $this->assertNull($inside, 'a última seção crítica não fechou');

        // INTEGRIDADE GIT: 3 commits (seed + 2 workers), history linear, fsck limpo.
        $count = trim($this->git($repo, ['rev-list', '--count', 'HEAD'])->getOutput());
        $this->assertSame('3', $count, 'cada worker landou exatamente 1 commit, sem perda/corrupção');
        $fsck = $this->git($repo, ['fsck', '--full']);
        $this->assertTrue($fsck->isSuccessful(), 'git fsck limpo: '.$fsck->getErrorOutput());
    }

    public function test_contended_lock_defers_within_timeout_never_silently_drops(): void
    {
        $repo = $this->freshRepo();
        $actuator = new AtlasLoopMergeActuator();

        // Segura o lock in-process (simula a outra crossing) e tenta de novo com timeout curto.
        $held = @fopen($repo.'/.git/'.AtlasLoopMergeActuator::LOCK_BASENAME, 'c');
        $this->assertTrue(flock($held, LOCK_EX | LOCK_NB), 'pré-condição: peguei o lock');

        $ran = false;
        $res = $actuator->withMainMergeLock($repo, function () use (&$ran) {
            $ran = true;

            return 'should-not-run';
        }, 0.3);

        $this->assertFalse($res['acquired'], 'contendido → NÃO adquire');
        $this->assertSame('lock_timeout', $res['reason'], 'sinaliza timeout p/ deferred-retry');
        $this->assertFalse($ran, 'a seção crítica NUNCA roda sem o lock (sem silent-drop disfarçado de sucesso)');
        $this->assertGreaterThanOrEqual(0.3, $res['waited_seconds'], 'esperou o orçamento antes de deferir');

        @flock($held, LOCK_UN);
        @fclose($held);

        // Lock livre → adquire e roda.
        $ok = $actuator->withMainMergeLock($repo, fn () => 'ran', 5.0);
        $this->assertTrue($ok['acquired']);
        $this->assertSame('ran', $ok['result']);
    }

    public function test_php_lint_floor_rejects_a_non_parsing_file(): void
    {
        $repo = $this->freshRepo();
        $actuator = new AtlasLoopMergeActuator();

        $good = $repo.'/Good.php';
        File::put($good, "<?php\nclass Good { public function x(): int { return 1; } }\n");
        $bad = $repo.'/Bad.php';
        File::put($bad, "<?php\nclass Bad { public function x(): int { return ; \n"); // parse error

        $this->assertTrue($actuator->phpLintOk([$good]), 'arquivo válido passa');
        $this->assertFalse($actuator->phpLintOk([$good, $bad]), 'um arquivo que não faz parse barra o floor');
    }
}
