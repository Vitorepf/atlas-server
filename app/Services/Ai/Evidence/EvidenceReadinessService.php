<?php

namespace App\Services\Ai\Evidence;

use App\Models\AiArtifact;
use App\Models\AiAuditEvent;
use App\Models\AiBlocker;
use App\Models\AiCertification;
use App\Models\AiClaim;
use App\Models\AiEvidencePack;
use App\Models\AiGateRun;
use App\Models\AiOperatorDecision;
use App\Models\AiReceipt;
use App\Models\AiSourceRef;
use App\Models\AiTestResult;
use App\Services\Ai\Support\DatabaseTableAvailability;
use Illuminate\Contracts\Container\Container;

class EvidenceReadinessService
{
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

    private const REQUIRED_MODELS = [
        AiEvidencePack::class,
        AiReceipt::class,
        AiClaim::class,
        AiArtifact::class,
        AiSourceRef::class,
        AiGateRun::class,
        AiTestResult::class,
        AiOperatorDecision::class,
        AiCertification::class,
        AiBlocker::class,
        AiAuditEvent::class,
    ];

    private const REQUIRED_SERVICES = [
        EvidencePackService::class,
        ReceiptService::class,
        ClaimVerificationService::class,
        ArtifactRegistryService::class,
        SourceRefService::class,
        GateRunService::class,
        TestResultService::class,
        OperatorDecisionService::class,
        CertificationRuntimeService::class,
        BlockerService::class,
        AuditEventService::class,
        EvidenceControlPlaneService::class,
        MissionEvidenceAdapter::class,
    ];

    private const REQUIRED_TEST_FILES = [
        'tests/Feature/Ai/Evidence/EvidenceRuntimeReadinessTest.php',
        'tests/Feature/Ai/Evidence/EvidenceRuntimeSmokeTest.php',
        'tests/Feature/Ai/Evidence/EvidenceRuntimeCertificationTest.php',
        'tests/Feature/Ai/Evidence/EvidenceRuntimeClaimVerificationTest.php',
        'tests/Feature/Ai/Evidence/EvidenceRuntimeBlockerTest.php',
        'tests/Feature/Ai/Evidence/EvidenceRuntimeReceiptTest.php',
        'tests/Feature/Ai/Evidence/EvidenceRuntimeGateRunTest.php',
        'tests/Feature/Ai/Evidence/EvidenceRuntimeAuditEventTest.php',
        'tests/Feature/Ai/Evidence/EvidenceRuntimeControlPlaneTest.php',
        'tests/Feature/Ai/Evidence/EvidenceRuntimeMissionAdapterTest.php',
    ];

    public function __construct(private readonly Container $container) {}

    /**
     * @return array<string,mixed>
     */
    public function report(): array
    {
        $checks = [];

        foreach (self::REQUIRED_TABLES as $table) {
            $exists = DatabaseTableAvailability::has($table);
            $checks[] = [
                'name' => "table:{$table}",
                'status' => $exists ? 'passed' : 'failed',
                'detail' => $exists ? 'exists' : 'missing - run php artisan migrate',
            ];
        }

        foreach (self::REQUIRED_MODELS as $model) {
            $exists = class_exists($model);
            $checks[] = [
                'name' => 'model:'.class_basename($model),
                'status' => $exists ? 'passed' : 'failed',
                'detail' => $exists ? 'class exists' : "missing class [{$model}]",
            ];
        }

        foreach (self::REQUIRED_SERVICES as $service) {
            $resolvable = false;
            $detail = 'not resolvable';
            try {
                $resolved = $this->container->make($service);
                $resolvable = $resolved instanceof $service;
                $detail = $resolvable ? 'resolved' : 'not an instance';
            } catch (\Throwable $e) {
                $detail = 'exception: '.$e->getMessage();
            }
            $checks[] = [
                'name' => 'service:'.class_basename($service),
                'status' => $resolvable ? 'passed' : 'failed',
                'detail' => $detail,
            ];
        }

        $checks[] = $this->checkAntiFalseCompletionGuard();

        foreach (self::REQUIRED_TEST_FILES as $relativePath) {
            $exists = file_exists(base_path($relativePath));
            $checks[] = [
                'name' => 'test:'.basename($relativePath),
                'status' => $exists ? 'passed' : 'failed',
                'detail' => $exists ? 'present' : "missing [{$relativePath}]",
            ];
        }

        $passed = collect($checks)->where('status', 'passed')->count();
        $failed = collect($checks)->where('status', 'failed')->count();

        return [
            'ok' => $failed === 0,
            'schema' => 'atlas.ai.evidence.readiness.v1',
            'summary' => [
                'total' => count($checks),
                'passed' => $passed,
                'failed' => $failed,
            ],
            'checks' => $checks,
            'generated_at' => now()->toJSON(),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function checkAntiFalseCompletionGuard(): array
    {
        $reflection = new \ReflectionClass(CertificationRuntimeService::class);
        $source = (string) file_get_contents((string) $reflection->getFileName());
        $hasGuard = str_contains($source, 'evidence_refs_present')
            && str_contains($source, 'evidence_pack_not_empty')
            && str_contains($source, 'resolveOpenBlockers');

        return [
            'name' => 'guard:anti_false_completion',
            'status' => $hasGuard ? 'passed' : 'failed',
            'detail' => $hasGuard
                ? 'evidence/pack/blocker checks present in CertificationRuntimeService'
                : 'CertificationRuntimeService missing required guards',
        ];
    }
}
