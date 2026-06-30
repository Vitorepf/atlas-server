<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Compiles a deterministic reasoning worksheet that forces smaller models through a
 * structured origination pipeline instead of relying on raw free-form intelligence.
 *
 * FIXED SECTION ORDER (cannot be reordered or skipped past validation rules):
 *   1. evidence_intake        — load all available evidence before generating ideas
 *   2. explored_surfaces      — map which surfaces/modules have been examined
 *   3. candidate_tasks        — generate candidate origination tasks
 *   4. anti_duplication_proof — prove each candidate does not already exist in the queue/done set
 *   5. adversarial_critique   — challenge every candidate; survivors proceed
 *   6. leverage_ranking       — rank survivors by leverage score
 *   7. final_batch_selection  — select the final batch from ranked survivors
 *
 * VALIDATION RULES:
 *   - evidence_intake may NOT be skipped (reject with "must_include_evidence_intake")
 *   - allow_direct_final_answer=true is forbidden (reject with "direct_final_answer_not_allowed")
 *
 * OUTPUT:
 *   { schema, is_valid, rejection_reason, scaffold_sections, required_artifacts,
 *     stop_conditions, frontier_deepening_prompts }
 *
 * PURE / DETERMINISTIC / NO I/O.
 */
final class AtlasExternalBrainReasoningScaffoldCompiler
{
    public const SCHEMA = 'atlas.external_brain.reasoning_scaffold_compiler.v1';

    private const SECTIONS = [
        [
            'section_id'       => 'evidence_intake',
            'order'            => 1,
            'required'         => true,
            'prompt_template'  => 'Load all available evidence: context pack, give-back logs, compounding ledger, frontier state. List every evidence item with its freshness timestamp. Do NOT generate ideas yet.',
            'required_artifacts' => ['evidence_list'],
            'stop_conditions'  => ['zero_evidence_available'],
        ],
        [
            'section_id'       => 'explored_surfaces',
            'order'            => 2,
            'required'         => true,
            'prompt_template'  => 'Map which modules, services, and architectural surfaces you examined during evidence intake. For each surface, note: last-touched date, known gaps, and whether it was covered in the last N origination cycles.',
            'required_artifacts' => ['surface_map'],
            'stop_conditions'  => [],
        ],
        [
            'section_id'       => 'candidate_tasks',
            'order'            => 3,
            'required'         => true,
            'prompt_template'  => 'Generate candidate origination tasks strictly derived from evidence and unexplored surfaces. Each candidate must name: target file, expected value, estimated worker-minutes, and the evidence item that motivates it.',
            'required_artifacts' => ['candidate_list'],
            'stop_conditions'  => [],
        ],
        [
            'section_id'       => 'anti_duplication_proof',
            'order'            => 4,
            'required'         => true,
            'prompt_template'  => 'For each candidate, prove it is not already in the task queue or done-set. Cross-reference against the task registry. Remove any candidate that matches an existing task or recently completed work.',
            'required_artifacts' => ['dedup_proof'],
            'stop_conditions'  => ['all_candidates_are_duplicates'],
        ],
        [
            'section_id'       => 'adversarial_critique',
            'order'            => 5,
            'required'         => true,
            'prompt_template'  => 'Challenge every surviving candidate: Is this proxy work? Does it evolve the scope exponentially or is it cleanup? Could a worker complete it within allowed_files without operator help? Kill every candidate that fails any gate.',
            'required_artifacts' => ['critique_report'],
            'stop_conditions'  => ['all_candidates_critiqued_out'],
        ],
        [
            'section_id'       => 'leverage_ranking',
            'order'            => 6,
            'required'         => true,
            'prompt_template'  => 'Rank surviving candidates by leverage score = (expected_value × compounding_multiplier) / estimated_worker_minutes. List scores explicitly. Highest score first.',
            'required_artifacts' => ['ranked_candidates'],
            'stop_conditions'  => [],
        ],
        [
            'section_id'       => 'final_batch_selection',
            'order'            => 7,
            'required'         => true,
            'prompt_template'  => 'Select the top-N candidates from the ranked list that fit within the worker fleet capacity. Return their task specifications. No new candidates may be introduced at this stage.',
            'required_artifacts' => ['final_batch'],
            'stop_conditions'  => [],
        ],
    ];

    private const FRONTIER_DEEPENING_PROMPTS = [
        'What would a fundamentally more capable Atlas look like? What is preventing that capability from existing today?',
        'Which evidence items point to a recurring failure pattern that no existing task addresses?',
        'If you could only ship one task that makes every future origination cycle easier, what would it be?',
        'Are there wiring gaps where an organ exists but nothing calls it? Those are higher-leverage than new organ creation.',
        'What would the Atlas loop do differently if it had 10× more context about its own failure modes?',
    ];

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function compile(array $input): array
    {
        $skipSections        = is_array($input['skip_sections'] ?? null) ? $input['skip_sections'] : [];
        $allowDirectFinal    = (bool) ($input['allow_direct_final_answer'] ?? false);

        // Validate.
        if (in_array('evidence_intake', $skipSections, true)) {
            return $this->rejected('must_include_evidence_intake');
        }

        if ($allowDirectFinal) {
            return $this->rejected('direct_final_answer_not_allowed');
        }

        // Build sections (honoring skip_sections for non-required-by-rule sections).
        $scaffoldSections   = [];
        $requiredArtifacts  = [];
        $stopConditions     = [];

        foreach (self::SECTIONS as $section) {
            $id = $section['section_id'];

            if (in_array($id, $skipSections, true) && ! $section['required']) {
                continue;
            }

            $scaffoldSections[]  = [
                'section_id'      => $id,
                'order'           => $section['order'],
                'required'        => $section['required'],
                'prompt_template' => $section['prompt_template'],
            ];

            foreach ($section['required_artifacts'] as $artifact) {
                $requiredArtifacts[$id][] = $artifact;
            }

            foreach ($section['stop_conditions'] as $cond) {
                $stopConditions[$id][] = $cond;
            }
        }

        return [
            'schema'                    => self::SCHEMA,
            'is_valid'                  => true,
            'rejection_reason'          => null,
            'scaffold_sections'         => $scaffoldSections,
            'required_artifacts'        => $requiredArtifacts,
            'stop_conditions'           => $stopConditions,
            'frontier_deepening_prompts' => self::FRONTIER_DEEPENING_PROMPTS,
        ];
    }

    /** @return array<string,mixed> */
    private function rejected(string $reason): array
    {
        return [
            'schema'                    => self::SCHEMA,
            'is_valid'                  => false,
            'rejection_reason'          => $reason,
            'scaffold_sections'         => [],
            'required_artifacts'        => [],
            'stop_conditions'           => [],
            'frontier_deepening_prompts' => [],
        ];
    }
}
