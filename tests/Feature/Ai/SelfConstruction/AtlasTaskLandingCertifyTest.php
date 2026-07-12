<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\AtlasTaskCommitVerificationGate;
use App\Services\Ai\SelfConstruction\AtlasTaskScopedCommitter;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * ENG-04 — unified autonomosGate certify on the primary Autônomos landing seam
 * (verify → certify → commit). Harness-captured phpunit counts; fail-closed on
 * false claims; docs-only boot_proven landings preserved.
 */
final class AtlasTaskLandingCertifyTest extends TestCase
{
    private string $repo = '';

    /** @var list<string> */
    private array $dirs = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->repo = sys_get_temp_dir().'/atlas-landing-certify-'.bin2hex(random_bytes(5));
        $this->dirs[] = $this->repo;
        @mkdir($this->repo, 0775, true);
        $this->git(['init', '-q']);
        $this->git(['config', 'user.email', 'test@atlas.local']);
        $this->git(['config', 'user.name', 'Atlas Test']);
        @file_put_contents($this->repo.'/README.md', "seed\n");
        $this->git(['add', 'README.md']);
        $this->git(['commit', '-q', '-m', 'seed']);
    }

    protected function tearDown(): void
    {
        foreach ($this->dirs as $d) {
            File::deleteDirectory($d);
        }
        parent::tearDown();
    }

    /**
     * (a) Declared tests + claimed pass + zero executed → FalseClaimInvariant refuses commit.
     */
    public function test_declared_tests_claimed_pass_zero_executed_refuses_commit(): void
    {
        $files = ['app/Services/Ai/SelfConstruction/Foo.php', 'tests/Unit/Ai/SelfConstruction/FooTest.php'];
        $this->writeFile($files[0], "<?php\nclass Foo {}\n");
        $this->writeFile($files[1], "<?php\nclass FooTest {}\n");

        $verification = $this->verifyWithRunner($files, [
            'lint' => ['ran' => true, 'ok' => true, 'out' => ''],
            'boot' => ['ran' => true, 'ok' => true, 'out' => ''],
            'test' => ['ran' => true, 'ok' => true, 'out' => 'OK (0 tests, 0 assertions)'],
        ]);

        $this->assertSame('task_tests_proven', $verification['proof_strength']);
        $this->assertSame(0, $verification['execution_evidence']['tests_run']);

        $committer = new AtlasTaskScopedCommitter(null, $this->repo);
        $result = $committer->commitScope($files, 'task-false-claim', 'client-a', 'wire foo', $verification);

        $this->assertFalse($result['committed']);
        $this->assertSame('landing_certify_refused', $result['reason']);
        $this->assertContains('false_claim_blocked', (array) data_get($result, 'landing_certify.verdict.blockers'));
    }

    /**
     * (b) Declared tests + green run with parsed counts → certify promoted, commit proceeds with receipt.
     */
    public function test_declared_tests_green_with_parsed_counts_certifies_promoted_and_commits(): void
    {
        $files = ['app/Services/Ai/SelfConstruction/Bar.php', 'tests/Unit/Ai/SelfConstruction/BarTest.php'];
        $this->writeFile($files[0], "<?php\nclass Bar {}\n");
        $this->writeFile($files[1], "<?php\nclass BarTest {}\n");

        $verification = $this->verifyWithRunner($files, [
            'lint' => ['ran' => true, 'ok' => true, 'out' => ''],
            'boot' => ['ran' => true, 'ok' => true, 'out' => ''],
            'test' => ['ran' => true, 'ok' => true, 'out' => "OK (3 tests, 9 assertions)\n"],
        ]);

        $this->assertSame('task_tests_proven', $verification['proof_strength']);
        $this->assertSame(3, $verification['execution_evidence']['tests_run']);
        $this->assertSame(9, $verification['execution_evidence']['assertions_executed']);
        $this->assertTrue($verification['execution_evidence']['counts_parseable']);

        $committer = new AtlasTaskScopedCommitter(null, $this->repo);
        $result = $committer->commitScope($files, 'task-green', 'client-b', 'wire bar', $verification);

        $this->assertTrue($result['committed'], json_encode($result));
        $this->assertTrue((bool) data_get($result, 'landing_certify.promoted'));
        $this->assertNotEmpty(data_get($result, 'landing_certify.receipt_ref'));
        $this->assertSame('landing_certify_admitted', data_get($result, 'landing_certify.reason'));
    }

    /**
     * (c) No tests declared in scope → boot_proven honest receipt, commit proceeds (docs-only preserved).
     */
    public function test_no_tests_declared_boot_proven_commits_with_honest_receipt(): void
    {
        $files = ['docs/engineering-knowledge-base/example-doc.md'];
        $this->writeFile($files[0], "# Example\n\nDocs-only landing.\n");

        $verification = $this->verifyWithRunner($files, [
            'lint' => ['ran' => true, 'ok' => true, 'out' => ''],
            'boot' => ['ran' => true, 'ok' => true, 'out' => 'env ok'],
        ]);

        $this->assertSame('boot_proven', $verification['proof_strength']);
        $this->assertSame('boot_verified', $verification['execution_evidence']['claimed_status']);
        $this->assertSame(0, $verification['execution_evidence']['tests_run']);

        $committer = new AtlasTaskScopedCommitter(null, $this->repo);
        $result = $committer->commitScope($files, 'task-docs', 'client-c', 'doc only', $verification);

        $this->assertTrue($result['committed'], json_encode($result));
        $this->assertSame('boot_proven', data_get($result, 'landing_certify.proof_strength'));
        $this->assertNotEmpty(data_get($result, 'landing_certify.receipt_ref'));
        $this->assertSame('landing_certify_admitted', data_get($result, 'landing_certify.reason'));
    }

    /**
     * Extra: malformed phpunit output with declared tests → refuse (give_back), do not poison main.
     */
    public function test_malformed_phpunit_output_refuses_commit(): void
    {
        $files = ['app/Services/Ai/SelfConstruction/Baz.php', 'tests/Unit/Ai/SelfConstruction/BazTest.php'];
        $this->writeFile($files[0], "<?php\nclass Baz {}\n");
        $this->writeFile($files[1], "<?php\nclass BazTest {}\n");

        $verification = $this->verifyWithRunner($files, [
            'lint' => ['ran' => true, 'ok' => true, 'out' => ''],
            'boot' => ['ran' => true, 'ok' => true, 'out' => ''],
            'test' => ['ran' => true, 'ok' => true, 'out' => 'garbled runner noise without test summary'],
        ]);

        $this->assertSame('task_tests_proven', $verification['proof_strength']);
        $this->assertFalse($verification['execution_evidence']['counts_parseable']);

        $committer = new AtlasTaskScopedCommitter(null, $this->repo);
        $result = $committer->commitScope($files, 'task-malformed', 'client-d', 'wire baz', $verification);

        $this->assertFalse($result['committed']);
        $this->assertSame('landing_certify_refused', $result['reason']);
        $this->assertSame('seed', trim($this->git(['log', '-1', '--pretty=%s'])['out']));
    }

    public function test_edit_after_green_test_makes_attestation_stale_and_refuses_commit(): void
    {
        $files = ['app/Services/Ai/SelfConstruction/Qux.php', 'tests/Unit/Ai/SelfConstruction/QuxTest.php'];
        $this->writeFile($files[0], "<?php\nclass Qux { public function value(): int { return 1; } }\n");
        $this->writeFile($files[1], "<?php\nclass QuxTest {}\n");

        $verification = $this->verifyWithRunner($files, [
            'lint' => ['ran' => true, 'ok' => true, 'out' => ''],
            'boot' => ['ran' => true, 'ok' => true, 'out' => ''],
            'test' => ['ran' => true, 'ok' => true, 'out' => "OK (1 test, 2 assertions)\n"],
        ]);
        $this->assertSame('valid', data_get($verification, 'test_attestation.status'));

        $this->writeFile($files[0], "<?php\nclass Qux { public function value(): int { return 2; } }\n");

        $committer = new AtlasTaskScopedCommitter(null, $this->repo);
        $result = $committer->commitScope($files, 'task-stale-attestation', 'client-e', 'wire qux', $verification);

        $this->assertFalse($result['committed']);
        $this->assertSame('landing_certify_refused', $result['reason']);
        $this->assertSame('attestation_stale', data_get($result, 'landing_certify.reason'));
        $this->assertSame('seed', trim($this->git(['log', '-1', '--pretty=%s'])['out']));
    }

    public function test_parse_run_counts_extracts_phpunit_summary(): void
    {
        [$tests, $assertions, $parseable] = AtlasTaskCommitVerificationGate::parseRunCounts("OK (5 tests, 12 assertions)\n");
        $this->assertSame([5, 12, true], [$tests, $assertions, $parseable]);

        [$tests2, $assertions2, $parseable2] = AtlasTaskCommitVerificationGate::parseRunCounts('5 passed (9 assertions)');
        $this->assertSame([5, 9, true], [$tests2, $assertions2, $parseable2]);

        [$tests3, $assertions3, $parseable3] = AtlasTaskCommitVerificationGate::parseRunCounts('not a test summary');
        $this->assertSame([0, 0, false], [$tests3, $assertions3, $parseable3]);
    }

    /**
     * @param  list<string>  $files
     * @param  array<string,array{ran:bool,ok:bool,out:string}>  $map
     * @return array<string,mixed>
     */
    private function verifyWithRunner(array $files, array $map): array
    {
        foreach ($files as $rel) {
            if (str_ends_with($rel, '.php')) {
                $this->writeFile($rel, "<?php\n// fixture\n");
            }
        }

        $runner = function (array $cmd, string $_cwd, float $_t) use ($map): array {
            $kind = in_array('-l', $cmd, true) ? 'lint' : (in_array('about', $cmd, true) ? 'boot' : (in_array('test', $cmd, true) ? 'test' : 'other'));

            return $map[$kind] ?? ['ran' => true, 'ok' => true, 'out' => ''];
        };

        return (new AtlasTaskCommitVerificationGate($this->repo, $runner))->verify($files, 'verify-fixture');
    }

    private function writeFile(string $rel, string $content): void
    {
        $path = $this->repo.'/'.$rel;
        @mkdir(dirname($path), 0775, true);
        @file_put_contents($path, $content);
    }

    /** @param list<string> $args @return array{code:int,out:string,err:string} */
    private function git(array $args): array
    {
        $p = new Process(array_merge(['git'], $args), $this->repo);
        $p->run();

        return ['code' => (int) $p->getExitCode(), 'out' => $p->getOutput(), 'err' => $p->getErrorOutput()];
    }
}
