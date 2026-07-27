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
use Illuminate\Contracts\Container\Container;

class EvidenceReadinessService
{
    use \App\Services\Ai\Support\BuildsReadinessChecks;

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

        $checks = array_merge($checks, $this->tableChecks(self::REQUIRED_TABLES));

        $checks = array_merge($checks, $this->modelChecks(self::REQUIRED_MODELS));

        $checks = array_merge($checks, $this->serviceChecks($this->container, self::REQUIRED_SERVICES));

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
