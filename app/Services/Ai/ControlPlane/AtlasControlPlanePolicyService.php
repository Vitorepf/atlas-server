<?php

namespace App\Services\Ai\ControlPlane;

use Illuminate\Contracts\Container\Container;
use App\Services\Ai\Support\DatabaseTableAvailability;
use Throwable;

class AtlasControlPlanePolicyService
{
    public const COMPONENT = 'policy';

    private const REQUIRED_TABLES = [
        'ai_policy_profiles',
        'ai_permission_gates',
        'ai_approval_requests',
        'ai_budget_envelopes',
        'ai_risk_assessments',
        'ai_safety_decisions',
        'ai_forbidden_actions',
    ];

    public function __construct(private readonly Container $container) {}

    /**
     * @return array<string,mixed>
     */
    public function snapshot(int $limitRecent = 20): array
    {
        $availability = $this->tableAvailability();
        if ($availability['status'] === AtlasControlPlaneStatus::MISSING) {
            return [
                'component' => self::COMPONENT,
                'status' => AtlasControlPlaneStatus::MISSING,
                'detail' => 'Policy Runtime tables not present',
                'tables' => $availability['tables'],
            ];
        }

        $service = '\\App\\Services\\Ai\\Policy\\PolicyControlPlaneService';
        if (! class_exists($service)) {
            return [
                'component' => self::COMPONENT,
                'status' => AtlasControlPlaneStatus::DEGRADED,
                'detail' => 'PolicyControlPlaneService not available',
                'tables' => $availability['tables'],
            ];
        }

        try {
            $resolved = $this->container->make($service);
            $payload = $resolved->snapshot($limitRecent);
            $payload['component'] = self::COMPONENT;
            $payload['status'] = $availability['status'];

            return $payload;
        } catch (Throwable $e) {
            return [
                'component' => self::COMPONENT,
                'status' => AtlasControlPlaneStatus::DEGRADED,
                'detail' => 'policy snapshot failed: '.$e->getMessage(),
            ];
        }
    }

    /**
     * @return array<string,mixed>
     */
    public function summary(): array
    {
        $availability = $this->tableAvailability();
        if ($availability['status'] === AtlasControlPlaneStatus::MISSING) {
            return [
                'component' => self::COMPONENT,
                'status' => AtlasControlPlaneStatus::MISSING,
            ];
        }

        try {
            $profileModel = '\\App\\Models\\AiPolicyProfile';
            $approvalModel = '\\App\\Models\\AiApprovalRequest';
            $gateModel = '\\App\\Models\\AiPermissionGate';
            $budgetModel = '\\App\\Models\\AiBudgetEnvelope';
            $riskModel = '\\App\\Models\\AiRiskAssessment';
            $safetyModel = '\\App\\Models\\AiSafetyDecision';
            $forbiddenModel = '\\App\\Models\\AiForbiddenAction';

            $totals = [
                'profiles' => $this->safeCount($profileModel),
                'permission_gates' => $this->safeCount($gateModel),
                'approval_requests' => $this->safeCount($approvalModel),
                'budget_envelopes' => $this->safeCount($budgetModel),
                'risk_assessments' => $this->safeCount($riskModel),
                'safety_decisions' => $this->safeCount($safetyModel),
                'forbidden_actions' => $this->safeCount($forbiddenModel),
            ];

            $openApprovals = $this->safeCount($approvalModel, ['status' => 'pending']);

            return [
                'component' => self::COMPONENT,
                'status' => $availability['status'],
                'tables' => $availability['tables'],
                'totals' => $totals,
                'open_approval_requests' => $openApprovals,
            ];
        } catch (Throwable $e) {
            return [
                'component' => self::COMPONENT,
                'status' => AtlasControlPlaneStatus::DEGRADED,
                'detail' => 'policy summary failed: '.$e->getMessage(),
            ];
        }
    }

    /**
     * @return array<string,mixed>
     */
    public function tableAvailability(): array
    {
        $tables = [];
        $present = 0;
        foreach (self::REQUIRED_TABLES as $table) {
            $exists = DatabaseTableAvailability::has($table);
            $tables[$table] = $exists;
            if ($exists) {
                $present++;
            }
        }

        $status = match (true) {
            $present === 0 => AtlasControlPlaneStatus::MISSING,
            $present === count(self::REQUIRED_TABLES) => AtlasControlPlaneStatus::READY,
            default => AtlasControlPlaneStatus::DEGRADED,
        };

        return [
            'status' => $status,
            'tables' => $tables,
            'tables_present' => $present,
            'tables_required' => count(self::REQUIRED_TABLES),
        ];
    }

    /**
     * @param  array<string,mixed>  $where
     */
    private function safeCount(string $modelClass, array $where = []): int
    {
        if (! class_exists($modelClass)) {
            return 0;
        }

        try {
            $query = $modelClass::query();
            foreach ($where as $column => $value) {
                $query->where($column, $value);
            }

            return (int) $query->count();
        } catch (Throwable) {
            return 0;
        }
    }
}
