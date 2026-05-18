<?php

namespace App\Services\Ai\ControlPlane;

use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Facades\Schema;
use Throwable;

class AtlasControlPlaneEvidenceService
{
    public const COMPONENT = 'evidence';

    private const REQUIRED_TABLES = [
        'ai_evidence_packs',
        'ai_receipts',
        'ai_claims',
        'ai_artifacts',
        'ai_source_refs',
        'ai_gate_runs',
        'ai_test_results',
        'ai_operator_decisions',
        'ai_certifications',
        'ai_blockers',
        'ai_audit_events',
    ];

    public function __construct(private readonly Container $container) {}

    /**
     * @return array<string,mixed>
     */
    public function snapshot(): array
    {
        $availability = $this->tableAvailability();
        if ($availability['status'] === AtlasControlPlaneStatus::MISSING) {
            return [
                'component' => self::COMPONENT,
                'status' => AtlasControlPlaneStatus::MISSING,
                'detail' => 'Evidence Runtime tables not present',
                'tables' => $availability['tables'],
            ];
        }

        $service = '\\App\\Services\\Ai\\Evidence\\EvidenceControlPlaneService';
        if (! class_exists($service)) {
            return [
                'component' => self::COMPONENT,
                'status' => AtlasControlPlaneStatus::DEGRADED,
                'detail' => 'EvidenceControlPlaneService not available',
                'tables' => $availability['tables'],
            ];
        }

        try {
            $resolved = $this->container->make($service);
            $payload = $resolved->snapshot();
            $payload['component'] = self::COMPONENT;
            $payload['status'] = $availability['status'];

            return $payload;
        } catch (Throwable $e) {
            return [
                'component' => self::COMPONENT,
                'status' => AtlasControlPlaneStatus::DEGRADED,
                'detail' => 'evidence snapshot failed: '.$e->getMessage(),
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
            $blockerModel = '\\App\\Models\\AiBlocker';
            $certModel = '\\App\\Models\\AiCertification';
            $claimModel = '\\App\\Models\\AiClaim';
            $packModel = '\\App\\Models\\AiEvidencePack';
            $receiptModel = '\\App\\Models\\AiReceipt';
            $auditModel = '\\App\\Models\\AiAuditEvent';

            $totals = [
                'evidence_packs' => $this->safeCount($packModel),
                'receipts' => $this->safeCount($receiptModel),
                'claims' => $this->safeCount($claimModel),
                'certifications' => $this->safeCount($certModel),
                'blockers' => $this->safeCount($blockerModel),
                'audit_events' => $this->safeCount($auditModel),
            ];

            $openBlockers = $this->safeCount($blockerModel, ['status' => 'open']);
            $criticalOpenBlockers = 0;
            if (class_exists($blockerModel)) {
                try {
                    $criticalOpenBlockers = (int) $blockerModel::query()
                        ->where('status', 'open')
                        ->where('severity', 'critical')
                        ->count();
                } catch (Throwable) {
                    $criticalOpenBlockers = 0;
                }
            }
            $certifiedPassed = $this->safeCount($certModel, ['status' => 'passed']);

            return [
                'component' => self::COMPONENT,
                'status' => $availability['status'],
                'tables' => $availability['tables'],
                'totals' => $totals,
                'open_blockers' => $openBlockers,
                'critical_open_blockers' => $criticalOpenBlockers,
                'certifications_passed' => $certifiedPassed,
            ];
        } catch (Throwable $e) {
            return [
                'component' => self::COMPONENT,
                'status' => AtlasControlPlaneStatus::DEGRADED,
                'detail' => 'evidence summary failed: '.$e->getMessage(),
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
            $exists = Schema::hasTable($table);
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
