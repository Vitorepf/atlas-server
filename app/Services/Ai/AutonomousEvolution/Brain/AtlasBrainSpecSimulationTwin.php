<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Brain;

use App\Services\Ai\SelfConstruction\AtlasTaskPacketQualityInspector;

/**
 * SIMULATION-TWIN substrate — the portfolio path's lens was "simulate candidates against scenarios, keep
 * the best return". The brain had no way to evaluate ALTERNATIVE specs BEFORE committing to one: every
 * authored spec went straight to the seed gate (one-shot, no exploration). This organ lets the brain
 * try N candidate specs in-process, see what the inspector would verdict each, and keep the best.
 *
 * Pure + deterministic: takes a list of candidate specs, runs the SAME inspector the live gate uses,
 * returns a verdict per candidate. No provider, no I/O, no enqueue, no side effect. The output ordering
 * is stable: PASSES_CLEAN (no deficiencies) ⇒ ADVISORY_ONLY (deficiencies but no blocking) ⇒ BLOCKED
 * (has blocking deficiencies), and within each class by deficiency count ASC + by task_packet_id ASC.
 *
 * OUTCOME CLASSES are FACTS the inspector exposes, NOT a learned score:
 *   - passes_clean:  deficiencies == []
 *   - advisory_only: deficiencies != [] AND blocking_deficiencies == []
 *   - blocked:       blocking_deficiencies != []
 *
 * Author≠judge intact: this PREDICTS the inspector's verdict, it does NOT alter it / does NOT seed /
 * does NOT write. Brain reads the predictions and picks; the LIVE inspector is still the final word.
 * Pétreo: the réu never edits the simulator that grades its own candidates (else it'd return
 * "passes_clean" for whatever it wanted to win, collapsing the exploration's discriminating power).
 */
final class AtlasBrainSpecSimulationTwin
{
    public const SCHEMA = 'atlas.brain.spec_simulation_twin.v1';

    public const OUTCOME_PASSES_CLEAN = 'passes_clean';

    public const OUTCOME_ADVISORY_ONLY = 'advisory_only';

    public const OUTCOME_BLOCKED = 'blocked';

    /** Class rank for ordering — smaller = better (clean first, blocked last). */
    private const CLASS_RANK = [
        self::OUTCOME_PASSES_CLEAN => 0,
        self::OUTCOME_ADVISORY_ONLY => 1,
        self::OUTCOME_BLOCKED => 2,
    ];

    /**
     * Simulate every candidate spec through the inspector. Output ranks them best-first; `winner` is the
     * top-ranked candidate when any predicts CLEAN or ADVISORY_ONLY, else null (every candidate would be
     * blocked ⇒ the brain should re-author, not pick a blocked one).
     *
     * @param  list<array<string,mixed>>  $candidates
     * @return array{
     *     schema:string,
     *     verdicts:list<array{task_packet_id:string, outcome:string, blocking:list<string>, advisory:list<string>}>,
     *     winner:?string
     * }
     */
    public function simulate(array $candidates, AtlasTaskPacketQualityInspector $inspector): array
    {
        $verdicts = [];
        foreach ($candidates as $spec) {
            $report = $inspector->inspect($spec);
            $blocking = array_values(array_map('strval', (array) ($report['blocking_deficiencies'] ?? [])));
            $deficiencies = array_values(array_map('strval', (array) ($report['deficiencies'] ?? [])));
            $advisory = array_values(array_diff($deficiencies, $blocking));

            $outcome = match (true) {
                $blocking !== [] => self::OUTCOME_BLOCKED,
                $deficiencies !== [] => self::OUTCOME_ADVISORY_ONLY,
                default => self::OUTCOME_PASSES_CLEAN,
            };

            $verdicts[] = [
                'task_packet_id' => trim((string) ($spec['task_packet_id'] ?? '')),
                'outcome' => $outcome,
                'blocking' => $blocking,
                'advisory' => $advisory,
            ];
        }

        // Deterministic best-first ordering: class rank ASC, advisory count ASC, task_packet_id ASC.
        usort($verdicts, static function (array $a, array $b): int {
            return [
                self::CLASS_RANK[$a['outcome']],
                count($a['advisory']),
                $a['task_packet_id'],
            ] <=> [
                self::CLASS_RANK[$b['outcome']],
                count($b['advisory']),
                $b['task_packet_id'],
            ];
        });

        $winner = null;
        foreach ($verdicts as $v) {
            if ($v['outcome'] !== self::OUTCOME_BLOCKED) {
                $winner = $v['task_packet_id'] !== '' ? $v['task_packet_id'] : null;
                break;
            }
        }

        return [
            'schema' => self::SCHEMA,
            'verdicts' => $verdicts,
            'winner' => $winner,
        ];
    }
}
