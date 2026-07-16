<?php

declare(strict_types=1);

namespace Tests\Feature\AtlasCode;

use App\Models\AtlasLedgerEvent;
use App\Services\Ai\Kernel\Evidence\LedgerEventType;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Process\Process;
use Tests\TestCase;

final class AtlasCodeWhyControllerTest extends TestCase
{
    private string $root;

    private string $repo;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('atlas_ledger_events');
        (require database_path('migrations/2026_05_05_020000_create_atlas_ledger_events_table.php'))->up();
        (require database_path('migrations/2026_05_19_050000_extend_atlas_ledger_events_with_timeline_fields.php'))->up();

        $this->root = sys_get_temp_dir().'/atlas-code-why-'.uniqid();
        $this->repo = $this->root.'/produto/why-fixture';
        mkdir($this->repo.'/Sources', 0o777, true);
        putenv('ATLAS_CODE_WORKSPACE_ROOT='.$this->root);

        $this->git(['init', '-b', 'main']);
        $this->git(['config', 'user.name', 'Vitor']);
        $this->git(['config', 'user.email', 'vitordsny@gmail.com']);

        file_put_contents($this->repo.'/Sources/Old.swift', "let old = 1\n");
        $this->git(['add', 'Sources/Old.swift']);
        $first = $this->commit('feat(core): cria arquivo original');
        $this->recordProvenance($first, 'preciso da origem do arquivo', ['checks', 'build']);

        $this->git(['mv', 'Sources/Old.swift', 'Sources/New.swift']);
        $this->git(['add', 'Sources/New.swift']);
        $this->commit('polish(core): renomeia arquivo');

        file_put_contents($this->repo.'/Sources/New.swift', "let old = 1\nlet renamed = true\nlet final = 3\n");
        $this->git(['add', 'Sources/New.swift']);
        $this->commit('feat(core): ajusta arquivo final');
    }

    protected function tearDown(): void
    {
        putenv('ATLAS_CODE_WORKSPACE_ROOT');
        $this->removeDirectory($this->root);
        parent::tearDown();
    }

    public function test_returns_file_biography_with_real_provenance_and_nulls(): void
    {
        $response = $this->jsonWithToken('/api/code/why?repo=why-fixture&file=Sources/New.swift&limit=20');

        $response->assertOk()
            ->assertJsonPath('schema_version', 'atlas.code.why.v1')
            ->assertJsonPath('repo', 'why-fixture')
            ->assertJsonPath('file', 'Sources/New.swift')
            ->assertJsonPath('commits_total', 3)
            ->assertJsonPath('truncated', false);

        self::assertSame(
            [
                'feat(core): ajusta arquivo final',
                'polish(core): renomeia arquivo',
                'feat(core): cria arquivo original',
            ],
            array_column($response->json('commits'), 'subject'),
        );

        self::assertNull($response->json('commits.0.provenance'));
        self::assertNull($response->json('commits.1.provenance'));
        self::assertSame('preciso da origem do arquivo', $response->json('commits.2.provenance.quote'));
        self::assertSame('obra-17', $response->json('commits.2.provenance.obra'));
        self::assertSame(['checks', 'build'], $response->json('commits.2.provenance.gates'));
    }

    public function test_limit_sets_truncated_without_hiding_total(): void
    {
        $response = $this->jsonWithToken('/api/code/why?repo=why-fixture&file=Sources/New.swift&limit=2');

        $response->assertOk()
            ->assertJsonPath('commits_total', 3)
            ->assertJsonPath('truncated', true);

        self::assertCount(2, $response->json('commits'));
    }

    public function test_empty_history_is_not_an_error(): void
    {
        $this->jsonWithToken('/api/code/why?repo=why-fixture&file=Sources/NeverExisted.swift&limit=20')
            ->assertOk()
            ->assertJsonPath('commits_total', 0)
            ->assertJsonPath('truncated', false)
            ->assertJsonPath('commits', []);
    }

    public function test_invalid_file_path_is_rejected_before_git(): void
    {
        $this->jsonWithToken('/api/code/why?repo=why-fixture&file=../secrets.env')
            ->assertUnprocessable()
            ->assertJsonPath('error', 'invalid_file');

        $this->jsonWithToken('/api/code/why?repo=why-fixture&file=/tmp/secrets.env')
            ->assertUnprocessable()
            ->assertJsonPath('error', 'invalid_file');
    }

    public function test_unknown_or_ambiguous_repo_is_404_without_path_echo(): void
    {
        $this->jsonWithToken('/api/code/why?repo=missing&file=Sources/New.swift')
            ->assertNotFound()
            ->assertExactJson([]);

        mkdir($this->root.'/outro/duplicate-why/.git', 0o777, true);
        mkdir($this->root.'/mais/duplicate-why/.git', 0o777, true);

        $this->jsonWithToken('/api/code/why?repo=duplicate-why&file=README.md')
            ->assertNotFound()
            ->assertExactJson([]);
    }

    private function jsonWithToken(string $uri): \Illuminate\Testing\TestResponse
    {
        return $this->withHeaders([
            'Accept' => 'application/json',
            'X-Atlas-Token' => 'testing-atlas-token-with-enough-length',
        ])->getJson($uri);
    }

    /**
     * @param  array<int,string>  $args
     */
    private function git(array $args): string
    {
        $process = new Process(array_merge(['git'], $args), $this->repo, null, null, 10);
        $process->mustRun();

        return trim($process->getOutput());
    }

    private function commit(string $subject): string
    {
        $this->git(['commit', '-m', $subject]);

        return $this->git(['rev-parse', 'HEAD']);
    }

    /**
     * @param  array<int,string>  $gates
     */
    private function recordProvenance(string $hash, string $quote, array $gates): void
    {
        AtlasLedgerEvent::query()->create([
            'event_id' => substr(hash('sha256', $hash), 0, 32),
            'tenant_id' => 'test',
            'operator_id' => 'vitor',
            'envelope_id' => 'env-'.$hash,
            'correlation_id' => 'corr-'.$hash,
            'event_type' => LedgerEventType::CodeProvenanceRecorded->value,
            'emitter_stage' => 'test',
            'emitter_version' => 'test',
            'payload' => [
                'commit_hash' => $hash,
                'operator_quote' => $quote,
                'obra' => ['obra-17'],
                'gates' => $gates,
            ],
            'payload_hash' => hash('sha256', $hash.$quote),
            'occurred_at' => now(),
        ]);
    }

    private function removeDirectory(string $path): void
    {
        if ($path === '' || ! is_dir($path)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($path);
    }
}
