<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Cognition\AcosProgram;

use App\Services\Ai\Cognition\AcosProgram\AcosMaxParallelExecutionProtocol;
use App\Services\Ai\AtlasAobgBlackboardService;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * TETO-06 — bancada: 2 engines em famílias distintas do mesmo lote; caso negativo = claim alheio ⇒ skip.
 */
final class Teto06ParallelExecutionProtocolTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->createBlackboardTable();
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('atlas_aobg_blackboard');
        parent::tearDown();
    }

    public function test_two_engines_claim_distinct_families_in_same_lote(): void
    {
        $protocol = $this->protocol();

        $cursor = $protocol->claimFamily('cursor', 1, 'ASI');
        $codex = $protocol->claimFamily('codex', 1, 'MAXL');

        $this->assertSame('proceed', $cursor['action']);
        $this->assertSame('claimed_by:cursor', $cursor['scoreboard_annotation']);
        $this->assertSame('proceed', $codex['action']);
        $this->assertSame('claimed_by:codex', $codex['scoreboard_annotation']);
        $this->assertNotSame($cursor['target'], $codex['target']);
    }

    public function test_negative_case_foreign_family_claim_forces_skip_with_registration(): void
    {
        $protocol = $this->protocol();

        $first = $protocol->claimFamily('cursor', 1, 'ASI');
        $this->assertSame('proceed', $first['action']);

        $second = $protocol->claimFamily('codex', 1, 'ASI');
        $this->assertSame('skip', $second['action']);
        $this->assertSame('conflict', $second['status']);
        $this->assertSame('claimed_by:cursor', $second['scoreboard_annotation']);
        $this->assertSame('family_claimed_by_other_engine:cursor', $second['skip_reason']);
        $this->assertNotSame('proceed', $second['action'], 'negative case: must not proceed on foreign family claim');
    }

    public function test_playbook_and_scoreboard_declare_teto06_protocol(): void
    {
        $root = dirname(__DIR__, 5);
        $playbook = (string) file_get_contents($root.'/docs/engineering-knowledge-base/atlas-acos-max-implementation-playbook-v1.md');
        $scoreboard = (string) file_get_contents($root.'/docs/engineering-knowledge-base/atlas-acos-max-execution-scoreboard-v1.md');

        $this->assertStringContainsString('## 2.1 TETO-06', $playbook);
        $this->assertStringContainsString('claimed_by:<engine>', $playbook);
        $this->assertStringContainsString('FAMÍLIA×lote', $playbook);
        $this->assertStringContainsString('claimed_by:<engine>', $scoreboard);
        $this->assertStringContainsString('TETO-06', $scoreboard);
    }

    private function protocol(): AcosMaxParallelExecutionProtocol
    {
        return new AcosMaxParallelExecutionProtocol(app(AtlasAobgBlackboardService::class));
    }

    private function createBlackboardTable(): void
    {
        Schema::dropIfExists('atlas_aobg_blackboard');
        $migration = require database_path('migrations/2026_06_10_120000_create_atlas_aobg_blackboard_table.php');
        $migration->up();
    }
}
