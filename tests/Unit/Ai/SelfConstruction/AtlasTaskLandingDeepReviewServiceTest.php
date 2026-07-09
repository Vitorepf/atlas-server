<?php

namespace Tests\Unit\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\AtlasTaskLandingDeepReviewService;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * GAP-COCKPIT-04 · the deep-review GENERATOR over a landed commit: deterministic
 * scope/lint/test-presence findings from a real throwaway git repo.
 */
class AtlasTaskLandingDeepReviewServiceTest extends TestCase
{
    private string $repo;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repo = sys_get_temp_dir().'/atlas-landing-deep-review-'.uniqid();
        File::makeDirectory($this->repo.'/app', 0755, true);
        File::makeDirectory($this->repo.'/tests', 0755, true);
        foreach ([
            ['git', 'init', '-q'],
            ['git', 'config', 'user.email', 'test@atlas.local'],
            ['git', 'config', 'user.name', 'Atlas Test'],
        ] as $cmd) {
            $this->git($cmd);
        }
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->repo);

        parent::tearDown();
    }

    public function test_clean_scoped_landing_with_test_gets_approve(): void
    {
        File::put($this->repo.'/app/Example.php', "<?php\n\nfinal class Example {}\n");
        File::put($this->repo.'/tests/ExampleTest.php', "<?php\n\nfinal class ExampleTest {}\n");
        $sha = $this->commitAll('clean landing');

        $packet = $this->service([
            'task_packet_id' => 'task-clean-01',
            'commit_sha' => $sha,
            'agent_id' => 'agent-1',
            'allowed_files' => ['app/Example.php', 'tests/ExampleTest.php'],
        ])->review($sha);

        $this->assertSame('clean', $packet['risk_level']);
        $this->assertSame('approve', $packet['recommendation']);
        $this->assertSame('task-clean-01', $packet['task_packet_id']);
        $this->assertSame([], $packet['findings']);
        $this->assertEqualsCanonicalizing(['app/Example.php', 'tests/ExampleTest.php'], $packet['files_changed']);
    }

    public function test_out_of_scope_and_broken_syntax_block_the_landing(): void
    {
        File::put($this->repo.'/app/InScope.php', "<?php\n\nfinal class InScope {}\n");
        // Out-of-scope file, committed AND syntactically broken at the commit.
        File::put($this->repo.'/app/Sneaky.php', "<?php\n\nfinal class Sneaky { broken");
        $sha = $this->commitAll('sneaky landing');

        $packet = $this->service([
            'task_packet_id' => 'task-sneaky-01',
            'commit_sha' => $sha,
            'agent_id' => 'agent-2',
            'allowed_files' => ['app/InScope.php'],
        ])->review('task-sneaky-01'); // resolve pelo task_packet_id, não pelo sha

        $this->assertSame($sha, $packet['sha']);
        $this->assertSame('blocking', $packet['risk_level']);
        $this->assertSame('reject', $packet['recommendation']);

        $categories = array_column($packet['findings'], 'category');
        $this->assertContains('scope_violation', $categories);
        $this->assertContains('syntax_error', $categories);
        $this->assertContains('no_test_touched', $categories);
    }

    public function test_unresolvable_ref_fails_closed(): void
    {
        $packet = $this->service(null)->review('nao-existe-task');

        $this->assertSame('blocking', $packet['risk_level']);
        $this->assertSame('unresolvable_ref', $packet['findings'][0]['category']);
    }

    public function test_semantic_mode_is_failopen_when_provider_unconfigured(): void
    {
        config()->set('atlas.provider_defaults.brain_default', '');
        config()->set('atlas.loop.default_provider', '');

        File::put($this->repo.'/app/Solo.php', "<?php\n\nfinal class Solo {}\n");
        $sha = $this->commitAll('semantic failopen');

        $packet = $this->service([
            'task_packet_id' => 'task-sem-01',
            'commit_sha' => $sha,
            'agent_id' => 'agent-3',
            'allowed_files' => ['app/Solo.php', 'tests/SoloTest.php'],
        ])->review($sha, semantic: true);

        // Provider off ⇒ só o bloco semantic degrada; o packet determinístico fica intacto.
        $this->assertSame('provider_unavailable', $packet['semantic']['status']);
        $this->assertContains('semantic_diff_review', $packet['checks_run']);
        $this->assertSame('warning', $packet['risk_level']); // no_test_touched (determinístico)
    }

    public function test_semantic_parser_accepts_markers_rejects_noise_and_honors_no_findings(): void
    {
        $service = $this->service(null);

        $parsed = $service->parseSemanticFindings(
            "blah\n[[FINDING]]p1|0.9|logic_bug|Caller X ignora retorno null em Foo.php:42[[END]]\n".
            "[[FINDING]]px|0.9|bad|inválido[[END]]\n".
            '[[FINDING]]p3|not-a-number|Style!|título ok[[END]]',
        );
        $this->assertCount(2, $parsed);
        $this->assertSame('p1', $parsed[0]['severity']);
        $this->assertSame(0.9, $parsed[0]['confidence']);
        $this->assertSame('semantic', $parsed[0]['source']);
        $this->assertSame('semantic', $parsed[1]['category']); // categoria inválida cai pro default
        $this->assertNull($parsed[1]['confidence']);

        $this->assertSame([], $service->parseSemanticFindings('texto [[NO_FINDINGS]] fim'));
        $this->assertNull($service->parseSemanticFindings('resposta sem marcador nenhum'));
    }

    /**
     * @param  array<string,mixed>|null  $receipt
     */
    private function service(?array $receipt): AtlasTaskLandingDeepReviewService
    {
        $receiptsPath = $this->repo.'/resolved.jsonl';
        if ($receipt !== null) {
            File::put($receiptsPath, json_encode([
                'schema_version' => 'atlas.self_construction.resolved_receipt.v1',
                'resolved_at' => '2026-07-09T12:00:00Z',
                ...$receipt,
            ])."\n");
        }

        return new AtlasTaskLandingDeepReviewService(
            guard: null,
            repoRootOverride: $this->repo,
            receiptsPathOverride: $receiptsPath,
        );
    }

    private function commitAll(string $message): string
    {
        $this->git(['git', 'add', '-A']);
        $this->git(['git', 'commit', '-q', '-m', $message, '--no-gpg-sign']);

        return trim($this->git(['git', 'rev-parse', 'HEAD']));
    }

    /**
     * @param  list<string>  $cmd
     */
    private function git(array $cmd): string
    {
        $process = new Process($cmd, $this->repo);
        $process->setTimeout(30);
        $process->mustRun();

        return $process->getOutput();
    }
}
