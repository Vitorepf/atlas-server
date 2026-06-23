<?php

declare(strict_types=1);

namespace Tests\Feature\Loop\Discovery;

use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopScopeRuntimeFacts;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * PART 1 · §1.3 — proves the FREE runtime facts are REAL reads of persisted loop state, not stubs:
 *   - hasGateBlock(file) lights up from a grind RESULT whose mutation-adequacy gate blocked the file;
 *   - lastMergeClean(file) reflects the file's most recent merged-to-main proposal's canary;
 *   - an empty DB yields NO facts (the safe "no evidence" default), never an error.
 */
final class AtlasLoopScopeRuntimeFactsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->ensureLoopTables();
        DB::table('atlas_loop_tasks')->delete();
        DB::table('atlas_loop_proposals')->delete();
    }

    protected function tearDown(): void
    {
        DB::table('atlas_loop_tasks')->delete();
        DB::table('atlas_loop_proposals')->delete();
        parent::tearDown();
    }

    public function test_has_gate_block_reads_a_blocked_grind_result(): void
    {
        $this->insertTaskResult($this->gateBlockedResult('app/Services/Ai/Foo.php'));

        $facts = new AtlasLoopScopeRuntimeFacts;
        $this->assertTrue($facts->hasGateBlock('app/Services/Ai/Foo.php'), 'a surviving-mutant gate block makes the file gate-blocked');
        $this->assertTrue($facts->hasGateBlock('/app/Services/Ai/Foo.php'), 'path is normalized (leading slash)');
        $this->assertFalse($facts->hasGateBlock('app/Services/Ai/Other.php'), 'an unrelated file is not blocked');
    }

    public function test_certified_result_is_not_a_gate_block(): void
    {
        // A certified report (certified !== false) must NOT register as a block.
        $this->insertTaskResult([
            'semantic_implementation_certification' => [
                'reports' => [['certified' => true, 'reasons' => [], 'mutation_adequacy_gate' => ['status' => 'ok']]],
            ],
        ]);

        $this->assertFalse((new AtlasLoopScopeRuntimeFacts)->hasGateBlock('app/Services/Ai/Foo.php'));
    }

    public function test_last_merge_clean_reads_merged_proposals(): void
    {
        $this->insertMergedProposal('app/Services/Ai/Green.php', ['_canary' => ['ran' => true, 'passed' => true]]);
        $this->insertMergedProposal('app/Services/Ai/Red.php', ['_canary' => ['ran' => true, 'passed' => false]]);

        $facts = new AtlasLoopScopeRuntimeFacts;
        $this->assertTrue($facts->lastMergeClean('app/Services/Ai/Green.php'), 'a green canary on the merged proposal is a clean merge');
        $this->assertFalse($facts->lastMergeClean('app/Services/Ai/Red.php'), 'a red canary is not clean');
        $this->assertFalse($facts->lastMergeClean('app/Services/Ai/Never.php'), 'a file with no merged proposal has no clean merge recorded');
    }

    public function test_empty_db_yields_no_facts(): void
    {
        $facts = new AtlasLoopScopeRuntimeFacts;
        $this->assertFalse($facts->hasGateBlock('app/Services/Ai/Foo.php'));
        $this->assertFalse($facts->lastMergeClean('app/Services/Ai/Foo.php'));
    }

    // --- fixtures --------------------------------------------------------------------------------------------

    /** A grind result whose mutation-adequacy gate blocked $file on a surviving NON-cosmetic decision mutant. */
    private function gateBlockedResult(string $file): array
    {
        return [
            'semantic_implementation_certification' => [
                'reports' => [
                    [
                        'certified' => false,
                        'reasons' => ['mutation_adequacy_gate:mutation_survived'],
                        'mutation_adequacy_gate' => [
                            'status' => 'mutation_survived',
                            'mutants' => [
                                [
                                    'survived' => true,
                                    'file' => $file,
                                    'operator' => 'negate_conditional', // not in COSMETIC_OPERATORS
                                    'mutation_id' => 'm1',
                                    'mutant_hash' => 'h1',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }

    private function insertTaskResult(array $result): void
    {
        DB::table('atlas_loop_tasks')->insert([
            'id' => (string) Str::uuid(),
            'campaign_id' => (string) Str::uuid(),
            'schema_version' => 'atlas.loop.task.v1',
            'status' => 'done',
            'source' => 'discovery',
            'target_path' => 'app/Services/Ai/Foo.php',
            'objective' => 'characterize runtime facts',
            'payload' => json_encode([], JSON_THROW_ON_ERROR),
            'dedupe_key' => substr(hash('sha256', (string) Str::uuid()), 0, 32),
            'result' => json_encode($result, JSON_THROW_ON_ERROR),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * @param  array<string,mixed>  $quality
     */
    private function insertMergedProposal(string $targetPath, array $quality): void
    {
        $payload = [
            'id' => (string) Str::uuid(),
            'campaign_id' => (string) Str::uuid(),
            'task_id' => null,
            'schema_version' => 'atlas.loop.proposal.v1',
            'status' => 'certified_for_review',
            'objective' => 'characterize runtime facts merge-clean',
            'provider' => 'phpunit',
            'target_path' => $targetPath,
            'diff_text' => 'n/a',
            'proposal_hash' => hash('sha256', json_encode([$targetPath, $quality, Str::uuid()->toString()], JSON_THROW_ON_ERROR)),
            'metric' => null,
            'quality' => json_encode($quality, JSON_THROW_ON_ERROR),
            'acceptance_hash' => null,
            'scenarios_explored' => 0,
            'scenarios_accepted' => 0,
            'winning_scenario' => null,
            'merged_to_main' => true,
            'reviewed_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ];

        DB::transaction(function () use ($payload): void {
            if (DB::connection()->getDriverName() === 'pgsql') {
                DB::statement("SET LOCAL atlas.governed_merge='on'");
            }
            DB::table('atlas_loop_proposals')->insert($payload);
        });
    }

    private function ensureLoopTables(): void
    {
        if (! Schema::hasTable('atlas_loop_proposals')) {
            foreach ([
                '2026_06_02_000100_create_atlas_loop_runtime_tables.php',
                '2026_06_02_000200_complete_atlas_loop_runtime_schema.php',
            ] as $file) {
                (require base_path('database/migrations/'.$file))->up();
            }
        }
        if (! Schema::hasColumn('atlas_loop_proposals', 'quality')) {
            (require base_path('database/migrations/2026_06_11_000100_add_quality_columns_to_atlas_loop_tables.php'))->up();
        }
        if (DB::connection()->getDriverName() === 'pgsql') {
            (require base_path('database/migrations/2026_06_12_000100_governed_merge_door_atlas_loop_proposals.php'))->up();
        }
    }
}
