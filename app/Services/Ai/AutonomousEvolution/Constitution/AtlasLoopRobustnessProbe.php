<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Constitution;

use Symfony\Component\Process\Process;

/**
 * LOOP-OS · FASE 3 · SLICE 2 — the ROBUSTNESS PROBE (pétreo / FORBIDDEN under Constitution/).
 *
 * The cert battery proves a candidate judge still REFUTES bad code; this proves a candidate did not weaken a
 * structural SAFETY property — BEHAVIORALLY, by executing the breakage, never by grepping the source. The
 * keystone case is the merge actuator's MUTUAL EXCLUSION: it races two REAL OS processes that load the
 * candidate actuator's actual bytes and contend for the single lock. A correct actuator serializes (perfect
 * START/END nesting, exactly one in the critical section at a time); a candidate that downgraded LOCK_EX→
 * LOCK_SH (or dropped the flock) lets BOTH in — the probe observes the interleave and reports NOT-HELD. This
 * runs the CANDIDATE bytes (the worker `require`s the exact actuator FILE under test), so a blinder cannot
 * pass by leaving the live actuator intact (the §3.4 R3 trap).
 */
final class AtlasLoopRobustnessProbe
{
    /**
     * Behaviorally verify the merge actuator at $actuatorFile provides mutual exclusion. Returns
     * {held:bool, reason:string, lines:list<string>}. held=false ⇒ the candidate weakened the lock.
     *
     * @return array{held:bool, reason:string, lines:list<string>}
     */
    public function lockIsExclusive(string $actuatorFile, float $timeoutSeconds = 60.0): array
    {
        if (! is_file($actuatorFile)) {
            return ['held' => false, 'reason' => 'actuator_file_missing', 'lines' => []];
        }

        $repo = sys_get_temp_dir().'/atlas-robust-'.bin2hex(random_bytes(5));
        @mkdir($repo, 0o755, true);
        try {
            foreach ([['init', '-q'], ['config', 'user.email', 'r@r'], ['config', 'user.name', 'r']] as $argv) {
                (new Process(array_merge(['git'], $argv), $repo))->run();
            }
            file_put_contents($repo.'/seed.txt', "seed\n");
            (new Process(['git', 'add', '-A'], $repo))->run();
            (new Process(['git', '-c', 'user.email=r@r', '-c', 'user.name=r', 'commit', '-q', '-m', 'seed', '--no-gpg-sign'], $repo))->run();

            $worker = $repo.'/_robust_worker.php';
            file_put_contents($worker, $this->workerSource());
            $log = $repo.'/race.log';

            $vendor = base_path('vendor/autoload.php');
            $a = new Process([PHP_BINARY, $worker, $vendor, $actuatorFile, $repo, 'A', $log], $repo, null, null, $timeoutSeconds);
            $b = new Process([PHP_BINARY, $worker, $vendor, $actuatorFile, $repo, 'B', $log], $repo, null, null, $timeoutSeconds);
            $a->start();
            $b->start();
            $a->wait();
            $b->wait();

            $lines = array_values(array_filter(explode("\n", trim((string) @file_get_contents($log)))));

            return $this->verifyNesting($lines);
        } finally {
            (new Process(['rm', '-rf', $repo]))->run();
        }
    }

    /**
     * Perfect nesting (START x, END x, START y, END y) ⇒ exclusive (held). Any START while another id is
     * still inside ⇒ INTERLEAVE ⇒ the lock did not serialize ⇒ NOT held.
     *
     * @param  list<string>  $lines
     * @return array{held:bool, reason:string, lines:list<string>}
     */
    private function verifyNesting(array $lines): array
    {
        if (count($lines) < 4) {
            return ['held' => false, 'reason' => 'incomplete_race (a worker did not complete the critical section)', 'lines' => $lines];
        }
        $inside = null;
        foreach ($lines as $line) {
            $parts = explode(' ', $line, 2);
            if (count($parts) !== 2) {
                continue;
            }
            [$kind, $who] = $parts;
            if ($kind === 'START') {
                if ($inside !== null) {
                    return ['held' => false, 'reason' => 'interleave: START '.$who.' while '.$inside.' still inside ⇒ lock not exclusive', 'lines' => $lines];
                }
                $inside = $who;
            } elseif ($kind === 'END') {
                $inside = null;
            }
        }

        return ['held' => true, 'reason' => 'serialized_exclusive', 'lines' => $lines];
    }

    /** The subprocess worker: loads the CANDIDATE actuator bytes (explicit require, never autoload) + races. */
    private function workerSource(): string
    {
        return <<<'PHP'
            <?php
            [$self, $vendor, $actuatorFile, $repo, $id, $log] = $argv;
            require $vendor;                 // Symfony\Process etc. (frozen infra, not the judge)
            require $actuatorFile;           // defines the CANDIDATE actuator class with its actual bytes
            $cls = 'App\\Services\\Ai\\AutonomousEvolution\\Constitution\\AtlasLoopMergeActuator';
            $res = (new $cls())->withMainMergeLock($repo, function () use ($log, $id) {
                file_put_contents($log, "START $id\n", FILE_APPEND | LOCK_EX);
                usleep(250000); // widen the window so a non-exclusive lock visibly interleaves
                file_put_contents($log, "END $id\n", FILE_APPEND | LOCK_EX);
            }, 15.0);
            fwrite(STDOUT, json_encode(['id' => $id, 'acquired' => $res['acquired'] ?? null]));
            PHP;
    }
}
