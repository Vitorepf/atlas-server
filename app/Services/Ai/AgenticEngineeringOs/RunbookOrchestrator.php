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
