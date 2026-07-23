<?php

declare(strict_types=1);

namespace App\Services\Ai\AgenticEngineeringOs\Maturity;

use App\Services\Ai\Support\AiValueNormalizer;

final class AtlasDepartmentMaturityService
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
    public const FIELD_SUMMARY = 'summary';
    public const FIELD_SIGNALS = 'signals';
    public const FIELD_DEPARTMENT = 'department';
    public const FIELD_DEPARTMENTS = 'departments';
    public const FIELD_ID = 'id';
    public const FIELD_LAST_EVALUATION = 'last_evaluation';
    public const FIELD_MATURITY_TIER = 'maturity_tier';
    public const FIELD_NEXT_EVALUATION_DUE = 'next_evaluation_due';
    public const FIELD_PRIMARY_BLOCKER = 'primary_blocker';
    public const FIELD_SCHEMA = 'schema';
    public const FIELD_SCHEMA_VERSION = 'schema_version';
    public const FIELD_SEVERITY = 'severity';
    public const FIELD_MEDIUM = 'medium';
    public const FIELD_HIGH = 'high';
    public const FIELD_ARCHITECT = 'architect';
    public const FIELD_ARCHITECT_AUTONOMOUS_AGENT_L4 = 'architect_autonomous_agent_l4';
    public const FIELD_DEBUG = 'debug';
    public const FIELD_DEBUG_AUTOMATED_ROOT_CAUSE_L3 = 'debug_automated_root_cause_l3';
    public const FIELD_DELIVERY = 'delivery';
    public const FIELD_DELIVERY_ZERO_DOWNTIME_L3 = 'delivery_zero_downtime_l3';
    public const FIELD_DEV = 'dev';
    public const FIELD_DEV_PLAN_VISIBLE_L2 = 'dev_plan_visible_l2';
    public const FIELD_FORGE = 'forge';
    public const FIELD_FORGE_MERGE_REVIEW_PROMOTION_R5 = 'forge_merge_review_promotion_r5';
    public const FIELD_MEMORY = 'memory';
    public const FIELD_MEMORY_CROSS_SESSION_HANDOFF_L4 = 'memory_cross_session_handoff_l4';
    public const FIELD_PRODUCT = 'product';
    public const FIELD_PRODUCT_MOBILE_SURFACE_L4 = 'product_mobile_surface_l4';
    public const FIELD_QA_CONTRACT_TESTING_E2E_L4 = 'qa_contract_testing_e2e_l4';
    public const FIELD_RESEARCH = 'research';
    public const FIELD_RESEARCH_SOURCE_BACKED_SCORE_L3 = 'research_source_backed_score_l3';
    public const FIELD_REVIEW = 'review';
    public const FIELD_REVIEW_CROSS_REVIEW_R4 = 'review_cross_review_r4';
    public const FIELD_SECURITY = 'security';
    public const FIELD_SECURITY_THREAT_MODELING_L4 = 'security_threat_modeling_l4';
    public const FIELD_QA = 'qa';
    public const FIELD_L2 = 'L2';
    public const FIELD_L3 = 'L3';
    public const FIELD_L1 = 'L1';
    public const FIELD_L4 = 'L4';
    public const FIELD_FALTA_THREAT_MODELING_AUTOMATICO = 'falta threat modeling automatico';
    public const FIELD_A2_PLAN_VISIBLE_INCOMPLETO__HTTP_PATH_LEGADO = 'A2 Plan-Visible incompleto, HTTP path legado';
    public const FIELD_FALTA_AUTOMATED_ROOT_CAUSE_PARA_L3 = 'falta automated root-cause para L3';
    public const FIELD_FALTA_CONTRACT_TESTING_E2_E = 'falta contract testing E2E';
    public const FIELD_FALTA_CROSS_SESSION_HANDOFF_PACK_L4 = 'falta cross-session handoff pack L4';
    public const FIELD_FALTA_MERGE_REVIEW_PROMOTION_R5_GOVERNADO = 'falta merge review promotion R5 governado';
    public const FIELD_FALTA_SURFACE_MOBILE_COMPLETA_PARA_L4 = 'falta surface mobile completa para L4';
    public const FIELD_FALTA_ZERO_DOWNTIME_GATE_L3 = 'falta zero-downtime gate L3';
    public const FIELD_PRECISA_ARCHITECT_AGENT_AUTONOMO_PARA_L4 = 'precisa Architect agent autonomo para L4';
    public const FIELD_SOURCE_BACKED_SCORE_BAIXO_PARA_L3 = 'source-backed score baixo para L3';

    public const DEPARTMENTS = [
        [
            self::FIELD_DEPARTMENT_ID => self::FIELD_PRODUCT,
            self::FIELD_CURRENT_LEVEL => self::FIELD_L3,
            self::FIELD_EVIDENCE => ['docs/engineering-knowledge-base/atlas-aaeos-department-maturity-matrix.md#product'],
            self::FIELD_BLOCKER_ID => self::FIELD_PRODUCT_MOBILE_SURFACE_L4,
            self::FIELD_BLOCKER_SUMMARY => self::FIELD_FALTA_SURFACE_MOBILE_COMPLETA_PARA_L4,
            self::FIELD_BLOCKER_SEVERITY => self::FIELD_MEDIUM,
        ],
        [
            self::FIELD_DEPARTMENT_ID => self::FIELD_ARCHITECT,
            self::FIELD_CURRENT_LEVEL => self::FIELD_L3,
            self::FIELD_EVIDENCE => ['docs/engineering-knowledge-base/atlas-aaeos-department-maturity-matrix.md#architect'],
            self::FIELD_BLOCKER_ID => self::FIELD_ARCHITECT_AUTONOMOUS_AGENT_L4,
            self::FIELD_BLOCKER_SUMMARY => self::FIELD_PRECISA_ARCHITECT_AGENT_AUTONOMO_PARA_L4,
            self::FIELD_BLOCKER_SEVERITY => self::FIELD_MEDIUM,
        ],
        [
            self::FIELD_DEPARTMENT_ID => self::FIELD_RESEARCH,
            self::FIELD_CURRENT_LEVEL => self::FIELD_L2,
            self::FIELD_EVIDENCE => ['docs/engineering-knowledge-base/atlas-aaeos-department-maturity-matrix.md#research'],
            self::FIELD_BLOCKER_ID => self::FIELD_RESEARCH_SOURCE_BACKED_SCORE_L3,
            self::FIELD_BLOCKER_SUMMARY => self::FIELD_SOURCE_BACKED_SCORE_BAIXO_PARA_L3,
            self::FIELD_BLOCKER_SEVERITY => self::FIELD_MEDIUM,
        ],
        [
            self::FIELD_DEPARTMENT_ID => self::FIELD_DEV,
            self::FIELD_CURRENT_LEVEL => self::FIELD_L1,
            self::FIELD_EVIDENCE => ['docs/engineering-knowledge-base/atlas-aaeos-department-maturity-matrix.md#dev'],
            self::FIELD_BLOCKER_ID => self::FIELD_DEV_PLAN_VISIBLE_L2,
            self::FIELD_BLOCKER_SUMMARY => self::FIELD_A2_PLAN_VISIBLE_INCOMPLETO__HTTP_PATH_LEGADO,
            self::FIELD_BLOCKER_SEVERITY => self::FIELD_HIGH,
        ],
        [
            self::FIELD_DEPARTMENT_ID => self::FIELD_DEBUG,
            self::FIELD_CURRENT_LEVEL => self::FIELD_L2,
            self::FIELD_EVIDENCE => ['docs/engineering-knowledge-base/atlas-aaeos-department-maturity-matrix.md#debug'],
            self::FIELD_BLOCKER_ID => self::FIELD_DEBUG_AUTOMATED_ROOT_CAUSE_L3,
            self::FIELD_BLOCKER_SUMMARY => self::FIELD_FALTA_AUTOMATED_ROOT_CAUSE_PARA_L3,
            self::FIELD_BLOCKER_SEVERITY => self::FIELD_MEDIUM,
        ],
        [
            self::FIELD_DEPARTMENT_ID => self::FIELD_REVIEW,
            self::FIELD_CURRENT_LEVEL => self::FIELD_L2,
            self::FIELD_EVIDENCE => ['docs/engineering-knowledge-base/atlas-aaeos-department-maturity-matrix.md#review'],
            self::FIELD_BLOCKER_ID => self::FIELD_REVIEW_CROSS_REVIEW_R4,
            self::FIELD_BLOCKER_SUMMARY => 'falta cross-review automatico R4+',
            self::FIELD_BLOCKER_SEVERITY => self::FIELD_MEDIUM,
        ],
        [
            self::FIELD_DEPARTMENT_ID => self::FIELD_QA,
            self::FIELD_CURRENT_LEVEL => self::FIELD_L2,
            self::FIELD_EVIDENCE => ['docs/engineering-knowledge-base/atlas-aaeos-department-maturity-matrix.md#qa'],
            self::FIELD_BLOCKER_ID => self::FIELD_QA_CONTRACT_TESTING_E2E_L4,
            self::FIELD_BLOCKER_SUMMARY => self::FIELD_FALTA_CONTRACT_TESTING_E2_E,
            self::FIELD_BLOCKER_SEVERITY => self::FIELD_MEDIUM,
        ],
        [
            self::FIELD_DEPARTMENT_ID => self::FIELD_SECURITY,
            self::FIELD_CURRENT_LEVEL => self::FIELD_L3,
            self::FIELD_EVIDENCE => ['docs/engineering-knowledge-base/atlas-aaeos-department-maturity-matrix.md#security'],
            self::FIELD_BLOCKER_ID => self::FIELD_SECURITY_THREAT_MODELING_L4,
            self::FIELD_BLOCKER_SUMMARY => self::FIELD_FALTA_THREAT_MODELING_AUTOMATICO,
            self::FIELD_BLOCKER_SEVERITY => self::FIELD_MEDIUM,
        ],
        [
            self::FIELD_DEPARTMENT_ID => self::FIELD_FORGE,
            self::FIELD_CURRENT_LEVEL => self::FIELD_L4,
            self::FIELD_EVIDENCE => ['docs/engineering-knowledge-base/atlas-aaeos-department-maturity-matrix.md#forge'],
            self::FIELD_BLOCKER_ID => self::FIELD_FORGE_MERGE_REVIEW_PROMOTION_R5,
            self::FIELD_BLOCKER_SUMMARY => self::FIELD_FALTA_MERGE_REVIEW_PROMOTION_R5_GOVERNADO,
            self::FIELD_BLOCKER_SEVERITY => self::FIELD_MEDIUM,
        ],
        [
            self::FIELD_DEPARTMENT_ID => self::FIELD_DELIVERY,
            self::FIELD_CURRENT_LEVEL => self::FIELD_L2,
            self::FIELD_EVIDENCE => ['docs/engineering-knowledge-base/atlas-aaeos-department-maturity-matrix.md#delivery'],
            self::FIELD_BLOCKER_ID => self::FIELD_DELIVERY_ZERO_DOWNTIME_L3,
            self::FIELD_BLOCKER_SUMMARY => self::FIELD_FALTA_ZERO_DOWNTIME_GATE_L3,
            self::FIELD_BLOCKER_SEVERITY => self::FIELD_HIGH,
        ],
        [
            self::FIELD_DEPARTMENT_ID => self::FIELD_MEMORY,
            self::FIELD_CURRENT_LEVEL => self::FIELD_L3,
            self::FIELD_EVIDENCE => ['docs/engineering-knowledge-base/atlas-aaeos-department-maturity-matrix.md#memory'],
            self::FIELD_BLOCKER_ID => self::FIELD_MEMORY_CROSS_SESSION_HANDOFF_L4,
            self::FIELD_BLOCKER_SUMMARY => self::FIELD_FALTA_CROSS_SESSION_HANDOFF_PACK_L4,
            self::FIELD_BLOCKER_SEVERITY => self::FIELD_MEDIUM,
        ],
    ];

    public function maturity(): array
    {
        return [
            self::FIELD_SCHEMA_VERSION => self::SCHEMA_VERSION,
            self::FIELD_DEPARTMENTS => $this->buildDepartments(),
        ];
    }

    private function buildDepartments(): array
    {
        return array_map(
            fn (array $department): array => [
                self::FIELD_DEPARTMENT => AiValueNormalizer::trimmedStringOrNull($department[self::FIELD_DEPARTMENT_ID]) ?? '',
                self::FIELD_MATURITY_TIER => $this->parseLevel($department[self::FIELD_CURRENT_LEVEL]),
                self::FIELD_SIGNALS => $this->buildSignals($department),
                self::FIELD_SCHEMA => self::SCHEMA_VERSION,
                self::FIELD_EVIDENCE => $department[self::FIELD_EVIDENCE],
                self::FIELD_BLOCKERS_TO_NEXT => [
                    [
                        self::FIELD_ID => AiValueNormalizer::trimmedStringOrNull($department[self::FIELD_BLOCKER_ID]) ?? '',
                        self::FIELD_SEVERITY => AiValueNormalizer::lowerTrimmedString($department[self::FIELD_BLOCKER_SEVERITY]),
                        self::FIELD_OWNER => self::OWNER,
                        self::FIELD_SUMMARY => AiValueNormalizer::trimmedStringOrNull($department[self::FIELD_BLOCKER_SUMMARY]) ?? '',
                    ],
                ],
                self::FIELD_LAST_EVALUATION => self::LAST_EVALUATION,
                self::FIELD_NEXT_EVALUATION_DUE => self::NEXT_EVALUATION_DUE,
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
            self::FIELD_PRIMARY_BLOCKER => AiValueNormalizer::trimmedStringOrNull($department[self::FIELD_BLOCKER_ID] ?? null) ?? '',
            self::FIELD_BLOCKER_SUMMARY => AiValueNormalizer::trimmedStringOrNull($department[self::FIELD_BLOCKER_SUMMARY] ?? null) ?? '',
            self::FIELD_BLOCKER_SEVERITY => AiValueNormalizer::lowerTrimmedString($department[self::FIELD_BLOCKER_SEVERITY] ?? ''),
        ];
    }
}