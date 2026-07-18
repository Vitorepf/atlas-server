<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos;

use App\Services\Ai\Support\AiValueNormalizer;

final class AtlasAaeosDepartmentMaturityService
{
    public const SCHEMA_VERSION = 'atlas.aaeos.department_maturity.v1';

    public const LAST_EVALUATION = '2026-05-26T00:00:00+00:00';

    public const NEXT_EVALUATION_DUE = '2026-06-26T00:00:00+00:00';

    public const OWNER = 'atlas-ai';

    public const FIELD_DEPARTMENT_ID = 'department_id';

    public const FIELD_CURRENT_LEVEL = 'current_level';

    public const FIELD_EVIDENCE = 'evidence';

    public const FIELD_BLOCKER_ID = 'blocker_id';

    public const FIELD_BLOCKER_SUMMARY = 'blocker_summary';

    public const FIELD_BLOCKER_SEVERITY = 'blocker_severity';
    public const FIELD_OWNER = 'owner';
    public const FIELD_BLOCKERS_TO_NEXT = 'blockers_to_next';

    public const DEPARTMENTS = [
        [
            self::FIELD_DEPARTMENT_ID => 'product',
            self::FIELD_CURRENT_LEVEL => 'L3',
            self::FIELD_EVIDENCE => ['docs/engineering-knowledge-base/atlas-aaeos-department-maturity-matrix.md#product'],
            self::FIELD_BLOCKER_ID => 'product_mobile_surface_l4',
            self::FIELD_BLOCKER_SUMMARY => 'falta surface mobile completa para L4',
            self::FIELD_BLOCKER_SEVERITY => 'medium',
        ],
        [
            self::FIELD_DEPARTMENT_ID => 'architect',
            self::FIELD_CURRENT_LEVEL => 'L3',
            self::FIELD_EVIDENCE => ['docs/engineering-knowledge-base/atlas-aaeos-department-maturity-matrix.md#architect'],
            self::FIELD_BLOCKER_ID => 'architect_autonomous_agent_l4',
            self::FIELD_BLOCKER_SUMMARY => 'precisa Architect agent autonomo para L4',
            self::FIELD_BLOCKER_SEVERITY => 'medium',
        ],
        [
            self::FIELD_DEPARTMENT_ID => 'research',
            self::FIELD_CURRENT_LEVEL => 'L2',
            self::FIELD_EVIDENCE => ['docs/engineering-knowledge-base/atlas-aaeos-department-maturity-matrix.md#research'],
            self::FIELD_BLOCKER_ID => 'research_source_backed_score_l3',
            self::FIELD_BLOCKER_SUMMARY => 'source-backed score baixo para L3',
            self::FIELD_BLOCKER_SEVERITY => 'medium',
        ],
        [
            self::FIELD_DEPARTMENT_ID => 'dev',
            self::FIELD_CURRENT_LEVEL => 'L1',
            self::FIELD_EVIDENCE => ['docs/engineering-knowledge-base/atlas-aaeos-department-maturity-matrix.md#dev'],
            self::FIELD_BLOCKER_ID => 'dev_plan_visible_l2',
            self::FIELD_BLOCKER_SUMMARY => 'A2 Plan-Visible incompleto, HTTP path legado',
            self::FIELD_BLOCKER_SEVERITY => 'high',
        ],
        [
            self::FIELD_DEPARTMENT_ID => 'debug',
            self::FIELD_CURRENT_LEVEL => 'L2',
            self::FIELD_EVIDENCE => ['docs/engineering-knowledge-base/atlas-aaeos-department-maturity-matrix.md#debug'],
            self::FIELD_BLOCKER_ID => 'debug_automated_root_cause_l3',
            self::FIELD_BLOCKER_SUMMARY => 'falta automated root-cause para L3',
            self::FIELD_BLOCKER_SEVERITY => 'medium',
        ],
        [
            self::FIELD_DEPARTMENT_ID => 'review',
            self::FIELD_CURRENT_LEVEL => 'L2',
            self::FIELD_EVIDENCE => ['docs/engineering-knowledge-base/atlas-aaeos-department-maturity-matrix.md#review'],
            self::FIELD_BLOCKER_ID => 'review_cross_review_r4',
            self::FIELD_BLOCKER_SUMMARY => 'falta cross-review automatico R4+',
            self::FIELD_BLOCKER_SEVERITY => 'medium',
        ],
        [
            self::FIELD_DEPARTMENT_ID => 'qa',
            self::FIELD_CURRENT_LEVEL => 'L2',
            self::FIELD_EVIDENCE => ['docs/engineering-knowledge-base/atlas-aaeos-department-maturity-matrix.md#qa'],
            self::FIELD_BLOCKER_ID => 'qa_contract_testing_e2e_l4',
            self::FIELD_BLOCKER_SUMMARY => 'falta contract testing E2E',
            self::FIELD_BLOCKER_SEVERITY => 'medium',
        ],
        [
            self::FIELD_DEPARTMENT_ID => 'security',
            self::FIELD_CURRENT_LEVEL => 'L3',
            self::FIELD_EVIDENCE => ['docs/engineering-knowledge-base/atlas-aaeos-department-maturity-matrix.md#security'],
            self::FIELD_BLOCKER_ID => 'security_threat_modeling_l4',
            self::FIELD_BLOCKER_SUMMARY => 'falta threat modeling automatico',
            self::FIELD_BLOCKER_SEVERITY => 'medium',
        ],
        [
            self::FIELD_DEPARTMENT_ID => 'forge',
            self::FIELD_CURRENT_LEVEL => 'L4',
            self::FIELD_EVIDENCE => ['docs/engineering-knowledge-base/atlas-aaeos-department-maturity-matrix.md#forge'],
            self::FIELD_BLOCKER_ID => 'forge_merge_review_promotion_r5',
            self::FIELD_BLOCKER_SUMMARY => 'falta merge review promotion R5 governado',
            self::FIELD_BLOCKER_SEVERITY => 'medium',
        ],
        [
            self::FIELD_DEPARTMENT_ID => 'delivery',
            self::FIELD_CURRENT_LEVEL => 'L2',
            self::FIELD_EVIDENCE => ['docs/engineering-knowledge-base/atlas-aaeos-department-maturity-matrix.md#delivery'],
            self::FIELD_BLOCKER_ID => 'delivery_zero_downtime_l3',
            self::FIELD_BLOCKER_SUMMARY => 'falta zero-downtime gate L3',
            self::FIELD_BLOCKER_SEVERITY => 'high',
        ],
        [
            self::FIELD_DEPARTMENT_ID => 'memory',
            self::FIELD_CURRENT_LEVEL => 'L3',
            self::FIELD_EVIDENCE => ['docs/engineering-knowledge-base/atlas-aaeos-department-maturity-matrix.md#memory'],
            self::FIELD_BLOCKER_ID => 'memory_cross_session_handoff_l4',
            self::FIELD_BLOCKER_SUMMARY => 'falta cross-session handoff pack L4',
            self::FIELD_BLOCKER_SEVERITY => 'medium',
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
                'department' => AiValueNormalizer::trimmedStringOrNull($department[self::FIELD_DEPARTMENT_ID]) ?? '',
                'maturity_tier' => $this->parseLevel($department[self::FIELD_CURRENT_LEVEL]),
                'signals' => $this->buildSignals($department),
                'schema' => self::SCHEMA_VERSION,
                self::FIELD_EVIDENCE => $department[self::FIELD_EVIDENCE],
                self::FIELD_BLOCKERS_TO_NEXT => [
                    [
                        'id' => AiValueNormalizer::trimmedStringOrNull($department[self::FIELD_BLOCKER_ID]) ?? '',
                        'severity' => AiValueNormalizer::lowerTrimmedString($department[self::FIELD_BLOCKER_SEVERITY]),
                        self::FIELD_OWNER => self::OWNER,
                        'summary' => AiValueNormalizer::trimmedStringOrNull($department[self::FIELD_BLOCKER_SUMMARY]) ?? '',
                    ],
                ],
                'last_evaluation' => self::LAST_EVALUATION,
                'next_evaluation_due' => self::NEXT_EVALUATION_DUE,
                self::FIELD_OWNER => self::OWNER,
            ],
            self::DEPARTMENTS,
        );
    }

    private function parseLevel(string $level): int
    {
        return (int) preg_replace('/[^0-9]/', '', AiValueNormalizer::trimmedStringOrNull($level) ?? '');
    }

    private function buildSignals(array $department): array
    {
        return [
            self::FIELD_CURRENT_LEVEL => AiValueNormalizer::trimmedStringOrNull($department[self::FIELD_CURRENT_LEVEL] ?? null) ?? '',
            self::FIELD_EVIDENCE => AiValueNormalizer::arrayOrEmpty($department[self::FIELD_EVIDENCE] ?? null),
            'primary_blocker' => AiValueNormalizer::trimmedStringOrNull($department[self::FIELD_BLOCKER_ID] ?? null) ?? '',
            self::FIELD_BLOCKER_SUMMARY => AiValueNormalizer::trimmedStringOrNull($department[self::FIELD_BLOCKER_SUMMARY] ?? null) ?? '',
            self::FIELD_BLOCKER_SEVERITY => AiValueNormalizer::lowerTrimmedString($department[self::FIELD_BLOCKER_SEVERITY] ?? ''),
        ];
    }
}