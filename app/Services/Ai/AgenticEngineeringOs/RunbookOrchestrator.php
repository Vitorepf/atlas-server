<?php

declare(strict_types=1);

namespace App\Services\Ai\AgenticEngineeringOs;

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
    public const SCHEMA_VERSION = 'atlas.agentic_engineering_os.runbook.v1';

    public const ARCHITECTURE_REDESIGN_PROPOSAL_SCHEMA = 'atlas.architecture.redesign_proposal.v1';

    /** Minimum replay count before a structural redesign may be promoted. */
    public const REPLAY_OBRAS_COUNT_MIN = 100;

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
        $intent = (string) ($request['intent'] ?? '');
        $intentClass = (string) ($request['intent_class'] ?? $this->classify($intent));
        $needsResearch = (bool) ($request['needs_research'] ?? false);
        $needsDebug = (bool) ($request['needs_debug'] ?? false);

        $flow = $this->flowFor($intentClass, $needsResearch, $needsDebug);
        $stages = [];
        foreach ($flow as $i => $dept) {
            $stages[] = [
                'order' => $i + 1,
                'department' => $dept,
                'gates' => $this->departments->gatesFor($dept),
                'evidence_schema' => $this->departments->evidenceSchemaFor($dept),
                'handoff_to' => (array) (DepartmentContractRuntime::CATALOGUE[$dept]['emits_handoff_to'] ?? []),
            ];
        }

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'intent_hash' => hash('sha256', $intent),
            'intent_class' => $intentClass,
            'stages' => $stages,
            'stage_count' => count($stages),
            'detail' => sprintf(
                'Runbook for %s intent: %d stages, %d gates total.',
                $intentClass,
                count($stages),
                array_sum(array_map(static fn ($s): int => count($s['gates']), $stages)),
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
        $title = trim((string) ($request['title'] ?? ''));
        $limitation = trim((string) ($request['limitation'] ?? ''));
        if ($title === '' || $limitation === '') {
            throw new \InvalidArgumentException('title and limitation are required for structural redesign proposals.');
        }

        $rawChanges = (array) ($request['structural_changes'] ?? []);
        $structuralChanges = [];
        foreach ($rawChanges as $change) {
            if (! is_array($change)) {
                continue;
            }
            $target = (string) ($change['target'] ?? '');
            if (! in_array($target, ['department', 'phase', 'gate'], true)) {
                continue;
            }
            $current = trim((string) ($change['current'] ?? ''));
            $proposed = trim((string) ($change['proposed'] ?? ''));
            if ($current === '' || $proposed === '') {
                continue;
            }
            $structuralChanges[] = [
                'target' => $target,
                'current' => $current,
                'proposed' => $proposed,
                'target_doc' => (string) ($change['target_doc'] ?? 'atlas-agentic-engineering-os-runbook'),
                'current_state_snapshot_hash' => hash('sha256', $current),
            ];
        }

        $touchesSovereignty = (bool) ($request['touches_sovereignty_layer'] ?? false);
        $baselineFlow = self::DEFAULT_FLOW;
        $baselineGatesTotal = array_sum(array_map(
            fn (string $dept): int => count($this->departments->gatesFor($dept)),
            $baselineFlow,
        ));

        $proposal = [
            'schema' => self::ARCHITECTURE_REDESIGN_PROPOSAL_SCHEMA,
            'proposal_id' => 'arp-'.bin2hex(random_bytes(8)),
            'title' => $title,
            'structural_changes' => $structuralChanges,
            'runtime_baseline' => [
                'default_flow' => $baselineFlow,
                'department_count' => count(DepartmentContractRuntime::CATALOGUE),
                'default_flow_gates_total' => $baselineGatesTotal,
            ],
            'motivating_evidence' => [
                [
                    'limitation_observed' => $limitation,
                    'frequency' => 1,
                ],
            ],
            'touches_sovereignty_layer' => $touchesSovereignty,
            'safety_sovereignty_block_applied' => $touchesSovereignty,
            'promotion_gates' => [
                'replay_obras_count_min' => self::REPLAY_OBRAS_COUNT_MIN,
                'replay_regression_observed_count_max' => 0,
                'dual_signature_required' => true,
                'architect_signatures_count' => 2,
            ],
            'requires_replay_before_promotion' => true,
            'review_status' => 'pending_replay',
            'proposed_by_actor' => (array) ($request['proposed_by_actor'] ?? [
                'kind' => 'agent',
                'id' => 'aaeos-runbook-orchestrator',
                'autonomy_level' => 'L13',
            ]),
            'proposed_at' => now()->toAtomString(),
        ];
        $proposal['proposal_hash'] = hash('sha256', json_encode(
            array_diff_key($proposal, ['proposal_hash' => true]),
            JSON_THROW_ON_ERROR,
        ));

        return $proposal;
    }

    /**
     * @return list<string>
     */
    private function flowFor(string $intentClass, bool $needsResearch, bool $needsDebug): array
    {
        if ($intentClass === 'trivial') {
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

        if ($intentClass === 'obra') {
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
        $lower = mb_strtolower(trim($intent));
        if ($lower === '' || mb_strlen($lower) < 12) {
            return 'trivial';
        }
        if (preg_match('/\b(refactor|rewrite|migrate|consolida|nova area|nova surface|enterprise)\b/u', $lower)) {
            return 'obra';
        }
        if (preg_match('/\b(implementa|cria|adiciona|conserta|fix|debug|melhora)\b/u', $lower)) {
            return 'task';
        }

        return 'mission';
    }
}
