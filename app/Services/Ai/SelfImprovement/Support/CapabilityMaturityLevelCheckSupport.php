<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfImprovement\Support;

use App\Services\Ai\SelfImprovement\AtlasSelfImprovementCapabilityMaturityScoreService as Mother;

/**
 * Pure level-checker + cumulative ladder math for capability maturity score.
 *
 * No filesystem, no class_exists, no Carbon/Str, no container. The mother
 * resolves existence facts (is_file / class_exists / file contents) and passes
 * them in; this support owns pure reason/pass decisions and contiguous climb.
 *
 * Schema owner remains {@see Mother} (atlas.self_improvement.capability_maturity_score.v1).
 */
final class CapabilityMaturityLevelCheckSupport
{
    /**
     * @return array{passed: bool, reason: ?string}
     */
    public static function level0(?string $doc, bool $docFileExists): array
    {
        if ($doc === null) {
            return self::fail('no_doc_path_provided');
        }
        if (! $docFileExists) {
            return self::fail('doc_file_missing');
        }

        return self::pass();
    }

    /**
     * @return array{passed: bool, reason: ?string}
     */
    public static function level1(?string $serviceClass, bool $serviceClassExists): array
    {
        if ($serviceClass === null) {
            return self::fail('no_service_class_provided');
        }

        return $serviceClassExists
            ? self::pass()
            : self::fail('service_class_missing');
    }

    /**
     * Command signature form: FQCN under App\, or atlas:* signature string.
     *
     * @return array{passed: bool, reason: ?string}
     */
    public static function level2(?string $signature, bool $commandClassExists): array
    {
        if ($signature === null) {
            return self::fail('no_command_signature_provided');
        }
        if (str_starts_with($signature, 'App\\')) {
            return $commandClassExists
                ? self::pass()
                : self::fail('command_class_missing');
        }
        if (! str_starts_with($signature, 'atlas:')) {
            return self::fail('invalid_command_signature');
        }

        return self::pass();
    }

    /**
     * @return array{passed: bool, reason: ?string}
     */
    public static function level3(?string $route, bool $routesFileExists, string $routesSource): array
    {
        if ($route === null) {
            return self::fail('no_api_route_provided');
        }
        if (! $routesFileExists) {
            return self::fail('routes_file_missing');
        }

        return str_contains($routesSource, $route)
            ? self::pass()
            : self::fail('api_route_not_found_in_routes_file');
    }

    /**
     * test_class may be a repo-relative path (tests/...) or FQCN (Tests\...).
     *
     * @return array{passed: bool, reason: ?string}
     */
    public static function level4(?string $test, bool $testArtifactExists): array
    {
        if ($test === null) {
            return self::fail('no_test_class_provided');
        }
        if (str_starts_with($test, 'tests/')) {
            return $testArtifactExists
                ? self::pass()
                : self::fail('test_file_missing');
        }
        if (str_starts_with($test, 'Tests\\')) {
            return $testArtifactExists
                ? self::pass()
                : self::fail('test_class_missing');
        }

        return self::fail('invalid_test_class_descriptor');
    }

    /**
     * @return array{passed: bool, reason: ?string}
     */
    public static function level5(?string $ui, bool $uiFileExists): array
    {
        if ($ui === null) {
            return self::fail('no_ui_path_provided');
        }

        return $uiFileExists
            ? self::pass()
            : self::fail('ui_file_missing');
    }

    /**
     * @return array{passed: bool, reason: ?string}
     */
    public static function level6(?string $field, bool $controllerFileExists, string $controllerSource): array
    {
        if ($field === null) {
            return self::fail('no_state_field_provided');
        }
        if (! $controllerFileExists) {
            return self::fail('state_controller_missing');
        }

        return str_contains($controllerSource, $field)
            ? self::pass()
            : self::fail('state_field_not_in_controller');
    }

    /**
     * @param  array<string,mixed>  $audit
     * @return array{passed: bool, reason: ?string}
     */
    public static function level7(?string $block, array $audit): array
    {
        if ($block === null) {
            return self::fail('no_audit_block_provided');
        }
        if (! array_key_exists($block, $audit)) {
            return self::fail('audit_block_missing');
        }
        $entry = $audit[$block];
        if (! is_array($entry) || ! isset($entry['schema_version'])) {
            return self::fail('audit_block_invalid');
        }

        return self::pass();
    }

    /**
     * @param  array<string,mixed>  $d
     * @return array{passed: bool, reason: ?string}
     */
    public static function level8(array $d): array
    {
        $evidence = $d['evidence_paths'] ?? [];
        if (! is_array($evidence) || $evidence === []) {
            return self::fail('no_evidence_paths_provided');
        }

        return self::pass();
    }

    /**
     * @param  array<string,mixed>  $d
     * @return array{passed: bool, reason: ?string}
     */
    public static function level9(array $d): array
    {
        return (bool) ($d['rivals_or_delta_evidence'] ?? false)
            ? self::pass()
            : self::fail('no_rivals_or_delta_evidence_declared');
    }

    /**
     * @param  array<string,mixed>  $d
     * @return array{passed: bool, reason: ?string}
     */
    public static function level10(array $d): array
    {
        return (bool) ($d['production_ready'] ?? false)
            ? self::pass()
            : self::fail('production_ready_not_declared');
    }

    /**
     * Highest contiguous level with passed=true. Returns -1 when level 0 fails.
     *
     * @param  array<int, array{passed?: bool}>  $checks
     */
    public static function achievedLevel(array $checks, int $maxLevel = Mother::MAX_LEVEL): int
    {
        $achieved = -1;
        foreach (range(0, $maxLevel) as $level) {
            if (! ($checks[$level]['passed'] ?? false)) {
                break;
            }
            $achieved = $level;
        }

        return $achieved;
    }

    public static function nextAction(int $achieved, int $maxLevel = Mother::MAX_LEVEL): string
    {
        if ($achieved >= $maxLevel) {
            return 'capability_production_ready';
        }

        $next = $achieved + 1;

        return 'climb_to_level_'.$next.':'.(Mother::LEVELS[$next] ?? 'unknown');
    }

    /**
     * @param  array<int, array{passed?: bool, reason?: ?string}>  $checks
     * @return list<array{level: int, label: string, passed: bool, reason: ?string}>
     */
    public static function levelsRows(array $checks, int $maxLevel = Mother::MAX_LEVEL): array
    {
        return array_map(static fn (int $i): array => [
            'level' => $i,
            'label' => Mother::LEVELS[$i] ?? 'unknown',
            'passed' => (bool) ($checks[$i]['passed'] ?? false),
            'reason' => $checks[$i]['reason'] ?? null,
        ], range(0, $maxLevel));
    }

    public static function intInRange(mixed $value, int $min, int $max): ?int
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

    public static function stringOrNull(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $value = trim($value);

        return $value === '' ? null : $value;
    }

    /**
     * @return array{passed: bool, reason: null}
     */
    private static function pass(): array
    {
        return ['passed' => true, 'reason' => null];
    }

    /**
     * @return array{passed: bool, reason: string}
     */
    private static function fail(string $reason): array
    {
        return ['passed' => false, 'reason' => $reason];
    }
}
