<?php

namespace Tests\Feature\Ai\Company\Ventures\Comprehension;

use App\Models\AiVentureComprehensionRun;
use App\Services\Ai\Strategy\StrategyCanonicalHash;
use App\Services\Ai\Company\Ventures\Comprehension\ComprehensionException;
use App\Services\Ai\Company\Ventures\Comprehension\ComprehensionRecorder;
use App\Services\Ai\Company\Ventures\Comprehension\FindingDraft;
use App\Services\Ai\Company\Ventures\Comprehension\WorkspaceReader;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesVentureComprehensionTables;
use Tests\TestCase;

class WorkspaceReaderTest extends TestCase
{
    use CreatesVentureComprehensionTables;

    private string $ws;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createVentureComprehensionTables();
        $this->ws = $this->makeFakeWorkspace();
    }

    protected function tearDown(): void
    {
        $this->removeFakeWorkspace($this->ws);
        $this->dropVentureComprehensionTables();
        parent::tearDown();
    }

    public function test_lists_code_files_and_excludes_vendor(): void
    {
        $reader = new WorkspaceReader($this->ws);
        $files = $reader->files(['php']);

        $this->assertContains('app/Http/Controllers/BillingController.php', $files);
        $this->assertContains('config/plans.php', $files);
        foreach ($files as $f) {
            $this->assertStringNotContainsString('vendor/', $f, 'vendor must be excluded');
        }
    }

    public function test_grep_returns_cited_matches(): void
    {
        $reader = new WorkspaceReader($this->ws);
        $todos = $reader->grep('/TODO|FIXME/', ['php']);

        $this->assertNotEmpty($todos);
        $first = $todos[0];
        $this->assertArrayHasKey('path', $first);
        $this->assertArrayHasKey('line', $first);
        $this->assertGreaterThan(0, $first['line']);

        $secrets = $reader->grep('/sk_live_[A-Za-z0-9]+/', ['php']);
        $this->assertNotEmpty($secrets, 'hardcoded secret must be found');
        $this->assertSame('app/Http/Controllers/BillingController.php', $secrets[0]['path']);
    }

    public function test_json_manifest_and_repo_roots(): void
    {
        $reader = new WorkspaceReader($this->ws);
        $composer = $reader->json('composer.json');
        $this->assertSame('Fake performance app for tests', $composer['description']);
        $this->assertArrayHasKey('laravel/cashier', $composer['require']);

        $this->assertContains('', $reader->repoRoots());
    }

    public function test_path_traversal_is_rejected(): void
    {
        $reader = new WorkspaceReader($this->ws);
        $this->assertNull($reader->read('../../../etc/passwd'));
        $this->assertNull($reader->read('..'));
        $this->assertSame([], $reader->lines('../outside.txt'));
    }

    public function test_missing_workspace_throws(): void
    {
        $this->expectException(ComprehensionException::class);
        new WorkspaceReader('/no/such/path/'.Str::random(8));
    }

    public function test_recorder_persists_with_dedup(): void
    {
        $run = AiVentureComprehensionRun::query()->create([
            'uuid' => (string) Str::uuid(),
            'venture_id' => (string) Str::uuid(),
            'workspace_path' => $this->ws,
            'status' => 'running',
            'run_hash' => StrategyCanonicalHash::sha256(['x' => Str::uuid()->toString()]),
        ]);

        $recorder = new ComprehensionRecorder;
        $draft = new FindingDraft(
            capability: 'problem',
            kind: 'secret',
            title: 'Hardcoded Stripe secret',
            severity: 'critical',
            evidencePath: 'app/Http/Controllers/BillingController.php',
            evidenceLine: 9,
        );

        $a = $recorder->record($run, $draft);
        $b = $recorder->record($run, $draft); // identical -> dedup

        $this->assertNotNull($a);
        $this->assertNull($b, 'identical finding in the same run must dedup');
        $this->assertSame(1, $run->findings()->count());
        $this->assertSame('critical', $a->severity);
        $this->assertSame(64, strlen($a->finding_hash));
    }
}
