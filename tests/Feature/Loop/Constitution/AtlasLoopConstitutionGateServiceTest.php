<?php

declare(strict_types=1);

namespace Tests\Feature\Loop\Constitution;

use App\Services\Ai\AutonomousEvolution\AtlasLoopHarnessGuard;
use App\Services\Ai\AutonomousEvolution\Constitution\AtlasLoopConstitutionGateService;
use App\Services\Ai\AutonomousEvolution\Constitution\AtlasLoopConstitutionGateToken;
use App\Services\Ai\AutonomousEvolution\Constitution\AtlasLoopMergeActuator;
use App\Services\Ai\SelfConstruction\AtlasTaskScopedCommitter;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * LOOP-OS · Fase 3 · Slice 4 — the ConstitutionGate service. The config surface is COMPLETE (a monotonic
 * candidate PASSES with a re-verifiable token; a gate-disabling candidate is REJECTED); the JUDGE surface is
 * FAIL-CLOSED until §3.6(ii) lands — it never returns a PASS it cannot back.
 */
final class AtlasLoopConstitutionGateServiceTest extends TestCase
{
    /** @var list<string> */
    private array $files = [];

    /** @var list<string> */
    private array $dirs = [];

    private AtlasLoopConstitutionGateService $gate;

    protected function setUp(): void
    {
        parent::setUp();
        $this->gate = new AtlasLoopConstitutionGateService;
    }

    protected function tearDown(): void
    {
        foreach ($this->files as $f) {
            @unlink($f);
        }
        foreach ($this->dirs as $dir) {
            File::deleteDirectory($dir);
        }
        parent::tearDown();
    }

    private function config(array $loop): string
    {
        $path = sys_get_temp_dir().'/atlas-gate-cfg-'.bin2hex(random_bytes(4)).'.php';
        $this->files[] = $path;
        file_put_contents($path, '<?php return '.var_export(['ai' => ['loop' => []], 'loop' => $loop], true).';');

        return $path;
    }

    public function test_constitution_gate_service_live_caller_and_presence_sentinels_exist(): void
    {
        $this->assertTrue(class_exists(AtlasLoopConstitutionGateService::class), 'ConstitutionGate service must stay present.');
        $this->assertTrue(method_exists(AtlasLoopMergeActuator::class, 'commitWithConstitutionToken'), 'Merge actuator must keep the token-gated commit spine.');

        $committerSource = (string) file_get_contents(app_path('Services/Ai/SelfConstruction/AtlasTaskScopedCommitter.php'));
        $this->assertStringContainsString('->commitWithConstitutionToken(', $committerSource, 'The live Autonomos committer must call the token-gated actuator method.');

        $forbidden = AtlasLoopHarnessGuard::FORBIDDEN_SELF_TARGETS;
        $this->assertContains('app/Services/Ai/AutonomousEvolution/Constitution/', $forbidden, 'The Constitution implementation subtree must remain petreo.');
        $this->assertContains('tests/Feature/Loop/Constitution/', $forbidden, 'The Constitution sentinel tests must remain petreo.');
    }

    public function test_forbidden_self_target_synthetic_commit_without_valid_token_is_refused_with_receipt(): void
    {
        $repo = $this->gitRepo();
        $forbidden = 'app/Services/Ai/AutonomousEvolution/AtlasLoopHarnessGuard.php';
        $this->assertContains($forbidden, AtlasLoopHarnessGuard::FORBIDDEN_SELF_TARGETS);
        $this->writeRepoFile($repo, $forbidden, "<?php\n// tamper with petreo guard\n");

        $receipt = (new AtlasTaskScopedCommitter(new AtlasLoopHarnessGuard, $repo))->commitScope(
            [$forbidden],
            'asi-01-negative',
            'asi-01-test',
            'forbidden self-target without valid token',
            constitution: ['token' => 'invalid', 'battery_root' => 'battery-1', 'nonce' => 'nonce-1'],
        );

        $this->assertSame('atlas.task_serving.scoped_commit.v1', $receipt['schema']);
        $this->assertFalse($receipt['committed']);
        $this->assertSame('forbidden_self_target', $receipt['reason']);
        $this->assertSame($forbidden, $receipt['path']);
        $this->assertSame('seed', trim($this->git($repo, ['log', '-1', '--pretty=%s'])['out']), 'no commit landed');
    }

    public function test_a_monotonic_config_candidate_passes_with_a_verifiable_token(): void
    {
        $live = $this->config(['boot_smoke_guard' => true, 'value_gate_enabled' => true]);
        $candidate = $this->config(['boot_smoke_guard' => true, 'value_gate_enabled' => true, 'new_feature' => true]);

        $res = $this->gate->admitConfig($live, $candidate, 'treeSha1', 'batteryRoot1', 'nonce-1');

        $this->assertSame('PASS', $res['verdict']);
        $this->assertNotNull($res['token']);
        // the minted token re-verifies against the same tree + battery + nonce (the actuator's merge-time check)
        $v = (new AtlasLoopConstitutionGateToken)->verify($res['token'], 'treeSha1', 'batteryRoot1', 'nonce-1', []);
        $this->assertTrue($v['valid']);
    }

    public function test_a_config_candidate_that_disables_a_safety_gate_is_rejected(): void
    {
        $live = $this->config(['boot_smoke_guard' => true]);
        $candidate = $this->config(['boot_smoke_guard' => false]); // disabling the boot-smoke gate

        $res = $this->gate->admitConfig($live, $candidate, 'treeSha1', 'batteryRoot1', 'nonce-1');

        $this->assertSame('REJECT', $res['verdict']);
        $this->assertNull($res['token'], 'a rejected candidate mints no token ⇒ the actuator can never commit it');
        $this->assertStringContainsString('boot_smoke_guard', $res['violations'][0]);
    }

    public function test_the_judge_surface_is_fail_closed_never_a_false_pass(): void
    {
        // A judge self-edit must NOT pass until the §3.6(ii) judge-execution leg is dischargeable.
        $res = $this->gate->admitJudge('diff', base_path(), '/tmp/battery');
        $this->assertSame('REJECT', $res['verdict']);
        $this->assertNull($res['token']);
        $this->assertStringContainsString('§3.6(ii)', $res['reason']);
    }

    private function gitRepo(): string
    {
        $repo = sys_get_temp_dir().'/atlas-constitution-gate-'.bin2hex(random_bytes(5));
        $this->dirs[] = $repo;
        @mkdir($repo, 0775, true);
        $this->git($repo, ['init', '-q']);
        $this->git($repo, ['config', 'user.email', 'test@atlas.local']);
        $this->git($repo, ['config', 'user.name', 'Atlas Test']);
        $this->writeRepoFile($repo, 'README.md', "seed\n");
        $this->git($repo, ['add', 'README.md']);
        $this->git($repo, ['commit', '-q', '-m', 'seed']);

        return $repo;
    }

    private function writeRepoFile(string $repo, string $rel, string $content): void
    {
        $path = $repo.'/'.$rel;
        @mkdir(\dirname($path), 0775, true);
        file_put_contents($path, $content);
    }

    /** @param list<string> $args @return array{code:int,out:string,err:string} */
    private function git(string $repo, array $args): array
    {
        $process = new Process(array_merge(['git'], $args), $repo);
        $process->run();

        return ['code' => (int) $process->getExitCode(), 'out' => $process->getOutput(), 'err' => $process->getErrorOutput()];
    }
}
