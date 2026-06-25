<?php

declare(strict_types=1);

namespace Tests\Feature\Loop\BehaviorDelta;

use App\Services\Ai\AutonomousEvolution\BehaviorDelta\AtlasLoopMergedDeliveryBehaviorDeltaRecorder;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * Fase 1: o recorder calcula o Δ de comportamento real de uma entrega mergeada (pai-vs-commit), fresco
 * do git. Provas: (1) a matemática do Δ + fail-closed via snapshot injetado (determinístico); (2) o
 * caminho DEFAULT roda `git archive` de verdade num repo git real — não é adaptador de callback.
 */
final class AtlasLoopMergedDeliveryBehaviorDeltaRecorderTest extends TestCase
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

    /** Um snapshot no formato que o computer consome. */
    private function snap(array $symbols): array
    {
        return ['schema' => 'atlas.loop.behavior_delta_snapshot.v1', 'captured_at' => '1970-01-01T00:00:00Z', 'symbols' => $symbols, 'api_surface_hash' => 'x'];
    }

    public function test_measures_net_delta_between_parent_and_merge(): void
    {
        $before = $this->snap([
            ['fqcn' => 'App\\Demo\\Alpha', 'public_api_signature_hash' => 'h1', 'caller_fqcns' => []],
        ]);
        $after = $this->snap([
            ['fqcn' => 'App\\Demo\\Alpha', 'public_api_signature_hash' => 'h2', 'caller_fqcns' => []],   // sig mudou
            ['fqcn' => 'App\\Demo\\Beta', 'public_api_signature_hash' => 'b1', 'caller_fqcns' => []],     // símbolo novo
        ]);

        $seenRefs = [];
        $recorder = new AtlasLoopMergedDeliveryBehaviorDeltaRecorder(
            function (string $_repo, string $ref, string $_scope) use (&$seenRefs, $before, $after): array {
                $seenRefs[] = $ref;

                return str_ends_with($ref, '^') ? $before : $after;
            },
        );

        $out = $recorder->record('/repo', 'abc1234', 'app/Demo');

        $this->assertTrue($out['measured']);
        $this->assertSame('abc1234^', $out['parent_ref']);
        $this->assertSame(['abc1234^', 'abc1234'], $seenRefs, 'pai primeiro, depois o commit');
        $this->assertSame(1, $out['symbols_added']);          // Beta
        $this->assertSame(1, $out['api_signature_changed']);  // Alpha h1->h2
        $this->assertSame(2, $out['net_behavior_delta']);     // 1 added + 1 changed
    }

    public function test_identical_snapshots_yield_zero_but_measured(): void
    {
        $same = $this->snap([['fqcn' => 'App\\Demo\\Alpha', 'public_api_signature_hash' => 'h1', 'caller_fqcns' => []]]);
        $recorder = new AtlasLoopMergedDeliveryBehaviorDeltaRecorder(fn (): array => $same);

        $out = $recorder->record('/repo', 'deadbeef', 'app/Demo');

        $this->assertTrue($out['measured']);
        $this->assertSame(0, $out['net_behavior_delta']);
    }

    public function test_invalid_sha_is_unmeasured_and_never_calls_snapshotter(): void
    {
        $called = false;
        $recorder = new AtlasLoopMergedDeliveryBehaviorDeltaRecorder(function () use (&$called): array {
            $called = true;

            return $this->snap([]);
        });

        $out = $recorder->record('/repo', 'not-a-sha', 'app/Demo');

        $this->assertFalse($out['measured']);
        $this->assertSame(0, $out['net_behavior_delta']);
        $this->assertFalse($called, 'sha inválido não dispara o snapshotter (fail-closed cedo)');
    }

    public function test_snapshotter_failure_fails_closed(): void
    {
        $recorder = new AtlasLoopMergedDeliveryBehaviorDeltaRecorder(function (): array {
            throw new \RuntimeException('git exploded');
        });

        $out = $recorder->record('/repo', 'abc1234', 'app/Demo');

        $this->assertFalse($out['measured']);
        $this->assertSame(0, $out['net_behavior_delta']);
    }

    public function test_merge_into_quality_preserves_existing_keys(): void
    {
        $recorder = new AtlasLoopMergedDeliveryBehaviorDeltaRecorder(fn (): array => $this->snap([]));
        $record = $recorder->record('/repo', 'abc1234', 'app/Demo'); // measured zero
        $record['net_behavior_delta'] = 7;                            // forja só para checar a fusão

        $merged = $recorder->mergeIntoQuality(['_impact_receipt' => ['commit' => 'abc1234'], 'foo' => 'bar'], $record);

        $this->assertSame('bar', $merged['foo'], 'chave existente preservada');
        $this->assertSame(['commit' => 'abc1234'], $merged['_impact_receipt']);
        $this->assertSame(7, $merged['_behavior_delta']['net_behavior_delta']);
    }

    public function test_real_git_archive_path_measures_a_two_commit_delta(): void
    {
        if ((new Process(['git', '--version']))->run() !== 0) {
            $this->markTestSkipped('git indisponível');
        }

        $repo = rtrim(sys_get_temp_dir(), '/').'/atlas-mdbd-it-'.bin2hex(random_bytes(5));
        $this->dirs[] = $repo;
        $scope = 'app/Services/Demo';
        File::ensureDirectoryExists($repo.'/'.$scope);

        $git = function (array $args) use ($repo): void {
            $p = new Process(array_merge(['git'], $args), $repo, ['GIT_CONFIG_NOSYSTEM' => '1', 'HOME' => $repo, 'PATH' => getenv('PATH') ?: '/usr/bin:/bin']);
            $p->mustRun();
        };
        $git(['init', '-q']);
        $git(['config', 'user.email', 'x@example.com']);
        $git(['config', 'user.name', 'x']);
        $git(['config', 'commit.gpgsign', 'false']);

        // C1 — uma classe com um método público.
        File::put($repo.'/'.$scope.'/Alpha.php', "<?php\n\nnamespace App\\Services\\Demo;\n\nclass Alpha\n{\n    public function one(): int { return 1; }\n}\n");
        $git(['add', '-A']);
        $git(['commit', '-q', '-m', 'c1']);

        // C2 — muda a API pública de Alpha (novo método público) + adiciona Beta.
        File::put($repo.'/'.$scope.'/Alpha.php', "<?php\n\nnamespace App\\Services\\Demo;\n\nclass Alpha\n{\n    public function one(): int { return 1; }\n    public function two(): int { return 2; }\n}\n");
        File::put($repo.'/'.$scope.'/Beta.php', "<?php\n\nnamespace App\\Services\\Demo;\n\nclass Beta\n{\n    public function go(): void {}\n}\n");
        $git(['add', '-A']);
        $git(['commit', '-q', '-m', 'c2']);

        $sha = trim((new Process(['git', 'rev-parse', 'HEAD'], $repo))->mustRun()->getOutput());

        $out = (new AtlasLoopMergedDeliveryBehaviorDeltaRecorder)->record($repo, $sha, $scope);

        $this->assertTrue($out['measured'], 'o caminho git real mediu o par de commits');
        $this->assertGreaterThanOrEqual(1, $out['symbols_added'], 'Beta entrou como símbolo novo');
        $this->assertGreaterThanOrEqual(1, $out['api_signature_changed'], 'a API pública de Alpha mudou');
        $this->assertGreaterThan(0, $out['net_behavior_delta']);
    }
}
