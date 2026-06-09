<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use Symfony\Component\Process\Process;
use Tests\TestCase;

final class AtlasLoopCertifyImplementationCommandTest extends TestCase
{
    private string $workspace;

    protected function tearDown(): void
    {
        if (isset($this->workspace) && is_dir($this->workspace)) {
            (new Process(['rm', '-rf', $this->workspace]))->run();
        }

        parent::tearDown();
    }

    public function test_command_certifies_candidate_with_external_refuter(): void
    {
        $this->workspace = $this->workspace();

        $this->artisan('atlas:loop:certify-implementation', [
            '--workspace' => $this->workspace,
            '--acceptance' => json_encode($this->acceptance(), JSON_THROW_ON_ERROR),
            '--objective' => 'make Bar pass',
            '--allowed-file' => ['src/Bar.php'],
            '--refuter-command' => [$this->cleanRefuterCommand()],
            '--refuters' => '1',
            '--json' => true,
        ])->assertExitCode(0);
    }

    /**
     * @return array<string,mixed>
     */
    private function acceptance(): array
    {
        return [
            'commands' => ['php tests/BarTest.php'],
            'allowed_globs' => ['src/**'],
            'frozen_globs' => ['tests/**'],
            'metric_kind' => 'gate',
            'revert_recheck' => true,
            'timeout_seconds' => 30,
        ];
    }

    private function workspace(): string
    {
        $dir = sys_get_temp_dir().'/atlas-loop-cert-command-'.bin2hex(random_bytes(5));
        mkdir($dir.'/src', 0o755, true);
        mkdir($dir.'/tests', 0o755, true);
        file_put_contents($dir.'/src/Bar.php', "<?php\nfinal class Bar { public function value(): string { return 'red'; } }\n");
        file_put_contents($dir.'/tests/BarTest.php', <<<'PHP'
<?php
require __DIR__.'/../src/Bar.php';
$bar = new Bar();
exit($bar->value() === 'green' ? 0 : 1);
PHP);
        $this->runProcess(['git', 'init'], $dir);
        $this->runProcess(['git', 'config', 'user.email', 'atlas-loop@local'], $dir);
        $this->runProcess(['git', 'config', 'user.name', 'Atlas Loop'], $dir);
        $this->runProcess(['git', 'add', '-A'], $dir);
        $this->runProcess(['git', 'commit', '-q', '-m', 'baseline'], $dir);
        file_put_contents($dir.'/src/Bar.php', "<?php\nfinal class Bar { public function value(): string { return 'green'; } }\n");

        return $dir;
    }

    private function cleanRefuterCommand(): string
    {
        return <<<'CMD'
php -r '$p=getenv("ATLAS_SEMANTIC_REFUTER_PACKET"); $j=json_decode(file_get_contents($p), true); echo json_encode(["refuted"=>!(($j["deterministic_gate"]["certified"] ?? false) === true), "reason"=>"checked"]); exit(0);'
CMD;
    }

    /**
     * @param  list<string>  $argv
     */
    private function runProcess(array $argv, string $cwd): void
    {
        $process = new Process($argv, $cwd, null, null, 30.0);
        $process->run();
        $this->assertTrue($process->isSuccessful(), $process->getErrorOutput() ?: $process->getOutput());
    }
}
