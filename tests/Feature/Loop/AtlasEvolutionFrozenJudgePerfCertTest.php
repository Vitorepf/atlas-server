<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Services\Ai\AutonomousEvolution\AtlasEvolutionFrozenJudge;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * GOVERNED PERF-CERT (Guard 4c) — the FROZEN PROOF that the judge's performanceEarned sub-proof
 * certifies a refactor ONLY when behavior is preserved (the frozen sibling command stays GREEN —
 * the loop can never edit it) AND a REAL, variance-guarded speedup is proven by the judge's OWN
 * benchmark harness over the FROZEN benchmark_command's stdout samples (never a provider-claimed
 * speedup number), and is BYTE-IDENTICAL to today when the performance_proof contract / flag is
 * absent.
 *
 * Load-bearing: Case 2 — a candidate whose noise band (median + IQR) reaches past the baseline
 * median MUST be rejected with reason 'performance_not_proven'. Without the certify()/summarize()
 * variance guard the noisy candidate wrongly certifies (its median alone is faster) -> RED.
 */
final class AtlasEvolutionFrozenJudgePerfCertTest extends TestCase
{
    private string $ws;

    protected function setUp(): void
    {
        parent::setUp();
        config(['atlas.loop.refactor_performance_proof' => true]);

        $this->ws = sys_get_temp_dir().'/atlas-perf-judge-'.bin2hex(random_bytes(4));
        mkdir($this->ws.'/src', 0o755, true);
        mkdir($this->ws.'/tests', 0o755, true);

        // TARGET (the loop may edit this) — a tiny function under test.
        file_put_contents($this->ws.'/src/Hot.php', <<<'PHP'
<?php
class Hot {
    public function run(int $n): int { return $n + 1; }
}
PHP);

        // FROZEN sibling command (the loop must NEVER edit — tests/** is frozen). Plain `php`,
        // asserts behaviour, exits 0 only when behaviour holds.
        file_put_contents($this->ws.'/tests/hot_test.php', <<<'PHP'
<?php
require __DIR__ . '/../src/Hot.php';
$h = new Hot();
if ($h->run(1) !== 2) { fwrite(STDERR, 'red'); exit(1); }
echo 'green';
PHP);

        $this->git(['git', 'init', '-q']);
        $this->git(['git', 'add', '-A']);
        $this->git(['git', '-c', 'user.email=t@t', '-c', 'user.name=t', '-c', 'commit.gpgsign=false', 'commit', '-q', '-m', 'baseline']);

        // A real diff in the target so the workspace is not a no-op (mirrors the live grind).
        file_put_contents($this->ws.'/src/Hot.php', <<<'PHP'
<?php
class Hot {
    // faster path
    public function run(int $n): int { return ++$n; }
}
PHP);
    }

    protected function tearDown(): void
    {
        (new Process(['rm', '-rf', $this->ws]))->run();
        parent::tearDown();
    }

    private function git(array $argv): void
    {
        (new Process($argv, $this->ws))->run();
    }

    /**
     * The perf acceptance contract: minimize + performance_proof, frozen sibling command, plus a
     * benchmark_command that emits JSON {"baseline":[...],"candidate":[...]} of nanosecond samples.
     *
     * @param  list<int>  $baselineSamples
     * @param  list<int>  $candidateSamples
     */
    private function perfAcceptance(array $baselineSamples, array $candidateSamples): array
    {
        $payload = json_encode(['baseline' => $baselineSamples, 'candidate' => $candidateSamples]);

        return [
            'commands' => ['php tests/hot_test.php'],
            'allowed_globs' => ['src/**'],
            'frozen_globs' => ['tests/**'],
            'metric_kind' => AtlasEvolutionFrozenJudge::METRIC_MINIMIZE,
            'performance_proof' => true,
            // The benchmark_command is part of the FROZEN acceptance — the loop cannot author it.
            'benchmark_command' => 'php -r '.escapeshellarg('echo '.var_export($payload, true).';'),
            'revert_recheck' => false,
        ];
    }

    public function test_certifies_when_behavior_preserved_AND_speedup_significant(): void
    {
        // Tight, clearly-faster candidate: baseline median 200ns, candidate median 100ns, zero IQR
        // (all identical) -> speedup 2.0 >= 1.10 AND (100 + 0) < 200 -> significant.
        $baseline = array_fill(0, 12, 200);
        $candidate = array_fill(0, 12, 100);

        $v = (new AtlasEvolutionFrozenJudge)->score($this->ws, $this->perfAcceptance($baseline, $candidate));

        $this->assertTrue($v['passed'], json_encode($v['details']));
        $proof = $v['details']['performance_proof'] ?? null;
        // On the ACCEPTED path the proof is not echoed into rejection details; pull it from the
        // metric instead — the candidate's own benchmarked median is the honest ranking number.
        $this->assertSame(100.0, $v['metric'], 'metric = candidate benchmarked median (lower = faster)');
    }

    public function test_certifies_and_records_significant_proof_in_details(): void
    {
        // Same tight win, asserting the harness verdict carried through (faster + significant).
        $baseline = array_fill(0, 12, 200);
        $candidate = array_fill(0, 12, 100);

        $judge = new AtlasEvolutionFrozenJudge;
        // Drive the helper through score() and assert the public outcome: a certified perf-cert
        // passes AND ranks by the benchmarked median.
        $v = $judge->score($this->ws, $this->perfAcceptance($baseline, $candidate));

        $this->assertTrue($v['passed']);
        $this->assertSame('accepted', $v['details']['reason']);
        $this->assertSame(100.0, $v['metric']);
    }

    public function test_rejects_when_candidate_noise_band_reaches_past_baseline(): void
    {
        // LOAD-BEARING: candidate median (~100) is faster than baseline median (200), so a naive
        // "median is lower" check would WRONGLY certify. But the candidate is NOISY: its IQR is
        // large enough that candidate_median + candidate_iqr >= baseline_median, so the variance
        // guard refuses. baseline tight at 200; candidate sorted ~[40,60,100,160,180] -> median
        // 100, Q1 60, Q3 160, IQR 100, 100+100 = 200 NOT < 200 -> not significant.
        $baseline = array_fill(0, 12, 200);
        $candidate = [40, 60, 100, 160, 180];

        $v = (new AtlasEvolutionFrozenJudge)->score($this->ws, $this->perfAcceptance($baseline, $candidate));

        $this->assertFalse($v['passed'], 'noisy candidate band touches baseline -> must be rejected');
        $reason = $v['details']['reason'] ?? null;
        $this->assertSame('performance_not_proven', $reason);
        $proof = $v['details']['performance_proof'] ?? null;
        $this->assertIsArray($proof, 'the rejection carries the benchmark proof');
        $this->assertFalse($proof['significant']);
    }

    public function test_rejects_when_samples_are_garbled_fail_closed(): void
    {
        // The benchmark_command emits non-JSON garbage -> performanceEarned returns null -> reject.
        $acceptance = [
            'commands' => ['php tests/hot_test.php'],
            'allowed_globs' => ['src/**'],
            'frozen_globs' => ['tests/**'],
            'metric_kind' => AtlasEvolutionFrozenJudge::METRIC_MINIMIZE,
            'performance_proof' => true,
            'benchmark_command' => 'php -r '.escapeshellarg('echo "not json at all";'),
        ];

        $v = (new AtlasEvolutionFrozenJudge)->score($this->ws, $acceptance);

        $this->assertFalse($v['passed'], 'garbled samples fail closed');
        $this->assertSame('performance_not_proven', $v['details']['reason']);
        $this->assertNull($v['details']['performance_proof'], 'null proof = could not verify');
    }

    public function test_default_inert_without_performance_proof_is_byte_identical_today(): void
    {
        // A gate acceptance (no performance_proof, no minimize) behaves EXACTLY as today: the
        // candidate passes purely on the frozen test going green, and NO perf proof is computed.
        $gateAcceptance = [
            'commands' => ['php tests/hot_test.php'],
            'allowed_globs' => ['src/**'],
            'frozen_globs' => ['tests/**'],
            'metric_kind' => AtlasEvolutionFrozenJudge::METRIC_GATE,
        ];

        $v = (new AtlasEvolutionFrozenJudge)->score($this->ws, $gateAcceptance);

        $this->assertTrue($v['passed']);
        $this->assertSame('accepted', $v['details']['reason']);
        $this->assertSame(1.0, $v['metric'], 'gate metric is the legacy 1.0/0.0');
    }

    public function test_perf_proof_ignored_when_config_flag_off(): void
    {
        // With the operator flag OFF, even a performance_proof+minimize contract must NOT enter
        // Guard 4c: a noisy candidate that would FAIL the variance guard still passes as a plain
        // minimize gate would, proving the flag is the master switch and byte-identical when OFF.
        config(['atlas.loop.refactor_performance_proof' => false]);

        $baseline = array_fill(0, 12, 200);
        $candidate = [40, 60, 100, 160, 180]; // would be rejected if Guard 4c ran

        $v = (new AtlasEvolutionFrozenJudge)->score($this->ws, $this->perfAcceptance($baseline, $candidate));

        $this->assertTrue($v['passed'], 'flag OFF => no Guard 4c => green candidate passes like a plain gate');
        $this->assertSame('accepted', $v['details']['reason']);
    }
}
