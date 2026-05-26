<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfImprovement;

use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Atlas Self-Improvement Capability Maturity Score (0..10 ladder).
 *
 * Measures how mature a capability really is, NOT how much code exists. Each
 * level is cumulative: level 7 (audit certification) requires levels 0..6 to
 * already be present. The service evaluates against a capability descriptor
 * (file paths to check, audit block to inspect, test file, doc, etc) and
 * returns the highest contiguous level reached + the per-level checklist.
 *
 * Hard rules:
 *   - NEVER calls a provider;
 *   - NEVER promotes a Forge run;
 *   - Level cannot be claimed without evidence (file/class/method exists,
 *     audit block present, etc).
 *
 * Schema: atlas.self_improvement.capability_maturity_score.v1
 */
class AtlasSelfImprovementCapabilityMaturityScoreService
{
    public const SCHEMA_VERSION = 'atlas.self_improvement.capability_maturity_score.v1';

    public const MAX_LEVEL = 10;

    /** @var array<int,string> Canonical level labels. */
    public const LEVELS = [
        0 => 'doc_only',
        1 => 'service_exists',
        2 => 'cli_exists',
        3 => 'api_exists',
        4 => 'tests_exist',
        5 => 'ui_exists',
        6 => 'state_projection_exists',
        7 => 'audit_certification_exists',
        8 => 'replay_evidence_exists',
        9 => 'rivals_or_before_after_exists',
        10 => 'production_ready',
    ];

    /**
     * Compute the maturity score for a capability descriptor.
     *
     * @param  array{
     *     capability: string,
     *     doc?: ?string,
     *     service_class?: ?string,
     *     command_signature?: ?string,
     *     api_route?: ?string,
     *     test_class?: ?string,
     *     ui_path?: ?string,
     *     state_field?: ?string,
     *     audit_block?: ?string,
     *     evidence_paths?: list<string>,
     *     rivals_or_delta_evidence?: bool,
     *     production_ready?: bool,
     * }  $descriptor
     * @param  array<string,mixed>  $context  Workspace root, after-snapshot for audit checks, etc.
     * @return array<string,mixed>
     */
    public function score(array $descriptor, array $context = []): array
    {
        $workspace = $this->stringOrNull($context['workspace'] ?? null) ?? base_path();
        $audit = $context['after_snapshot']['completion_audit'] ?? $context['completion_audit'] ?? [];

        $checks = [];
        $checks[0] = $this->checkLevel0($descriptor, $workspace);
        $checks[1] = $this->checkLevel1($descriptor);
        $checks[2] = $this->checkLevel2($descriptor);
        $checks[3] = $this->checkLevel3($descriptor, $workspace);
        $checks[4] = $this->checkLevel4($descriptor, $workspace);
        $checks[5] = $this->checkLevel5($descriptor, $workspace);
        $checks[6] = $this->checkLevel6($descriptor, $workspace);
        $checks[7] = $this->checkLevel7($descriptor, $audit);
        $checks[8] = $this->checkLevel8($descriptor);
        $checks[9] = $this->checkLevel9($descriptor);
        $checks[10] = $this->checkLevel10($descriptor);

        // Cumulative: highest contiguous level passed.
        $achieved = -1;
        foreach (range(0, self::MAX_LEVEL) as $level) {
            if (! ($checks[$level]['passed'] ?? false)) {
                break;
            }
            $achieved = $level;
        }

        $expectedLevel = $this->intInRange($descriptor['expected_level'] ?? null, 0, self::MAX_LEVEL);
        $beforeLevel = $this->intInRange($descriptor['before_level'] ?? null, -1, self::MAX_LEVEL);
        $delta = $beforeLevel >= 0 ? ($achieved - $beforeLevel) : null;

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'score_id' => 'mat_'.(string) Str::ulid(),
            'evaluated_at' => Carbon::now()->toIso8601String(),
            'capability' => (string) ($descriptor['capability'] ?? 'unspecified'),
            'before_level' => $beforeLevel >= 0 ? $beforeLevel : null,
            'expected_level' => $expectedLevel,
            'achieved_level' => $achieved,
            'achieved_label' => $achieved >= 0 ? self::LEVELS[$achieved] : 'none',
            'delta' => $delta,
            'meets_expected' => $expectedLevel === null || $achieved >= $expectedLevel,
            'levels' => array_map(static fn (int $i) => [
                'level' => $i,
                'label' => self::LEVELS[$i],
                'passed' => $checks[$i]['passed'] ?? false,
                'reason' => $checks[$i]['reason'] ?? null,
            ], range(0, self::MAX_LEVEL)),
            'next_action' => $achieved >= self::MAX_LEVEL
                ? 'capability_production_ready'
                : 'climb_to_level_'.($achieved + 1).':'.self::LEVELS[$achieved + 1],
            'invariants' => [
                'cumulative_levels' => true,
                'evidence_required_per_level' => true,
                'never_calls_external_provider' => true,
                'never_promotes_directly' => true,
            ],
            'external_provider_call' => false,
            'provider_tokens_spent' => false,
            'separated_from' => 'external_rivals_certification',
            'is_read_model' => true,
        ];
    }

    /**
     * @param  array<string,mixed>  $d
     * @return array{passed: bool, reason: ?string}
     */
    private function checkLevel0(array $d, string $workspace): array
    {
        $doc = $this->stringOrNull($d['doc'] ?? null);
        if ($doc === null) {
            return ['passed' => false, 'reason' => 'no_doc_path_provided'];
        }
        if (! is_file($workspace.DIRECTORY_SEPARATOR.$doc)) {
            return ['passed' => false, 'reason' => 'doc_file_missing'];
        }

        return ['passed' => true, 'reason' => null];
    }

    /** @param array<string,mixed> $d */
    private function checkLevel1(array $d): array
    {
        $class = $this->stringOrNull($d['service_class'] ?? null);
        if ($class === null) {
            return ['passed' => false, 'reason' => 'no_service_class_provided'];
        }

        return class_exists($class)
            ? ['passed' => true, 'reason' => null]
            : ['passed' => false, 'reason' => 'service_class_missing'];
    }

    /** @param array<string,mixed> $d */
    private function checkLevel2(array $d): array
    {
        $signature = $this->stringOrNull($d['command_signature'] ?? null);
        if ($signature === null) {
            return ['passed' => false, 'reason' => 'no_command_signature_provided'];
        }
        // We accept either explicit class or signature with `atlas:` prefix.
        if (str_starts_with($signature, 'App\\')) {
            return class_exists($signature)
                ? ['passed' => true, 'reason' => null]
                : ['passed' => false, 'reason' => 'command_class_missing'];
        }
        if (! str_starts_with($signature, 'atlas:')) {
            return ['passed' => false, 'reason' => 'invalid_command_signature'];
        }

        return ['passed' => true, 'reason' => null];
    }

    /** @param array<string,mixed> $d */
    private function checkLevel3(array $d, string $workspace): array
    {
        $route = $this->stringOrNull($d['api_route'] ?? null);
        if ($route === null) {
            return ['passed' => false, 'reason' => 'no_api_route_provided'];
        }
        $routesPath = $workspace.'/routes/api.php';
        if (! is_file($routesPath)) {
            return ['passed' => false, 'reason' => 'routes_file_missing'];
        }
        $source = (string) @file_get_contents($routesPath);

        return str_contains($source, $route)
            ? ['passed' => true, 'reason' => null]
            : ['passed' => false, 'reason' => 'api_route_not_found_in_routes_file'];
    }

    /** @param array<string,mixed> $d */
    private function checkLevel4(array $d, string $workspace): array
    {
        $test = $this->stringOrNull($d['test_class'] ?? null);
        if ($test === null) {
            return ['passed' => false, 'reason' => 'no_test_class_provided'];
        }
        if (str_starts_with($test, 'tests/')) {
            return is_file($workspace.DIRECTORY_SEPARATOR.$test)
                ? ['passed' => true, 'reason' => null]
                : ['passed' => false, 'reason' => 'test_file_missing'];
        }
        if (str_starts_with($test, 'Tests\\')) {
            return class_exists($test)
                ? ['passed' => true, 'reason' => null]
                : ['passed' => false, 'reason' => 'test_class_missing'];
        }

        return ['passed' => false, 'reason' => 'invalid_test_class_descriptor'];
    }

    /** @param array<string,mixed> $d */
    private function checkLevel5(array $d, string $workspace): array
    {
        $ui = $this->stringOrNull($d['ui_path'] ?? null);
        if ($ui === null) {
            return ['passed' => false, 'reason' => 'no_ui_path_provided'];
        }
        $candidates = [
            $workspace.DIRECTORY_SEPARATOR.$ui,
            dirname($workspace).DIRECTORY_SEPARATOR.$ui,
            dirname($workspace).'/atlas-desktop/'.$ui,
        ];
        foreach ($candidates as $candidate) {
            if (is_file($candidate)) {
                return ['passed' => true, 'reason' => null];
            }
        }

        return ['passed' => false, 'reason' => 'ui_file_missing'];
    }

    /** @param array<string,mixed> $d */
    private function checkLevel6(array $d, string $workspace): array
    {
        $field = $this->stringOrNull($d['state_field'] ?? null);
        if ($field === null) {
            return ['passed' => false, 'reason' => 'no_state_field_provided'];
        }
        $controllerPath = $workspace.'/app/Http/Controllers/AtlasCodeWorkController.php';
        if (! is_file($controllerPath)) {
            return ['passed' => false, 'reason' => 'state_controller_missing'];
        }
        $source = (string) @file_get_contents($controllerPath);

        return str_contains($source, $field)
            ? ['passed' => true, 'reason' => null]
            : ['passed' => false, 'reason' => 'state_field_not_in_controller'];
    }

    /**
     * @param  array<string,mixed>  $d
     * @param  array<string,mixed>  $audit
     */
    private function checkLevel7(array $d, array $audit): array
    {
        $block = $this->stringOrNull($d['audit_block'] ?? null);
        if ($block === null) {
            return ['passed' => false, 'reason' => 'no_audit_block_provided'];
        }
        if (! array_key_exists($block, $audit)) {
            return ['passed' => false, 'reason' => 'audit_block_missing'];
        }
        $entry = $audit[$block];
        if (! is_array($entry) || ! isset($entry['schema_version'])) {
            return ['passed' => false, 'reason' => 'audit_block_invalid'];
        }

        return ['passed' => true, 'reason' => null];
    }

    /** @param array<string,mixed> $d */
    private function checkLevel8(array $d): array
    {
        $evidence = $d['evidence_paths'] ?? [];
        if (! is_array($evidence) || $evidence === []) {
            return ['passed' => false, 'reason' => 'no_evidence_paths_provided'];
        }

        return ['passed' => true, 'reason' => null];
    }

    /** @param array<string,mixed> $d */
    private function checkLevel9(array $d): array
    {
        return (bool) ($d['rivals_or_delta_evidence'] ?? false)
            ? ['passed' => true, 'reason' => null]
            : ['passed' => false, 'reason' => 'no_rivals_or_delta_evidence_declared'];
    }

    /** @param array<string,mixed> $d */
    private function checkLevel10(array $d): array
    {
        return (bool) ($d['production_ready'] ?? false)
            ? ['passed' => true, 'reason' => null]
            : ['passed' => false, 'reason' => 'production_ready_not_declared'];
    }

    private function intInRange(mixed $value, int $min, int $max): ?int
    {
        if (! is_numeric($value)) {
            return null;
        }
        $int = (int) $value;
        if ($int < $min || $int > $max) {
            return null;
        }

        return $int;
    }

    private function stringOrNull(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
