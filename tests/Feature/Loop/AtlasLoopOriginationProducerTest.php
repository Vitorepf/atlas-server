<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Models\AtlasLoopProposal;
use App\Services\Ai\AutonomousEvolution\AtlasLoopAutoMergeService;
use App\Services\Ai\AutonomousEvolution\AtlasLoopOriginationProducer;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * ACDE O1 — proves the origination producer is propose-only AND safe on the REAL drain path: a forbidden
 * self-target is never originated, OFF authors nothing, and an authored origination row (empty diff + no
 * acceptance_contract) is RETIRED by the live auto-merge drain — never merged, never clogging the queue.
 */
final class AtlasLoopOriginationProducerTest extends TestCase
{
    /** @var list<string> */
    private array $dirs = [];

    protected function setUp(): void
    {
        parent::setUp();
        if (! Schema::hasTable('atlas_loop_campaigns')) {
            foreach ([
                '2026_06_02_000100_create_atlas_loop_runtime_tables.php',
                '2026_06_02_000200_complete_atlas_loop_runtime_schema.php',
                '2026_06_11_000100_add_quality_columns_to_atlas_loop_tables.php',
            ] as $file) {
                (require base_path('database/migrations/'.$file))->up();
            }
        }
        AtlasLoopProposal::query()->delete();
        config(['atlas.ai.loop.auto_merge_to_main' => true]);
        config(['atlas.ai.loop.value_gate_enabled' => false]);
        config(['atlas.ai.loop.substance_floor_enabled' => false]);
    }

    protected function tearDown(): void
    {
        foreach ($this->dirs as $d) {
            (new Process(['rm', '-rf', $d]))->run();
        }
        parent::tearDown();
    }

    private function repo(): string
    {
        $d = sys_get_temp_dir().'/atlas-origination-'.bin2hex(random_bytes(4));
        mkdir($d, 0o755, true);
        $this->dirs[] = $d;
        file_put_contents($d.'/snippet.php', "<?php\nfunction val(){ return 1; }\n");
        (new Process(['git', 'init', '-q'], $d))->run();
        (new Process(['git', 'add', '-A'], $d))->run();
        (new Process(['git', '-c', 'user.email=t@t', '-c', 'user.name=t', 'commit', '-q', '-m', 'base', '--no-gpg-sign'], $d))->run();
        config(['atlas.ai.loop.multi_repo.enabled' => true]);
        $allowed = (array) config('atlas.ai.loop.multi_repo.allowed_repos', []);
        $allowed[] = realpath($d) ?: $d;
        config(['atlas.ai.loop.multi_repo.allowed_repos' => array_values(array_unique($allowed))]);

        return $d;
    }

    public function test_off_authors_nothing(): void
    {
        config(['atlas.loop.origination_producer_enabled' => false]);
        $this->assertNull((new AtlasLoopOriginationProducer)->produce('snippet.php', 'add a totals() method'));
        $this->assertSame(0, AtlasLoopProposal::query()->count(), 'OFF => no row => byte-identical');
    }

    public function test_a_forbidden_self_target_is_never_originated(): void
    {
        config(['atlas.loop.origination_producer_enabled' => true]);
        $id = (new AtlasLoopOriginationProducer)->produce(
            'app/Services/Ai/AutonomousEvolution/AtlasEvolutionFrozenJudge.php',
            'rewrite the judge',
        );
        $this->assertNull($id, 'the judge (a forbidden self-target) can never be originated against');
        $this->assertSame(0, AtlasLoopProposal::query()->count());
    }

    public function test_origination_row_is_retired_by_the_drain_never_merged(): void
    {
        config(['atlas.loop.origination_producer_enabled' => true]);
        $repo = $this->repo();

        $id = (new AtlasLoopOriginationProducer)->produce('snippet.php', 'add a totals() method', ['criteria_count' => 3]);
        $this->assertNotNull($id);

        $proposal = AtlasLoopProposal::findOrFail($id);
        $this->assertSame('', (string) $proposal->diff_text, 'origination is spec-only: no diff');
        $this->assertArrayNotHasKey('_acceptance_contract', (array) $proposal->quality, 'no frozen bar is authored');
        $this->assertArrayHasKey('_origination', (array) $proposal->quality);

        // The REAL drain re-selects it (certified_for_review + merged_to_main=false + reviewed_at NULL) and,
        // finding no acceptance_contract, fails closed and RETIRES it — never merged, never clogging.
        app(AtlasLoopAutoMergeService::class)->drain($repo, 5);

        $fresh = $proposal->fresh();
        $this->assertFalse((bool) $fresh->merged_to_main, 'an origination row is NEVER merged to main');
        $this->assertNotNull($fresh->reviewed_at, 'an origination row is retired (leaves the oldest-first queue — no clog)');
    }
}
