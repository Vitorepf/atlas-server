<?php

declare(strict_types=1);

namespace App\Services\Ai\RuntimeEfficiency;

use App\Services\Ai\Mission\MissionCanonicalHash;
use App\Services\Ai\Programming\ProgrammingTestImpactAnalyzer;
use Carbon\CarbonImmutable;

final class AtlasLocalVerificationEngineService
{
    public const SCHEMA_VERSION = 'atlas.local_verification.engine.v1';

    public const FAILURE_CAPSULE_SCHEMA = 'atlas.failure_capsule.v1';

    public const TEST_IMPACT_SCHEMA = 'atlas.local_verification.test_impact.v1';

    public const STATUS_READY = 'ready';

    public const STATUS_WATCH = 'watch';

    public const STATUS_BLOCKED = 'blocked';

    public function __construct(
        private readonly ProgrammingTestImpactAnalyzer $testImpactAnalyzer,
    ) {}

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function run(array $input = []): array
    {
        $flowId = (string) ($input['flow_id'] ?? 'atlas_dev');
        $risk = $this->risk((string) ($input['risk_level'] ?? 'medium'));
        $changedFiles = $this->stringList($input['changed_files'] ?? []);
        $forbidden = $this->stringList($input['forbidden_files'] ?? []);
        $allowed = $this->stringList($input['allowed_files'] ?? []);
        $resourcePolicy = is_array($input['resource_policy'] ?? null) ? $input['resource_policy'] : [];
        $scope = $this->scopeGuard($changedFiles, $allowed, $forbidden);
        $impact = $this->testImpact($changedFiles, is_array($input['code_graph'] ?? null) ? $input['code_graph'] : [], $risk);
        $failureCapsule = $this->failureCapsule($input, $changedFiles);
        $gatePlan = $this->gatePlan($impact, $resourcePolicy, $risk);
        $blockers = [];
        if (($scope['status'] ?? null) === self::STATUS_BLOCKED) {
            $blockers[] = 'diff_scope_violation';
        }
        if ((bool) data_get($resourcePolicy, 'heavy_jobs_allowed') === false && in_array('deep_gates', $gatePlan['admitted_gate_groups'], true)) {
            $blockers[] = 'deep_gates_not_allowed_by_resource_policy';
        }
        if ($failureCapsule !== null && (bool) ($failureCapsule['root_cause_present'] ?? false) === false) {
            $blockers[] = 'failure_capsule_root_cause_missing';
        }
        $requiresNoTestReason = $changedFiles !== [] && (bool) ($impact['requires_no_test_reason'] ?? false);
        $status = $blockers !== [] ? self::STATUS_BLOCKED : (($failureCapsule !== null || $requiresNoTestReason) ? self::STATUS_WATCH : self::STATUS_READY);

        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $status,
            'generated_at' => CarbonImmutable::now()->toJSON(),
            'flow_id' => $flowId,
            'risk_level' => $risk,
            'scope_guard' => $scope,
            'test_impact' => $impact,
            'gate_plan' => $gatePlan,
            'failure_capsule' => $failureCapsule,
            'blockers' => $blockers,
            'metrics' => [
                'changed_file_count' => count($changedFiles),
                'selected_test_count' => count((array) ($impact['selected_tests'] ?? [])),
                'selected_existing_test_count' => count((array) ($impact['selected_existing_tests'] ?? [])),
                'failure_capsule_present' => $failureCapsule !== null,
                'local_verification_value' => $this->localVerificationValue($impact, $failureCapsule, $scope),
            ],
            'claim_policy' => [
                'commands_executed' => false,
                'providers_invoked' => false,
                'writes' => false,
                'raw_log_exposed' => false,
                'auto_repair_allowed' => $failureCapsule !== null && (bool) ($failureCapsule['root_cause_present'] ?? false),
            ],
        ];
        $payload['local_verification_hash'] = MissionCanonicalHash::sha256(array_diff_key($payload, ['generated_at' => true]));

        return $payload;
    }

    /**
     * @param  list<string>  $changedFiles
     * @param  list<string>  $allowed
     * @param  list<string>  $forbidden
     * @return array<string,mixed>
     */
    private function scopeGuard(array $changedFiles, array $allowed, array $forbidden): array
    {
        $forbiddenHits = array_values(array_filter($changedFiles, fn (string $file): bool => $this->matchesAny($file, $forbidden)));
        $outsideAllowed = $allowed === [] ? [] : array_values(array_filter($changedFiles, fn (string $file): bool => ! $this->matchesAny($file, $allowed)));
        $payload = [
            'schema_version' => 'atlas.local_verification.diff_scope_guard.v1',
            'status' => ($forbiddenHits === [] && $outsideAllowed === []) ? self::STATUS_READY : self::STATUS_BLOCKED,
            'changed_files' => $changedFiles,
            'allowed_patterns' => $allowed,
            'forbidden_patterns' => $forbidden,
            'forbidden_hits' => $forbiddenHits,
            'outside_allowed' => $outsideAllowed,
        ];
        $payload['scope_guard_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * @param  list<string>  $changedFiles
     * @param  array<string,mixed>  $codeGraph
     * @return array<string,mixed>
     */
    private function testImpact(array $changedFiles, array $codeGraph, string $risk): array
    {
        $impact = $this->testImpactAnalyzer->analyze($changedFiles, $codeGraph, $risk);
        $impact['schema_version'] = self::TEST_IMPACT_SCHEMA;
        $impact['impact_hash'] = MissionCanonicalHash::sha256($impact);

        return $impact;
    }

    /**
     * @param  array<string,mixed>  $input
     * @param  list<string>  $changedFiles
     * @return array<string,mixed>|null
     */
    private function failureCapsule(array $input, array $changedFiles): ?array
    {
        $exitCode = array_key_exists('exit_code', $input) ? (int) $input['exit_code'] : null;
        $stderr = trim((string) ($input['stderr'] ?? ''));
        $stdout = trim((string) ($input['stdout'] ?? ''));
        $raw = trim($stderr."\n".$stdout);
        $failingTest = trim((string) ($input['failing_test'] ?? ''));
        if (($exitCode === null || $exitCode === 0) && $raw === '' && $failingTest === '') {
            return null;
        }

        $excerpt = $this->excerpt($raw);
        $rootCause = $this->rootCauseCandidate($excerpt, $failingTest);
        $payload = [
            'schema_version' => self::FAILURE_CAPSULE_SCHEMA,
            'command' => (string) ($input['command'] ?? ''),
            'exit_code' => $exitCode,
            'failing_test' => $failingTest === '' ? null : $failingTest,
            'file' => $this->firstFileMention($excerpt),
            'line' => $this->firstLineMention($excerpt),
            'stack_excerpt' => $excerpt,
            'root_cause_candidate' => $rootCause,
            'root_cause_present' => $rootCause !== '',
            'raw_log_hash' => MissionCanonicalHash::sha256($raw),
            'redaction_status' => 'excerpt_hashed_no_raw_log',
            'changed_files' => $changedFiles,
            'repair_allowed' => $rootCause !== '',
        ];
        $payload['failure_signature'] = MissionCanonicalHash::sha256([$payload['command'], $payload['exit_code'], $excerpt, $failingTest]);
        $payload['capsule_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * @param  array<string,mixed>  $impact
     * @param  array<string,mixed>  $resourcePolicy
     * @return array<string,mixed>
     */
    private function gatePlan(array $impact, array $resourcePolicy, string $risk): array
    {
        $resourceMode = (string) ($resourcePolicy['mode'] ?? 'normal');
        $heavyAllowed = (bool) ($resourcePolicy['heavy_jobs_allowed'] ?? in_array($resourceMode, ['normal', 'deep'], true));
        $groups = ['diff_scope_guard', 'impact_selector', 'cheap_gates'];
        if ((array) ($impact['selected_existing_tests'] ?? []) !== []) {
            $groups[] = 'targeted_tests';
        }
        if ($heavyAllowed && in_array($risk, ['high', 'critical'], true)) {
            $groups[] = 'deep_gates';
        }

        $plan = [
            'schema_version' => 'atlas.local_verification.gate_plan.v1',
            'resource_mode' => $resourceMode,
            'heavy_jobs_allowed' => $heavyAllowed,
            'admitted_gate_groups' => $groups,
            'recommended_commands' => array_values((array) ($impact['recommended_commands'] ?? [])),
            'commands_executed' => false,
        ];
        $plan['gate_plan_hash'] = MissionCanonicalHash::sha256($plan);

        return $plan;
    }

    /**
     * @param  array<string,mixed>  $impact
     * @param  array<string,mixed>|null  $capsule
     * @param  array<string,mixed>  $scope
     */
    private function localVerificationValue(array $impact, ?array $capsule, array $scope): float
    {
        $score = 0.20;
        if ((array) ($impact['selected_tests'] ?? []) !== []) {
            $score += 0.30;
        }
        if ((array) ($impact['selected_existing_tests'] ?? []) !== []) {
            $score += 0.20;
        }
        if (($scope['status'] ?? null) === self::STATUS_READY) {
            $score += 0.15;
        }
        if ($capsule !== null && (bool) ($capsule['root_cause_present'] ?? false)) {
            $score += 0.15;
        }

        return round(min(1.0, $score), 2);
    }

    private function excerpt(string $raw): string
    {
        $normalized = trim((string) preg_replace('/\s+/', ' ', $raw));
        $normalized = (string) preg_replace('/\b(api[_-]?key|token|secret|password)=\S+/i', '$1=[redacted]', $normalized);

        return substr($normalized, 0, 1200);
    }

    private function rootCauseCandidate(string $excerpt, string $failingTest): string
    {
        if ($failingTest !== '') {
            return 'failing_test:'.$failingTest;
        }
        if (preg_match('/(?:failed asserting|expect(?:ed)?|exception|error|fatal|parse error|typeerror|undefined|cannot|class .* not found)/i', $excerpt, $matches) === 1) {
            return strtolower((string) $matches[0]);
        }

        return $excerpt === '' ? '' : 'non_empty_failure_excerpt';
    }

    private function firstFileMention(string $excerpt): ?string
    {
        if (preg_match('/([A-Za-z0-9_\/.-]+\.(?:php|ts|tsx|js|jsx|vue|swift|kt|py))/', $excerpt, $matches) !== 1) {
            return null;
        }

        return $matches[1];
    }

    private function firstLineMention(string $excerpt): ?int
    {
        if (preg_match('/(?:line|:)(\d{1,6})/i', $excerpt, $matches) !== 1) {
            return null;
        }

        return max(1, (int) $matches[1]);
    }

    /**
     * @return list<string>
     */
    private function stringList(mixed $value): array
    {
        return array_values(array_filter((array) $value, 'is_string'));
    }

    /**
     * @param  list<string>  $patterns
     */
    private function matchesAny(string $file, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            if ($pattern !== '' && str_starts_with($file, $pattern)) {
                return true;
            }
            if ($pattern !== '' && fnmatch($pattern, $file)) {
                return true;
            }
        }

        return false;
    }

    private function risk(string $risk): string
    {
        return in_array($risk, ['low', 'medium', 'high', 'critical'], true) ? $risk : 'medium';
    }
}
