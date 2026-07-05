<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Composes the closed-loop learning completeness verifier with declared autonomous-spine
 * section evidence, queue-repair facts and outcome-feedback facts into ONE operator-readable
 * gap-closure snapshot — so an operator or another brain can check readiness before creating
 * more disconnected organs instead of discovering the gap after the fact.
 *
 * FAIL-CLOSED: no declared spine_sections is NOT treated as "nothing to prove" — it means
 * ready=false until real section evidence is supplied. Readiness is never fabricated from
 * absence, matching the same honesty contract as the rest of the External Brain gates.
 *
 * ready = true only when:
 *   - the closed-loop learning cycle is complete (no missing_links)
 *   - at least one spine_section is declared AND every declared section reports ready=true
 *   - queue_repair_summary.still_broken === 0
 *   - outcome_feedback_summary.leaks === 0
 *
 * Pure PHP, deterministic, no I/O.
 */
final class AtlasExternalBrainGapClosureSnapshot
{
    public const SCHEMA = 'atlas.external_brain.gap_closure_snapshot.v1';

    public const NEXT_ACTION_CLOSE_GAP = 'close_gap';
    public const NEXT_ACTION_PROCEED   = 'proceed_with_new_organs';

    public function __construct(
        private readonly ?AtlasExternalBrainClosedLoopLearningCompletenessVerifier $completenessVerifier = null,
    ) {}

    /**
     * @param  array{
     *     cycles?: list<array<string,mixed>>,
     *     spine_sections?: list<array{name?:string, ready?:bool, reasons?:list<string>}>,
     *     queue_repair_summary?: array{repaired?:int, still_broken?:int},
     *     outcome_feedback_summary?: array{learned?:int, leaks?:int},
     * }  $input
     * @return array<string,mixed>
     */
    public function snapshot(array $input): array
    {
        $verifier = $this->completenessVerifier ?? new AtlasExternalBrainClosedLoopLearningCompletenessVerifier;
        $loop = $verifier->verify(['cycles' => (array) ($input['cycles'] ?? [])]);

        $rawSections = (array) ($input['spine_sections'] ?? []);
        $spineSections = [];
        $allSectionsReady = $rawSections !== [];
        foreach ($rawSections as $section) {
            $name  = (string) ($section['name'] ?? 'unknown');
            $ready = (bool) ($section['ready'] ?? false);
            $reasons = array_values((array) ($section['reasons'] ?? []));
            // Contradiction guard: a section reporting ready=true with non-empty reasons
            // is coerced to not-ready — honest-ready requires ready===true AND reasons===[].
            if ($ready && $reasons !== []) {
                $ready = false;
                array_unshift($reasons, 'ready_true_but_reasons_present');
            }
            $spineSections[] = [
                'name'    => $name,
                'ready'   => $ready,
                'reasons' => $reasons,
            ];
            if (! $ready) {
                $allSectionsReady = false;
            }
        }

        $queueRaw = is_array($input['queue_repair_summary'] ?? null) ? $input['queue_repair_summary'] : [];
        $repaired    = max(0, (int) ($queueRaw['repaired'] ?? 0));
        $stillBroken = max(0, (int) ($queueRaw['still_broken'] ?? 0));
        $queueRepairSummary = [
            'repaired'     => $repaired,
            'still_broken' => $stillBroken,
            'all_repaired' => $stillBroken === 0,
        ];

        $outcomeRaw = is_array($input['outcome_feedback_summary'] ?? null) ? $input['outcome_feedback_summary'] : [];
        $learned = max(0, (int) ($outcomeRaw['learned'] ?? 0));
        $leaks   = max(0, (int) ($outcomeRaw['leaks'] ?? 0));
        $outcomeFeedbackSummary = [
            'learned'  => $learned,
            'leaks'    => $leaks,
            'no_leaks' => $leaks === 0,
        ];

        $ready = $loop['complete']
            && $allSectionsReady
            && $queueRepairSummary['all_repaired']
            && $outcomeFeedbackSummary['no_leaks'];

        return [
            'schema'                   => self::SCHEMA,
            'ready'                    => $ready,
            'missing_links'            => $loop['missing_links'],
            'spine_sections'           => $spineSections,
            'queue_repair_summary'     => $queueRepairSummary,
            'outcome_feedback_summary' => $outcomeFeedbackSummary,
            'recommended_next_action'  => $ready ? self::NEXT_ACTION_PROCEED : self::NEXT_ACTION_CLOSE_GAP,
        ];
    }
}
