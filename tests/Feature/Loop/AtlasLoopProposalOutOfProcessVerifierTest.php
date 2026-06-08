<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Services\Ai\AutonomousEvolution\AtlasLoopProposalOutOfProcessVerifier;
use Symfony\Component\Process\Process;
use Tests\TestCase;

final class AtlasLoopProposalOutOfProcessVerifierTest extends TestCase
{
    /** @var list<string> */
    private array $paths = [];

    protected function tearDown(): void
    {
        foreach ($this->paths as $path) {
            (new Process(['rm', '-rf', $path]))->run();
        }

        parent::tearDown();
    }

    public function test_independently_verifies_a_clean_deadcode_proposal(): void
    {
        $repo = $this->repoWithSubject($this->deadCodeOriginal());
        $runDir = $this->tmpDir('atlas-loop-verify-run');
        $proposals = $runDir.'/proposals.jsonl';
        $this->writeProposal($proposals, $this->proposal($this->deadRemovalDiff()));

        $summary = $this->app->make(AtlasLoopProposalOutOfProcessVerifier::class)
            ->verifyFile($repo, $runDir, $proposals, ['force' => true]);

        $this->assertSame('ok', $summary['status']);
        $this->assertSame(1, $summary['processed']);
        if ($summary['independently_verified'] !== 1) {
            $this->fail(json_encode($this->readFirstJsonl($runDir.'/refuted.jsonl'), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        }
        $this->assertSame(1, $summary['independently_verified']);
        $this->assertSame(0, $summary['refuted']);

        $verified = $this->readFirstJsonl($runDir.'/independently_verified.jsonl');
        $this->assertSame('independently_verified', $verified['outcome']);
        $this->assertTrue($verified['checks']['clean_checkout']);
        $this->assertTrue($verified['checks']['acceptance_green']);
        $this->assertTrue($verified['checks']['revert_red']);
        $this->assertFalse($verified['merged_to_main']);
    }

    public function test_refutes_a_change_when_the_clean_baseline_is_already_green(): void
    {
        $repo = $this->repoWithSubject("<?php\nfinal class Subject {\n    public function run(): int { return 1; }\n}\n");
        $runDir = $this->tmpDir('atlas-loop-verify-run');
        $proposals = $runDir.'/proposals.jsonl';
        $this->writeProposal($proposals, $this->proposal($this->deadRemovalDiff(), 'p-inert'));

        $summary = $this->app->make(AtlasLoopProposalOutOfProcessVerifier::class)
            ->verifyFile($repo, $runDir, $proposals, ['force' => true]);

        $this->assertSame(0, $summary['independently_verified']);
        $this->assertSame(1, $summary['refuted']);

        $refuted = $this->readFirstJsonl($runDir.'/refuted.jsonl');
        $this->assertSame('refuted', $refuted['outcome']);
        $this->assertContains('change_is_inert', $refuted['reasons']);
    }

    public function test_refutes_a_diff_that_does_not_apply_to_clean_head(): void
    {
        $repo = $this->repoWithSubject($this->deadCodeOriginal());
        $runDir = $this->tmpDir('atlas-loop-verify-run');
        $proposals = $runDir.'/proposals.jsonl';
        $this->writeProposal($proposals, $this->proposal($this->badDiff(), 'p-bad'));

        $summary = $this->app->make(AtlasLoopProposalOutOfProcessVerifier::class)
            ->verifyFile($repo, $runDir, $proposals, ['force' => true]);

        $this->assertSame(0, $summary['independently_verified']);
        $this->assertSame(1, $summary['refuted']);

        $refuted = $this->readFirstJsonl($runDir.'/refuted.jsonl');
        $this->assertSame('refuted', $refuted['outcome']);
        $this->assertContains('does_not_apply_clean', $refuted['reasons']);
        $this->assertFalse($refuted['checks']['diff_applies_clean']);
    }

    private function repoWithSubject(string $content): string
    {
        $repo = $this->tmpDir('atlas-loop-verify-repo');
        mkdir($repo.'/app', 0o755, true);
        file_put_contents($repo.'/app/Subject.php', $content);

        $this->runProcess(['git', 'init', '-q'], $repo);
        $this->runProcess(['git', 'config', 'user.email', 'loop-test@atlas'], $repo);
        $this->runProcess(['git', 'config', 'user.name', 'atlas-loop-test'], $repo);
        $this->runProcess(['git', 'add', '-A'], $repo);
        $this->runProcess(['git', 'commit', '-q', '-m', 'base', '--no-gpg-sign'], $repo);

        return $repo;
    }

    private function tmpDir(string $prefix): string
    {
        $path = sys_get_temp_dir().'/'.$prefix.'-'.bin2hex(random_bytes(4));
        mkdir($path, 0o755, true);
        $this->paths[] = $path;

        return $path;
    }

    /**
     * @param  list<string>  $command
     */
    private function runProcess(array $command, string $cwd): void
    {
        $process = new Process($command, $cwd, null, null, 60.0);
        $process->run();

        $this->assertTrue($process->isSuccessful(), implode(' ', $command)."\n".$process->getErrorOutput());
    }

    private function deadCodeOriginal(): string
    {
        return "<?php\nfinal class Subject {\n    public function run(): int { return 1; }\n    private function deadM(): int { return 2; }\n}\n";
    }

    /**
     * @return array<string,mixed>
     */
    private function proposal(string $diff, string $hash = 'p-good'): array
    {
        return [
            'at' => time(),
            'outcome' => 'certified',
            'mode' => 'deadcode',
            'path' => 'app/Subject.php',
            'finding' => 'method Subject::deadM@4',
            'proposal' => [
                'schema_version' => 'atlas.evolution.proposal.v1',
                'status' => 'certified_for_review',
                'provider' => 'fixture_provider',
                'diff_text' => $diff,
                'proposal_hash' => $hash,
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function writeProposal(string $path, array $payload): void
    {
        file_put_contents($path, json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).PHP_EOL);
    }

    /**
     * @return array<string,mixed>
     */
    private function readFirstJsonl(string $path): array
    {
        $line = (string) fgets(fopen($path, 'rb') ?: throw new \RuntimeException('cannot open '.$path));
        $decoded = json_decode($line, true);

        $this->assertIsArray($decoded);

        return $decoded;
    }

    private function deadRemovalDiff(): string
    {
        $proposed = str_replace("    private function deadM(): int { return 2; }\n", '', $this->deadCodeOriginal());

        return $this->targetDiff($this->deadCodeOriginal(), $proposed);
    }

    private function badDiff(): string
    {
        return <<<'PATCH'
diff --git a/target.php b/target.php
--- a/target.php
+++ b/target.php
@@ -20,2 +20,2 @@
-this line is not present
+nor is this one
PATCH;
    }

    private function targetDiff(string $original, string $proposed): string
    {
        $dir = $this->tmpDir('atlas-loop-target-diff');
        file_put_contents($dir.'/target.php', $original);
        $this->runProcess(['git', 'init', '-q'], $dir);
        $this->runProcess(['git', 'config', 'user.email', 'loop-test@atlas'], $dir);
        $this->runProcess(['git', 'config', 'user.name', 'atlas-loop-test'], $dir);
        $this->runProcess(['git', 'add', '-A'], $dir);
        $this->runProcess(['git', 'commit', '-q', '-m', 'base', '--no-gpg-sign'], $dir);
        file_put_contents($dir.'/target.php', $proposed);

        $process = new Process(['git', 'diff', '--', 'target.php'], $dir, null, null, 60.0);
        $process->run();
        $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());

        return $process->getOutput();
    }
}
