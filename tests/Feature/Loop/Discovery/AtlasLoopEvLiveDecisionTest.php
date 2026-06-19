<?php

declare(strict_types=1);

namespace Tests\Feature\Loop\Discovery;

use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopExpectedValueDecider;
use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopObjectiveProducer;
use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopSystemAxisService;
use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopTouchesAxesProducer;
use App\Services\Ai\AutonomousEvolution\Discovery\StateOfAtlas;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

/**
 * S1 — the EV brain wired into the LIVE origination decision (ev_live_decision_enabled, default ON).
 *
 * produce() now ranks/shapes by VALUE on the live default and PARKS a cycle whose only floor-passers are
 * proxy-shaped refactors that do NOT relieve the BINDING value axis. The proof flows through the WHOLE live
 * pipeline — gather() over REAL files on disk (real caller_count via grep, real cyclomatic via AST) → REAL
 * leverage scorer → REAL touches-axes deriver → REAL EV decider; only the axis vector is injected (a
 * controlled binding axis), exactly the proven seam the EvPick slice uses.
 *
 * Two floor-passer packets, derived from real files:
 *   - P2 (value hub): app/Svc/Hub.php with 3 production callers (≥ hub 3) + a frozen sibling test on a
 *     genuinely complex file ⇒ machine touches_axes = {real_target, wired, compounding, non_trivial}.
 *     It RELIEVES the compounding bottleneck.
 *   - P1 (proxy refactor): app/Svc/Proxy.php with 2 callers (wired, but BELOW the hub threshold) + sibling +
 *     complexity ⇒ machine touches_axes = {real_target, wired, non_trivial}. It does NOT touch compounding —
 *     a behaviour-preserving refactor with no compounding contribution.
 *
 * LOAD-BEARING: with the binding axis = compounding and ONLY P1 present, the ON path PARKS (produce() === null,
 * no proxy refactor emitted); flipping ev_live_decision_enabled OFF falls back to the legacy lane which EMITS
 * a refactor objective for the same proxy. Removing the EV wire flips proxy-only from null(parked) → refactor.
 */
final class AtlasLoopEvLiveDecisionTest extends TestCase
{
    private string $repo = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->repo = sys_get_temp_dir().'/ev_live_'.bin2hex(random_bytes(6));
        @mkdir($this->repo.'/app/Svc', 0777, true);
        @mkdir($this->repo.'/tests/Unit/Svc', 0777, true);
    }

    protected function tearDown(): void
    {
        if ($this->repo !== '' && is_dir($this->repo)) {
            exec('rm -rf '.escapeshellarg($this->repo));
        }
        parent::tearDown();
    }

    /** A genuinely complex method body (worst-method cyclomatic ≫ the refactor floor of 10). */
    private function complexBody(): string
    {
        $b = "        \$x = 0;\n";
        for ($i = 0; $i < 14; $i++) {
            $b .= "        if (\$n === $i) { \$x += $i; } elseif (\$n > $i) { \$x -= $i; }\n";
        }

        return $b."        return \$x;\n";
    }

    /** Write a production class + $callerCount production callers (FQCN refs) + a plain-php sibling test. */
    private function writeTarget(string $class, int $callerCount): void
    {
        file_put_contents(
            $this->repo."/app/Svc/$class.php",
            "<?php\nnamespace App\\Svc;\nclass $class {\n    public function run(int \$n): int {\n".$this->complexBody()."    }\n}\n",
        );
        for ($i = 1; $i <= $callerCount; $i++) {
            $caller = $class.'Caller'.$i;
            file_put_contents(
                $this->repo."/app/Svc/$caller.php",
                "<?php\nnamespace App\\Svc;\nuse App\\Svc\\$class;\nclass $caller {\n    public function go(): int { return (new $class())->run(1); }\n}\n",
            );
        }
        // A plain-`php` sibling that REQUIRES the production file (no PHPUnit/Laravel boot) — the behaviour
        // anchor the refactor synthesizer needs to emit a legacy refactor objective.
        file_put_contents(
            $this->repo."/tests/Unit/Svc/{$class}Test.php",
            "<?php\nrequire __DIR__ . '/../../../app/Svc/$class.php';\n\$o = new App\\Svc\\$class();\nif (\$o->run(1) === 999999) { echo 'bad'; }\necho 'ok';\n",
        );
    }

    /** A producer whose ONLY injected collaborator is the axis vector (a controlled binding axis). */
    private function producer(string $bindingAxis): AtlasLoopObjectiveProducer
    {
        $grader = function (string $repoRoot, ?int $window) use ($bindingAxis): array {
            $axes = ['wired' => 0.95, 'real_target' => 0.95, 'non_trivial' => 0.95, 'compounding' => 0.95, 'safety' => 0.95];
            $axes[$bindingAxis] = 0.1; // this axis binds

            return ['graded_merges' => 9, 'axes' => $axes];
        };

        return new AtlasLoopObjectiveProducer(
            axis: new AtlasLoopSystemAxisService($grader),
            touches: new AtlasLoopTouchesAxesProducer(), // REAL deriver
            ev: new AtlasLoopExpectedValueDecider(),     // REAL decider
        );
    }

    public function test_live_decision_picks_the_value_candidate_over_the_proxy_refactor(): void
    {
        $this->writeTarget('Hub', 3);   // value hub — touches compounding
        $this->writeTarget('Proxy', 2); // proxy refactor — does NOT touch compounding

        Config::set('atlas.loop.ev_live_decision_enabled', true);
        Config::set('atlas.loop.producer_ev_pick_enabled', false);

        $out = $this->producer('compounding')->produce(
            $this->repo,
            ['app/Svc/Hub.php', 'app/Svc/Proxy.php'],
            'hermes',
            'tgt',
            new StateOfAtlas(),
        );

        $this->assertNotNull($out, 'a value candidate exists ⇒ produce() emits an objective, not a PARK');
        $this->assertSame('app/Svc/Hub.php', $out['target_path'], 'compounding binds ⇒ the wired hub (value) is chosen, NOT the proxy');
        $this->assertStringContainsString('binding=compounding', $out['rationale'], 'the live EV decision is recorded on the objective');
    }

    public function test_proxy_only_cycle_parks_on_the_live_default(): void
    {
        $this->writeTarget('Proxy', 2); // the ONLY floor-passer is a proxy refactor

        Config::set('atlas.loop.ev_live_decision_enabled', true);
        Config::set('atlas.loop.producer_ev_pick_enabled', false);

        $out = $this->producer('compounding')->produce(
            $this->repo,
            ['app/Svc/Proxy.php'],
            'hermes',
            'tgt',
            new StateOfAtlas(),
        );

        $this->assertNull($out, 'every floor-passer is a proxy refactor that cannot relieve compounding ⇒ honest PARK');
    }

    public function test_load_bearing_flipping_the_wire_off_falls_back_to_a_legacy_refactor_objective(): void
    {
        $this->writeTarget('Proxy', 2); // identical proxy-only set as the PARK case

        // The SAME proxy-only set that PARKS with the wire ON must EMIT a refactor when the wire is OFF.
        Config::set('atlas.loop.ev_live_decision_enabled', false);
        Config::set('atlas.loop.producer_ev_pick_enabled', false);

        $out = $this->producer('compounding')->produce(
            $this->repo,
            ['app/Svc/Proxy.php'],
            'hermes',
            'tgt',
            new StateOfAtlas(),
        );

        $this->assertNotNull($out, 'legacy lane (wire OFF) emits a proxy refactor — the byte-identical pre-S1 behaviour');
        $this->assertSame('refactor', $out['shape'], 'the legacy emission is a behaviour-preserving refactor objective');
        $this->assertSame('app/Svc/Proxy.php', $out['target_path']);
    }
}
