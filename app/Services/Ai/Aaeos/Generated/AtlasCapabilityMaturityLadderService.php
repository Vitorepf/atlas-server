<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Generated;

use App\Services\Ai\Aaeos\Support\AtlasAaeosValueNormalizer;

/**
 * Atlas Self-Construction Capability Maturity Ladder decider.
 *
 * Pure, deterministic classifier that turns the maturity-ladder doc into a
 * contract. The doc's hard claim is that Atlas must distinguish idea, law,
 * scaffold, runtime and autonomous competence: "A capability is not complete
 * because it is documented or scaffolded." This service enforces exactly that.
 *
 * Three documented mechanisms are modelled and never lie:
 *
 *  1. The Levels table (doc "Levels"): nine ordered, named levels L0..L8 —
 *     Named, Documented, Specified, Scaffolded, Executable Manual, Agent
 *     Executable, Autonomous Restricted, Self-Improving Governed, Strategic
 *     Self-Construction.
 *
 *  2. The Promotion Requirements table (doc "Promotion Requirements"): every
 *     step L(n) -> L(n+1) needs a specific named proof. Because the doc frames
 *     the ladder as a chain of promotions, a level can only be claimed when
 *     every proof from L0 up to that level is present. The classifier therefore
 *     returns the highest *contiguous* level reached: the first missing proof
 *     caps the level and names the exact blocking promotion. A higher proof
 *     present while a lower one is missing does NOT skip the gap.
 *
 *  3. The Anti-Confusion Rule (doc "Anti-Confusion Rule"): "Never describe a
 *     capability as 'ready' without its maturity level." A readiness/completion
 *     claim that does not carry a level is a violation; the same claim WITH a
 *     level is allowed. The doc's own Good/Bad examples are the test oracle.
 *
 * Evidence gate (doc frontmatter `forbidden_changes`): "Declarar runtime,
 * maturidade ou prontidao sem evidencia verificavel e gates verdes." Each proof
 * flag must be an explicit, truthy boolean — absent or falsey proof never
 * counts, so the ladder cannot be inflated by silence.
 *
 * NEVER calls a provider. NEVER promotes a Forge run. No database.
 *
 * @see docs/engineering-knowledge-base/self-construction/capability-maturity-ladder.md
 */
class AtlasCapabilityMaturityLadderService
{
    public const SCHEMA_VERSION = 'atlas.aaeos.capability_maturity_ladder.v1';

    public const MAX_LEVEL = 8;

    /**
     * Doc "Levels" — the nine ordered, named maturity levels.
     *
     * @var array<int,string>
     */
    public const LEVELS = [
        0 => 'Named',
        1 => 'Documented',
        2 => 'Specified',
        3 => 'Scaffolded',
        4 => 'Executable Manual',
        5 => 'Agent Executable',
        6 => 'Autonomous Restricted',
        7 => 'Self-Improving Governed',
        8 => 'Strategic Self-Construction',
    ];

    /**
     * Doc "Promotion Requirements" — proof required to enter each level.
     * Index N holds the proof key + human label for the L(N-1) -> LN promotion.
     * L0 ("Named") needs no proof; it is the floor.
     *
     * @var array<int,array{proof:string,required:string}>
     */
    public const PROMOTIONS = [
        1 => ['proof' => 'canonical_doc_and_owner', 'required' => 'Canonical doc and owner.'],
        2 => ['proof' => 'ap_spec_acceptance_risk_nongoals', 'required' => 'AP/spec, acceptance criteria, risk and non-goals.'],
        3 => ['proof' => 'scaffold_with_tests_or_marker', 'required' => 'Scaffold with tests or explicit scaffold marker.'],
        4 => ['proof' => 'passing_manual_command_or_test', 'required' => 'Passing manual command or test proving behavior.'],
        5 => ['proof' => 'agent_executes_with_receipt_and_gates', 'required' => 'Agent can execute with Decision Receipt and gates.'],
        6 => ['proof' => 'repeated_runs_rollback_and_drift', 'required' => 'Repeated successful runs, rollback and drift checks.'],
        7 => ['proof' => 'learning_proposals_without_unsafe_mutation', 'required' => 'Learning proposals improve future runs without unsafe mutation.'],
        8 => ['proof' => 'priority_engine_build_graph_and_metrics', 'required' => 'Priority engine, build graph and metrics prove strategic selection quality.'],
    ];

    /** Doc "Current Target Framing" — the documented starting baseline. */
    public const TARGET_FRAMING = [
        'documentation_maturity' => 'L1/L2',
        'runtime_maturity' => 'L0/L1',
        'autonomous_maturity' => 'L0',
    ];

    /**
     * Classify a capability against the ladder using its evidence proofs.
     *
     * Returns the highest contiguous level reached (the chain of promotions
     * from L0 up), the exact blocking promotion (first missing proof), and the
     * per-level checklist. A capability is NEVER "complete"; the doc only ever
     * grants a maturity level.
     *
     * @param  array{capability?:string,proofs?:array<string,bool>}  $descriptor
     * @return array<string,mixed>
     */
    public function classify(array $descriptor): array
    {
        $capability = AtlasAaeosValueNormalizer::stringOrNull($descriptor['capability'] ?? null) ?? 'unnamed_capability';
        $proofsIn = is_array($descriptor['proofs'] ?? null) ? $descriptor['proofs'] : [];

        $checklist = [];
        $level = 0; // L0 Named is the floor: a classified capability is at least named.
        $contiguousBroken = false;
        $blockingPromotion = null;

        foreach (self::PROMOTIONS as $target => $spec) {
            // Strictly-true only: absence or falsey never counts as proof.
            $present = ($proofsIn[$spec['proof']] ?? null) === true;

            $reached = $present && ! $contiguousBroken;
            if (! $present && ! $contiguousBroken) {
                // First gap in the chain — this is the promotion that blocks.
                $contiguousBroken = true;
                $blockingPromotion = [
                    'from' => 'L'.($target - 1),
                    'to' => 'L'.$target,
                    'missing_proof' => $spec['proof'],
                    'required' => $spec['required'],
                ];
            }

            if ($reached) {
                $level = $target;
            }

            $checklist[] = [
                'level' => 'L'.$target,
                'name' => self::LEVELS[$target],
                'proof' => $spec['proof'],
                'required' => $spec['required'],
                'present' => $present,
                'counts' => $reached,
            ];
        }

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'capability' => $capability,
            'level' => $level,
            'level_label' => 'L'.$level,
            'level_name' => self::LEVELS[$level],
            'is_max' => $level === self::MAX_LEVEL,
            // Doc decision: documented/scaffolded != complete. There is no
            // "complete" verdict below L8 strategic self-construction.
            'complete' => false,
            'next_level' => $level < self::MAX_LEVEL ? 'L'.($level + 1) : null,
            'blocking_promotion' => $blockingPromotion,
            'checklist' => $checklist,
        ];
    }

    /**
     * Anti-Confusion Rule (doc): "Never describe a capability as 'ready'
     * without its maturity level." A readiness/completion claim must carry a
     * level token (Lx) to be valid. The doc's Good example passes; the Bad
     * example ("... is complete.") fails.
     *
     * @return array{valid:bool,readiness_claim:bool,has_level:bool,rule:string,reasons:list<string>}
     */
    public function checkReadinessClaim(string $claim): array
    {
        $readinessClaim = (bool) preg_match('/\b(ready|complete|completo|pronto|prontid[aã]o|done)\b/i', $claim);
        $hasLevel = (bool) preg_match('/\bL[0-8]\b/', $claim);

        $reasons = [];
        // A claim only needs to carry a level when it actually asserts readiness.
        $valid = ! $readinessClaim || $hasLevel;
        if ($readinessClaim && ! $hasLevel) {
            $reasons[] = 'readiness_claim_without_maturity_level';
        }

        return [
            'valid' => $valid,
            'readiness_claim' => $readinessClaim,
            'has_level' => $hasLevel,
            'rule' => 'anti_confusion_rule',
            'reasons' => $reasons,
        ];
    }

    /**
     * Doc "Current Target Framing" + next step: report the documented baseline
     * and the documented next move (promote selected low-risk slices L2 -> L4
     * -> L5).
     *
     * @return array<string,mixed>
     */
    public function targetFraming(): array
    {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'framing' => self::TARGET_FRAMING,
            'next_step' => 'Promote selected low-risk slices from L2 to L4, then L5.',
        ];
    }

}
