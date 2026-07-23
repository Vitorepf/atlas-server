<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Quarantine;

/**
 * Runtime for the Impeccable Skill And Command Flow doc.
 *
 * The doc dissects the external frontend skill (`skill/SKILL.md`) and pins the
 * contract Atlas must turn into governed runtime — NOT a copy of slash commands.
 * Its load-bearing, deterministic rules:
 *
 *   1. "Evidencias" command taxonomy: the 23 documented commands are partitioned
 *      into EXACTLY five families — Build, Evaluate, Refine, Enhance, Fix. Each
 *      command belongs to one family. `classifyCommand()` routes a command to its
 *      family; an unknown command is reported, never silently bucketed.
 *   2. "Contratos" + "Regras para IA" 1-3 (context gate): work cannot start
 *      without context. `PRODUCT.md` is REQUIRED — absent it blocks and the only
 *      legal next move is `teach`. `DESIGN.md` is RECOMMENDED — absent it does
 *      not block but emits a nudge to `document`.
 *   3. "Regras para IA" 4 (brand vs product register): brand and product use
 *      different criteria; the selector picks the matching reference lane.
 *   4. "Regras para IA" 5 (`craft` nao pula `shape`): a `craft` run that has not
 *      passed `shape` is refused.
 *   5. "Regras para IA" 6 (Codex image-gen gate): when image generation exists,
 *      `craft` stops at FOUR gates before any code — questions, palette, mocks,
 *      approved direction. Code before all four are cleared is refused.
 *   6. "Regras para IA" 7 (evidence): a screenshot with no read/inspection does
 *      NOT count as evidence.
 *   7. "Fluxo": the documented 7-step pipeline is ordered; routing a command
 *      before context is loaded / register inferred is a flow violation.
 *
 * This service enforces those rules in pure memory with NO DB, returning typed
 * arrays. It is a SKILL-FLOW CONTRACT EVALUATOR (does a frontend task respect the
 * doc's context gates, register lanes, command taxonomy and craft gates?). It
 * never declares an Atlas command "implemented" just because the doc lists it
 * (`forbidden_changes`).
 *
 * @see docs/engineering-knowledge-base/domains/programming-frontend-impeccable-skill-command-flow.md
 */
final class AtlasProgrammingFrontendImpeccableSkillCommandFlowService
{
    public const SCHEMA_VERSION = 'atlas.aaeos.programming_frontend_impeccable_skill_command_flow.v1';

    public const MODE = 'skill_command_flow_contract_evaluator';

    /** Doc frontmatter: risk_level medium, requires_evidence true. */
    public const RISK_LEVEL = 'medium';

    /** Doc `required_tests` / `quality_gates`. */
    public const REQUIRED_QUALITY_GATE = 'php artisan atlas:engineering:knowledge docs-health --json';

    /**
     * The command -> family taxonomy, verbatim from the doc's "Evidencias" table.
     * Every command maps to exactly one of the five families.
     *
     * @var array<string, array<int, string>>
     */
    private const FAMILIES = [
        'Build' => ['craft', 'teach', 'document', 'extract', 'shape'],
        'Evaluate' => ['critique', 'audit'],
        'Refine' => ['polish', 'bolder', 'quieter', 'distill', 'harden', 'onboard'],
        'Enhance' => ['animate', 'colorize', 'typeset', 'layout', 'delight', 'overdrive'],
        'Fix' => ['clarify', 'adapt', 'optimize', 'live'],
    ];

    /**
     * The ordered "Fluxo" pipeline. Index = required execution order. Routing a
     * command before context/register steps have run is a flow violation.
     *
     * @var array<int, string>
     */
    private const FLOW = [
        'load_product_design',       // load PRODUCT/DESIGN
        'infer_brand_product_register', // infer brand/product register
        'load_matching_reference',   // load matching reference
        'route_command',             // route command
        'load_command_reference',    // load command reference
        'execute_with_stops_gates',  // execute with stops/gates
        'verify_visually_technically', // verify visually/technically
    ];

    public const STEP_LOAD_CONTEXT = 'load_product_design';

    public const STEP_INFER_REGISTER = 'infer_brand_product_register';

    public const STEP_ROUTE_COMMAND = 'route_command';

    /**
     * The four craft gates before code, in order (Regra 6). All must clear before
     * any code is written when Codex image generation is available.
     *
     * @var array<int, string>
     */
    private const CRAFT_PRE_CODE_GATES = [
        'questions',
        'palette',
        'mocks',
        'approved_direction',
    ];

    /**
     * The two register lanes (Regra 4). Brand and product use different criteria.
     *
     * @var array<int, string>
     */
    private const REGISTERS = ['brand', 'product'];

    /**
     * Return the command -> family taxonomy.
     *
     * @return array<string, array<int, string>>
     */
    public function families(): array
    {
        return self::FAMILIES;
    }

    /**
     * Return the ordered Fluxo pipeline.
     *
     * @return array<int, string>
     */
    public function flow(): array
    {
        return self::FLOW;
    }

    /**
     * Return the four pre-code craft gates (Regra 6).
     *
     * @return array<int, string>
     */
    public function craftPreCodeGates(): array
    {
        return self::CRAFT_PRE_CODE_GATES;
    }

    /**
     * Classify a documented command into its single family (doc rule 1, the
     * "Evidencias" taxonomy). Comparison is case-insensitive on the command verb;
     * an unknown command resolves to family null and is flagged, never bucketed.
     *
     * @return array{command:string, family:?string, known:bool, conclusion:string}
     */
    public function classifyCommand(string $command): array
    {
        $needle = strtolower(trim($command));

        foreach (self::FAMILIES as $family => $commands) {
            if (in_array($needle, $commands, true)) {
                return [
                    'command' => $needle,
                    'family' => $family,
                    'known' => true,
                    'conclusion' => 'command_belongs_to_documented_family',
                ];
            }
        }

        return [
            'command' => $needle,
            'family' => null,
            'known' => false,
            'conclusion' => 'command_is_not_in_documented_taxonomy',
        ];
    }

    /**
     * The context gate (Contratos + Regras para IA 1-3).
     *
     * Rule 1/2: work cannot start without context. `PRODUCT.md` is REQUIRED — if
     * missing the task is BLOCKED and the only legal next move is `teach`.
     * Rule 3: `DESIGN.md` is RECOMMENDED — if missing the task is NOT blocked but
     * a nudge to `document` is emitted.
     *
     * @return array{
     *   can_start:bool,
     *   blocked:bool,
     *   blocking_reason:?string,
     *   forced_command:?string,
     *   nudges:array<int, string>,
     *   product_md:bool,
     *   design_md:bool,
     *   conclusion:string
     * }
     */
    public function evaluateContextGate(bool $hasProductMd, bool $hasDesignMd): array
    {
        $nudges = [];

        if (! $hasProductMd) {
            return [
                'can_start' => false,
                'blocked' => true,
                'blocking_reason' => 'product_md_missing',
                'forced_command' => 'teach',
                'nudges' => $nudges,
                'product_md' => false,
                'design_md' => $hasDesignMd,
                'conclusion' => 'context_gate_blocked_product_md_required_call_teach',
            ];
        }

        if (! $hasDesignMd) {
            $nudges[] = 'document';
        }

        return [
            'can_start' => true,
            'blocked' => false,
            'blocking_reason' => null,
            'forced_command' => null,
            'nudges' => $nudges,
            'product_md' => true,
            'design_md' => $hasDesignMd,
            'conclusion' => $nudges === []
                ? 'context_ready_brand_and_product_context_present'
                : 'context_ready_but_design_md_recommended_nudge_document',
        ];
    }

    /**
     * Select the reference lane for the task register (Regra 4: brand and product
     * use different criteria). An unknown register is rejected — the doc only
     * defines the brand and product lanes.
     *
     * @return array{register:string, valid:bool, reference_lane:?string, conclusion:string}
     */
    public function selectRegister(string $register): array
    {
        $needle = strtolower(trim($register));
        $valid = in_array($needle, self::REGISTERS, true);

        return [
            'register' => $needle,
            'valid' => $valid,
            'reference_lane' => $valid ? $needle : null,
            'conclusion' => $valid
                ? 'register_lane_selected_with_distinct_criteria'
                : 'register_must_be_brand_or_product',
        ];
    }

    /**
     * The `craft` gate (Regras para IA 5 + 6).
     *
     * Rule 5: `craft` nao pula `shape` — a craft run that has not passed `shape`
     * is refused. Rule 6: when Codex image generation is available, `craft` STOPS
     * at the four pre-code gates (questions, palette, mocks, approved direction);
     * writing code before all four are cleared is refused. When image generation
     * is unavailable the four-gate stop does not apply (the doc scopes it to
     * "Quando Codex tem image generation"), but `shape` is still mandatory.
     *
     * @param  array<string, mixed>  $context
     * @return array{
     *   may_write_code:bool,
     *   refused:bool,
     *   refusal_reasons:array<int, string>,
     *   cleared_gates:array<int, string>,
     *   pending_gates:array<int, string>,
     *   shape_passed:bool,
     *   image_generation_available:bool,
     *   conclusion:string
     * }
     */
    public function evaluateCraftGate(array $context): array
    {
        $shapePassed = ($context['shape_passed'] ?? null) === true;
        $imageGen = ($context['image_generation_available'] ?? null) === true;

        $refusals = [];

        // Rule 5: craft can never skip shape.
        if (! $shapePassed) {
            $refusals[] = 'craft_cannot_skip_shape';
        }

        // Rule 6: four pre-code gates when image generation exists.
        $cleared = [];
        $pending = [];
        if ($imageGen) {
            foreach (self::CRAFT_PRE_CODE_GATES as $gate) {
                if (($context['gate_'.$gate] ?? null) === true) {
                    $cleared[] = $gate;
                } else {
                    $pending[] = $gate;
                }
            }
            if ($pending !== []) {
                $refusals[] = 'craft_must_clear_four_gates_before_code';
            }
        }

        $refused = $refusals !== [];

        return [
            'may_write_code' => ! $refused,
            'refused' => $refused,
            'refusal_reasons' => $refusals,
            'cleared_gates' => $cleared,
            'pending_gates' => $pending,
            'shape_passed' => $shapePassed,
            'image_generation_available' => $imageGen,
            'conclusion' => $refused
                ? 'craft_refused_shape_or_pre_code_gates_incomplete'
                : 'craft_may_proceed_shape_passed_and_gates_clear',
        ];
    }

    /**
     * The evidence gate (Regra 7: a screenshot with no read/inspection does NOT
     * count as evidence). A captured screenshot only becomes evidence once it has
     * been read AND inspected.
     *
     * @return array{
     *   counts_as_evidence:bool,
     *   screenshot_captured:bool,
     *   was_read:bool,
     *   was_inspected:bool,
     *   reason:string
     * }
     */
    public function evaluateScreenshotEvidence(bool $screenshotCaptured, bool $wasRead, bool $wasInspected): array
    {
        $counts = $screenshotCaptured && $wasRead && $wasInspected;

        return [
            'counts_as_evidence' => $counts,
            'screenshot_captured' => $screenshotCaptured,
            'was_read' => $wasRead,
            'was_inspected' => $wasInspected,
            'reason' => match (true) {
                ! $screenshotCaptured => 'no_screenshot_captured',
                ! ($wasRead && $wasInspected) => 'screenshot_without_read_or_inspection_is_not_evidence',
                default => 'screenshot_read_and_inspected_counts_as_evidence',
            },
        ];
    }

    /**
     * Evaluate an observed sequence of Fluxo steps against the documented ordered
     * pipeline (doc rule "Fluxo"). A step is a violation when it fires before one
     * of its prerequisites (an earlier step in FLOW) has occurred — e.g. routing
     * a command before context is loaded or the register inferred. Unknown steps
     * are reported and never count as satisfied.
     *
     * @param  array<int, string>  $observedSteps
     * @return array{
     *   ordered:bool,
     *   order_violations:array<int, array{step:string, missing_prerequisites:array<int, string>}>,
     *   unknown_steps:array<int, string>,
     *   executed_in_order:array<int, string>,
     *   conclusion:string
     * }
     */
    public function evaluateFlowOrder(array $observedSteps): array
    {
        $rank = array_flip(self::FLOW);
        $seen = [];
        $violations = [];
        $unknown = [];
        $executed = [];

        foreach ($observedSteps as $step) {
            if (! array_key_exists($step, $rank)) {
                $unknown[] = $step;

                continue;
            }

            $missing = [];
            for ($i = 0; $i < $rank[$step]; $i++) {
                $prereq = self::FLOW[$i];
                if (! array_key_exists($prereq, $seen)) {
                    $missing[] = $prereq;
                }
            }

            if ($missing !== []) {
                $violations[] = ['step' => $step, 'missing_prerequisites' => $missing];
            } else {
                $executed[] = $step;
            }

            $seen[$step] = true;
        }

        $ordered = $violations === [] && $unknown === [];

        return [
            'ordered' => $ordered,
            'order_violations' => $violations,
            'unknown_steps' => $unknown,
            'executed_in_order' => $executed,
            'conclusion' => $ordered
                ? 'skill_flow_respects_documented_order'
                : 'skill_flow_out_of_order_contract_violated',
        ];
    }
}
