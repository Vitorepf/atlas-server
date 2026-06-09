<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\Mission\MissionCanonicalHash;

/**
 * Builds and prioritizes canonical factory_max backlog findings.
 *
 * The canonical backlog service owns the catalogue and admission report. This
 * builder owns hash/test-path/priority policy so the catalogue does not also
 * become the ranking engine.
 */
final class AreaFocusFactoryMaxFindingBuilder
{
    /**
     * @return array<string,mixed>
     */
    public function build(string $id, string $title, string $detail, string $sourceDoc, string $sourceFile, string $testBasename, string $valueReason, string $areaId, string $focus): array
    {
        $testPath = $this->expectedTestPath($testBasename, $sourceFile);
        $factoryPriority = $this->priority($id, $title, $detail, $sourceFile, $valueReason);
        $l7Haystack = strtolower(implode(' ', [$id, $title, $detail, $sourceFile, $valueReason]));
        $isL7Completion = $this->isL7CompletionFinding($l7Haystack);
        $l7Phase = $isL7Completion ? 'L7' : '';
        $completionGapId = $isL7Completion ? $this->completionGapId($l7Haystack) : '';
        $hash = 'sha256:'.MissionCanonicalHash::sha256([
            AreaFocusFactoryMaxCanonicalBacklogService::REPORT_SCHEMA,
            $id,
            $sourceDoc,
            $sourceFile,
            $testPath,
        ]);

        return [
            'schema_version' => 'atlas.software_company_stewardship.area_focus_deep_finding.v1',
            'finding_id' => 'canonical_aaeos_'.$id,
            'finding_hash' => $hash,
            'area_id' => $areaId,
            'focus' => $focus,
            'title' => $title,
            'detail' => $detail,
            'why_it_matters' => $detail,
            'value_reason' => $valueReason,
            'source_doc' => $sourceDoc,
            'kind' => 'runtime',
            'severity' => 'high',
            'confidence' => 'high',
            'confidence_score' => 0.92,
            'owner_candidate' => 'atlas_dev',
            'affected_files' => [$sourceFile],
            'affected_docs' => [],
            'evidence_refs' => [
                'source_doc:'.$sourceDoc,
                'impl:'.$sourceFile,
                'expected_test:'.$testBasename,
            ],
            'origin' => 'canonical_aaeos_backlog',
            'origin_type' => 'runtime_gap',
            'auto_execution_allowed' => true,
            'operator_review_required' => false,
            'factory_priority_order' => $factoryPriority['order'],
            'factory_priority_group' => $factoryPriority['group'],
            'factory_priority_reason' => $factoryPriority['reason'],
            'factory_priority_score' => $factoryPriority['score'],
            'priority_score' => $factoryPriority['score'],
            'l7_phase' => $l7Phase,
            'completion_gap_id' => $completionGapId,
            'spec_seed' => [
                'schema_version' => 'atlas.software_company_stewardship.canonical_factory_max_backlog_seed.v1',
                'candidate_id' => 'canonical_aaeos_'.$id,
                'candidate_hash' => $hash,
                'source_owner' => 'atlas_dev',
                'gap_kind' => 'canonical_aaeos_high_value_runtime_gap',
                'title' => $title,
                'rationale' => $detail,
                'value_reason' => $valueReason,
                'source_doc' => $sourceDoc,
                'owner_doc_refs' => [$sourceDoc],
                'route_hint_owner' => 'atlas_dev',
                'risk_level' => 'high',
                'factory_priority_order' => $factoryPriority['order'],
                'factory_priority_group' => $factoryPriority['group'],
                'factory_priority_reason' => $factoryPriority['reason'],
                'tests_required' => [$testPath],
                'evidence_refs' => [
                    'source_doc:'.$sourceDoc,
                    'impl:'.$sourceFile,
                    'expected_test:'.$testBasename,
                ],
                'acceptance' => [
                    'The work is derived from a canonical AAEOS/runtime/stewardship doc, not chat or filler.',
                    'The Self-Construction bridge decomposes it into bounded packets before owner execution.',
                    'The focused test path proves the first bounded runtime contract.',
                ],
                'proposal_only' => false,
                'operator_review_required' => false,
            ],
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $findings
     * @return list<array<string,mixed>>
     */
    public function prioritized(array $findings): array
    {
        usort($findings, static function (array $a, array $b): int {
            return ((int) ($a['factory_priority_order'] ?? 999) <=> (int) ($b['factory_priority_order'] ?? 999))
                ?: ((int) ($b['factory_priority_score'] ?? 0) <=> (int) ($a['factory_priority_score'] ?? 0))
                ?: ((string) ($a['finding_id'] ?? '') <=> (string) ($b['finding_id'] ?? ''));
        });

        return array_values($findings);
    }

    /**
     * @return array{order:int,group:string,reason:string,score:int}
     */
    public function priority(string $id, string $title, string $detail, string $sourceFile, string $valueReason): array
    {
        $haystack = strtolower(implode(' ', [$id, $title, $detail, $sourceFile, $valueReason]));

        if ($this->isL7CompletionFinding($haystack)) {
            return [
                'order' => 5,
                'group' => 'l7_completion_runtime_wiring',
                'reason' => 'closing an L7 (S83-S100) completion/runtime-wiring gap is the highest-leverage work; the loop converges the ladder before any pure new-class work',
                'score' => 10000,
            ];
        }

        if ($this->isDocsOnlyNoOpFinding($haystack)) {
            return [
                'order' => 999,
                'group' => 'docs_only_no_op_fatal',
                'reason' => 'docs-only / no-op work changes no runtime and no test, so it earns a fatal factory_max penalty and is selected last',
                'score' => -10000,
            ];
        }

        if (str_contains($haystack, 'context_quality') || str_contains($haystack, 'context memory') || str_contains($haystack, 'retrieval')) {
            return [
                'order' => 60,
                'group' => 'context_memory_quality',
                'reason' => 'context, memory, and retrieval quality reduce provider mistakes before heavier AAEOS work',
                'score' => 4000,
            ];
        }

        $buckets = [
            10 => ['repair_agent_real', 'repair feedback, remediation, and owner-runtime failure handling come before new feature work', ['repair', 'remediation', 'failure_taxonomy', 'owner_runtime_result_failed', 'senior_loop']],
            20 => ['provider_fallback_routing', 'provider fallback, serving-provider attribution, and budget routing keep long runs alive', ['provider_routing', 'provider fallback', 'fallback chain', 'serving provider', 'provider_budget', 'provider timeout', 'provider_timeout', 'atlas_decide_provider', 'minimax']],
            30 => ['backlog_depth_anti_starvation', 'backlog depth, admission, quarantine, and selection prevent starvation or repeated filler', ['backlog', 'admission', 'starvation', 'selection', 'quarantine']],
            40 => ['cycle_firewall_post_auditor', 'preflight, post-cycle auditing, chaos, invariants, and merge policy prevent false success', ['preflight', 'firewall', 'post_cycle', 'post-cycle', 'auditor', 'chaos', 'invariant', 'merge_queue', 'merge_autonomy', 'judge_accept', 'judge-accept', 'no-merge-without']],
            50 => ['evidence_ledger_hygiene', 'evidence, ledger, receipts, recorders, and phase handoffs make long runs replayable', ['evidence', 'ledger', 'receipt', 'cycle_recorder', 'phase_handoff', 'deferred_phase', 'merge_hash']],
            70 => ['cleanup_process_hygiene', 'sandbox, process, branch, lock, and worktree hygiene make unattended runs survivable', ['process_isolated', 'process-isolated', 'sandbox', 'branch', 'lock_', '_lock', 'stale lock', 'worktree', 'cleanup', 'isolation']],
            80 => ['long_run_supervisor', 'supervisors, resource governors, and certification ladders keep 10h/24h/7d runs observable', ['supervisor', 'long_run', 'long-run', 'resource_governor', '24h_runner', 'cert_ladder', 'certification_ladder']],
            90 => ['aaeos_quality_gates', 'department maturity, quality bars, review, QA, delivery, and universal gates raise AAEOS quality safely', ['quality_bar', 'department maturity', 'dept_maturity', 'qa', 'cross_review', 'zero_downtime', 'universal_gate', 'universal gates', 'gate evaluator']],
        ];

        foreach ($buckets as $order => [$group, $reason, $needles]) {
            foreach ($needles as $needle) {
                if (str_contains($haystack, $needle)) {
                    return [
                        'order' => $order,
                        'group' => $group,
                        'reason' => $reason,
                        'score' => 10000 - ($order * 100),
                    ];
                }
            }
        }

        return [
            'order' => 100,
            'group' => 'heavy_aaeos_runtime',
            'reason' => 'large AAEOS runtime capability work waits until the factory loop is stable, observable, and provider-resilient',
            'score' => 1000,
        ];
    }

    private function isL7CompletionFinding(string $haystack): bool
    {
        foreach (['l7_completion', 'l7 completion', 'runtime_wiring', 'runtime wiring', 'l7_phase', 'completion_gap'] as $needle) {
            if (str_contains($haystack, $needle)) {
                return true;
            }
        }

        if (str_contains($haystack, 'l7-l10-governed-ladder-backlog') || str_contains($haystack, 'l7 ladder backlog')) {
            return true;
        }

        return $this->completionGapId($haystack) !== '';
    }

    private function isDocsOnlyNoOpFinding(string $haystack): bool
    {
        foreach (['docs_only', 'docs-only', 'doc_only', 'documentation only', 'no-op', 'no_op', 'noop'] as $needle) {
            if (str_contains($haystack, $needle)) {
                return true;
            }
        }

        return str_contains($haystack, 'no runtime change') || str_contains($haystack, 'no test change');
    }

    private function completionGapId(string $haystack): string
    {
        if (preg_match('/\bs(8[3-9]|9[0-9]|100)\b/', $haystack, $m) === 1) {
            return 'S'.$m[1];
        }

        return '';
    }

    private function expectedTestPath(string $testBasename, string $sourceFile): string
    {
        if (str_starts_with($testBasename, 'tests/')) {
            return $testBasename;
        }
        $dir = 'tests/Unit/Ai/SoftwareCompanyStewardship/AreaFocusLoop';
        if (str_contains($sourceFile, '/OwnerFlow/')) {
            $dir .= '/OwnerFlow';
        }

        return $dir.'/'.$testBasename;
    }
}
