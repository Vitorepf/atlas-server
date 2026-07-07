<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Reality;

use App\Models\AtlasAurgEdge;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * SIS4 (Obra #20): edge validity is SQL. `AtlasAurgEdge::current($at)` must
 * return exactly the relations that held at instant T (valid_from <= T, not
 * expired/superseded). The as-of is REAL — an edge not yet valid or already
 * expired is excluded, reconciling with a hand-computed set on N dates.
 *
 * House pattern (sqlite :memory:): boot the base table, then run the REAL
 * migration up() so the actual temporal-truth columns are exercised.
 */
class AtlasAurgEdgesAsOfTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('atlas_aurg_edges', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('from_node_id', 300)->index();
            $table->string('to_node_id', 300)->index();
            $table->string('kind', 40)->index();
            $table->string('source', 60)->index();
            $table->float('confidence')->default(1.0);
            $table->json('meta')->default('{}');
            $table->timestamps();
            $table->unique(['from_node_id', 'to_node_id', 'kind'], 'uniq_atlas_aurg_edges_triple');
        });

        // Run the ACTUAL SIS4 migration — proves it adds the columns on sqlite.
        (require base_path('database/migrations/2026_07_07_181500_add_temporal_truth_to_atlas_aurg_edges.php'))->up();
    }

    private function edge(string $id, ?string $from, ?string $until): AtlasAurgEdge
    {
        return AtlasAurgEdge::create([
            'from_node_id' => "n_{$id}_a",
            'to_node_id' => "n_{$id}_b",
            'kind' => 'depends_on',
            'source' => 'test',
            'confidence' => 1.0,
            'meta' => [],
            'valid_from' => $from,
            'valid_until' => $until,
        ]);
    }

    public function test_migration_adds_temporal_columns(): void
    {
        foreach (['valid_from', 'valid_until', 'stale_after', 'superseded_by', 'authority_level'] as $col) {
            $this->assertTrue(Schema::hasColumn('atlas_aurg_edges', $col), "coluna {$col} deve existir após a migration");
        }
    }

    public function test_current_scope_is_a_real_asof_over_valid_time(): void
    {
        $this->edge('a', '2026-01-01T00:00:00Z', null);            // always current since Jan
        $this->edge('b', '2026-05-01T00:00:00Z', null);            // not valid before May
        $this->edge('c', '2026-01-01T00:00:00Z', '2026-04-01T00:00:00Z'); // expired after Apr

        $march = AtlasAurgEdge::query()->current(CarbonImmutable::parse('2026-03-01T00:00:00Z'))->pluck('from_node_id')->all();
        sort($march);
        $this->assertSame(['n_a_a', 'n_c_a'], $march);

        $june = AtlasAurgEdge::query()->current(CarbonImmutable::parse('2026-06-01T00:00:00Z'))->pluck('from_node_id')->all();
        sort($june);
        $this->assertSame(['n_a_a', 'n_b_a'], $june);

        // Reconciliation: SQL current() == hand-computed filter, on N dates.
        foreach (['2026-02-01', '2026-04-15', '2026-05-15', '2026-12-01'] as $d) {
            $at = CarbonImmutable::parse($d.'T00:00:00Z');
            $sql = AtlasAurgEdge::query()->current($at)->count();
            $expected = AtlasAurgEdge::all()->filter(function (AtlasAurgEdge $e) use ($at): bool {
                $validFrom = $e->valid_from === null || $e->valid_from <= $at;
                $notExpired = $e->valid_until === null || $e->valid_until > $at;

                return $validFrom && $notExpired && $e->superseded_by === null;
            })->count();
            $this->assertSame($expected, $sql, "reconciliação SQL≡scan falhou em {$d}");
        }
    }

    public function test_valid_from_is_stamped_on_create(): void
    {
        $edge = $this->edge('stamp', null, null);

        $this->assertNotNull($edge->valid_from, 'valid_from deve ser carimbado no create (validade começa ao aparecer)');
    }
}
