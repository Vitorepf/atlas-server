<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction;

use App\Services\Ai\AutonomousEvolution\AtlasLoopMasterSwitch;
use App\Services\Ai\SelfConstruction\AgentControlPlaneTaskPacketBuilder;
use App\Services\Ai\SelfConstruction\AtlasTaskServingService;
use App\Services\Ai\SelfConstruction\AtlasTaskServingStack;
use App\Services\Ai\SelfConstruction\AtlasTaskServingSwitch;
use App\Services\Ai\SelfConstruction\TaskServing\AtlasRefactorChainComposer;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Proves the heavy-refactor DESIGN SPEC GATE (builder) and the REFACTOR CHAIN
 * (composer + dependency-gated serving): a 3+-file refactor without a complete
 * seam decision warns (or blocks under the operator flag); a complete spec
 * rides the packet to the served worker; the composed chain serves stage by
 * stage — s2 is unclaimable until s1 resolves.
 */
final class AtlasRefactorDesignSpecAndChainTest extends TestCase
{
    private string $envFile = '';

    private const SPEC = [
        'problem' => 'the report pipeline is duplicated across three services',
        'proposed_abstraction' => 'extract ReportPipeline with normalize/validate/stamp as the single seam',
        'rejected_alternative' => 'a trait mixin was rejected: it hides the seam and keeps duplication callable',
        'risk' => 'callers depend on implicit ordering of normalize before validate',
        'expected_delta' => 'duplicate_blocks and loc shrink across the three services',
        'callers' => ['app/Services/Reports/Daily.php', 'app/Services/Reports/Weekly.php'],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->envFile = sys_get_temp_dir().'/atlas-rds-env-'.bin2hex(random_bytes(5)).'.env';
        file_put_contents($this->envFile, "ATLAS_LOOP_MASTER_ENABLED=true\n");
        AtlasLoopMasterSwitch::$envPathOverride = $this->envFile;
        AtlasTaskServingSwitch::on();
    }

    protected function tearDown(): void
    {
        AtlasLoopMasterSwitch::$envPathOverride = null;
        AtlasTaskServingSwitch::$envPathOverride = null;
        @unlink($this->envFile);
        parent::tearDown();
    }

    // ── (1) the builder gate ─────────────────────────────────────────────────────

    public function test_heavy_refactor_without_design_spec_warns_by_default_and_blocks_under_flag(): void
    {
        $input = [
            'objective' => 'refactor the report pipeline seam across services',
            'allowed_files' => ['app/Services/Reports/A.php', 'app/Services/Reports/B.php', 'app/Services/Reports/C.php'],
            'scope_in' => ['app/Services/Reports/A.php', 'app/Services/Reports/B.php', 'app/Services/Reports/C.php'],
            'acceptance_criteria' => ['pipeline behavior preserved'],
            'required_evidence' => ['tests_or_gates_result'],
        ];

        $packet = (new AgentControlPlaneTaskPacketBuilder)->build($input);
        $this->assertContains('heavy_refactor_without_design_spec', $packet['warnings']);
        $this->assertSame('planned', $packet['status']);

        config(['atlas_task_governance.refactor_design_spec_required' => true]);
        $blocked = (new AgentControlPlaneTaskPacketBuilder)->build($input);
        $this->assertContains('heavy_refactor_requires_design_spec', $blocked['blocking_reasons']);
        $this->assertNotSame('planned', $blocked['status']);
    }

    public function test_incomplete_spec_is_treated_as_no_spec(): void
    {
        $this->assertNull(AgentControlPlaneTaskPacketBuilder::normalizeRefactorDesignSpec([
            'problem' => 'duplicated pipeline everywhere in reports',
            'proposed_abstraction' => 'extract the pipeline seam',
            // rejected_alternative missing → incomplete → null
            'risk' => 'ordering dependency between steps',
            'expected_delta' => 'loc and duplication shrink',
            'callers' => ['app/A.php'],
        ]));
    }

    public function test_complete_spec_rides_the_packet_to_the_served_worker(): void
    {
        $exit = Artisan::call('atlas:task:enqueue', [
            '--file' => $this->batchFile([[
                'task_packet_id' => 'rds-spec-ride',
                'objective' => 'refactor the report pipeline seam across the three services',
                'allowed_files' => ['app/Services/Reports/A.php', 'app/Services/Reports/B.php', 'app/Services/Reports/C.php'],
                'acceptance_criteria' => ['pipeline behavior preserved end to end'],
                'required_evidence' => ['tests_or_gates_result'],
                'refactor_design_spec' => self::SPEC,
            ]]),
            '--json' => true,
        ]);
        $this->assertSame(0, $exit);

        $serving = new AtlasTaskServingService(AtlasTaskServingStack::orchestrator());
        $res = $serving->next('client-rds');
        $this->assertSame('served', $res['status'], json_encode($res));
        $this->assertSame(self::SPEC['proposed_abstraction'], data_get($res, 'task.refactor_design_spec.proposed_abstraction'));
    }

    // ── (2) the chain: composed, ordered, dependency-gated ──────────────────────

    public function test_chain_serves_stage_by_stage_through_the_dependency_gate(): void
    {
        $chain = (new AtlasRefactorChainComposer)->compose([
            'objective' => 'refatore o pipeline de relatórios para a costura única',
            'refactor_design_spec' => self::SPEC,
            'seam_files' => ['app/Services/Reports/ReportPipeline.php'],
            'caller_files' => ['app/Services/Reports/Daily.php', 'app/Services/Reports/Weekly.php'],
            'legacy_files' => ['app/Services/Reports/LegacyPipelineHelper.php'],
            'proof_files' => ['tests/Unit/Reports/ReportPipelineEquivalenceTest.php'],
            'id_prefix' => 'rds-chain',
        ]);

        $this->assertCount(4, $chain);
        $this->assertSame([], $chain[0]['depends_on']);
        $this->assertSame(['rds-chain-s1-extract'], $chain[1]['depends_on']);
        $this->assertSame(['rds-chain-s2-migrate'], $chain[2]['depends_on']);
        $this->assertSame(['rds-chain-s3-remove'], $chain[3]['depends_on']);
        foreach ($chain as $stage) {
            $this->assertSame(self::SPEC, $stage['refactor_design_spec']);
        }

        $exit = Artisan::call('atlas:task:enqueue', ['--file' => $this->batchFile($chain), '--json' => true]);
        $this->assertSame(0, $exit);

        $serving = new AtlasTaskServingService(AtlasTaskServingStack::orchestrator());

        // Only s1 is claimable; s2..s4 are dependency-gated.
        $first = $serving->next('client-chain');
        $this->assertSame('served', $first['status'], json_encode($first));
        $this->assertSame('rds-chain-s1-extract', (string) data_get($first, 'task.task_packet_id'));

        // A second worker gets NO task (the rest of the chain waits on s1).
        $second = $serving->next('client-chain-2');
        $this->assertNotSame('served', $second['status'], json_encode($second));
    }

    public function test_empty_stage_groups_relink_the_chain(): void
    {
        $chain = (new AtlasRefactorChainComposer)->compose([
            'objective' => 'extract and migrate only',
            'refactor_design_spec' => self::SPEC,
            'seam_files' => ['app/Services/Reports/ReportPipeline.php'],
            'caller_files' => ['app/Services/Reports/Daily.php'],
            // no legacy_files / proof_files
            'id_prefix' => 'rds-short',
        ]);

        $this->assertCount(2, $chain);
        $this->assertSame(['rds-short-s1-extract'], $chain[1]['depends_on']);
    }

    /** @param list<array<string,mixed>> $specs */
    private function batchFile(array $specs): string
    {
        $path = sys_get_temp_dir().'/atlas-rds-batch-'.bin2hex(random_bytes(5)).'.json';
        file_put_contents($path, json_encode($specs));

        return $path;
    }
}
