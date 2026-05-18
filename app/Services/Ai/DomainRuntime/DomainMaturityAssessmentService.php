<?php

namespace App\Services\Ai\DomainRuntime;

use App\Models\AiDomainManifest;
use App\Models\AiDomainMaturityAssessment;
use App\Services\Ai\Mission\MissionCanonicalHash;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class DomainMaturityAssessmentService
{
    public const STATUS_PASSED = 'passed';

    public const STATUS_FAILED = 'failed';

    public const STATUS_BLOCKED = 'blocked';

    /**
     * @param  array<string,mixed>  $args
     */
    public function assess(AiDomainManifest $manifest, int $targetStage, array $args = []): AiDomainMaturityAssessment
    {
        if ($targetStage < 1 || $targetStage > 5) {
            throw DomainRuntimeException::invalidMaturityStage($targetStage);
        }

        $evidenceRefs = array_values((array) ($args['evidence_refs'] ?? []));
        $metrics = (array) ($args['metrics'] ?? []);
        $certificationHash = (string) ($args['certification_hash'] ?? '');

        $checks = $this->runChecks($manifest, $targetStage, $evidenceRefs, $metrics, $certificationHash);

        $missing = array_values(array_filter(
            $checks,
            static fn (array $check): bool => $check['status'] !== 'passed',
        ));

        $status = $missing === [] ? self::STATUS_PASSED : self::STATUS_BLOCKED;

        $assessmentHash = MissionCanonicalHash::sha256([
            'manifest_id' => $manifest->id,
            'domain_id' => $manifest->domain_id,
            'target_stage' => $targetStage,
            'checks' => $checks,
            'missing' => $missing,
            'evidence_refs' => $evidenceRefs,
            'metrics' => $metrics,
        ]);

        $assessment = AiDomainMaturityAssessment::query()->create([
            'uuid' => (string) Str::uuid(),
            'domain_manifest_id' => $manifest->id,
            'maturity_stage' => $targetStage,
            'checked_requirements' => $checks,
            'missing_requirements' => $missing,
            'evidence_refs' => $evidenceRefs,
            'status' => $status,
            'assessed_at' => $status === self::STATUS_PASSED ? Carbon::now() : null,
            'assessment_hash' => $assessmentHash,
        ]);

        if ($status === self::STATUS_PASSED) {
            $manifest->maturity_stage = $targetStage;
            $manifest->save();
        }

        return $assessment;
    }

    /**
     * @param  array<int,string>  $evidenceRefs
     * @param  array<string,mixed>  $metrics
     * @return array<int,array<string,mixed>>
     */
    public function runChecks(AiDomainManifest $manifest, int $targetStage, array $evidenceRefs, array $metrics, string $certificationHash): array
    {
        $requiresEvidence = $targetStage >= DomainSeedManifests::STAGE_OPERATING_UNIT;

        return [
            [
                'requirement' => 'manifest_active',
                'status' => in_array($manifest->status, ['active', 'scaffold'], true) ? 'passed' : 'failed',
                'detail' => 'manifest.status='.$manifest->status,
            ],
            [
                'requirement' => 'manifest_has_charter',
                'status' => is_array($manifest->charter) && $manifest->charter !== [] ? 'passed' : 'failed',
                'detail' => 'charter present',
            ],
            [
                'requirement' => 'manifest_has_quality_gates',
                'status' => is_array($manifest->quality_gates) && $manifest->quality_gates !== [] ? 'passed' : 'failed',
                'detail' => 'quality_gates count='.count((array) $manifest->quality_gates),
            ],
            [
                'requirement' => 'manifest_has_evidence_schema',
                'status' => is_array($manifest->evidence_schema) && $manifest->evidence_schema !== [] ? 'passed' : 'failed',
                'detail' => 'evidence_schema count='.count((array) $manifest->evidence_schema),
            ],
            [
                'requirement' => 'evidence_refs_present_for_high_stage',
                'status' => $requiresEvidence ? ($evidenceRefs !== [] ? 'passed' : 'failed') : 'passed',
                'detail' => $requiresEvidence
                    ? 'evidence_refs_count='.count($evidenceRefs)
                    : 'not required for stage '.$targetStage,
            ],
            [
                'requirement' => 'metrics_recorded_for_high_stage',
                'status' => $requiresEvidence ? ($metrics !== [] ? 'passed' : 'failed') : 'passed',
                'detail' => $requiresEvidence
                    ? 'metrics count='.count($metrics)
                    : 'not required for stage '.$targetStage,
            ],
            [
                'requirement' => 'certification_hash_for_high_stage',
                'status' => $requiresEvidence ? ($certificationHash !== '' ? 'passed' : 'failed') : 'passed',
                'detail' => $requiresEvidence
                    ? ($certificationHash !== '' ? 'present' : 'missing certification_hash')
                    : 'not required for stage '.$targetStage,
            ],
        ];
    }
}
