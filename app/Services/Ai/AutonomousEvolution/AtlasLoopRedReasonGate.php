<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution;

use PhpParser\ParserFactory;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * ACDE lever U2 — the red-REASON discriminator.
 *
 * {@see AtlasEvolutionTaskGenerator::isRed} accepts ANY non-zero exit as a "real RED task". That is the
 * documented hole: a weak engine (MiniMax-M3) routinely emits a generated test that exits non-zero for a
 * STRUCTURAL reason — the test does not parse, or its `require` path is wrong so it never loads the target —
 * which looks identical to a genuine behavioral RED. The loop then "verifies" a fabricated task and grinds on
 * nothing.
 *
 * This gate proves the RED is for the RIGHT reason, DETERMINISTICALLY (zero model, no LLM judge):
 *   (1) the test SOURCE is syntactically valid PHP (in-process nikic parse) — a non-parsing test cannot make
 *       any behavioral assertion;
 *   (2) the test actually EXERCISES the target — it `require`/`include`s a path mentioning the target basename;
 *   (3) run it: a green test (exit 0) is not RED at all;
 *   (4) the failure output must NOT carry a LOAD-TIME structural signature (parse error / failed-require /
 *       missing file) that means the test died before reaching a behavioral assertion.
 *
 * Deliberately CONSERVATIVE: it does NOT reject on `call to undefined method/function/class` or an uncaught
 * exception, because those are frequently the GENUINE behavioral gap (the improvement is to ADD the missing
 * symbol). It only rejects the unambiguous structural defects, so it never false-rejects a real RED.
 */
final class AtlasLoopRedReasonGate
{
    /** Load-time signatures that mean the test never reached a behavioral assertion (lowercased). */
    private const STRUCTURAL_SIGNATURES = [
        'parse error',
        'failed opening required',
        'failed to open stream',
        'no such file or directory',
    ];

    /**
     * @return array{is_red: bool, reason: string}
     */
    public function evaluate(string $base, string $testRel, string $targetRel, float $timeoutSeconds = 120.0): array
    {
        $base = rtrim($base, '/');
        $testPath = $base.'/'.$testRel;
        $src = (string) @file_get_contents($testPath);

        // (1) syntactic validity — a non-parsing test "fails" structurally, not behaviorally.
        if ($src === '' || ! $this->phpParses($src)) {
            return ['is_red' => false, 'reason' => 'test_does_not_parse'];
        }

        // (2) the test must exercise the target — require/include it by basename.
        $targetBase = basename($targetRel);
        $exercisePattern = '/(?=.*\\b(require|include)(_once)?\\b)(?=.*'.preg_quote($targetBase, '/').')/is';
        if (preg_match('/.+/', $targetBase) !== 1 || preg_match($exercisePattern, $src) !== 1) {
            return ['is_red' => false, 'reason' => 'test_does_not_exercise_target'];
        }

        // (3) run it.
        $process = Process::fromShellCommandline(
            'php '.escapeshellarg($testRel),
            $base,
            AtlasLoopHermeticCommandEnvironment::forAcceptance(),
            null,
            $timeoutSeconds,
        );
        try {
            $process->run();
        } catch (Throwable) {
            return ['is_red' => false, 'reason' => 'test_run_threw'];
        }
        $exit = $process->getExitCode() ?? 1;
        if ($exit === 0) {
            return ['is_red' => false, 'reason' => 'test_is_green'];
        }

        // (4) the RED must not be a load-time structural defect.
        $out = strtolower($process->getOutput()."\n".$process->getErrorOutput());
        foreach (self::STRUCTURAL_SIGNATURES as $sig) {
            if (str_contains($out, $sig)) {
                return ['is_red' => false, 'reason' => 'red_for_structural_reason'];
            }
        }

        return ['is_red' => true, 'reason' => 'red_for_behavioral_reason'];
    }

    private function phpParses(string $code): bool
    {
        try {
            return (new ParserFactory)->createForHostVersion()->parse($code) !== null;
        } catch (Throwable) {
            return false;
        }
    }
}
