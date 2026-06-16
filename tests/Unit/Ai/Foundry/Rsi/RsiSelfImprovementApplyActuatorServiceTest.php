<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Foundry\Rsi;

use App\Services\Ai\Foundry\Rsi\EarnedAutonomy\DriftAnomalyDetectorService;
use App\Services\Ai\Foundry\Rsi\EarnedAutonomy\EarnedAutonomyGateService;
use App\Services\Ai\Foundry\Rsi\EarnedAutonomy\KillAuthorityService;
use App\Services\Ai\Foundry\Rsi\EarnedAutonomy\RiskClassifierService;
use App\Services\Ai\Foundry\Rsi\EarnedAutonomy\StandingRedTeamService;
use App\Services\Ai\Foundry\Rsi\EarnedAutonomy\TrustLedgerService;
use App\Services\Ai\Foundry\Rsi\RsiInvariantGuardService;
use App\Services\Ai\Foundry\Rsi\RsiSelfImprovementApplyActuatorService;
use App\Services\Ai\Foundry\Rsi\RsiSelfImprovementProposalGate;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\Rsi\ComponentValueLedgerService;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * ACDE S2 — the apply actuator is INERT until the operator arms it, and can NEVER apply unless EVERY gate
 * passes. Proves: flag-OFF never touches the tree; the operator kill-file blocks an otherwise-armed apply
 * (GATE 0, which decide() does not check); a gate/invariant-touch diff never applies even at max trust; and the
 * happy path applies to the WORKING TREE ONLY (HEAD unchanged). Default state = byte-identical.
 */
final class RsiSelfImprovementApplyActuatorServiceTest extends TestCase
{
    private const ENABLED = ['earned_autonomy_mode_enabled' => true, 'area_id' => 'area', 'focus' => 'focus', 'rsi_mode_enabled' => true];

    private const TARGET = 'app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/PlanExecution/FindingDecomposerService.php';

    private string $killRoot;

    private string $trustRoot;

    private string $valueRoot;

    /** @var list<string> */
    private array $dirs = [];

    protected function setUp(): void
    {
        parent::setUp();
        $s = bin2hex(random_bytes(6));
        $this->killRoot = sys_get_temp_dir().'/atlas_rsi_act_kill_'.$s;
        $this->trustRoot = sys_get_temp_dir().'/atlas_rsi_act_trust_'.$s;
        $this->valueRoot = sys_get_temp_dir().'/atlas_rsi_act_value_'.$s;
        config(['atlas.foundry.rsi.self_improvement_apply_enabled' => true]); // most tests need GATE 1 open
    }

    protected function tearDown(): void
    {
        foreach ([$this->killRoot, $this->trustRoot, $this->valueRoot, ...$this->dirs] as $d) {
            if (is_dir($d)) {
                File::deleteDirectory($d);
            }
        }
        parent::tearDown();
    }

    private function clock(): \Closure
    {
        return static fn (): string => '2026-05-30T12:00:00+00:00';
    }

    private function killAuthority(bool $armed): KillAuthorityService
    {
        $svc = new KillAuthorityService($this->clock());
        $svc->setStorageRootForTesting($this->killRoot);
        if ($armed) {
            $svc->arm(['source' => 'operator_cli'], 'operator:vitor');
        }

        return $svc;
    }

    private function driftDetector(): DriftAnomalyDetectorService
    {
        $ledger = new ComponentValueLedgerService;
        $ledger->setStorageRootForTesting($this->valueRoot);
        $path = rtrim($this->valueRoot, '/').'/area/focus.jsonl';
        File::ensureDirectoryExists(dirname($path));
        $lines = [];
        for ($i = 0; $i < 6; $i++) {
            $lines[] = json_encode([
                'schema_version' => ComponentValueLedgerService::EVENT_SCHEMA,
                'recorded_at' => sprintf('2026-05-30T%02d:00:00+00:00', $i),
                'area_id' => 'area', 'focus' => 'focus', 'cycle_id' => 'vc'.$i,
                'outcome_status' => ComponentValueLedgerService::OUTCOME_PROVEN,
                'proven_value_delta' => 1000.0, 'total_tokens_cycle' => 1000, 'value_per_token_cycle' => 1.0,
                'components' => [['component_id' => 'c', 'seam_type' => ComponentValueLedgerService::SEAM_LIVE_PROVIDER, 'tokens_consumed' => 1000, 'participation_flags' => ['provider_invoked' => true]]],
            ], JSON_UNESCAPED_SLASHES);
        }
        File::put($path, implode("\n", $lines)."\n");
        $detector = new DriftAnomalyDetectorService;
        $detector->setValueLedgerForTesting($ledger);

        return $detector;
    }

    private function trustLedger(int $proven): TrustLedgerService
    {
        $svc = new TrustLedgerService($this->clock());
        $svc->setStorageRootForTesting($this->trustRoot);
        for ($i = 0; $i < $proven; $i++) {
            $svc->recordCycle(['area_id' => 'area', 'focus' => 'focus', 'cycle_id' => 'c'.$i], ['outcome_proven' => true, 'red_team_survived' => true, 'drift_clean' => true, 'risk_class' => 'cosmetic', 'auto_applied' => false]);
        }

        return $svc;
    }

    private function actuator(KillAuthorityService $kill): RsiSelfImprovementApplyActuatorService
    {
        $composer = new EarnedAutonomyGateService(new RiskClassifierService, $this->trustLedger(3), $this->driftDetector(), $kill, new StandingRedTeamService);
        $gate = new RsiSelfImprovementProposalGate(app(RsiInvariantGuardService::class), $composer);

        return new RsiSelfImprovementApplyActuatorService($gate, $kill);
    }

    /** A sandbox git repo whose patch adds a comment-only line to the (cosmetic) target file. */
    private function sandbox(): array
    {
        $d = sys_get_temp_dir().'/atlas_rsi_act_repo_'.bin2hex(random_bytes(4));
        $abs = $d.'/'.self::TARGET;
        File::ensureDirectoryExists(dirname($abs));
        $this->dirs[] = $d;
        file_put_contents($abs, "<?php\n\$x = 1;\n");
        foreach ([['init', '-q'], ['add', '-A'], ['-c', 'user.email=t@t', '-c', 'user.name=t', 'commit', '-q', '-m', 'base', '--no-gpg-sign']] as $argv) {
            (new Process(array_merge(['git'], $argv), $d))->run();
        }
        file_put_contents($abs, "<?php\n// a clarifying comment only\n\$x = 1;\n");
        $diff = new Process(['git', 'diff'], $d);
        $diff->run();
        $patch = $diff->getOutput();
        (new Process(['git', 'checkout', '--', '.'], $d))->run(); // restore — the actuator must re-apply it

        return [$d, $patch];
    }

    private function porcelain(string $repo): string
    {
        $p = new Process(['git', 'status', '--porcelain'], $repo);
        $p->run();

        return trim($p->getOutput());
    }

    private function headSha(string $repo): string
    {
        $p = new Process(['git', 'rev-parse', 'HEAD'], $repo);
        $p->run();

        return trim($p->getOutput());
    }

    private function cosmetic(): array
    {
        return ['diff' => ['changed_paths' => [self::TARGET], 'added_lines' => ['// a clarifying comment only']]];
    }

    public function test_flag_off_never_touches_the_tree(): void
    {
        config(['atlas.foundry.rsi.self_improvement_apply_enabled' => false]);
        [$repo, $patch] = $this->sandbox();
        $r = $this->actuator($this->killAuthority(true))->attempt($this->cosmetic(), self::ENABLED, $repo, $patch);

        $this->assertSame(RsiSelfImprovementApplyActuatorService::STATUS_FLAG_OFF, $r['status']);
        $this->assertFalse($r['applied']);
        $this->assertSame('', $this->porcelain($repo), 'flag OFF => working tree byte-identical');
    }

    public function test_the_operator_kill_file_blocks_an_otherwise_armed_apply(): void
    {
        [$repo, $patch] = $this->sandbox();
        $kill = $this->killAuthority(true);
        // operator drops the kill-file — decide() would still say armed, but GATE 0 must refuse.
        File::ensureDirectoryExists($this->killRoot);
        File::put(rtrim($this->killRoot, '/').'/'.KillAuthorityService::KILL_FILE, '1');

        $r = $this->actuator($kill)->attempt($this->cosmetic(), self::ENABLED, $repo, $patch);

        $this->assertSame(RsiSelfImprovementApplyActuatorService::STATUS_AUTONOMY_KILLED, $r['status']);
        $this->assertFalse($r['applied']);
        $this->assertSame('', $this->porcelain($repo), 'a dropped kill-file => no apply, tree byte-identical');
    }

    public function test_a_gate_or_invariant_touch_never_applies_even_at_max_trust(): void
    {
        [$repo, $patch] = $this->sandbox();
        // a weakening signature in the added lines => rank-3 gate_or_invariant_touch => never auto_apply.
        $proposal = ['diff' => ['changed_paths' => [self::TARGET], 'added_lines' => ['$proposal["auto_apply"] = true;']]];
        $r = $this->actuator($this->killAuthority(true))->attempt($proposal, self::ENABLED, $repo, $patch);

        $this->assertSame(RsiSelfImprovementApplyActuatorService::STATUS_NOT_AUTO_APPLIED, $r['status']);
        $this->assertFalse($r['applied']);
        $this->assertSame('', $this->porcelain($repo), 'a gate-touch diff => no apply');
    }

    public function test_disarmed_never_applies(): void
    {
        [$repo, $patch] = $this->sandbox();
        // Disarmed => the dead-man heartbeat was never written => isAutonomyKilled()=true => GATE 0 refuses
        // (even stronger than human-gating: the kill barrier catches it before the decide gate is consulted).
        $r = $this->actuator($this->killAuthority(false))->attempt($this->cosmetic(), self::ENABLED, $repo, $patch);

        $this->assertSame(RsiSelfImprovementApplyActuatorService::STATUS_AUTONOMY_KILLED, $r['status']);
        $this->assertFalse($r['applied']);
        $this->assertSame('', $this->porcelain($repo));
    }

    public function test_happy_path_applies_to_the_working_tree_only_head_unchanged(): void
    {
        [$repo, $patch] = $this->sandbox();
        $headBefore = $this->headSha($repo);
        $r = $this->actuator($this->killAuthority(true))->attempt($this->cosmetic(), self::ENABLED, $repo, $patch);

        $this->assertSame(RsiSelfImprovementApplyActuatorService::STATUS_APPLIED, $r['status'], 'a fully-earned + armed + not-killed cosmetic diff applies');
        $this->assertTrue($r['applied']);
        $this->assertNotSame('', $this->porcelain($repo), 'the working tree changed');
        $this->assertSame($headBefore, $this->headSha($repo), 'HEAD is unchanged — working-tree apply only, never a commit');
        $this->assertStringContainsString('a clarifying comment only', (string) file_get_contents($repo.'/'.self::TARGET));
    }
}
