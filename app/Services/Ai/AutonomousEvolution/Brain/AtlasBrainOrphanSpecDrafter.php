<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Brain;

/**
 * BRAIN-AS-AUTHOR EMBRYO — the first slice of the S4 leap (Self-Questioning/Challenger origination). Given
 * a single orphan FQCN (from the structural digest), draft a candidate spec the brain could plausibly seed.
 * Pure + deterministic + grounded: it never invents a target path, never invents acceptance text beyond a
 * specific runnable check naming the basename, never invents an FQCN — it just SHAPES the unwired-organ
 * fact into the seed-gov-lanes packet schema the gates already accept.
 *
 * MAPPING from FQCN App\X\Y\Bar:
 *   - target rel path        : app/X/Y/Bar.php
 *   - mirrored test path     : tests/Unit/X/Y/BarTest.php  (same convention as the spec translator)
 *   - objective              : ≥40 chars, names the FQCN + the runnable filter (passes vague_objective)
 *   - acceptance_criteria    : `php artisan test --filter=BarTest passes`  (runnable + names the basename
 *                              ⇒ passes acceptance_not_runnable AND the S5 acceptance_coverage_mismatch)
 *   - required_evidence      : ['tests_or_gates_result']  (a test path IS granted ⇒ no asymmetry)
 *   - task_packet_id         : 'brain:drafter:' + sha1(fqcn|scope)  (deterministic; same input ⇒ same id)
 *
 * Output goes through the SAME inspector as any other spec; this organ does NOT bypass any gate. It just
 * provides a STARTING SHAPE the brain (pasted model OR a future full-S4 originator) can sharpen further.
 *
 * Author≠judge intact: drafts ARE NOT seeded by the drafter — the brain still chooses + the gate still vets.
 * Pétreo: réu never edits the drafter that shapes its OWN candidates (else it'd shape them to whatever
 * passes inspection regardless of real merit — Goodhart again).
 */
final class AtlasBrainOrphanSpecDrafter
{
    public const SCHEMA = 'atlas.brain.orphan_spec_drafter.v1';

    /**
     * Draft a candidate spec from an orphan FQCN. Returns null on a malformed/unsafe input (empty FQCN,
     * literal '...' / '..' path traversal, no app\\ prefix ⇒ can't map to a real path).
     *
     * @return array<string,mixed>|null
     */
    public function draft(string $orphanFqcn, string $scope): ?array
    {
        $fqcn = ltrim(trim($orphanFqcn), '\\');
        if ($fqcn === '' || str_contains($fqcn, '..') || str_contains($fqcn, "\u{2026}") || str_contains($fqcn, '...')) {
            return null;
        }
        // Map App\X\Y\Bar to app/X/Y/Bar.php — only when the FQCN actually starts with App\\ (the project's
        // canonical root). A non-App\\ FQCN comes from outside the muscle ⇒ refuse rather than guess.
        if (! str_starts_with($fqcn, 'App\\')) {
            return null;
        }
        $relPath = 'app/'.str_replace('\\', '/', substr($fqcn, strlen('App\\'))).'.php';
        $basename = $this->classShortname($fqcn);
        if ($basename === '') {
            return null;
        }
        $testRel = 'tests/Unit/'.str_replace('\\', '/', substr($fqcn, strlen('App\\'))).'Test.php';
        $scopeSlug = trim($scope) !== '' ? trim($scope) : 'loop';

        $objective = "Wire orphan {$fqcn} into a real production caller and prove it via php artisan test --filter={$basename}Test.";
        $acceptance = ["php artisan test --filter={$basename}Test passes"];

        return [
            'task_packet_id' => 'brain:drafter:'.sha1($fqcn.'|'.$scopeSlug),
            'objective' => $objective,
            'allowed_files' => [$relPath, $testRel],
            'scope_in' => [$relPath, $testRel],
            'acceptance_criteria' => $acceptance,
            'required_evidence' => ['tests_or_gates_result'],
            'depends_on' => [],
            'wave' => 1,
            'risk_level' => 'medium',
            'source' => 'brain:orphan_spec_drafter',
            'origin' => ['orphan_fqcn' => $fqcn, 'scope' => $scopeSlug, 'schema' => self::SCHEMA],
        ];
    }

    private function classShortname(string $fqcn): string
    {
        $parts = explode('\\', $fqcn);
        $tail = trim((string) end($parts));

        return $tail;
    }
}
