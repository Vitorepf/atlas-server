<?php

namespace App\Services\Ai\DomainRuntime;

class DomainSeedManifests
{
    public const STAGE_ASSISTANT = 1;

    public const STAGE_SPECIALIST = 2;

    public const STAGE_DEPARTMENT = 3;

    public const STAGE_OPERATING_UNIT = 4;

    public const STAGE_AUTONOMOUS_ENTERPRISE_UNIT = 5;

    /**
     * @return array<int,array<string,mixed>>
     */
    public static function all(): array
    {
        return [
            self::software(),
            self::research(),
            self::strategy(),
            self::finance(),
            self::marketing(),
            self::cyber(),
            self::personalDevelopment(),
            self::automation(),
            self::operations(),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private static function software(): array
    {
        return [
            'domain_id' => 'software',
            'name' => 'Software Company Runtime',
            'status' => 'active',
            'maturity_stage' => self::STAGE_DEPARTMENT,
            'owner' => 'atlas-software',
            'charter' => [
                'mission' => 'Engenharia de software ponta a ponta com placement, spec, plan, execucao, evidencia e certificacao.',
                'audience' => 'Atlas Dev, Atlas Forge, time interno de programacao.',
                'outcomes' => ['code delivered', 'tests green', 'evidence pack signed', 'certification recorded'],
                'frontier' => 'Implementacao de software sob Programming Governance e Spec Operating System.',
                'forbidden' => ['merge sem evidence', 'deploy sem approval', 'edit fora do workspace'],
            ],
            'ontology' => ['mission', 'objective', 'work_order', 'spec', 'task', 'patch', 'test_run', 'review'],
            'departments' => ['dev', 'debug', 'review', 'qa', 'security', 'forge', 'delivery'],
            'flow_profiles' => ['atlas_dev_quick_fix', 'atlas_forge_obra', 'atlas_review_pipeline'],
            'tools_allowed' => ['shell.run', 'git.write', 'editor.patch', 'test.run', 'review.tool'],
            'evidence_schema' => ['test_run', 'patch_diff', 'review_finding', 'certification'],
            'quality_gates' => ['placement_verified', 'spec_present', 'tests_green', 'review_signed', 'evidence_attached'],
            'handoff_rules' => ['allowed' => ['research', 'cyber', 'operations'], 'forbidden' => []],
            'delivery_types' => ['patch_set', 'spec_pack', 'review_report'],
            'metrics' => ['delivery_lead_time', 'review_pass_rate', 'evidence_completeness'],
            'forbidden_actions' => ['silent merge', 'unreviewed deploy'],
            'policy_profile' => ['autonomy' => 'execute_with_approval', 'risk' => 'medium'],
            'memory_scope' => ['retain_days' => 365, 'kinds' => ['decisions', 'patches', 'reviews']],
            'capabilities' => [
                [
                    'capability_id' => 'software.plan_change',
                    'name' => 'Plan software change',
                    'description' => 'Plan a change with placement and spec before code.',
                    'input_schema' => ['type' => 'object', 'required' => ['mission_id']],
                    'output_schema' => ['type' => 'object', 'required' => ['plan']],
                    'allowed_tools' => ['shell.run', 'editor.patch'],
                    'risk_level' => 'medium',
                    'required_gates' => ['placement_verified', 'spec_present'],
                    'evidence_required' => ['spec_pack'],
                    'maturity_level' => self::STAGE_SPECIALIST,
                ],
                [
                    'capability_id' => 'software.execute_patch',
                    'name' => 'Execute patch in workspace',
                    'description' => 'Apply a vetted patch, run tests, attach evidence.',
                    'input_schema' => ['type' => 'object', 'required' => ['patch']],
                    'output_schema' => ['type' => 'object', 'required' => ['test_run', 'patch_diff']],
                    'allowed_tools' => ['git.write', 'editor.patch', 'test.run'],
                    'risk_level' => 'high',
                    'required_gates' => ['tests_green', 'review_signed'],
                    'evidence_required' => ['test_run', 'patch_diff'],
                    'maturity_level' => self::STAGE_DEPARTMENT,
                ],
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private static function research(): array
    {
        return [
            'domain_id' => 'research',
            'name' => 'Research Company Runtime',
            'status' => 'active',
            'maturity_stage' => self::STAGE_SPECIALIST,
            'owner' => 'atlas-research',
            'charter' => [
                'mission' => 'Pesquisa profunda com fontes primarias, contradiction check e sintese auditavel.',
                'audience' => 'Atlas AI, software, strategy, finance, cyber',
                'outcomes' => ['research_brief', 'evidence_pack', 'opportunity_radar'],
                'forbidden' => ['claim sem source_ref', 'fonte unica para risco alto'],
            ],
            'ontology' => ['research_question', 'source_ref', 'claim', 'evidence_pack'],
            'departments' => ['discovery', 'verification', 'synthesis'],
            'flow_profiles' => ['research_brief', 'opportunity_scan', 'contradiction_check'],
            'tools_allowed' => ['web.search', 'web.fetch', 'pdf.parse', 'doc.summarize'],
            'evidence_schema' => ['source_ref', 'doc', 'screenshot'],
            'quality_gates' => ['sources_diverse', 'contradictions_resolved_or_declared', 'claims_attributed'],
            'handoff_rules' => ['allowed' => ['software', 'strategy', 'finance', 'marketing'], 'forbidden' => []],
            'delivery_types' => ['research_brief', 'opportunity_radar'],
            'metrics' => ['source_diversity', 'claim_attribution_rate', 'time_to_brief'],
            'forbidden_actions' => ['inference sem citation'],
            'policy_profile' => ['autonomy' => 'execute_with_approval', 'risk' => 'low'],
            'memory_scope' => ['retain_days' => 365, 'kinds' => ['sources', 'briefs']],
            'capabilities' => [
                [
                    'capability_id' => 'research.brief',
                    'name' => 'Produce research brief',
                    'description' => 'Produce a research brief from primary sources with attribution.',
                    'input_schema' => ['type' => 'object', 'required' => ['question']],
                    'output_schema' => ['type' => 'object', 'required' => ['brief', 'sources']],
                    'allowed_tools' => ['web.search', 'web.fetch'],
                    'risk_level' => 'low',
                    'required_gates' => ['sources_diverse', 'claims_attributed'],
                    'evidence_required' => ['source_ref'],
                    'maturity_level' => self::STAGE_SPECIALIST,
                ],
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private static function strategy(): array
    {
        return [
            'domain_id' => 'strategy',
            'name' => 'Corporate Strategy / Venture Studio',
            'status' => 'active',
            'maturity_stage' => self::STAGE_SPECIALIST,
            'owner' => 'atlas-strategy',
            'charter' => [
                'mission' => 'Identificar oportunidades, modelar empresa, dimensionar mercado, planejar GTM e experimentos.',
                'outcomes' => ['opportunity_pack', 'company_model', 'gtm_plan'],
                'forbidden' => ['promessa sem assumption', 'commit financeiro real sem mandato'],
            ],
            'ontology' => ['opportunity', 'company_model', 'gtm_plan', 'experiment'],
            'departments' => ['radar', 'modelagem', 'gtm', 'experimentos'],
            'flow_profiles' => ['opportunity_scan', 'company_model', 'gtm_plan'],
            'tools_allowed' => ['web.search', 'spreadsheet.eval'],
            'evidence_schema' => ['source_ref', 'doc', 'data_artifact'],
            'quality_gates' => ['assumptions_listed', 'risks_listed', 'tam_sam_som_present'],
            'handoff_rules' => ['allowed' => ['research', 'finance', 'marketing'], 'forbidden' => []],
            'delivery_types' => ['opportunity_pack', 'company_model'],
            'metrics' => ['assumption_coverage', 'opportunity_throughput'],
            'forbidden_actions' => ['real spend without operator approval'],
            'policy_profile' => ['autonomy' => 'suggest', 'risk' => 'medium'],
            'memory_scope' => ['retain_days' => 365, 'kinds' => ['opportunities', 'models']],
            'capabilities' => [
                [
                    'capability_id' => 'strategy.opportunity_scan',
                    'name' => 'Opportunity scan',
                    'description' => 'Scan and rank opportunities with assumptions and TAM/SAM/SOM.',
                    'input_schema' => ['type' => 'object', 'required' => ['theme']],
                    'output_schema' => ['type' => 'object', 'required' => ['opportunities']],
                    'allowed_tools' => ['web.search'],
                    'risk_level' => 'low',
                    'required_gates' => ['assumptions_listed'],
                    'evidence_required' => ['source_ref'],
                    'maturity_level' => self::STAGE_SPECIALIST,
                ],
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private static function finance(): array
    {
        return [
            'domain_id' => 'finance',
            'name' => 'Finance / Investment Research',
            'status' => 'active',
            'maturity_stage' => self::STAGE_SPECIALIST,
            'owner' => 'atlas-finance',
            'charter' => [
                'mission' => 'Research desk, valuation, portfolio analysis, risk/compliance reporting; no real trade without explicit mandate.',
                'outcomes' => ['research_note', 'valuation_model', 'portfolio_view'],
                'forbidden' => ['real trade execution without mandate', 'leverage without limits'],
            ],
            'ontology' => ['asset', 'portfolio', 'valuation_model', 'risk_metric'],
            'departments' => ['research_desk', 'valuation', 'portfolio', 'compliance'],
            'flow_profiles' => ['research_note', 'valuation', 'portfolio_review'],
            'tools_allowed' => ['web.search', 'spreadsheet.eval', 'api.read'],
            'evidence_schema' => ['source_ref', 'data_artifact'],
            'quality_gates' => ['assumptions_listed', 'risk_disclosed', 'sources_attributed'],
            'handoff_rules' => ['allowed' => ['research', 'strategy'], 'forbidden' => []],
            'delivery_types' => ['research_note', 'valuation_model', 'portfolio_view'],
            'metrics' => ['source_diversity', 'risk_disclosure_rate'],
            'forbidden_actions' => ['execute trade without mandate', 'omit risk disclosure'],
            'policy_profile' => ['autonomy' => 'suggest', 'risk' => 'high'],
            'memory_scope' => ['retain_days' => 1825, 'kinds' => ['notes', 'models']],
            'capabilities' => [
                [
                    'capability_id' => 'finance.research_note',
                    'name' => 'Investment research note',
                    'description' => 'Produce a research note with valuation rationale and risk disclosure.',
                    'input_schema' => ['type' => 'object', 'required' => ['asset']],
                    'output_schema' => ['type' => 'object', 'required' => ['note', 'sources']],
                    'allowed_tools' => ['web.search', 'spreadsheet.eval'],
                    'risk_level' => 'medium',
                    'required_gates' => ['risk_disclosed', 'sources_attributed'],
                    'evidence_required' => ['source_ref', 'data_artifact'],
                    'maturity_level' => self::STAGE_SPECIALIST,
                ],
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private static function marketing(): array
    {
        return [
            'domain_id' => 'marketing',
            'name' => 'Marketing / Growth',
            'status' => 'active',
            'maturity_stage' => self::STAGE_SPECIALIST,
            'owner' => 'atlas-marketing',
            'charter' => [
                'mission' => 'Positioning, ICP, campaign planning, copy, funnel experiments; no public publishing or spend without approval.',
                'outcomes' => ['positioning_doc', 'campaign_plan', 'copy_pack'],
                'forbidden' => ['publish without approval', 'paid spend without approval'],
            ],
            'ontology' => ['positioning', 'icp', 'campaign', 'copy', 'funnel'],
            'departments' => ['positioning', 'campaign', 'copy', 'analytics'],
            'flow_profiles' => ['positioning_draft', 'campaign_plan', 'copy_iteration'],
            'tools_allowed' => ['web.search', 'doc.write'],
            'evidence_schema' => ['source_ref', 'doc', 'artifact'],
            'quality_gates' => ['icp_present', 'message_clear', 'no_unsubstantiated_claim'],
            'handoff_rules' => ['allowed' => ['strategy', 'research'], 'forbidden' => []],
            'delivery_types' => ['positioning_doc', 'campaign_plan'],
            'metrics' => ['message_clarity', 'asset_throughput'],
            'forbidden_actions' => ['publish without approval'],
            'policy_profile' => ['autonomy' => 'suggest', 'risk' => 'medium'],
            'memory_scope' => ['retain_days' => 365, 'kinds' => ['positioning', 'campaigns']],
            'capabilities' => [
                [
                    'capability_id' => 'marketing.positioning_draft',
                    'name' => 'Draft positioning',
                    'description' => 'Draft positioning with ICP, message and proof points.',
                    'input_schema' => ['type' => 'object', 'required' => ['product']],
                    'output_schema' => ['type' => 'object', 'required' => ['positioning']],
                    'allowed_tools' => ['doc.write'],
                    'risk_level' => 'low',
                    'required_gates' => ['icp_present'],
                    'evidence_required' => ['doc'],
                    'maturity_level' => self::STAGE_SPECIALIST,
                ],
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private static function cyber(): array
    {
        return [
            'domain_id' => 'cyber',
            'name' => 'Cyber Security',
            'status' => 'active',
            'maturity_stage' => self::STAGE_SPECIALIST,
            'owner' => 'atlas-cyber',
            'charter' => [
                'mission' => 'AppSec, GRC, defensive ops, remediation, authorized pentest/bug bounty; no offensive ops without RoE.',
                'outcomes' => ['finding', 'patch_recommendation', 'detection_rule', 'purple_report'],
                'forbidden' => ['offensive operation without Rules of Engagement', 'mass targeting'],
            ],
            'ontology' => ['finding', 'patch', 'detection', 'engagement', 'roe'],
            'departments' => ['appsec', 'grc', 'defensive', 'purple_team'],
            'flow_profiles' => ['finding_pipeline', 'patch_recommendation', 'detection_authoring'],
            'tools_allowed' => ['shell.run.readonly', 'web.fetch', 'sbom.read'],
            'evidence_schema' => ['finding', 'patch_diff', 'detection_rule', 'source_ref'],
            'quality_gates' => ['scope_authorized', 'evidence_attached', 'cvss_present'],
            'handoff_rules' => ['allowed' => ['software', 'operations'], 'forbidden' => []],
            'delivery_types' => ['finding_pack', 'patch_recommendation', 'purple_report'],
            'metrics' => ['mttr', 'finding_quality', 'detection_coverage'],
            'forbidden_actions' => ['unauthorized offensive operation', 'detection evasion for malicious use'],
            'policy_profile' => ['autonomy' => 'execute_with_approval', 'risk' => 'high'],
            'memory_scope' => ['retain_days' => 1825, 'kinds' => ['findings', 'patches', 'detections']],
            'capabilities' => [
                [
                    'capability_id' => 'cyber.finding_review',
                    'name' => 'Finding review',
                    'description' => 'Review a finding for accuracy, scope, severity, exploitability and remediation.',
                    'input_schema' => ['type' => 'object', 'required' => ['finding']],
                    'output_schema' => ['type' => 'object', 'required' => ['review']],
                    'allowed_tools' => ['web.fetch'],
                    'risk_level' => 'medium',
                    'required_gates' => ['scope_authorized', 'evidence_attached'],
                    'evidence_required' => ['finding', 'source_ref'],
                    'maturity_level' => self::STAGE_SPECIALIST,
                ],
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private static function personalDevelopment(): array
    {
        return [
            'domain_id' => 'personal_development',
            'name' => 'Personal Development / Learning',
            'status' => 'active',
            'maturity_stage' => self::STAGE_ASSISTANT,
            'owner' => 'atlas-personal',
            'charter' => [
                'mission' => 'Goals, habits, study planning, deliberate practice for the operator. Not clinical.',
                'outcomes' => ['plan', 'study_brief', 'habit_review'],
                'forbidden' => ['clinical advice', 'unsourced medical claims'],
            ],
            'ontology' => ['goal', 'habit', 'study_plan', 'practice_session'],
            'departments' => ['coach', 'curriculum'],
            'flow_profiles' => ['goal_plan', 'study_brief', 'habit_review'],
            'tools_allowed' => ['doc.write'],
            'evidence_schema' => ['doc', 'source_ref'],
            'quality_gates' => ['goal_clear', 'cadence_present'],
            'handoff_rules' => ['allowed' => ['research'], 'forbidden' => []],
            'delivery_types' => ['plan', 'study_brief'],
            'metrics' => ['plan_adherence', 'practice_sessions'],
            'forbidden_actions' => ['clinical diagnosis'],
            'policy_profile' => ['autonomy' => 'suggest', 'risk' => 'low'],
            'memory_scope' => ['retain_days' => 730, 'kinds' => ['goals', 'habits']],
            'capabilities' => [
                [
                    'capability_id' => 'personal.goal_plan',
                    'name' => 'Goal plan',
                    'description' => 'Draft a goal plan with cadence and review checkpoints.',
                    'input_schema' => ['type' => 'object', 'required' => ['goal']],
                    'output_schema' => ['type' => 'object', 'required' => ['plan']],
                    'allowed_tools' => ['doc.write'],
                    'risk_level' => 'low',
                    'required_gates' => ['goal_clear', 'cadence_present'],
                    'evidence_required' => ['doc'],
                    'maturity_level' => self::STAGE_ASSISTANT,
                ],
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private static function automation(): array
    {
        return [
            'domain_id' => 'automation',
            'name' => 'Automation / Tool Factory',
            'status' => 'active',
            'maturity_stage' => self::STAGE_SPECIALIST,
            'owner' => 'atlas-automation',
            'charter' => [
                'mission' => 'Browser/terminal/GitHub/API automation, repo evaluation and tool builder/evolution.',
                'outcomes' => ['automation_pack', 'tool_blueprint', 'repo_evaluation'],
                'forbidden' => ['credential exfiltration', 'destructive ops without approval'],
            ],
            'ontology' => ['tool', 'automation_recipe', 'evaluation_report'],
            'departments' => ['browser', 'terminal', 'tool_builder', 'evaluator'],
            'flow_profiles' => ['automation_build', 'tool_evolution', 'repo_evaluation'],
            'tools_allowed' => ['browser.automate', 'shell.run', 'git.read', 'api.read'],
            'evidence_schema' => ['command', 'artifact', 'receipt', 'source_ref'],
            'quality_gates' => ['scope_authorized', 'evidence_attached', 'rollback_present'],
            'handoff_rules' => ['allowed' => ['software', 'operations', 'cyber'], 'forbidden' => []],
            'delivery_types' => ['automation_pack', 'tool_blueprint'],
            'metrics' => ['automation_success_rate', 'tool_lead_time'],
            'forbidden_actions' => ['credential exfiltration', 'destructive op without approval'],
            'policy_profile' => ['autonomy' => 'execute_with_approval', 'risk' => 'high'],
            'memory_scope' => ['retain_days' => 365, 'kinds' => ['tools', 'recipes']],
            'capabilities' => [
                [
                    'capability_id' => 'automation.tool_build',
                    'name' => 'Build automation tool',
                    'description' => 'Build a vetted automation tool with rollback plan and evidence.',
                    'input_schema' => ['type' => 'object', 'required' => ['blueprint']],
                    'output_schema' => ['type' => 'object', 'required' => ['tool']],
                    'allowed_tools' => ['shell.run', 'git.read'],
                    'risk_level' => 'high',
                    'required_gates' => ['scope_authorized', 'rollback_present'],
                    'evidence_required' => ['command', 'artifact'],
                    'maturity_level' => self::STAGE_SPECIALIST,
                ],
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private static function operations(): array
    {
        return [
            'domain_id' => 'operations',
            'name' => 'Operations',
            'status' => 'active',
            'maturity_stage' => self::STAGE_ASSISTANT,
            'owner' => 'atlas-operations',
            'charter' => [
                'mission' => 'Diagnostico, runbook, incident handling, readiness review; no deploy/restart/infra mutation without approval.',
                'outcomes' => ['runbook', 'incident_report', 'readiness_report'],
                'forbidden' => ['unauthorized deploy', 'unauthorized restart', 'infra mutation without approval'],
            ],
            'ontology' => ['runbook', 'incident', 'readiness_check', 'sla'],
            'departments' => ['oncall', 'sre', 'runbook_authoring'],
            'flow_profiles' => ['incident_handle', 'runbook_author', 'readiness_review'],
            'tools_allowed' => ['shell.run.readonly', 'log.read', 'metrics.read'],
            'evidence_schema' => ['command', 'log', 'doc'],
            'quality_gates' => ['scope_authorized', 'evidence_attached'],
            'handoff_rules' => ['allowed' => ['software', 'cyber'], 'forbidden' => []],
            'delivery_types' => ['runbook', 'incident_report'],
            'metrics' => ['mttr', 'incident_volume', 'runbook_coverage'],
            'forbidden_actions' => ['unauthorized deploy', 'unauthorized restart'],
            'policy_profile' => ['autonomy' => 'execute_with_approval', 'risk' => 'high'],
            'memory_scope' => ['retain_days' => 1825, 'kinds' => ['incidents', 'runbooks']],
            'capabilities' => [
                [
                    'capability_id' => 'operations.incident_handle',
                    'name' => 'Incident handle',
                    'description' => 'Handle an incident with read-only diagnostics and approved actions.',
                    'input_schema' => ['type' => 'object', 'required' => ['alert']],
                    'output_schema' => ['type' => 'object', 'required' => ['incident_report']],
                    'allowed_tools' => ['shell.run.readonly', 'log.read'],
                    'risk_level' => 'high',
                    'required_gates' => ['scope_authorized', 'evidence_attached'],
                    'evidence_required' => ['command', 'log'],
                    'maturity_level' => self::STAGE_ASSISTANT,
                ],
            ],
        ];
    }
}
