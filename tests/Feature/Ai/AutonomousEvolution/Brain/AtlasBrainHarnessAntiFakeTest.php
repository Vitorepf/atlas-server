<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\AutonomousEvolution\Brain;

use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainDoneSetLedger;
use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainMasterSwitch;
use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainScopeDryProbe;
use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopScopeComprehensionModel;
use App\Services\Ai\SelfConstruction\AtlasTaskPacketQualityInspector;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * FROZEN anti-fake contract for the EXTERNAL BRAIN. Every case is ISOLATED — a faked serving disk + temp
 * done-set/journal/.env roots — so NOTHING here touches the live atlas_serving disk, storage/ledgers, the real
 * docs/ journal, or the operator's .env. The proofs are MECHANICAL: the unit served is the serving queue (not
 * the model self-judging), the STOP is the dry-probe's, and the brain can never seed against its own pétreo lock.
 */
final class AtlasBrainHarnessAntiFakeTest extends TestCase
{
    private const TEST_DISK = 'atlas_serving_brain_antifake_test';

    private string $doneSetRoot;

    private string $journalRoot;

    private string $envPath;

    protected function setUp(): void
    {
        parent::setUp();

        // SERVING isolation — a dedicated faked disk (never the live atlas_serving / shared local default).
        config()->set('atlas.task_serving.queue_disk', self::TEST_DISK);
        Storage::fake(self::TEST_DISK);
        Storage::fake('local');

        // BRAIN-writer isolation — done-set + journal go to a scratch dir, never storage/ledgers nor real docs/.
        $base = sys_get_temp_dir().'/atlas-brain-antifake-'.bin2hex(random_bytes(6));
        $this->doneSetRoot = $base.'/done-set';
        $this->journalRoot = $base.'/journal';
        @mkdir($this->doneSetRoot, 0775, true);
        @mkdir($this->journalRoot, 0775, true);
        config()->set('atlas.brain.done_set_root', $this->doneSetRoot);
        config()->set('atlas.brain.journal_root', $this->journalRoot);

        // SWITCH isolation — point the master switch at a temp .env (it parses the file directly, not config()).
        $this->envPath = $base.'/.env';
        file_put_contents($this->envPath, "APP_ENV=testing\n");
        AtlasBrainMasterSwitch::$envPathOverride = $this->envPath;

        // Controlled 'loop' test scope: single root (AutonomousEvolution), meta_harness ON by default (the armed
        // recursive-total reality). Case (g) flips THIS scope's meta to OFF to prove the harness_gated path.
        config()->set('atlas.brain.default_scope', 'loop');
        config()->set('atlas.brain.scopes.loop', [
            'label' => 'test',
            'roots' => ['app/Services/Ai/AutonomousEvolution'],
            'docs_roots' => [],
            'meta_harness' => true,
        ]);
    }

    protected function tearDown(): void
    {
        AtlasBrainMasterSwitch::$envPathOverride = null;
        parent::tearDown();
    }

    private function brainOn(): void
    {
        file_put_contents($this->envPath, "APP_ENV=testing\n".AtlasBrainMasterSwitch::KEY."=true\n");
    }

    private function brainOff(): void
    {
        file_put_contents($this->envPath, "APP_ENV=testing\n".AtlasBrainMasterSwitch::KEY."=false\n");
    }

    /**
     * A clean, grounded, runnable packet spec (cold-worker implementable). The allowed_file is OUTSIDE the
     * harness subtree (app/Models/...) so the harness guard admits it and the downstream gates are exercised.
     */
    private function cleanPacket(): array
    {
        return [
            'task_packet_id' => 'brain:clean-'.bin2hex(random_bytes(4)),
            'objective' => 'Harden App\\Models\\AtlasNonHarnessTarget so the computed field stays consistent — '
                .'add the missing validation guard and cover it.',
            'allowed_files' => ['app/Models/AtlasNonHarnessTarget.php'],
            'scope_in' => ['app/Models/AtlasNonHarnessTarget.php'],
            'acceptance_criteria' => ['php artisan test tests/Unit passes (no regressions)'],
            'evidence_requirements' => ['tests_or_gates_result'],
            'risk_level' => 'medium',
        ];
    }

    private function specsFile(array $packets): string
    {
        $path = sys_get_temp_dir().'/brain-specs-'.bin2hex(random_bytes(6)).'.json';
        file_put_contents($path, (string) json_encode(['packets' => $packets]));

        return $path;
    }

    // (a) clean grounded packet on --dry-run => gated ok (NOT enqueued, NOT blocked).
    public function test_a_clean_grounded_packet_dry_run_is_gated_ok(): void
    {
        $this->brainOn();
        $path = $this->specsFile([$this->cleanPacket()]);

        $exit = Artisan::call('atlas:brain:seed', ['--specs' => $path, '--dry-run' => true, '--json' => true]);
        self::assertSame(0, $exit);

        $payload = json_decode(trim(Artisan::output()), true);
        self::assertSame('ok', $payload['status']);
        self::assertTrue((bool) $payload['dry_run']);
        self::assertSame(0, (int) $payload['counts']['blocked'], 'clean packet must not be blocked');
        self::assertSame(0, (int) $payload['counts']['enqueued'], 'dry-run must enqueue nothing');
        self::assertSame(1, (int) $payload['counts']['dry_run']);
        self::assertSame('dry_run', $payload['results'][0]['status']);
        @unlink($path);
    }

    // (b) a vague + non-runnable + proxy packet => blocked (FIX-1 seed gate + classifier).
    public function test_b_vague_nonrunnable_proxy_packet_is_blocked(): void
    {
        $this->brainOn();
        $vague = [
            'task_packet_id' => 'brain:vague-1',
            'objective' => 'refactor and rename things', // proxy term + < 40 chars + no concrete anchor
            'allowed_files' => ['app/Services/Ai/AutonomousEvolution/Brain/AtlasBrainTaskSpecTranslator.php'],
            'scope_in' => ['app/Services/Ai/AutonomousEvolution/Brain/AtlasBrainTaskSpecTranslator.php'],
            'acceptance_criteria' => ['it looks better'], // no runnable signal
            'evidence_requirements' => ['tests_or_gates_result'],
        ];
        $path = $this->specsFile([$vague]);

        $exit = Artisan::call('atlas:brain:seed', ['--specs' => $path, '--dry-run' => true, '--json' => true]);
        self::assertSame(0, $exit);

        $payload = json_decode(trim(Artisan::output()), true);
        self::assertSame('ok', $payload['status']);
        self::assertSame(1, (int) $payload['counts']['blocked'], 'vague/proxy packet must be blocked');
        self::assertSame('blocked', $payload['results'][0]['status']);
        // The classifier fires FIRST (proxy objective), so the failing stage is the classifier.
        self::assertSame('classifier', $payload['results'][0]['stage']);
        @unlink($path);
    }

    // (b') a NON-proxy but vague+non-runnable packet => blocked at the seed-quality gate (FIX-1 advisory→fatal).
    //      allowed_file is OUTSIDE the harness subtree so the guard admits it and the seed-quality gate is reached.
    public function test_b2_nonproxy_vague_packet_is_blocked_at_seed_quality_gate(): void
    {
        $this->brainOn();
        $vague = [
            'task_packet_id' => 'brain:vague-2',
            'objective' => 'do the thing', // not proxy, but < 40 chars + no concrete anchor => vague_objective
            'allowed_files' => ['app/Models/AtlasNonHarnessTarget.php'],
            'scope_in' => ['app/Models/AtlasNonHarnessTarget.php'],
            'acceptance_criteria' => ['it is nicer'], // no runnable signal => acceptance_not_runnable
            'evidence_requirements' => ['tests_or_gates_result'],
        ];
        $path = $this->specsFile([$vague]);

        Artisan::call('atlas:brain:seed', ['--specs' => $path, '--dry-run' => true, '--json' => true]);
        $payload = json_decode(trim(Artisan::output()), true);

        self::assertSame('blocked', $payload['results'][0]['status']);
        self::assertSame('seed_quality', $payload['results'][0]['stage']);
        self::assertContains('vague_objective', $payload['results'][0]['reasons']);
        self::assertContains('acceptance_not_runnable', $payload['results'][0]['reasons']);
        @unlink($path);
    }

    // (c) the UNIVERSAL replenisher path is NOT starved: the shared const still EXCLUDES the 3 advisory flags.
    public function test_c_universal_blocking_const_excludes_the_three_advisory_flags(): void
    {
        foreach (['vague_objective', 'acceptance_not_runnable', 'blind_orphan_wiring_proxy'] as $flag) {
            self::assertNotContains(
                $flag,
                AtlasTaskPacketQualityInspector::BLOCKING_DEFICIENCIES,
                "promoting {$flag} to the universal const would starve the replenisher (FIX-1 must stay at the seed boundary)"
            );
        }

        // And the universal inspector itself does NOT block a vague-but-otherwise-valid minimal packet
        // (proving the replenisher path is not starved).
        $inspection = (new AtlasTaskPacketQualityInspector)->inspect([
            'objective' => 'do the thing',
            'allowed_files' => ['app/Foo.php'],
            'scope_in' => ['app/Foo.php'],
            'acceptance_criteria' => ['it is nicer'],
            'required_evidence' => ['tests_or_gates_result'],
        ]);
        self::assertTrue((bool) $inspection['self_sufficient'], 'universal inspector must not hard-block a vague minimal packet');
        self::assertContains('vague_objective', $inspection['deficiencies'], 'but it must still SURFACE the advisory signal');
    }

    // (d) the done-set HARD-SKIP is the brain's sticky dedup: once a target is originated, it never re-serves.
    //     Proved two provider-free ways: (1) the ledger contract verbatim, (2) the REAL command path —
    //     the REAL builder finds an orphan target in a temp repo, the REAL pipeline (material path, no provider)
    //     produces it, and because it is already in the done-set the command HARD-SKIPS it as already_done.
    public function test_d_done_set_hard_skips_an_already_originated_target(): void
    {
        // (1) LEDGER CONTRACT — sticky dedup the command delegates to verbatim (AtlasBrainNextCommand:109).
        $ledger = new AtlasBrainDoneSetLedger('loop', $this->doneSetRoot);
        $ledger->record(['snapshot_id' => 's', 'status' => 'served', 'produced' => true, 'action' => 'proceed',
            'target_path' => 'app/Some/Originated/Target.php', 'task_packet_id' => 'brain:x', 'refusal' => false]);
        self::assertTrue($ledger->isDone('app/Some/Originated/Target.php'), 'a recorded target is sticky-done');
        self::assertFalse($ledger->isDone('app/Some/Other/Target.php'), 'a distinct target is not done');

        // (2) REAL COMMAND PATH — drive the deterministic, provider-free leverage-first material originator.
        //     proceed_on_grounded_novelty ON makes a grounded+designed orphan-wiring PROCEED (not park), so the
        //     command reaches the isDone() hard-skip with a real produced target — no provider, no flakiness.
        $this->brainOn();
        config()->set('atlas.loop.leverage_first_origination_enabled', true);
        config()->set('atlas.loop.proceed_on_grounded_novelty_enabled', true);

        // A temp repo whose AutonomousEvolution scope holds exactly ONE class with no callers => a real orphan.
        $repo = sys_get_temp_dir().'/atlas-brain-repo-'.bin2hex(random_bytes(6));
        $scopeDir = $repo.'/app/Services/Ai/AutonomousEvolution';
        @mkdir($scopeDir, 0775, true);
        $orphanRel = 'app/Services/Ai/AutonomousEvolution/AtlasBrainTestOrphanWidget.php';
        file_put_contents($repo.'/'.$orphanRel,
            "<?php\n\nnamespace App\\Services\\Ai\\AutonomousEvolution;\n\nfinal class AtlasBrainTestOrphanWidget\n{\n    public function run(): void {}\n}\n");

        // Pre-seed the done-set with EXACTLY that orphan target (a non-refusal row, so the dry-probe stays not-dry).
        $ledger->record(['snapshot_id' => 's', 'status' => 'served', 'produced' => true, 'action' => 'proceed',
            'target_path' => $orphanRel, 'task_packet_id' => 'brain:orphan', 'refusal' => false]);

        Artisan::call('atlas:brain:next', ['scope' => 'loop', '--repo' => $repo, '--json' => true]);
        $payload = json_decode(trim(Artisan::output()), true);

        self::assertSame('already_done', $payload['status'],
            'the REAL command must HARD-SKIP an originated target already in the done-set (never re-serve)');
        self::assertSame($orphanRel, $payload['target_path']);
    }

    // (e) N consecutive {produced:true, action:'abstain'} cycles + zero grounded gaps => dry-probe state 'dry'.
    //     This drives the REAL pipeline ABSTAIN shape — the ABSTAIN->DRY fix: a produced=true abstain still counts.
    public function test_e_consecutive_produced_true_abstain_cycles_go_dry(): void
    {
        $ledger = new AtlasBrainDoneSetLedger('loop', $this->doneSetRoot);
        for ($i = 0; $i < 3; $i++) {
            // The EXACT shape AtlasLoopOriginationPipeline emits for park-and-ask: produced=TRUE, action=abstain.
            $ledger->record([
                'snapshot_id' => 'snap',
                'status' => 'abstain',
                'produced' => true,
                'action' => 'abstain',
                'target_path' => '',
                'task_packet_id' => '',
                'refusal' => true,
            ]);
        }

        // Empty model => zero grounded gaps (no orphans, no doc-stated gaps).
        $emptyModel = AtlasLoopScopeComprehensionModel::fromArray([]);
        $probe = (new AtlasBrainScopeDryProbe)->probe($ledger->recentCycles(10), $emptyModel, 3);

        self::assertSame('dry', $probe['state'], 'produced=true abstain cycles MUST count toward dry (the ABSTAIN->DRY fix)');
        self::assertSame(3, (int) $probe['consecutive_refusals']);

        // GUARD: a single grounded gap must FLIP it back to not-dry (the dry-probe never fakes the stop).
        $modelWithGap = AtlasLoopScopeComprehensionModel::fromArray(['orphans' => ['App\\Some\\Orphan']]);
        $probe2 = (new AtlasBrainScopeDryProbe)->probe($ledger->recentCycles(10), $modelWithGap, 3);
        self::assertNotSame('dry', $probe2['state'], 'a grounded gap must prevent a dry verdict');
    }

    // (f) ATLAS_BRAIN_MASTER_ENABLED=false => brain:next + brain:seed are byte-identical no-ops (never enqueue).
    public function test_f_master_switch_off_is_a_noop(): void
    {
        $this->brainOff();
        self::assertFalse(AtlasBrainMasterSwitch::enabled());

        // brain:next OFF => disabled, no journal, no ledger write.
        Artisan::call('atlas:brain:next', ['scope' => 'loop', '--json' => true]);
        $next = json_decode(trim(Artisan::output()), true);
        self::assertSame('disabled', $next['status']);
        self::assertSame('brain_master_switch_off', $next['reason']);
        self::assertSame([], glob($this->journalRoot.'/*.md') ?: [], 'disabled brain must write no journal');
        self::assertSame([], glob($this->doneSetRoot.'/*.jsonl') ?: [], 'disabled brain must write no ledger');

        // brain:seed OFF => gates run but ZERO enqueue (the survivor is reported dry_run with brain_switch_off).
        $path = $this->specsFile([$this->cleanPacket()]);
        Artisan::call('atlas:brain:seed', ['--specs' => $path, '--json' => true]); // NOTE: no --dry-run
        $seed = json_decode(trim(Artisan::output()), true);
        self::assertFalse((bool) $seed['brain_enabled']);
        self::assertSame(0, (int) $seed['counts']['enqueued'], 'OFF must never enqueue even without --dry-run');
        self::assertSame('dry_run', $seed['results'][0]['status']);
        self::assertContains('brain_switch_off', $seed['results'][0]['reasons']);
        @unlink($path);
    }

    // (g) a harness_gated allowed_file (meta_harness OFF) is NON-seedable.
    public function test_g_harness_gated_allowed_file_is_non_seedable(): void
    {
        $this->brainOn();
        config()->set('atlas.brain.scopes.loop.meta_harness', false);

        // A NON-pétreo harness file under AutonomousEvolution/ — admits as 'harness_gated' while meta is OFF.
        $packet = $this->cleanPacket();
        $packet['allowed_files'] = ['app/Services/Ai/AutonomousEvolution/AtlasLoopOriginationPipeline.php'];
        $packet['scope_in'] = $packet['allowed_files'];
        $path = $this->specsFile([$packet]);

        Artisan::call('atlas:brain:seed', ['--specs' => $path, '--dry-run' => true, '--json' => true]);
        $payload = json_decode(trim(Artisan::output()), true);

        self::assertSame('blocked', $payload['results'][0]['status']);
        self::assertSame('harness_guard', $payload['results'][0]['stage']);
        self::assertNotEmpty(array_filter(
            (array) $payload['results'][0]['reasons'],
            static fn ($r): bool => str_starts_with((string) $r, 'harness_gated:'),
        ), 'a harness file must be blocked as harness_gated while meta is OFF');
        @unlink($path);
    }

    // (h) RECURSIVE-TOTAL: meta_harness ON (the autonomous scope) makes a NON-pétreo engine file admissible, yet
    //     a pétreo FORBIDDEN_SELF_TARGET stays blocked — the floor holds even when the brain may self-evolve.
    public function test_h_meta_on_admits_nonpetreo_engine_file_but_floor_blocks_petreo(): void
    {
        $this->brainOn();
        config()->set('atlas.brain.scopes.loop.meta_harness', true);

        // A non-pétreo ENGINE file (harness) ⇒ admissible with meta ON.
        $ok = $this->cleanPacket();
        $ok['allowed_files'] = ['app/Services/Ai/AutonomousEvolution/AtlasLoopOriginationPipeline.php'];
        $ok['scope_in'] = $ok['allowed_files'];
        $okPath = $this->specsFile([$ok]);
        Artisan::call('atlas:brain:seed', ['--specs' => $okPath, '--dry-run' => true, '--json' => true]);
        $okPayload = json_decode(trim(Artisan::output()), true);
        self::assertSame('dry_run', $okPayload['results'][0]['status'], 'meta ON ⇒ a non-pétreo engine file is admissible');

        // A pétreo file (the judge) ⇒ STILL forbidden even with meta ON (the floor never yields).
        $petreo = $this->cleanPacket();
        $petreo['allowed_files'] = ['app/Services/Ai/AutonomousEvolution/AtlasEvolutionFrozenJudge.php'];
        $petreo['scope_in'] = $petreo['allowed_files'];
        $petreoPath = $this->specsFile([$petreo]);
        Artisan::call('atlas:brain:seed', ['--specs' => $petreoPath, '--dry-run' => true, '--json' => true]);
        $petreoPayload = json_decode(trim(Artisan::output()), true);
        self::assertSame('blocked', $petreoPayload['results'][0]['status'], 'a pétreo file is forbidden even with meta ON');
        self::assertNotEmpty(array_filter(
            (array) $petreoPayload['results'][0]['reasons'],
            static fn ($r): bool => str_starts_with((string) $r, 'forbidden:'),
        ), 'the pétreo block reason is forbidden:, not harness_gated:');
        @unlink($okPath);
        @unlink($petreoPath);
    }
}
