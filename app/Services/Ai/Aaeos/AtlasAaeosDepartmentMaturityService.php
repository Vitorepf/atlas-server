<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos;

final class AtlasAaeosDepartmentMaturityService
{
    private const SCHEMA_VERSION = 'atlas.aaeos.department_maturity.v1';

    public function maturity(): array
    {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'departments' => $this->buildDepartments(),
        ];
    }

    private function buildDepartments(): array
    {
        return [
            [
                'department' => 'Engineering',
                'maturity_tier' => 4,
                'signals' => [
                    'automated_testing_coverage',
                    'ci_cd_pipeline_operational',
                    'code_review_process_enforced',
                    'infrastructure_as_code_adopted',
                ],
            ],
            [
                'department' => 'Product',
                'maturity_tier' => 3,
                'signals' => [
                    'roadmap_planning_documented',
                    'user_feedback_loops_active',
                    'a_b_testing_capability_exists',
                ],
            ],
            [
                'department' => 'Data',
                'maturity_tier' => 3,
                'signals' => [
                    'data_quality_framework_established',
                    'analytics_dashboard_deployed',
                    'etl_processes_automated',
                ],
            ],
            [
                'department' => 'Finance',
                'maturity_tier' => 3,
                'signals' => [
                    'budget_automation_implemented',
                    'reporting_dashboard_available',
                    'forecasting_models_in_use',
                ],
            ],
            [
                'department' => 'HR',
                'maturity_tier' => 2,
                'signals' => [
                    'onboarding_workflow_digitized',
                    'performance_review_process_standardized',
                ],
            ],
            [
                'department' => 'Marketing',
                'maturity_tier' => 2,
                'signals' => [
                    'campaign_automation_enabled',
                    'analytics_tracking_configured',
                ],
            ],
            [
                'department' => 'Sales',
                'maturity_tier' => 3,
                'signals' => [
                    'crm_integration_complete',
                    'lead_scoring_implemented',
                    'sales_forecast_accuracy_tracked',
                ],
            ],
            [
                'department' => 'Operations',
                'maturity_tier' => 2,
                'signals' => [
                    'process_documentation_initiated',
                    'vendor_management_tracked',
                ],
            ],
            [
                'department' => 'Legal',
                'maturity_tier' => 2,
                'signals' => [
                    'contract_templates_standardized',
                    'compliance_checklist_operational',
                ],
            ],
            [
                'department' => 'Security',
                'maturity_tier' => 4,
                'signals' => [
                    'security_audit_schedule_regular',
                    'incident_response_plan_documented',
                    'access_control_enforced',
                    'vulnerability_scanning_automated',
                ],
            ],
            [
                'department' => 'CustomerSuccess',
                'maturity_tier' => 2,
                'signals' => [
                    'support_ticket_system_deployed',
                    'nps_survey_captures_feedback',
                ],
            ],
        ];
    }
}