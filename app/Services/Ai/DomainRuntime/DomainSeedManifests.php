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
            'maturity_stage' => self::STAGE_OPERATING_UNIT,
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
            ...self::enterpriseOperatingModel('software'),
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
            'maturity_stage' => self::STAGE_OPERATING_UNIT,
            'owner' => 'atlas-research',
            'charter' => [
                'mission' => 'Pesquisa profunda com fontes primarias, contradiction check e sintese auditavel.',
                'audience' => 'Atlas AI, software, strategy, finance, cyber',
                'outcomes' => ['research_brief', 'evidence_pack', 'opportunity_radar'],
                'forbidden' => ['claim sem source_ref', 'fonte unica para risco alto'],
                'promotion_evidence' => [
                    'php artisan atlas:ai:research-domain readiness --json',
                    'php artisan atlas:ai:research-domain smoke --json',
                    'tests/Feature/Ai/ResearchDomain',
                    'certification requires diverse accepted sources and attributed claims',
                ],
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
            ...self::enterpriseOperatingModel('research'),
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
            'maturity_stage' => self::STAGE_OPERATING_UNIT,
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
            ...self::enterpriseOperatingModel('strategy'),
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
            'maturity_stage' => self::STAGE_OPERATING_UNIT,
            'owner' => 'atlas-finance',
            'charter' => [
                'mission' => 'Research desk, valuation, portfolio analysis, risk/compliance reporting; no real trade without explicit mandate.',
                'outcomes' => ['research_note', 'valuation_model', 'portfolio_view'],
                'forbidden' => ['real trade execution without mandate', 'leverage without limits'],
                'promotion_evidence' => [
                    'php artisan atlas:ai:finance-domain readiness --json',
                    'php artisan atlas:ai:finance-domain smoke --json',
                    'tests/Feature/Ai/Finance',
                    'tests/Feature/Ai/FinanceDomain',
                    'live trading and broker execution blocked by default',
                ],
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
            ...self::enterpriseOperatingModel('finance'),
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
            'maturity_stage' => self::STAGE_AUTONOMOUS_ENTERPRISE_UNIT,
            'owner' => 'atlas-marketing',
            'charter' => [
                'mission' => 'Positioning, ICP, campaign planning, copy, funnel experiments; no public publishing or spend without approval.',
                'outcomes' => ['positioning_doc', 'campaign_plan', 'copy_pack'],
                'forbidden' => ['publish without approval', 'paid spend without approval'],
                'promotion_evidence' => [
                    'php artisan atlas:ai:marketing-domain readiness --json',
                    'php artisan atlas:ai:marketing-domain smoke --json',
                    'php artisan atlas:ai:marketing-domain limited-autonomy-policy --json',
                    'tests/Feature/Ai/MarketingDomain',
                    'approval gates block auto-publish and auto-spend',
                    'limited autonomy policy caps external spend at zero without approval',
                ],
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
            'policy_profile' => ['autonomy' => 'limited_internal_autonomy', 'risk' => 'medium'],
            'memory_scope' => ['retain_days' => 365, 'kinds' => ['positioning', 'campaigns']],
            ...self::enterpriseOperatingModel('marketing'),
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
            'maturity_stage' => self::STAGE_OPERATING_UNIT,
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
            ...self::enterpriseOperatingModel('cyber'),
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
            'maturity_stage' => self::STAGE_OPERATING_UNIT,
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
            ...self::enterpriseOperatingModel('personal_development'),
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
            'maturity_stage' => self::STAGE_OPERATING_UNIT,
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
            ...self::enterpriseOperatingModel('automation'),
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
            'maturity_stage' => self::STAGE_OPERATING_UNIT,
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
            ...self::enterpriseOperatingModel('operations'),
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

    /**
     * @return array<string,mixed>
     */
    private static function enterpriseOperatingModel(string $domainId): array
    {
        $models = [
            'software' => [
                'enterprise_functions' => ['intake', 'architecture', 'implementation', 'qa', 'security_review', 'release', 'maintenance'],
                'agent_roles' => ['planner_agent', 'code_discovery_agent', 'patch_agent', 'test_agent', 'review_agent', 'repair_agent'],
                'flow_profiles' => ['placement', 'spec', 'plan', 'patch', 'test', 'review', 'repair'],
                'delivery_types' => ['spec_pack', 'patch_set', 'test_report', 'review_report', 'release_evidence_pack'],
                'integration_contracts' => ['git_workspace', 'shell_test_runner', 'evidence_ledger', 'decision_receipt'],
                'recurring_cadences' => ['per_change_preflight', 'per_patch_test_run', 'daily_runtime_review'],
                'metrics' => ['delivery_lead_time', 'review_pass_rate', 'evidence_completeness', 'repair_success_rate'],
                'operational_history' => ['atlas_engineering_runs', 'ai_traces', 'programming_runtime_control_plane'],
                'runtime_commands' => [
                    'php artisan atlas:ai:engineering-company --json',
                    'php artisan atlas:ai:programming-runtime-control-plane --json',
                    'php artisan atlas:forge:runtime-certify --json',
                ],
            ],
            'research' => [
                'enterprise_functions' => ['source_discovery', 'source_quality', 'claim_attribution', 'contradiction_review', 'synthesis'],
                'agent_roles' => ['source_planner_agent', 'source_quality_agent', 'claim_agent', 'contradiction_agent', 'synthesis_agent'],
                'flow_profiles' => ['source_plan', 'source_quality', 'claim_record', 'contradiction_check', 'synthesis_certification'],
                'delivery_types' => ['source_plan', 'evidence_pack', 'claim_map', 'contradiction_report', 'research_brief'],
                'integration_contracts' => ['web_search_read_adapter', 'source_registry', 'evidence_bridge'],
                'recurring_cadences' => ['daily_source_watch', 'per_brief_claim_audit', 'weekly_contradiction_review'],
                'metrics' => ['source_diversity', 'claim_attribution_rate', 'contradiction_resolution_rate', 'time_to_brief'],
                'operational_history' => ['ai_research_runs', 'ai_research_sources', 'ai_research_claims', 'ai_research_syntheses'],
                'runtime_commands' => [
                    'php artisan atlas:ai:research-domain readiness --json',
                    'php artisan atlas:ai:research-domain smoke --json',
                    'php artisan atlas:ai:research-domain control-plane --json',
                ],
            ],
            'strategy' => [
                'enterprise_functions' => ['opportunity_radar', 'venture_blueprint', 'market_modeling', 'unit_economics', 'experiment_decision'],
                'agent_roles' => ['opportunity_agent', 'venture_architect_agent', 'market_model_agent', 'unit_economics_agent', 'strategy_memo_agent'],
                'flow_profiles' => ['opportunity_scan', 'venture_blueprint', 'market_model', 'unit_economics', 'experiment_decision'],
                'delivery_types' => ['opportunity_pack', 'venture_blueprint', 'market_model', 'unit_economics_memo', 'experiment_decision_record'],
                'integration_contracts' => ['research_handoff', 'finance_handoff', 'marketing_handoff'],
                'recurring_cadences' => ['weekly_opportunity_radar', 'per_experiment_decision', 'monthly_portfolio_review'],
                'metrics' => ['assumption_coverage', 'opportunity_throughput', 'experiment_decision_rate', 'memo_completion_rate'],
                'operational_history' => ['ai_strategy_runs', 'ai_opportunities', 'ai_experiment_plans', 'ai_strategy_memos'],
                'runtime_commands' => [
                    'php artisan atlas:ai:strategy-domain readiness --json',
                    'php artisan atlas:ai:strategy-domain smoke --json',
                    'php artisan atlas:ai:strategy-domain control-plane --json',
                ],
            ],
            'finance' => [
                'enterprise_functions' => ['research_desk', 'valuation', 'portfolio_review', 'risk_review', 'compliance'],
                'agent_roles' => ['research_desk_agent', 'valuation_agent', 'portfolio_agent', 'risk_agent', 'compliance_agent'],
                'flow_profiles' => ['research_note', 'valuation', 'portfolio_review', 'risk_review', 'paper_trading_simulation'],
                'delivery_types' => ['research_note', 'valuation_model', 'portfolio_review', 'risk_report', 'compliance_memo'],
                'integration_contracts' => ['market_data_read_adapter', 'spreadsheet_eval', 'compliance_gate'],
                'recurring_cadences' => ['daily_watchlist_review', 'per_asset_risk_review', 'monthly_portfolio_review'],
                'metrics' => ['source_diversity', 'risk_disclosure_rate', 'compliance_block_rate', 'portfolio_drift'],
                'operational_history' => ['finance_smoke_payloads', 'finance_control_plane', 'ai_receipts'],
                'runtime_commands' => [
                    'php artisan atlas:ai:finance-domain readiness --json',
                    'php artisan atlas:ai:finance-domain smoke --json',
                    'php artisan atlas:ai:finance-domain control-plane --json',
                ],
            ],
            'marketing' => [
                'enterprise_functions' => ['icp_positioning', 'campaign_planning', 'copy_creative', 'funnel_analytics', 'experimentation'],
                'agent_roles' => ['icp_agent', 'positioning_agent', 'campaign_agent', 'copy_agent', 'analytics_agent', 'experiment_agent'],
                'flow_profiles' => ['icp', 'positioning', 'campaign_plan', 'copy_brief', 'funnel_analytics', 'growth_experiment'],
                'delivery_types' => ['icp_brief', 'positioning_doc', 'campaign_plan', 'copy_pack', 'experiment_readout'],
                'integration_contracts' => ['analytics_read_adapter', 'content_calendar_proposal', 'approval_gate'],
                'recurring_cadences' => ['weekly_campaign_review', 'per_asset_approval_gate', 'monthly_funnel_review'],
                'metrics' => ['message_clarity', 'asset_throughput', 'approval_latency', 'experiment_velocity'],
                'operational_history' => ['ai_marketing_runs', 'ai_marketing_artifacts', 'ai_marketing_experiments', 'ai_marketing_approval_gates'],
                'runtime_commands' => [
                    'php artisan atlas:ai:marketing-domain readiness --json',
                    'php artisan atlas:ai:marketing-domain smoke --json',
                    'php artisan atlas:ai:marketing-domain control-plane --json',
                    'php artisan atlas:ai:marketing-domain limited-autonomy-policy --json',
                ],
            ],
            'cyber' => [
                'enterprise_functions' => ['engagement_intake', 'appsec_review', 'grc_mapping', 'defensive_review', 'remediation'],
                'agent_roles' => ['scope_agent', 'appsec_agent', 'grc_agent', 'defensive_review_agent', 'remediation_agent'],
                'flow_profiles' => ['engagement_intake', 'scope_rules', 'appsec_review', 'grc_mapping', 'remediation_plan'],
                'delivery_types' => ['scope_packet', 'appsec_report', 'grc_mapping', 'remediation_plan', 'defensive_review'],
                'integration_contracts' => ['sbom_read_adapter', 'repo_read_adapter', 'evidence_chain'],
                'recurring_cadences' => ['weekly_defensive_review', 'per_finding_triage', 'monthly_grc_mapping_review'],
                'metrics' => ['mttr', 'finding_quality', 'detection_coverage', 'scope_block_rate'],
                'operational_history' => ['ai_cyber_engagements', 'ai_cyber_appsec_reviews', 'ai_cyber_remediation_plans', 'cyber_control_plane'],
                'runtime_commands' => [
                    'php artisan atlas:ai:cyber-domain readiness --json',
                    'php artisan atlas:ai:cyber-domain smoke --json',
                    'php artisan atlas:ai:cyber-domain control-plane --json',
                ],
            ],
            'automation' => [
                'enterprise_functions' => ['automation_planning', 'tool_selection', 'browser_automation', 'api_automation', 'tool_evolution'],
                'agent_roles' => ['automation_planner_agent', 'tool_selector_agent', 'browser_agent', 'api_agent', 'tool_evolution_agent'],
                'flow_profiles' => ['automation_plan', 'tool_selection', 'browser_plan', 'api_plan', 'tool_evolution_loop'],
                'delivery_types' => ['automation_plan', 'tool_selection_record', 'browser_runbook', 'api_runbook', 'tool_evolution_report'],
                'integration_contracts' => ['tool_runtime_registry', 'browser_planning_adapter', 'api_planning_adapter'],
                'recurring_cadences' => ['per_tool_selection_review', 'weekly_tool_evolution_review', 'per_automation_receipt_review'],
                'metrics' => ['automation_success_rate', 'tool_lead_time', 'blocked_plan_rate', 'evolution_event_count'],
                'operational_history' => ['ai_automation_runs', 'ai_automation_plans', 'ai_automation_tool_decisions', 'ai_automation_evolution_events'],
                'runtime_commands' => [
                    'php artisan atlas:ai:automation-domain readiness --json',
                    'php artisan atlas:ai:automation-domain smoke --json',
                    'php artisan atlas:ai:automation-domain control-plane --json',
                ],
            ],
            'personal_development' => [
                'enterprise_functions' => ['goal_architecture', 'habit_design', 'focus_planning', 'learning_plan', 'weekly_review'],
                'agent_roles' => ['goal_agent', 'habit_agent', 'focus_agent', 'learning_agent', 'review_agent'],
                'flow_profiles' => ['reflect', 'daily_review', 'weekly_review', 'habit_design', 'focus_plan', 'learning_plan', 'forge'],
                'delivery_types' => ['goal_architecture', 'habit_plan', 'focus_plan', 'learning_plan', 'weekly_review'],
                'integration_contracts' => ['private_memory_policy', 'evidence_refs', 'human_review_packet'],
                'recurring_cadences' => ['daily_review', 'weekly_review', 'per_goal_checkpoint'],
                'metrics' => ['plan_adherence', 'practice_sessions', 'focus_block_completion', 'review_completion_rate'],
                'operational_history' => ['personal_development_runtime_packets', 'personal_development_control_plane', 'evidence_refs'],
                'runtime_commands' => [
                    'php artisan atlas:ai:personal-development-domain readiness --json',
                    'php artisan atlas:ai:personal-development-domain smoke --json',
                    'php artisan atlas:ai:personal-development-domain control-plane --json',
                ],
            ],
            'operations' => [
                'enterprise_functions' => ['diagnostic', 'runbook_authoring', 'incident_review', 'readiness_review', 'postmortem_actions'],
                'agent_roles' => ['diagnostic_agent', 'runbook_agent', 'incident_agent', 'readiness_agent', 'postmortem_agent'],
                'flow_profiles' => ['diagnostic', 'runbook', 'incident_review', 'readiness_review'],
                'delivery_types' => ['diagnostic_report', 'runbook', 'incident_review', 'readiness_report', 'postmortem_action_plan'],
                'integration_contracts' => ['log_read_adapter', 'metrics_read_adapter', 'evidence_ledger'],
                'recurring_cadences' => ['daily_readiness_check', 'per_incident_review', 'weekly_runbook_review'],
                'metrics' => ['mttr', 'incident_volume', 'runbook_coverage', 'readiness_blocker_count'],
                'operational_history' => ['ai_traces', 'evidence_ledger', 'operations_packets'],
                'runtime_commands' => [
                    'php artisan atlas:ai:operations-domain readiness --json',
                    'php artisan atlas:ai:operations-domain smoke --json',
                    'php artisan atlas:ai:operations-domain control-plane --json',
                ],
            ],
        ];

        return $models[$domainId] ?? [];
    }
}
