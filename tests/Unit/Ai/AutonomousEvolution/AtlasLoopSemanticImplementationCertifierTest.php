<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution;

use App\Services\Ai\AutonomousEvolution\AtlasLoopSemanticImplementationCertifier;
use Symfony\Component\Process\Process;
use Tests\TestCase;

final class AtlasLoopSemanticImplementationCertifierTest extends TestCase
{
    private string $workspace;

    protected function tearDown(): void
    {
        if (isset($this->workspace) && is_dir($this->workspace)) {
            (new Process(['rm', '-rf', $this->workspace]))->run();
        }

        parent::tearDown();
    }

    public function test_certifies_diff_with_external_refuter_and_receipt(): void
    {
        $this->workspace = $this->workspaceWithCandidate('good');
        $receiptPath = $this->workspace.'/receipt/semantic.json';

        $receipt = app(AtlasLoopSemanticImplementationCertifier::class)->certify($this->workspace, $this->acceptance(), [
            'objective' => 'make Foo return good',
            'allowed_files' => ['src/Foo.php'],
            'semantic_refuter_commands' => [$this->cleanRefuterCommand()],
            'provider_refuters_required' => 1,
            'refuter_provider' => 'deterministic_fixture',
            'receipt_path' => $receiptPath,
        ]);

        $this->assertTrue($receipt['certified']);
        $this->assertSame('semantic_implementation_certified_with_external_refuters', $receipt['level']);
        $this->assertSame(1, data_get($receipt, 'provider_refuters.executed'));
        $this->assertSame(0, data_get($receipt, 'provider_refuters.refuted'));
        $this->assertSame(0, data_get($receipt, 'adversarial_panel.refuted_count'));
        $this->assertTrue(data_get($receipt, 'evidence.diff_earned'));
        $this->assertFalse(data_get($receipt, 'invariants.merged_to_main'));
        $this->assertFileExists($receiptPath);
    }

    public function test_fails_closed_when_required_refuter_is_missing(): void
    {
        $this->workspace = $this->workspaceWithCandidate('good');

        $receipt = app(AtlasLoopSemanticImplementationCertifier::class)->certify($this->workspace, $this->acceptance(), [
            'objective' => 'make Foo return good',
            'allowed_files' => ['src/Foo.php'],
            'provider_refuters_required' => 1,
        ]);

        $this->assertFalse($receipt['certified']);
        $this->assertContains('provider_refuters_missing(required:1,executed:0)', $receipt['reasons']);
    }

    public function test_external_refutation_blocks_certificate(): void
    {
        $this->workspace = $this->workspaceWithCandidate('good');

        $receipt = app(AtlasLoopSemanticImplementationCertifier::class)->certify($this->workspace, $this->acceptance(), [
            'objective' => 'make Foo return good',
            'allowed_files' => ['src/Foo.php'],
            'semantic_refuter_commands' => [$this->refutingCommand('semantic_gap')],
            'provider_refuters_required' => 1,
        ]);

        $this->assertFalse($receipt['certified']);
        $this->assertContains('provider_refuter_1:semantic_gap', $receipt['reasons']);
    }

    /**
     * @return array<string,mixed>
     */
    private function acceptance(): array
    {
        return [
            'commands' => ['php tests/FooTest.php'],
            'allowed_globs' => ['src/**'],
            'frozen_globs' => ['tests/**'],
            'metric_kind' => 'gate',
            'revert_recheck' => true,
            'timeout_seconds' => 30,
        ];
    }

    private function workspaceWithCandidate(string $candidateValue): string
    {
        $dir = sys_get_temp_dir().'/atlas-semantic-cert-'.bin2hex(random_bytes(5));
        mkdir($dir.'/src', 0o755, true);
        mkdir($dir.'/tests', 0o755, true);
        file_put_contents($dir.'/src/Foo.php', $this->foo('bad'));
        file_put_contents($dir.'/tests/FooTest.php', <<<'PHP'
<?php
require __DIR__.'/../src/Foo.php';

$foo = new Foo();
if ($foo->value() !== 'good') {
    fwrite(STDERR, 'expected good');
    exit(1);
}
PHP);
        $this->runProcess(['git', 'init'], $dir);
        $this->runProcess(['git', 'config', 'user.email', 'atlas-loop@local'], $dir);
        $this->runProcess(['git', 'config', 'user.name', 'Atlas Loop'], $dir);
        $this->runProcess(['git', 'add', '-A'], $dir);
        $this->runProcess(['git', 'commit', '-q', '-m', 'baseline'], $dir);
        file_put_contents($dir.'/src/Foo.php', $this->foo($candidateValue));

        return $dir;
    }

    private function foo(string $value): string
    {
        return "<?php\nfinal class Foo\n{\n    public function value(): string\n    {\n        return '".$value."';\n    }\n}\n";
    }

    private function cleanRefuterCommand(): string
    {
        return <<<'CMD'
php -r '$p=getenv("ATLAS_SEMANTIC_REFUTER_PACKET"); $j=json_decode(file_get_contents($p), true); $ok=(($j["deterministic_gate"]["certified"] ?? false) === true) && (($j["adversarial_panel"]["refuted_count"] ?? 1) === 0); echo json_encode(["refuted"=>!$ok, "reason"=>$ok ? "packet_clean" : "packet_not_clean"]); exit(0);'
CMD;
    }

    private function refutingCommand(string $reason): string
    {
        return "php -r 'echo json_encode([\"refuted\"=>true,\"reason\"=>\"".$reason."\"]); exit(0);'";
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
