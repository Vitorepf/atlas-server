<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Pure planner. Decides whether the self-construction brain can safely run against another
 * project without leaking Atlas-only assumptions, BEFORE 24/7 autonomy is applied outside Atlas.
 *
 * Checks five hard prerequisites: docs/context sync, task namespace, worker routing, evidence
 * gates, and workspace isolation. Any Atlas-specific assumption the caller surfaces is reported
 * as a portability_risk — never silently accepted — and also blocks portable=true, since an
 * unaddressed Atlas-only assumption is exactly the kind of leak this planner exists to catch.
 *
 * Input shape: {project_name?:string, has_docs_context_sync?:bool, has_task_namespace?:bool,
 *               has_worker_routing?:bool, has_evidence_gates?:bool, has_workspace_isolation?:bool,
 *               atlas_specific_assumptions?:list<string>}
 *
 * Pure — no I/O, no provider calls, no enqueue.
 */
final class AtlasExternalBrainCrossProjectPortabilityPlanner
{
    public const SCHEMA = 'atlas.self_construction.external_brain.cross_project_portability_planner.v1';

    /** @var list<string> */
    private const PREREQUISITES = [
        'docs_context_sync',
        'task_namespace',
        'worker_routing',
        'evidence_gates',
        'workspace_isolation',
    ];

    /**
     * @param  array<string,mixed>  $project
     * @return array{schema:string, portable:bool, readiness_score:float, missing_prerequisites:list<string>, required_setup_steps:list<string>, portability_risks:list<string>, first_safe_scope:string}
     */
    public function plan(array $project): array
    {
        $checks = [];
        foreach (self::PREREQUISITES as $prereq) {
            $checks[$prereq] = (bool) ($project['has_'.$prereq] ?? false);
        }

        $missing = array_keys(array_filter($checks, static fn (bool $ok): bool => ! $ok));
        sort($missing, SORT_STRING);

        $total = count($checks);
        $metCount = $total - count($missing);
        $readinessScore = $total > 0 ? round($metCount / $total, 4) : 0.0;

        $assumptions = array_values(array_unique(array_map(
            'strval',
            (array) ($project['atlas_specific_assumptions'] ?? []),
        )));
        sort($assumptions, SORT_STRING);
        $portabilityRisks = array_map(
            static fn (string $a): string => 'atlas_specific_assumption:'.$a,
            $assumptions,
        );

        // Both an unmet prerequisite AND an unresolved Atlas-only assumption are real leaks —
        // portable=true requires neither, never inferred from prerequisite-checklist completion alone.
        $portable = $missing === [] && $portabilityRisks === [];

        $requiredSetupSteps = array_map(
            static fn (string $m): string => 'set_up_'.$m,
            $missing,
        );

        $firstSafeScope = match (true) {
            $portable => 'full_self_construction_scope',
            $missing !== [] => 'read_only_discovery_scope_until_'.$missing[0].'_ready',
            default => 'read_only_discovery_scope_until_atlas_assumptions_resolved',
        };

        return [
            'schema' => self::SCHEMA,
            'portable' => $portable,
            'readiness_score' => $readinessScore,
            'missing_prerequisites' => $missing,
            'required_setup_steps' => $requiredSetupSteps,
            'portability_risks' => $portabilityRisks,
            'first_safe_scope' => $firstSafeScope,
        ];
    }
}
