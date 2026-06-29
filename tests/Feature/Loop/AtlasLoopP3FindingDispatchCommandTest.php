<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * Proves the P3 finding dispatcher is live at the operator surface and emits deterministic facts: scanning a
 * tiny scoped workspace returns the dispatch envelope (auto_loop / flags / summary) and reports the number of
 * code files actually scanned. Read-only — nothing is dispatched.
 */
final class AtlasLoopP3FindingDispatchCommandTest extends TestCase
{
    private ?string $workspace = null;

    protected function tearDown(): void
    {
        if ($this->workspace !== null && is_dir($this->workspace)) {
            (new Process(['rm', '-rf', $this->workspace]))->run();
        }
        parent::tearDown();
    }

    public function test_command_is_registered(): void
    {
        $this->assertArrayHasKey('atlas:loop:p3-finding-dispatch', Artisan::all());
    }

    public function test_emits_dispatch_envelope_for_a_scoped_workspace(): void
    {
        $this->workspace = sys_get_temp_dir().'/p3_dispatch_'.bin2hex(random_bytes(6));
        mkdir($this->workspace.'/app', 0777, true);
        file_put_contents($this->workspace.'/app/Sample.php', $this->sampleSource());

        $exit = Artisan::call('atlas:loop:p3-finding-dispatch', [
            '--repo-root' => $this->workspace,
            '--code-roots' => 'app',
            '--docs-roots' => '', // no docs to scan
            '--max-files' => 50,
            '--json' => true,
        ]);
        $decoded = json_decode(trim(Artisan::output()), true);

        $this->assertSame(0, $exit);
        $this->assertSame('atlas.loop.p3_dispatch.v1', $decoded['schema_version']);
        $this->assertSame($this->workspace, $decoded['repo_root']);
        $this->assertIsArray($decoded['auto_loop']);
        $this->assertIsArray($decoded['flags']);
        $this->assertSame(1, $decoded['summary']['code_files_scanned']);
        $this->assertSame(0, $decoded['summary']['docs_scanned']);
    }

    private function sampleSource(): string
    {
        return "<?php\n\nclass Sample\n{\n    public function used(): int\n    {\n        return \$this->helper();\n    }\n\n    private function helper(): int\n    {\n        return 1;\n    }\n}\n";
    }
}
