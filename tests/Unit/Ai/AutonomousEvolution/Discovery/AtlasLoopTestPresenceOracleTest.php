<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution\Discovery;

use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopTestPresenceOracle;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class AtlasLoopTestPresenceOracleTest extends TestCase
{
    private string $table = 'atlas_loop_test_coverage_edges';

    protected function setUp(): void
    {
        parent::setUp();
        if (! Schema::hasTable($this->table)) {
            Schema::create($this->table, function ($table) {
                $table->uuid('id')->primary();
                $table->string('source_path');
                $table->string('test_path');
                $table->integer('observed_count')->default(0);
                $table->timestamp('last_observed_at')->nullable();
                $table->timestamps();
            });
        }
    }

    private function seedEdge(string $source, string $test): void
    {
        \DB::table($this->table)->insert([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'source_path' => $source,
            'test_path' => $test,
            'observed_count' => 1,
            'last_observed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_returns_true_when_at_least_one_edge_exists_for_source(): void
    {
        $this->seedEdge('app/Services/Covered.php', 'tests/Unit/CoveredTest.php');
        $oracle = new AtlasLoopTestPresenceOracle();
        $this->assertTrue($oracle->hasTest('app/Services/Covered.php'));
    }

    public function test_returns_false_when_zero_edges_for_source(): void
    {
        $this->seedEdge('app/Services/Other.php', 'tests/Unit/OtherTest.php');
        $oracle = new AtlasLoopTestPresenceOracle();
        $this->assertFalse($oracle->hasTest('app/Services/Untested.php'));
    }

    public function test_fails_open_returning_true_when_table_missing(): void
    {
        // Drop the table to simulate "table not present yet".
        Schema::dropIfExists($this->table);
        $oracle = new AtlasLoopTestPresenceOracle();
        $this->assertTrue($oracle->hasTest('app/Services/Anything.php'));
    }

    public function test_is_request_memoized_via_reset(): void
    {
        $this->seedEdge('app/Services/A.php', 't.php');
        $oracle = new AtlasLoopTestPresenceOracle();
        $this->assertTrue($oracle->hasTest('app/Services/A.php'));

        // Insert a new edge AFTER memoization. Without reset, the oracle should not see it.
        $this->seedEdge('app/Services/B.php', 't2.php');
        $this->assertFalse($oracle->hasTest('app/Services/B.php'), 'memoized snapshot must not see post-load inserts');

        $oracle->reset();
        $this->assertTrue($oracle->hasTest('app/Services/B.php'), 'after reset, the fresh load picks up the new edge');
    }

    public function test_normalizes_leading_slash_and_backslashes(): void
    {
        $this->seedEdge('app/Foo/Bar.php', 'tests/X.php');
        $oracle = new AtlasLoopTestPresenceOracle();
        $this->assertTrue($oracle->hasTest('/app/Foo/Bar.php'));
        $this->assertTrue($oracle->hasTest('app\\Foo\\Bar.php'));
    }
}
