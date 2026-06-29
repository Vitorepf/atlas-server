<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Proves the test-presence oracle is live at the operator surface: a source with an observed coverage edge in
 * the ledger reports tested, one without reports untested, and a missing ledger table fails open to tested.
 */
final class AtlasLoopTestPresenceCommandTest extends TestCase
{
    private const TABLE = 'atlas_loop_test_coverage_edges';

    protected function tearDown(): void
    {
        Schema::dropIfExists(self::TABLE);
        parent::tearDown();
    }

    private function seedLedger(array $sourcePaths): void
    {
        Schema::dropIfExists(self::TABLE);
        Schema::create(self::TABLE, function (Blueprint $t): void {
            $t->increments('id');
            $t->string('source_path');
            $t->string('test_path')->nullable();
        });
        foreach ($sourcePaths as $p) {
            DB::table(self::TABLE)->insert(['source_path' => $p, 'test_path' => $p.'Test']);
        }
    }

    private function presence(string $file): array
    {
        $exit = Artisan::call('atlas:loop:test-presence', ['--file' => $file, '--json' => true]);

        return ['exit' => $exit, 'd' => json_decode(trim(Artisan::output()), true)];
    }

    public function test_source_with_coverage_edge_is_tested(): void
    {
        $this->seedLedger(['app/Services/Foo.php']);

        ['exit' => $exit, 'd' => $d] = $this->presence('app/Services/Foo.php');

        $this->assertSame(0, $exit);
        $this->assertSame('atlas.loop.test_presence.v1', $d['schema']);
        $this->assertTrue($d['has_test'], (string) json_encode($d));
    }

    public function test_source_without_coverage_edge_is_untested(): void
    {
        $this->seedLedger(['app/Services/Foo.php']);

        ['exit' => $exit, 'd' => $d] = $this->presence('app/Services/Bar.php');

        $this->assertSame(0, $exit);
        $this->assertFalse($d['has_test']);
    }

    public function test_missing_table_fails_open_tested(): void
    {
        Schema::dropIfExists(self::TABLE);

        ['exit' => $exit, 'd' => $d] = $this->presence('app/Services/Anything.php');

        $this->assertSame(0, $exit);
        $this->assertTrue($d['has_test']); // fail-open on infra absence
    }

    public function test_missing_file_is_usage_error(): void
    {
        $exit = Artisan::call('atlas:loop:test-presence', ['--json' => true]);

        $this->assertNotSame(0, $exit);
        $this->assertStringContainsString('usage_error', Artisan::output());
    }
}
