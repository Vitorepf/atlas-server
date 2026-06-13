<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Process\Process;
use Tests\TestCase;

final class AtlasLoopMutationAdequacyGateTest extends TestCase
{
    private ?string $workspace = null;

    protected function tearDown(): void
    {
        if ($this->workspace !== null && is_dir($this->workspace)) {
            (new Process(['rm', '-rf', $this->workspace], null, null, null, 30.0))->run();
        }

        parent::tearDown();
    }

    public function test_command_accepts_strong_fixture_and_generates_adversarial_numeric_cases(): void
    {
        $propertyCommand = <<<'CMD'
php -r '$cases=json_decode(getenv("ATLAS_MUTATION_PROPERTY_CASES") ?: "[]", true); $families=array_column(is_array($cases) ? $cases : [], "family"); $ok=in_array("nan", $families, true) && in_array("positive_infinity", $families, true) && in_array("float_overflow", $families, true); exit($ok ? 0 : 1);'
CMD;

        $exit = Artisan::call('atlas:loop:mutation-gate', [
            '--fixture' => 'strong',
            '--property-command' => [$propertyCommand],
            '--json' => true,
            '--strict' => true,
        ]);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit, Artisan::output());
        $this->assertSame('atlas.loop.mutation_adequacy_gate.v1', $payload['schema_version']);
        $this->assertSame('mutation_killed', $payload['status']);
        $this->assertTrue($payload['certified']);
        $this->assertSame(1, $payload['mutants_sampled']);
        $this->assertSame(1, $payload['mutants_killed']);
        $this->assertSame('generated_and_runner_passed', data_get($payload, 'property_adversarial_inputs.status'));
        $this->assertContains('nan', data_get($payload, 'property_adversarial_inputs.families'));
        $this->assertContains('float_overflow', data_get($payload, 'property_adversarial_inputs.families'));
    }

    public function test_command_rejects_weak_fixture_when_mutant_survives(): void
    {
        $exit = Artisan::call('atlas:loop:mutation-gate', [
            '--fixture' => 'weak',
            '--json' => true,
            '--strict' => true,
        ]);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(1, $exit);
        $this->assertSame('mutation_survived', $payload['status']);
        $this->assertFalse($payload['certified']);
        $this->assertSame(['mutation_survived'], $payload['blockers']);
        $this->assertSame(1, $payload['mutants_survived']);
    }

    public function test_semantic_certifier_rejects_diff_earned_test_that_does_not_kill_mutation(): void
    {
        config(['atlas.loop.mutation_adequacy_gate.enabled' => true]);
        $this->workspace = $this->weakButDiffEarnedWorkspace();

        $acceptance = [
            'commands' => ['php tests/BarTest.php'],
            'allowed_globs' => ['src/**'],
            'frozen_globs' => ['tests/**'],
            'metric_kind' => 'gate',
            'revert_recheck' => true,
            'timeout_seconds' => 30,
        ];

        $exit = Artisan::call('atlas:loop:certify-implementation', [
            '--workspace' => $this->workspace,
            '--acceptance' => json_encode($acceptance, JSON_THROW_ON_ERROR),
            '--objective' => 'prove weak tests are rejected by mutation adequacy',
            '--allowed-file' => ['src/Bar.php'],
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(1, $exit, Artisan::output());
        $this->assertFalse($payload['certified']);
        $this->assertTrue(data_get($payload, 'deterministic_gate.certified'), 'old gate should pass so mutation gate proves the extra protection');
        $this->assertSame('mutation_survived', data_get($payload, 'mutation_adequacy_gate.status'));
        $this->assertContains('mutation_adequacy_gate:mutation_survived', $payload['reasons']);
    }

    public function test_schedule_contains_daily_mutation_gate_fixture_proof(): void
    {
        Artisan::call('schedule:list');

        $this->assertStringContainsString('atlas:loop:mutation-gate --fixture=strong --write-receipt --json', Artisan::output());
    }

    private function weakButDiffEarnedWorkspace(): string
    {
        $dir = sys_get_temp_dir().'/atlas-loop-mutation-certifier-'.bin2hex(random_bytes(5));
        mkdir($dir.'/src', 0o755, true);
        mkdir($dir.'/tests', 0o755, true);
        file_put_contents($dir.'/src/Bar.php', "<?php\nfinal class Bar { public function value(): string { return 'red'; } }\n");
        file_put_contents($dir.'/tests/BarTest.php', <<<'PHP'
<?php
require __DIR__.'/../src/Bar.php';
$bar = new Bar();
if ($bar->value() === 'red') {
    fwrite(STDERR, 'still red');
    exit(1);
}
PHP);
        $this->runProcess(['git', 'init', '-q'], $dir);
        $this->runProcess(['git', 'config', 'user.email', 'atlas-loop@local'], $dir);
        $this->runProcess(['git', 'config', 'user.name', 'Atlas Loop'], $dir);
        $this->runProcess(['git', 'add', '-A'], $dir);
        $this->runProcess(['git', 'commit', '-q', '-m', 'baseline'], $dir);
        file_put_contents($dir.'/src/Bar.php', "<?php\nfinal class Bar { public function value(): string { return 'green'; } }\n");

        return $dir;
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
