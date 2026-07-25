<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfImprovement;

use App\Services\Ai\SelfImprovement\Support\CapabilityMaturityLevelCheckSupport as LevelCheck;
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
 * Pure level decisions live in {@see LevelCheck}; this class only resolves
 * workspace/filesystem/class-existence facts and assembles the score envelope.
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
        $workspace = LevelCheck::stringOrNull($context['workspace'] ?? null) ?? base_path();
        $audit = $context['after_snapshot']['completion_audit'] ?? $context['completion_audit'] ?? [];
        if (! is_array($audit)) {
            $audit = [];
        }

        $checks = $this->runLevelChecks($descriptor, $workspace, $audit);

        $achieved = LevelCheck::achievedLevel($checks);
        $expectedLevel = LevelCheck::intInRange($descriptor['expected_level'] ?? null, 0, self::MAX_LEVEL);
        $beforeLevel = LevelCheck::intInRange($descriptor['before_level'] ?? null, -1, self::MAX_LEVEL);
        $delta = $beforeLevel !== null && $beforeLevel >= 0 ? ($achieved - $beforeLevel) : null;

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'score_id' => 'mat_'.(string) Str::ulid(),
            'evaluated_at' => Carbon::now()->toIso8601String(),
            'capability' => (string) ($descriptor['capability'] ?? 'unspecified'),
            'before_level' => $beforeLevel !== null && $beforeLevel >= 0 ? $beforeLevel : null,
            'expected_level' => $expectedLevel,
            'achieved_level' => $achieved,
            'achieved_label' => $achieved >= 0 ? self::LEVELS[$achieved] : 'none',
            'delta' => $delta,
            'meets_expected' => $expectedLevel === null || $achieved >= $expectedLevel,
            'levels' => LevelCheck::levelsRows($checks),
            'next_action' => LevelCheck::nextAction($achieved),
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
     * @param  array<string,mixed>  $descriptor
     * @param  array<string,mixed>  $audit
     * @return array<int, array{passed: bool, reason: ?string}>
     */
    private function runLevelChecks(array $descriptor, string $workspace, array $audit): array
    {
        $doc = LevelCheck::stringOrNull($descriptor['doc'] ?? null);
        $serviceClass = LevelCheck::stringOrNull($descriptor['service_class'] ?? null);
        $signature = LevelCheck::stringOrNull($descriptor['command_signature'] ?? null);
        $route = LevelCheck::stringOrNull($descriptor['api_route'] ?? null);
        $test = LevelCheck::stringOrNull($descriptor['test_class'] ?? null);
        $ui = LevelCheck::stringOrNull($descriptor['ui_path'] ?? null);
        $field = LevelCheck::stringOrNull($descriptor['state_field'] ?? null);
        $block = LevelCheck::stringOrNull($descriptor['audit_block'] ?? null);

        $checks = [];
        $checks[0] = LevelCheck::level0(
            $doc,
            $doc !== null && is_file($workspace.DIRECTORY_SEPARATOR.$doc),
        );
        $checks[1] = LevelCheck::level1(
            $serviceClass,
            $serviceClass !== null && class_exists($serviceClass),
        );

        $commandClassExists = $signature !== null
            && str_starts_with($signature, 'App\\')
            && class_exists($signature);
        $checks[2] = LevelCheck::level2($signature, $commandClassExists);

        // routes/api.php may only require modular routes/api/*.php files; scan both.
        [$routesExists, $routesSource] = $this->resolveRoutesSource($workspace);
        $checks[3] = LevelCheck::level3($route, $routesExists, $routesSource);

        $testArtifactExists = false;
        if ($test !== null) {
            if (str_starts_with($test, 'tests/')) {
                $testArtifactExists = is_file($workspace.DIRECTORY_SEPARATOR.$test);
            } elseif (str_starts_with($test, 'Tests\\')) {
                $testArtifactExists = class_exists($test);
            }
        }
        $checks[4] = LevelCheck::level4($test, $testArtifactExists);

        $uiExists = false;
        if ($ui !== null) {
            $candidates = [
                $workspace.DIRECTORY_SEPARATOR.$ui,
                dirname($workspace).DIRECTORY_SEPARATOR.$ui,
                dirname($workspace).'/atlas-desktop/'.$ui,
            ];
            foreach ($candidates as $candidate) {
                if (is_file($candidate)) {
                    $uiExists = true;
                    break;
                }
            }
        }
        $checks[5] = LevelCheck::level5($ui, $uiExists);

        $controllerPath = $workspace.'/app/Http/Controllers/AtlasCodeWorkController.php';
        $controllerExists = is_file($controllerPath);
        $controllerSource = $controllerExists ? (string) @file_get_contents($controllerPath) : '';
        $checks[6] = LevelCheck::level6($field, $controllerExists, $controllerSource);

        $checks[7] = LevelCheck::level7($block, $audit);
        $checks[8] = LevelCheck::level8($descriptor);
        $checks[9] = LevelCheck::level9($descriptor);
        $checks[10] = LevelCheck::level10($descriptor);

        return $checks;
    }

    /**
     * @return array{0: bool, 1: string}  [exists, concatenated_source]
     */
    private function resolveRoutesSource(string $workspace): array
    {
        $chunks = [];
        $paths = [$workspace.'/routes/api.php'];
        $dir = $workspace.'/routes/api';
        if (is_dir($dir)) {
            $globbed = glob($dir.'/*.php') ?: [];
            sort($globbed);
            foreach ($globbed as $path) {
                $paths[] = $path;
            }
        }

        foreach ($paths as $path) {
            if (! is_file($path)) {
                continue;
            }
            $chunks[] = (string) @file_get_contents($path);
        }

        if ($chunks === []) {
            return [false, ''];
        }

        return [true, implode("\n", $chunks)];
    }
}
