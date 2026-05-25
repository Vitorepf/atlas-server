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
        $qualityBudget = $this->qualityBudget((string) ($input['quality_budget_report'] ?? ''));
        $evidence = $this->evidence((string) ($input['evidence_manifest'] ?? ''), (string) ($input['evidence_root'] ?? ''));
        $publication = $this->publication((string) ($input['bundle'] ?? ''), (string) ($input['publication_receipt'] ?? ''));
        $outcome = $this->outcome((string) ($input['outcome_store'] ?? ''));
        $taskSpecHashes = $this->taskSpecHashes($visual, $review, $qualityBudget, $evidence);
        $frontendAppScope = $this->frontendAppScopeConsistency([
            'visual_quality' => $visual,
            'design_review' => $review,
            'quality_budget' => $qualityBudget,
            'evidence_pack' => $evidence,
        ]);

        $checks = [
            $this->check('visual_quality_passed', in_array($visual['status'] ?? null, ['passed', 'warning'], true), $visual['status'] ?? 'missing'),
            $this->check('design_5d_review_passed', in_array($review['status'] ?? null, ['passed', 'warning'], true), $review['status'] ?? 'missing'),
            $this->check('quality_budget_passed', in_array($qualityBudget['status'] ?? null, ['passed', 'warning'], true), $qualityBudget['status'] ?? 'missing'),
            $this->check('evidence_pack_passed', ($evidence['status'] ?? null) === 'passed', $evidence['status'] ?? 'missing'),
            $this->check('task_spec_hash_consistent', count(array_unique($taskSpecHashes)) === 1 && count($taskSpecHashes) === 4, $taskSpecHashes === [] ? 'missing' : implode(',', array_values(array_unique($taskSpecHashes)))),
            $this->check('frontend_app_scope_consistent', (bool) ($frontendAppScope['consistent'] ?? false), $frontendAppScope['status'] ?? 'missing'),
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
            'frontend_app_scope' => $frontendAppScope,
            'checks' => $checks,
            'artifacts' => [
                'visual_quality' => $this->summary($visual, 'gate_hash'),
                'design_review' => $this->summary($review, 'review_hash'),
                'quality_budget' => $this->summary($qualityBudget, 'quality_budget_hash'),
                'evidence_pack' => $this->summary($evidence, 'verification_hash'),
                'publication' => $this->summary($publication, 'publication_hash'),
                'outcome_memory' => $this->summary($outcome, 'outcome_memory_hash'),
            ],
            'claim_policy' => [
                'frontend_completion_claim_allowed' => in_array($status, ['certified', 'warning'], true) && ! $failedCritical && ($outcome['status'] ?? null) === 'ready',
                'frontend_completion_claim_requires_outcome_memory' => true,
                'frontend_completion_claim_requires_quality_budget' => true,
                'frontend_completion_claim_requires_frontend_app_scope_consistency' => true,
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
    private function qualityBudget(string $path): array
    {
        return $path !== '' ? app(AtlasFrontendQualityBudgetGateService::class)->inspect($path) : ['status' => 'missing'];
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
            'frontend_app_scope_status' => data_get($payload, 'frontend_app_scope.status'),
            'frontend_app_scope_hash' => data_get($payload, 'frontend_app_scope.relative_name_hash'),
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

    /**
     * @param  array<string,array<string,mixed>>  $payloads
     * @return array<string,mixed>
     */
    private function frontendAppScopeConsistency(array $payloads): array
    {
        $scopes = [];
        foreach ($payloads as $name => $payload) {
            $scope = $this->normalizeFrontendAppScope((array) ($payload['frontend_app_scope'] ?? []));
            $scopes[$name] = $scope;
        }

        $keys = array_map(fn (array $scope): string => $this->frontendAppScopeKey($scope), $scopes);
        $uniqueKeys = array_values(array_unique($keys));
        $invalid = collect($scopes)->contains(fn (array $scope): bool => in_array($scope['status'] ?? null, ['invalid_subscope', 'missing_subscope'], true));
        $consistent = count($uniqueKeys) === 1 && ! $invalid;
        $first = array_values($scopes)[0] ?? ['status' => 'repo_root', 'relative_name_hash' => null];

        return [
            'status' => $consistent ? (string) ($first['status'] ?? 'repo_root') : 'mismatch',
            'consistent' => $consistent,
            'relative_name' => $consistent && ($first['status'] ?? null) === 'subscope_selected' ? ($first['relative_name'] ?? null) : null,
            'relative_name_hash' => $consistent ? ($first['relative_name_hash'] ?? null) : null,
            'artifact_scopes' => collect($scopes)->map(fn (array $scope): array => [
                'status' => $scope['status'] ?? 'repo_root',
                'relative_name_hash' => $scope['relative_name_hash'] ?? null,
            ])->all(),
            'observed_scope_keys_hash' => hash('sha256', implode('|', $uniqueKeys)),
        ];
    }

    /**
     * @param  array<string,mixed>  $scope
     * @return array<string,mixed>
     */
    private function normalizeFrontendAppScope(array $scope): array
    {
        if ($scope === []) {
            return ['status' => 'repo_root', 'relative_name_hash' => null];
        }

        $status = (string) ($scope['status'] ?? 'repo_root');
        if ($status !== 'subscope_selected') {
            return [
                'status' => $status !== '' ? $status : 'repo_root',
                'relative_name_hash' => null,
            ];
        }

        $relative = is_string($scope['relative_name'] ?? null) ? trim(str_replace('\\', '/', (string) $scope['relative_name']), '/') : null;
        if ($relative === null || $relative === '' || str_starts_with($relative, '/') || str_contains($relative, '..')) {
            return [
                'status' => 'invalid_subscope',
                'relative_name_hash' => $relative !== null ? hash('sha256', $relative) : null,
            ];
        }

        return [
            'status' => 'subscope_selected',
            'relative_name' => $relative,
            'relative_name_hash' => hash('sha256', $relative),
        ];
    }

    /**
     * @param  array<string,mixed>  $scope
     */
    private function frontendAppScopeKey(array $scope): string
    {
        return implode(':', [
            (string) ($scope['status'] ?? 'repo_root'),
            (string) ($scope['relative_name_hash'] ?? ''),
        ]);
    }
}
