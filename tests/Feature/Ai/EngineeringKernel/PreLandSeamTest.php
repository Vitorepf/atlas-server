<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\EngineeringKernel;

use App\Services\Ai\AtlasDecide\AtlasDecideLiveOutcomeFeedbackService;
use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopWiredCallerService;
use App\Services\Ai\AutonomousEvolution\Verify\AtlasLoopComprehensionGroundingGate;
use App\Services\Ai\EngineeringKernel\AuthorizedMergeAction;
use App\Services\Ai\EngineeringKernel\PressureLayerGuards;
use App\Services\Ai\Obra\AtlasBlastRadiusService;
use App\Services\Ai\SelfConstruction\Governance\AtlasTaskCommitGovernanceChain;
use App\Services\Ai\SelfConstruction\MergeGovernor\AtlasMergeGovernorReleaseDecisionLedger;
use App\Services\Ai\SelfConstruction\VerificationCourt\AtlasVerificationCourtVerdictLedger;
use Tests\TestCase;

/**
 * Gate for ATLAS REDONDO SLICE 4 — the PRE-LAND seam for the two weak-input guards.
 *
 * At land time the cartographer can only ground FQCNs a commit message cites in prose
 * (almost never any → fail-open, unproven: an honestly-low cadence). The pre-land seam
 * feeds it the RICH signal — the symbols the in-progress diff actually references via its
 * `use ...;` imports — so a clean wiring grounds and the verdict is PROVEN, and a
 * hallucinated import is caught before it lands.
 */
final class PreLandSeamTest extends TestCase
{
    private string $tmp;

    private AtlasDecideLiveOutcomeFeedbackService $feedback;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmp = sys_get_temp_dir().'/atlas_preland_'.uniqid('', true);
        @mkdir($this->tmp, 0775, true);
        $this->feedback = new AtlasDecideLiveOutcomeFeedbackService;
        $this->feedback->setLogPathForTesting($this->tmp.'/live_outcomes.jsonl');
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tmp.'/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->tmp);
        parent::tearDown();
    }

    private function guards(): PressureLayerGuards
    {
        return new PressureLayerGuards(
            new AtlasLoopWiredCallerService,
            new AtlasLoopComprehensionGroundingGate,
            $this->app->make(AtlasBlastRadiusService::class),
            $this->feedback,
        );
    }

    public function test_referenced_symbols_extracts_use_imports_as_the_rich_signal(): void
    {
        $source = <<<'PHP'
        <?php
        namespace App\Foo;
        use App\Services\Ai\EngineeringKernel\PressureLayerGuards;
        use App\Models\AiJob as Job;
        use function strlen;
        use Illuminate\Support\Str;
        class Bar {}
        PHP;

        $symbols = PressureLayerGuards::referencedSymbols($source);

        $this->assertContains('App\\Services\\Ai\\EngineeringKernel\\PressureLayerGuards', $symbols);
        $this->assertContains('App\\Models\\AiJob', $symbols);       // `as` alias handled
        $this->assertContains('Illuminate\\Support\\Str', $symbols);
        $this->assertNotContains('strlen', $symbols);                // `use function` excluded
    }

    public function test_pre_land_seam_grounds_rich_symbols_and_records_proven_cadence(): void
    {
        $guards = $this->guards();

        $summary = $guards->observeInProgressDiff(
            ['app/Services/Ai/EngineeringKernel/FalseClaimInvariant.php'],
            ['app/Services/Ai/EngineeringKernel/FalseClaimInvariant.php'],
            // rich signal: real FQCNs the diff wires into (resolve via class_exists)
            ['App\\Services\\Ai\\EngineeringKernel\\PressureLayerGuards', 'App\\Models\\AiJob'],
            'wire the new organ',
            'hermes_cli',
        );

        $this->assertTrue($summary['advisory']);
        $this->assertFalse($summary['blocked']);

        $byGuard = [];
        foreach ($summary['verdicts'] as $v) {
            $byGuard[$v['guard']] = $v;
        }

        // context_cartographer: rich real symbols → PROVEN (the whole point of the seam).
        $this->assertTrue($byGuard[PressureLayerGuards::CONTEXT_CARTOGRAPHER]['pass']);
        $this->assertTrue(
            $byGuard[PressureLayerGuards::CONTEXT_CARTOGRAPHER]['proven_real'],
            'rich referenced symbols must lift the cartographer off fail-open',
        );
        // boundary_wiring_guard on the in-progress diff: within radius → proven.
        $this->assertTrue($byGuard[PressureLayerGuards::BOUNDARY_WIRING_GUARD]['pass']);

        // Cadence: both verdicts entered the ledger the Decision Core weighs.
        $this->assertSame(2, $summary['recorded']);
        $stats = $this->feedback->routeStats('programming', PressureLayerGuards::CONTEXT_CARTOGRAPHER);
        $this->assertGreaterThanOrEqual(1, $stats['total_calls_observed']);
        $this->assertSame(1, $stats['providers'][0]['proven_success']);
    }

    public function test_pre_land_seam_is_richer_than_the_post_land_prose_only_path(): void
    {
        $guards = $this->guards();
        $objective = 'wire the new organ'; // no FQCN cited in prose

        // Post-land path: the cartographer only sees prose FQCNs (none) → fail-open, UNPROVEN.
        $landed = $guards->contextCartographer($objective, [], base_path());
        $this->assertTrue($landed['pass']);
        $this->assertFalse($landed['proven_real'], 'prose-only grounding is honestly fail-open');

        // Pre-land seam: same objective, but fed the diff\'s referenced symbols → PROVEN.
        $pre = $guards->observeInProgressDiff(
            ['app/Models/AiJob.php'],
            ['app/Models/AiJob.php'],
            ['App\\Services\\Ai\\EngineeringKernel\\PressureLayerGuards'],
            $objective,
            'hermes_cli',
            'programming',
            false, // no ledger write for this contrast assertion
        );
        $cart = null;
        foreach ($pre['verdicts'] as $v) {
            if ($v['guard'] === PressureLayerGuards::CONTEXT_CARTOGRAPHER) {
                $cart = $v;
            }
        }
        $this->assertNotNull($cart);
        $this->assertTrue($cart['proven_real'], 'the rich pre-land signal raises cadence off fail-open');
    }

    public function test_pre_land_seam_catches_a_hallucinated_import_before_it_lands(): void
    {
        $guards = $this->guards();

        $summary = $guards->observeInProgressDiff(
            ['app/Services/Ai/NewThing.php'],
            ['app/Services/Ai/NewThing.php'],
            ['App\\Services\\Ai\\AtlasNoSuchImport_ZZZ_9999'],
            'wire a phantom dependency',
            'claude_cli',
            'programming',
            false,
        );

        $cart = null;
        foreach ($summary['verdicts'] as $v) {
            if ($v['guard'] === PressureLayerGuards::CONTEXT_CARTOGRAPHER) {
                $cart = $v;
            }
        }
        $this->assertNotNull($cart);
        $this->assertFalse($cart['pass'], 'a hallucinated import must be caught pre-land');
        $this->assertTrue($guards->gate($cart)['blocked']);
    }

    public function test_command_runs_the_pre_land_seam_over_a_real_file(): void
    {
        // Dry run (no --record) over a real repo file → exits clean, extracts its imports.
        $this->artisan('atlas:pressure:preland', [
            '--file' => ['app/Services/Ai/EngineeringKernel/PressureLayerGuards.php'],
            '--objective' => 'reuse the pressure guards',
            '--json' => true,
        ])->assertExitCode(0);
    }

    public function test_prepare_phase_persists_authorized_action_without_budget_posture_escalation(): void
    {
        $chain = new AtlasTaskCommitGovernanceChain(
            verdictLedger: new AtlasVerificationCourtVerdictLedger($this->tmp.'/verdict.jsonl'),
            releaseLedger: new AtlasMergeGovernorReleaseDecisionLedger($this->tmp.'/release.jsonl'),
            clock: static fn (): string => '2026-01-01T00:00:00+00:00',
            modeOverride: AtlasTaskCommitGovernanceChain::MODE_ENFORCE,
        );

        $out = $chain->govern([
            'task_packet_id' => 'task-authority',
            'project_id' => 'atlas-self-construction',
            'changed_files' => ['app/Services/Ai/EngineeringKernel/MergeActuator.php'],
            'verification' => ['passed' => true, 'evidence_hash' => 'ev-authority'],
            'budget_posture' => 'unbounded_quality_first',
        ]);

        $this->assertTrue($out['admitted']);
        $this->assertFalse($out['enforced_block']);
        $this->assertIsArray($out['authorized_merge_action']);

        $action = AuthorizedMergeAction::fromArray($out['authorized_merge_action']);
        $this->assertSame('commit', $action->action);
        $this->assertSame('unbounded_quality_first', $action->metadata['budget_posture']);
        $this->assertSame(['app/Services/Ai/EngineeringKernel/MergeActuator.php'], $action->files);
        $this->assertNotEmpty((new AtlasMergeGovernorReleaseDecisionLedger($this->tmp.'/release.jsonl'))->all());
    }
}
