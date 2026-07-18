<?php

declare(strict_types=1);

namespace App\Services\Ai\AgenticEngineeringOs;

use App\Services\Ai\Support\AiValueNormalizer;

/**
 * AAEOS Runbook Orchestrator — PHP implementation of
 * `atlas-agentic-engineering-os-runbook.md`.
 *
 * Walks a human intent through the 13 departments (intake → product →
 * architecture → research? → dev|forge → debug? → review → qa → security
 * → delivery → memory) producing an ordered runbook envelope. The
 * orchestrator does NOT execute department work — it produces the
 * canonical plan for downstream services.
 */
final class RunbookOrchestrator
{
    public const FIELD_DEFAULT_FLOW_GATES_TOTAL = 'default_flow_gates_total';
    public const FIELD_DEPARTMENT_COUNT = 'department_count';
    public const SCHEMA_VERSION = 'atlas.agentic_engineering_os.runbook.v1';

    public const AMBITION_TRIVIAL = 'trivial';

    public const AMBITION_TASK = 'task';

    public const AMBITION_MISSION = 'mission';

    public const AMBITION_OBRA = 'obra';

    public const ACTOR_KIND_AGENT = 'agent';

    public const ARCHITECTURE_REDESIGN_PROPOSAL_SCHEMA = 'atlas.architecture.redesign_proposal.v1';
    public const FIELD_ARCHITECT_SIGNATURES_COUNT = 'architect_signatures_count';
    public const FIELD_AUTONOMY_LEVEL = 'autonomy_level';
    public const FIELD_CURRENT = 'current';
    public const FIELD_CURRENT_STATE_SNAPSHOT_HASH = 'current_state_snapshot_hash';
    public const FIELD_DEFAULT_FLOW = 'default_flow';
    public const FIELD_DEPARTMENT = 'department';
    public const FIELD_GATES = 'gates';
    public const FIELD_INTENT_CLASS = 'intent_class';
    public const FIELD_PROPOSAL_HASH = 'proposal_hash';
    public const FIELD_PROPOSED = 'proposed';
    public const FIELD_PROPOSED_BY_ACTOR = 'proposed_by_actor';
    public const FIELD_STRUCTURAL_CHANGES = 'structural_changes';
    public const FIELD_TARGET = 'target';
    public const FIELD_TARGET_DOC = 'target_doc';
    public const FIELD_TITLE = 'title';
    public const FIELD_TOUCHES_SOVEREIGNTY_LAYER = 'touches_sovereignty_layer';
    public const FIELD_NEEDS_RESEARCH = 'needs_research';
    public const FIELD_NEEDS_DEBUG = 'needs_debug';

    /** Minimum replay count before a structural redesign may be promoted. */
    public const REPLAY_OBRAS_COUNT_MIN = 100;
    public const FIELD_ORDER = 'order';
    public const FIELD_EVIDENCE_SCHEMA = 'evidence_schema';
    public const FIELD_DETAIL = 'detail';
    public const FIELD_DUAL_SIGNATURE_REQUIRED = 'dual_signature_required';
    public const FIELD_EMITS_HANDOFF_TO = 'emits_handoff_to';
    public const FIELD_FREQUENCY = 'frequency';
    public const FIELD_HANDOFF_TO = 'handoff_to';
    public const FIELD_ID = 'id';
    public const FIELD_INTENT = 'intent';
    public const FIELD_INTENT_HASH = 'intent_hash';
    public const FIELD_KIND = 'kind';
    public const FIELD_LIMITATION = 'limitation';
    public const FIELD_LIMITATION_OBSERVED = 'limitation_observed';
    public const FIELD_MOTIVATING_EVIDENCE = 'motivating_evidence';
    public const FIELD_PROMOTION_GATES = 'promotion_gates';
    public const FIELD_PROPOSAL_ID = 'proposal_id';
    public const FIELD_PROPOSED_AT = 'proposed_at';
    public const FIELD_REPLAY_OBRAS_COUNT_MIN = 'replay_obras_count_min';
    public const FIELD_REPLAY_REGRESSION_OBSERVED_COUNT_MAX = 'replay_regression_observed_count_max';
    public const FIELD_REQUIRES_REPLAY_BEFORE_PROMOTION = 'requires_replay_before_promotion';
    public const FIELD_REVIEW_STATUS = 'review_status';
    public const FIELD_RUNTIME_BASELINE = 'runtime_baseline';
    public const FIELD_SAFETY_SOVEREIGNTY_BLOCK_APPLIED = 'safety_sovereignty_block_applied';
    public const FIELD_SCHEMA = 'schema';
    public const FIELD_SCHEMA_VERSION = 'schema_version';
    public const FIELD_STAGE_COUNT = 'stage_count';
    public const FIELD_STAGES = 'stages';
    public const FIELD_SHA256 = 'sha256';
    public const FIELD_PENDING_REPLAY = 'pending_replay';

    /**
     * Canonical default flow for a non-trivial intent. Trivial intents
     * (e.g., "how do I run tests") skip directly from intake → memory.
     */
    public const DEFAULT_FLOW = [
        DepartmentContractRuntime::DEPARTMENT_EXECUTIVE_INTAKE,
        DepartmentContractRuntime::DEPARTMENT_PRODUCT,
        DepartmentContractRuntime::DEPARTMENT_ARCHITECTURE,
        DepartmentContractRuntime::DEPARTMENT_DEV,
        DepartmentContractRuntime::DEPARTMENT_REVIEW,
        DepartmentContractRuntime::DEPARTMENT_QA,
        DepartmentContractRuntime::DEPARTMENT_SECURITY,
        DepartmentContractRuntime::DEPARTMENT_DELIVERY,
        DepartmentContractRuntime::DEPARTMENT_MEMORY,
    ];

    public function __construct(
        private readonly DepartmentContractRuntime $departments,
    ) {}

    /**
     * @param  array{
     *   intent: string,
     *   intent_class?: 'trivial'|'task'|'mission'|'obra',
     *   needs_research?: bool,
     *   needs_debug?: bool,
     * }  $request
     * @return array{
     *   schema_version: string,
     *   intent_hash: string,
     *   intent_class: string,
     *   stages: list<array{
     *     order: int,
     *     department: string,
     *     gates: list<string>,
     *     evidence_schema: ?string,
     *     handoff_to: list<string>
     *   }>,
     *   stage_count: int,
     *   detail: string
     * }
     */
    public function plan(array $request): array
    {
        $intent = AiValueNormalizer::trimmedStringOrNull($request[self::FIELD_INTENT] ?? null) ?? '';
        $intentClass = AiValueNormalizer::trimmedStringOrNull($request[self::FIELD_INTENT_CLASS] ?? null) ?? $this->classify($intent);
        $needsResearch = (AiValueNormalizer::boolOrNull($request[self::FIELD_NEEDS_RESEARCH] ?? null) ?? false);
        $needsDebug = (AiValueNormalizer::boolOrNull($request[self::FIELD_NEEDS_DEBUG] ?? null) ?? false);

        $flow = $this->flowFor($intentClass, $needsResearch, $needsDebug);
        $stages = [];
        foreach ($flow as $i => $dept) {
            $stages[] = [
                self::FIELD_ORDER => $i + 1,
                self::FIELD_DEPARTMENT => $dept,
                self::FIELD_GATES => $this->departments->gatesFor($dept),
                self::FIELD_EVIDENCE_SCHEMA => $this->departments->evidenceSchemaFor($dept),
                self::FIELD_HANDOFF_TO => AiValueNormalizer::arrayOrEmpty(DepartmentContractRuntime::CATALOGUE[$dept][self::FIELD_EMITS_HANDOFF_TO] ?? null),
            ];
        }

        return [
            self::FIELD_SCHEMA_VERSION => self::SCHEMA_VERSION,
            self::FIELD_INTENT_HASH => hash(self::FIELD_SHA256, $intent),
            self::FIELD_INTENT_CLASS => $intentClass,
            self::FIELD_STAGES => $stages,
            self::FIELD_STAGE_COUNT => count($stages),
            self::FIELD_DETAIL => sprintf(
                'Runbook for %s intent: %d stages, %d gates total.',
                $intentClass,
                count($stages),
                array_sum(array_map(static fn ($s): int => count($s[self::FIELD_GATES]), $stages)),
            ),
        ];
    }

    /**
     * Promote architecture evolution proposals for phases, departments and gates
     * into a bounded reviewable packet. Promotion requires replay evidence; this
     * method only materializes the proposal envelope.
     *
     * @param  array{
     *   title: string,
     *   limitation: string,
     *   structural_changes?: list<array{
     *     target: 'department'|'phase'|'gate',
     *     current: string,
     *     proposed: string,
     *     target_doc?: string,
     *   }>,
     *   proposed_by_actor?: array{kind: string, id: string, autonomy_level: string},
     *   touches_sovereignty_layer?: bool,
     * }  $request
     * @return array<string, mixed>
     */
    public function proposeStructuralRedesign(array $request): array
    {
        $title = AiValueNormalizer::trimmedStringOrNull($request[self::FIELD_TITLE] ?? null) ?? '';
        $limitation = AiValueNormalizer::trimmedStringOrNull($request[self::FIELD_LIMITATION] ?? null) ?? '';
        if ($title === '' || $limitation === '') {
            throw new \InvalidArgumentException('title and limitation are required for structural redesign proposals.');
        }

        $rawChanges = AiValueNormalizer::arrayOrEmpty($request[self::FIELD_STRUCTURAL_CHANGES] ?? null);
        $structuralChanges = [];
        foreach ($rawChanges as $change) {
            if (! is_array($change)) {
                continue;
            }
            $target = AiValueNormalizer::trimmedStringOrNull($change[self::FIELD_TARGET] ?? null) ?? '';
            if (! in_array($target, ['department', 'phase', 'gate'], true)) {
                continue;
            }
            $current = AiValueNormalizer::trimmedStringOrNull($change[self::FIELD_CURRENT] ?? null) ?? '';
            $proposed = AiValueNormalizer::trimmedStringOrNull($change[self::FIELD_PROPOSED] ?? null) ?? '';
            if ($current === '' || $proposed === '') {
                continue;
            }
            $structuralChanges[] = [
                self::FIELD_TARGET => $target,
                self::FIELD_CURRENT => $current,
                self::FIELD_PROPOSED => $proposed,
                self::FIELD_TARGET_DOC => AiValueNormalizer::trimmedStringOrNull($change[self::FIELD_TARGET_DOC] ?? null) ?? 'atlas-agentic-engineering-os-runbook',
                self::FIELD_CURRENT_STATE_SNAPSHOT_HASH => hash(self::FIELD_SHA256, $current),
            ];
        }

        $touchesSovereignty = (AiValueNormalizer::boolOrNull($request[self::FIELD_TOUCHES_SOVEREIGNTY_LAYER] ?? null) ?? false);
        $baselineFlow = self::DEFAULT_FLOW;
        $baselineGatesTotal = array_sum(array_map(
            fn (string $dept): int => count($this->departments->gatesFor($dept)),
            $baselineFlow,
        ));

        $proposal = [
            self::FIELD_SCHEMA => self::ARCHITECTURE_REDESIGN_PROPOSAL_SCHEMA,
            self::FIELD_PROPOSAL_ID => 'arp-'.bin2hex(random_bytes(8)),
            self::FIELD_TITLE => $title,
            self::FIELD_STRUCTURAL_CHANGES => $structuralChanges,
            self::FIELD_RUNTIME_BASELINE => [
                self::FIELD_DEFAULT_FLOW => $baselineFlow,
                self::FIELD_DEPARTMENT_COUNT => count(DepartmentContractRuntime::CATALOGUE),
                self::FIELD_DEFAULT_FLOW_GATES_TOTAL => $baselineGatesTotal,
            ],
            self::FIELD_MOTIVATING_EVIDENCE => [
                [
                    self::FIELD_LIMITATION_OBSERVED => $limitation,
                    self::FIELD_FREQUENCY => 1,
                ],
            ],
            self::FIELD_TOUCHES_SOVEREIGNTY_LAYER => $touchesSovereignty,
            self::FIELD_SAFETY_SOVEREIGNTY_BLOCK_APPLIED => $touchesSovereignty,
            self::FIELD_PROMOTION_GATES => [
                self::FIELD_REPLAY_OBRAS_COUNT_MIN => self::REPLAY_OBRAS_COUNT_MIN,
                self::FIELD_REPLAY_REGRESSION_OBSERVED_COUNT_MAX => 0,
                self::FIELD_DUAL_SIGNATURE_REQUIRED => true,
                self::FIELD_ARCHITECT_SIGNATURES_COUNT => 2,
            ],
            self::FIELD_REQUIRES_REPLAY_BEFORE_PROMOTION => true,
            self::FIELD_REVIEW_STATUS => self::FIELD_PENDING_REPLAY,
            self::FIELD_PROPOSED_BY_ACTOR => AiValueNormalizer::arrayOrEmpty($request[self::FIELD_PROPOSED_BY_ACTOR] ?? [
                self::FIELD_KIND => self::ACTOR_KIND_AGENT,
                self::FIELD_ID => 'aaeos-runbook-orchestrator',
                self::FIELD_AUTONOMY_LEVEL => 'L13',
            ]),
            self::FIELD_PROPOSED_AT => now()->toAtomString(),
        ];
        $proposal[self::FIELD_PROPOSAL_HASH] = hash(self::FIELD_SHA256, json_encode(
            array_diff_key($proposal, [self::FIELD_PROPOSAL_HASH => true]),
            JSON_THROW_ON_ERROR,
        ));

        return $proposal;
    }

    /**
     * @return list<string>
     */
    private function flowFor(string $intentClass, bool $needsResearch, bool $needsDebug): array
    {
        if ($intentClass === self::AMBITION_TRIVIAL) {
            return [
                DepartmentContractRuntime::DEPARTMENT_EXECUTIVE_INTAKE,
                DepartmentContractRuntime::DEPARTMENT_MEMORY,
            ];
        }

        $flow = self::DEFAULT_FLOW;

        if ($needsResearch) {
            // Insert research after architecture, before dev
            $idx = array_search(DepartmentContractRuntime::DEPARTMENT_ARCHITECTURE, $flow, true);
            if ($idx !== false) {
                array_splice($flow, (int) $idx + 1, 0, DepartmentContractRuntime::DEPARTMENT_RESEARCH);
            }
        }

        if ($needsDebug) {
            $idx = array_search(DepartmentContractRuntime::DEPARTMENT_DEV, $flow, true);
            if ($idx !== false) {
                array_splice($flow, (int) $idx + 1, 0, DepartmentContractRuntime::DEPARTMENT_DEBUG);
            }
        }

        if ($intentClass === self::AMBITION_OBRA) {
            // Replace dev with forge for obra-class intents.
            $devIdx = array_search(DepartmentContractRuntime::DEPARTMENT_DEV, $flow, true);
            if ($devIdx !== false) {
                $flow[$devIdx] = DepartmentContractRuntime::DEPARTMENT_FORGE;
            }
        }

        return $flow;
    }

    private function classify(string $intent): string
    {
        $lower = AiValueNormalizer::lowerTrimmedString($intent);
        if ($lower === '' || mb_strlen($lower) < 12) {
            return self::AMBITION_TRIVIAL;
        }
        if (preg_match('/\b(refactor|rewrite|migrate|consolida|nova area|nova surface|enterprise)\b/u', $lower)) {
            return self::AMBITION_OBRA;
        }
        if (preg_match('/\b(implementa|cria|adiciona|conserta|fix|debug|melhora)\b/u', $lower)) {
            return self::AMBITION_TASK;
        }

        return self::AMBITION_MISSION;
    }
}
