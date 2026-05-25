<?php

namespace App\Services\Ai\Programming\Frontend;

use App\Services\Ai\Mission\MissionCanonicalHash;

final class AtlasFrontendRunCertificationService
{
    public const SCHEMA_VERSION = 'atlas.frontend.run_certification.v1';

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function certify(array $input): array
    {
        $visual = $this->visual((string) ($input['visual_report'] ?? ''));
        $review = $this->review((string) ($input['design_review_report'] ?? ''));
        $evidence = $this->evidence((string) ($input['evidence_manifest'] ?? ''), (string) ($input['evidence_root'] ?? ''));
        $publication = $this->publication((string) ($input['bundle'] ?? ''), (string) ($input['publication_receipt'] ?? ''));
        $outcome = $this->outcome((string) ($input['outcome_store'] ?? ''));
        $taskSpecHashes = $this->taskSpecHashes($visual, $review, $evidence);

        $checks = [
            $this->check('visual_quality_passed', in_array($visual['status'] ?? null, ['passed', 'warning'], true), $visual['status'] ?? 'missing'),
            $this->check('design_5d_review_passed', in_array($review['status'] ?? null, ['passed', 'warning'], true), $review['status'] ?? 'missing'),
            $this->check('evidence_pack_passed', ($evidence['status'] ?? null) === 'passed', $evidence['status'] ?? 'missing'),
            $this->check('task_spec_hash_consistent', count(array_unique($taskSpecHashes)) === 1 && count($taskSpecHashes) === 3, $taskSpecHashes === [] ? 'missing' : implode(',', array_values(array_unique($taskSpecHashes)))),
            $this->check('outcome_memory_available', ($outcome['status'] ?? null) === 'ready', $outcome['status'] ?? 'missing'),
            $this->check('publication_local_ready', in_array($publication['status'] ?? null, ['local_ready', 'public_verified'], true), $publication['status'] ?? 'missing', 'warn'),
        ];

        $failedCritical = collect($checks)->contains(fn (array $check): bool => $check['status'] === 'fail' && $check['severity'] === 'critical');
        $failedWarn = collect($checks)->contains(fn (array $check): bool => $check['status'] === 'fail' && $check['severity'] === 'warn');
        $status = $failedCritical ? 'blocked' : ($failedWarn ? 'warning' : 'certified');

        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $status,
            'source' => self::class,
            'task_spec_hash' => count(array_unique($taskSpecHashes)) === 1 ? ($taskSpecHashes[0] ?? null) : null,
            'checks' => $checks,
            'artifacts' => [
                'visual_quality' => $this->summary($visual, 'gate_hash'),
                'design_review' => $this->summary($review, 'review_hash'),
                'evidence_pack' => $this->summary($evidence, 'verification_hash'),
                'publication' => $this->summary($publication, 'publication_hash'),
                'outcome_memory' => $this->summary($outcome, 'outcome_memory_hash'),
            ],
            'claim_policy' => [
                'frontend_completion_claim_allowed' => in_array($status, ['certified', 'warning'], true) && ! $failedCritical && ($outcome['status'] ?? null) === 'ready',
                'frontend_completion_claim_requires_outcome_memory' => true,
                'public_distribution_claim_allowed' => ($publication['status'] ?? null) === 'public_verified',
                'world_best_claim_allowed' => false,
                'requires_real_artifact_hashes' => true,
                'raw_customer_source_returned' => false,
            ],
            'blockers' => collect($checks)->where('status', 'fail')->where('severity', 'critical')->pluck('id')->values()->all(),
            'warnings' => collect($checks)->where('status', 'fail')->where('severity', 'warn')->pluck('id')->values()->all(),
        ];
        $payload['run_certification_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * @return array<string,mixed>
     */
    private function visual(string $path): array
    {
        return $path !== '' ? app(AtlasFrontendVisualQualityGateService::class)->inspect($path) : ['status' => 'missing'];
    }

    /**
     * @return array<string,mixed>
     */
    private function review(string $path): array
    {
        return $path !== '' ? app(AtlasFrontendDesignReviewService::class)->inspect($path) : ['status' => 'missing'];
    }

    /**
     * @return array<string,mixed>
     */
    private function evidence(string $manifest, string $root): array
    {
        return $manifest !== '' ? app(AtlasFrontendEvidencePackVerifierService::class)->verify($manifest, $root !== '' ? $root : null) : ['status' => 'missing'];
    }

    /**
     * @return array<string,mixed>
     */
    private function publication(string $bundle, string $receipt): array
    {
        return $bundle !== '' ? app(AtlasFrontendPublicationVerifierService::class)->verify($bundle, $receipt !== '' ? $receipt : null) : ['status' => 'missing'];
    }

    /**
     * @return array<string,mixed>
     */
    private function outcome(string $store): array
    {
        return $store !== '' ? app(AtlasFrontendOutcomeMemoryService::class)->summarize($store) : ['status' => 'missing'];
    }

    /**
     * @return array<string,string>
     */
    private function check(string $id, bool $passed, mixed $observed, string $severity = 'critical'): array
    {
        return [
            'id' => $id,
            'status' => $passed ? 'pass' : 'fail',
            'severity' => $severity,
            'observed' => is_scalar($observed) ? (string) $observed : 'unknown',
        ];
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function summary(array $payload, string $hashKey): array
    {
        return [
            'status' => $payload['status'] ?? 'missing',
            'hash' => $payload[$hashKey] ?? null,
            'blocker_count' => count((array) ($payload['blockers'] ?? [])),
            'warning_count' => count((array) ($payload['warnings'] ?? [])),
        ];
    }

    /**
     * @param  array<string,mixed>  ...$payloads
     * @return array<int,string>
     */
    private function taskSpecHashes(array ...$payloads): array
    {
        $hashes = [];
        foreach ($payloads as $payload) {
            $hash = $payload['task_spec_hash'] ?? null;
            if (is_string($hash) && preg_match('/^[a-f0-9]{64}$/', $hash)) {
                $hashes[] = $hash;
            }
        }

        return $hashes;
    }
}
