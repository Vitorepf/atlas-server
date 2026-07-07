<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Reality;

use App\Services\Ai\Reality\AtlasGitHistoryTemporalProducerService;
use App\Services\Ai\Reality\AtlasUnifiedRealityGraphTemporalService;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

/**
 * T4-S1 gate (obra17 line 60): "30 perguntas temporais >=85% contra o git real".
 *
 * The bi-temporal READ (stateAtValid) must agree with git's OWN as-of resolution
 * (`git rev-list -1 --before=D HEAD`) for arbitrary instants BETWEEN commits.
 *
 * Anti-Goodhart: the ground truth is git ITSELF, computed independently of the
 * producer. If the valid-time axis were fake (falling back to transaction time
 * `at=now`), or `valid_at` were not stored, or the as-of ordering were wrong,
 * every probe would resolve to the newest tick and the match rate would collapse
 * far below 85%. The probes sit strictly between commits, so a correct answer
 * requires real as-of resolution, not echoing a commit date back.
 */
class AtlasBitemporalGitGateTest extends TestCase
{
    public function test_state_as_of_valid_matches_git_over_30_temporal_questions(): void
    {
        if (! is_dir(base_path('.git'))) {
            $this->markTestSkipped('needs a real git checkout for the git gate');
        }

        $logPath = tempnam(sys_get_temp_dir(), 'aurg_bitemporal_').'.jsonl';
        @unlink($logPath);

        $temporal = app(AtlasUnifiedRealityGraphTemporalService::class);
        $temporal->setLogPathForTesting($logPath);
        $producer = new AtlasGitHistoryTemporalProducerService($temporal);

        // PRODUCER: git history -> bi-temporal ticks (valid_at = commit date).
        $summary = $producer->backfill(80);
        $this->assertGreaterThanOrEqual(31, $summary['emitted'], 'need enough history to ask 30 temporal questions');

        // Idempotency: a second run over the same window is a no-op.
        $again = $producer->backfill(80);
        $this->assertSame(0, $again['emitted'], 're-run must not duplicate ticks');

        $ticks = $temporal->timeline(0)['ticks'];

        // Bi-temporality is REAL: valid_at is a distinct axis from transaction time.
        $distinct = array_filter($ticks, static fn ($t) => ($t['valid_at'] ?? null) !== ($t['at'] ?? null));
        $this->assertNotEmpty($distinct, 'valid_at must differ from `at` — else the log is mono-temporal in disguise');

        // Probe midpoints strictly between adjacent commits (in valid time).
        usort($ticks, static fn ($a, $b) => strcmp((string) ($a['valid_at'] ?? ''), (string) ($b['valid_at'] ?? '')));
        $probes = [];
        for ($i = 0; $i < count($ticks) - 1 && count($probes) < 30; $i++) {
            $lo = strtotime((string) $ticks[$i]['valid_at']);
            $hi = strtotime((string) $ticks[$i + 1]['valid_at']);
            if ($lo === false || $hi === false || $hi - $lo < 2) {
                continue; // no room to probe strictly between
            }
            $probes[] = gmdate('c', intdiv($lo + $hi, 2));
        }
        $this->assertGreaterThanOrEqual(30, count($probes), 'expected >=30 probeable gaps between commits');

        $hits = 0;
        foreach ($probes as $iso) {
            // Ground truth: git's own as-of, independent of the producer.
            $rev = Process::path(base_path())->run(['git', 'rev-list', '-1', '--before='.$iso, 'HEAD']);
            $gitSha = trim($rev->output());

            // Prediction: the bi-temporal read.
            $tick = $temporal->stateAtValid($iso);
            $predSha = (string) ($tick['snapshot_hash'] ?? '');

            if ($gitSha !== '' && $gitSha === $predSha) {
                $hits++;
            }
        }

        $rate = $hits / count($probes);
        $this->assertGreaterThanOrEqual(
            0.85,
            $rate,
            sprintf('temporal accuracy %.2f < 0.85 (%d/%d) vs real git', $rate, $hits, count($probes)),
        );

        @unlink($logPath);
    }
}
