<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Services\Ai\AutonomousEvolution\WorkspaceProviderLoopExecutionDriver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use ReflectionMethod;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * ACDE Tier-1 #7: dependency-BODY grounding range-reads a cross-file callee's EXACT body from the
 * code-symbols read-model (line_start..line_end) so a weak engine calls it as written. Verifies the
 * load-bearing read primitive (correct line slice + cap) and its fail-safe (missing file/row => '').
 */
final class WorkspaceProviderDependencyBodyTest extends TestCase
{
    private string $root = '';

    protected function setUp(): void
    {
        parent::setUp();
        if (! Schema::hasTable('atlas_engineering_code_symbols')) {
            (require database_path('migrations/2026_05_02_010000_create_atlas_engineering_code_intelligence_tables.php'))->up();
            (require database_path('migrations/2026_06_08_233000_add_workspace_id_to_code_intelligence_tables.php'))->up();
        }
        $this->root = sys_get_temp_dir().'/atlas-depbody-'.bin2hex(random_bytes(4));
        mkdir($this->root.'/src', 0o755, true);
    }

    protected function tearDown(): void
    {
        if ($this->root !== '' && is_dir($this->root)) {
            (new Process(['rm', '-rf', $this->root]))->run();
        }
        parent::tearDown();
    }

    private function bodyFor(string $filePath, string $symbol, string $workspaceId): string
    {
        $m = new ReflectionMethod(WorkspaceProviderLoopExecutionDriver::class, 'dependencyBodyFor');
        $m->setAccessible(true);

        return (string) $m->invoke(app(WorkspaceProviderLoopExecutionDriver::class), $this->root, $filePath, $symbol, $workspaceId);
    }

    private function seedSymbol(string $filePath, string $symbol, int $start, int $end): void
    {
        DB::table('atlas_engineering_code_symbols')->insert([
            'id' => (string) Str::uuid(),
            'symbol_type' => 'method',
            'symbol_name' => $symbol,
            'file_path' => $filePath,
            'line_start' => $start,
            'line_end' => $end,
            'language' => 'php',
            'status' => 'active',
            'source_hash' => 'sha256:'.hash('sha256', $symbol),
            'workspace_id' => 'ws-test',
            'indexed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_range_reads_the_exact_symbol_body(): void
    {
        file_put_contents($this->root.'/src/Calc.php', "<?php\nclass Calc\n{\n    public function add(\$a, \$b)\n    {\n        return \$a + \$b;\n    }\n}\n");
        // add() spans lines 4..7 (1-based).
        $this->seedSymbol('src/Calc.php', 'add', 4, 7);

        $body = $this->bodyFor('src/Calc.php', 'add', 'ws-test');

        $this->assertStringContainsString('public function add($a, $b)', $body);
        $this->assertStringContainsString('return $a + $b;', $body);
        $this->assertStringNotContainsString('<?php', $body, 'only the symbol range, not the whole file');
        $this->assertStringNotContainsString('class Calc', $body);
    }

    public function test_fail_safe_on_missing_file_or_row(): void
    {
        // a row pointing at a file that does not exist on disk => ''
        $this->seedSymbol('src/Ghost.php', 'gone', 1, 3);
        $this->assertSame('', $this->bodyFor('src/Ghost.php', 'gone', 'ws-test'));
        // no row at all => ''
        $this->assertSame('', $this->bodyFor('src/Nope.php', 'missing', 'ws-test'));
    }
}
