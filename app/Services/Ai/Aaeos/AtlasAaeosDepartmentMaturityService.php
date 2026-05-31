<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos;

final class AtlasAaeosDepartmentMaturityService
{
    private const SCHEMA_VERSION = 'atlas.aaeos.department_maturity.v1';

    private const LAST_EVALUATION = '2026-05-26T00:00:00+00:00';

    private const NEXT_EVALUATION_DUE = '2026-06-26T00:00:00+00:00';

    private const OWNER = 'atlas-ai';

    private const DEPARTMENTS = [
        [
            'department_id' => 'product',
            'current_level' => 'L3',
            'evidence' => ['docs/engineering-knowledge-base/atlas-aaeos-department-maturity-matrix.md#product'],
            'blocker_id' => 'product_mobile_surface_l4',
            'blocker_summary' => 'falta surface mobile completa para L4',
            'blocker_severity' => 'medium',
        ],
        [
            'department_id' => 'architect',
            'current_level' => 'L3',
            'evidence' => ['docs/engineering-knowledge-base/atlas-aaeos-department-maturity-matrix.md#architect'],
            'blocker_id' => 'architect_autonomous_agent_l4',
            'blocker_summary' => 'precisa Architect agent autonomo para L4',
            'blocker_severity' => 'medium',
        ],
        [
            'department_id' => 'research',
            'current_level' => 'L2',
            'evidence' => ['docs/engineering-knowledge-base/atlas-aaeos-department-maturity-matrix.md#research'],
            'blocker_id' => 'research_source_backed_score_l3',
            'blocker_summary' => 'source-backed score baixo para L3',
            'blocker_severity' => 'medium',
        ],
        [
            'department_id' => 'dev',
            'current_level' => 'L1',
            'evidence' => ['docs/engineering-knowledge-base/atlas-aaeos-department-maturity-matrix.md#dev'],
            'blocker_id' => 'dev_plan_visible_l2',
            'blocker_summary' => 'A2 Plan-Visible incompleto, HTTP path legado',
            'blocker_severity' => 'high',
        ],
        [
            'department_id' => 'debug',
            'current_level' => 'L2',
            'evidence' => ['docs/engineering-knowledge-base/atlas-aaeos-department-maturity-matrix.md#debug'],
            'blocker_id' => 'debug_automated_root_cause_l3',
            'blocker_summary' => 'falta automated root-cause para L3',
            'blocker_severity' => 'medium',
        ],
        [
            'department_id' => 'review',
            'current_level' => 'L2',
            'evidence' => ['docs/engineering-knowledge-base/atlas-aaeos-department-maturity-matrix.md#review'],
            'blocker_id' => 'review_cross_review_r4',
            'blocker_summary' => 'falta cross-review automatico R4+',
            'blocker_severity' => 'medium',
        ],
        [
            'department_id' => 'qa',
            'current_level' => 'L2',
            'evidence' => ['docs/engineering-knowledge-base/atlas-aaeos-department-maturity-matrix.md#qa'],
            'blocker_id' => 'qa_contract_testing_e2e_l4',
            'blocker_summary' => 'falta contract testing E2E',
            'blocker_severity' => 'medium',
        ],
        [
            'department_id' => 'security',
            'current_level' => 'L3',
            'evidence' => ['docs/engineering-knowledge-base/atlas-aaeos-department-maturity-matrix.md#security'],
            'blocker_id' => 'security_threat_modeling_l4',
            'blocker_summary' => 'falta threat modeling automatico',
            'blocker_severity' => 'medium',
        ],
        [
            'department_id' => 'forge',
            'current_level' => 'L4',
            'evidence' => ['docs/engineering-knowledge-base/atlas-aaeos-department-maturity-matrix.md#forge'],
            'blocker_id' => 'forge_merge_review_promotion_r5',
            'blocker_summary' => 'falta merge review promotion R5 governado',
            'blocker_severity' => 'medium',
        ],
        [
            'department_id' => 'delivery',
            'current_level' => 'L2',
            'evidence' => ['docs/engineering-knowledge-base/atlas-aaeos-department-maturity-matrix.md#delivery'],
            'blocker_id' => 'delivery_zero_downtime_l3',
            'blocker_summary' => 'falta zero-downtime gate L3',
            'blocker_severity' => 'high',
        ],
        [
            'department_id' => 'memory',
            'current_level' => 'L3',
            'evidence' => ['docs/engineering-knowledge-base/atlas-aaeos-department-maturity-matrix.md#memory'],
            'blocker_id' => 'memory_cross_session_handoff_l4',
            'blocker_summary' => 'falta cross-session handoff pack L4',
            'blocker_severity' => 'medium',
        ],
    ];

    public function maturity(): array
    {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'departments' => $this->buildDepartments(),
        ];
    }

    private function buildDepartments(): array
    {
        return array_map(
            fn (array $department): array => [
                'department' => $department['department_id'],
                'maturity_tier' => $this->parseLevel($department['current_level']),
                'signals' => $this->buildSignals($department),
                'schema' => self::SCHEMA_VERSION,
                'evidence' => $department['evidence'],
                'blockers_to_next' => [
                    [
                        'id' => $department['blocker_id'],
                        'severity' => $department['blocker_severity'],
                        'owner' => self::OWNER,
                        'summary' => $department['blocker_summary'],
                    ],
                ],
                'last_evaluation' => self::LAST_EVALUATION,
                'next_evaluation_due' => self::NEXT_EVALUATION_DUE,
                'owner' => self::OWNER,
            ],
            self::DEPARTMENTS,
        );
    }

    private function parseLevel(string $level): int
    {
        return (int) preg_replace('/[^0-9]/', '', $level);
    }

    private function buildSignals(array $department): array
    {
        return [
            'current_level' => $department['current_level'],
            'evidence' => $department['evidence'],
            'primary_blocker' => $department['blocker_id'],
            'blocker_summary' => $department['blocker_summary'],
            'blocker_severity' => $department['blocker_severity'],
        ];
    }
}